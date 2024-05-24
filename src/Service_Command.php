<?php

/**
 * Manages global services of EasyEngine.
 *
 * ## EXAMPLES
 *
 *     # Restarts global nginx proxy service
 *     $ ee service restart nginx-proxy
 *
 *     # Restarts global nginx proxy service
 *     $ ee service restart db
 *
 *     # Restarts global nginx proxy service
 *     $ ee service restart redis
 *
 *     # Restarts global nginx proxy service
 *     $ ee service restart newrelic-daemon
 *
 * @package ee-cli
 */
class Service_Command extends EE_Command {

	/**
	 * @var array Array of services defined in global docker-compose.yml
	 */
	private $whitelisted_services = [
		'nginx-proxy',
		'db',
		'redis',
		'newrelic-daemon',
		'cron',
	];

	/**
	 * Service_Command constructor.
	 *
	 * Changes directory to EE_SERVICE_DIR since that's where all docker-compose commands will be executed
	 */
	public function __construct() {

		if ( ! is_dir( EE_SERVICE_DIR ) ) {
			mkdir( EE_SERVICE_DIR );
		}
		chdir( EE_SERVICE_DIR );

	}

	/**
	 * Starts global services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service - [ nginx-proxy, db, redis, newrelic-daemon ]
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable global service
	 *     $ ee service enable nginx-proxy
	 *
	 *     # Enable global service
	 *     $ ee service enable db
	 *
	 *     # Enable global service
	 *     $ ee service enable redis
	 *
	 *     # Enable global service
	 *     $ ee service enable newrelic-daemon
	 *
	 */
	public function enable( $args, $assoc_args ) {
		$service   = $this->filter_service( $args );
		$container = 'services_' . $service . '_1';

		if ( EE_PROXY_TYPE === $container ) {
			$service_status = \EE\Service\Utils\nginx_proxy_check();
			if ( $service_status ) {
				EE::success( 'Service nginx_proxy enabled.' );
			} else {
				EE::Log( 'Notice: Service nginx_proxy already enabled.' );
			}
		} else {
			$service_status = \EE\Service\Utils\init_global_container( $service );
			if ( $service_status ) {
				EE::success( sprintf( 'Service %s enabled.', $service ) );
			} else {
				EE::Log( sprintf( 'Notice: Service %s already enabled.', $service ) );
			}
		}
	}

	/**
	 * Re-create global services docker-compose file and update global containers.
	 *
	 * ## EXAMPLES
	 *
	 *     # Refresh global services
	 *     $ ee service refresh
	 */
	public function refresh( $args, $assoc_args ) {

		$running_services = [];
		$count            = 0;
		foreach ( $this->whitelisted_services as $service ) {
			$running_services[ $count ]['name']  = $service;
			$launch                              = EE::launch( 'docker-compose ps -q global-' . $service );
			$running_services[ $count ]['state'] = $launch->stdout;
			$count ++;
		}

		\EE\Service\Utils\generate_global_docker_compose_yml( new Symfony\Component\Filesystem\Filesystem() );

		foreach ( $running_services as $service ) {
			if ( ! empty( $service['state'] ) ) {
				EE::exec( \EE_DOCKER::docker_compose_with_custom() . " up -d global-${service['name']}", true, true );
			}
		}

		EE::success( 'Global services refreshed.' );
	}

	/**
	 * Returns valid service name from arguments.
	 */
	private function filter_service( $args ) {
		$services = array_intersect( $this->whitelisted_services, $args );

		if ( empty( $services ) ) {
			EE::error( "Unable to find global EasyEngine service $args[0]" );
		}

		$services = array_values( $services );

		return 'global-' . $services[0];
	}

	/**
	 * Stops global services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service - [ nginx-proxy, db, redis, newrelic-daemon ]
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable global service
	 *     $ ee service disable nginx-proxy
	 *
	 *     # Disable global service
	 *     $ ee service disable db
	 *
	 *     # Disable global service
	 *     $ ee service disable redis
	 *
	 *     # Disable global service
	 *     $ ee service disable newrelic-daemon
	 *
	 */
	public function disable( $args, $assoc_args ) {
		$service = $this->filter_service( $args );
		EE::exec( \EE_DOCKER::docker_compose_with_custom() . " stop $service", true, true );
		EE::success( sprintf( 'Service %s disabled.', $service ) );
	}

	/**
	 * Restarts global services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service - [ nginx-proxy, db, redis, newrelic-daemon ]
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart global service
	 *     $ ee service restart nginx-proxy
	 *
	 *     # Restart global service
	 *     $ ee service restart db
	 *
	 *     # Restart global service
	 *     $ ee service restart redis
	 *
	 *     # Restart global service
	 *     $ ee service restart newrelic-daemon
	 *
	 */
	public function restart( $args, $assoc_args ) {
		$service = $this->filter_service( $args );
		EE::exec( \EE_DOCKER::docker_compose_with_custom() . " restart $service", true, true );
		EE::success( sprintf( 'Service %s restarted.', $service ) );
	}

	/**
	 * Reloads global service without restarting services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service - [ nginx-proxy, db, redis, newrelic-daemon ]
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload global service
	 *     $ ee service reload nginx-proxy
	 *
	 *     # Reload global service
	 *     $ ee service reload db
	 *
	 *     # Reload global service
	 *     $ ee service reload redis
	 *
	 *     # Reload global service
	 *     $ ee service reload newrelic-daemon
	 *
	 */
	public function reload( $args, $assoc_args ) {
		$service = $this->filter_service( $args );
		$command = $this->service_reload_command( $service );
		if ( $command ) {
			EE::exec( \EE_DOCKER::docker_compose_with_custom() . " exec $service $command", true, true );
			EE::success( sprintf( 'Reloaded %s.', $service ) );
		} else {
			EE::warning( "$service can not be reloaded." );
		}
	}

	/**
	 * Returns reload command of a service.
	 * This is necessary since command to reload each service can be different.
	 *
	 * @param $service string name of service
	 *
	 * @return mixed
	 */
	private function service_reload_command( string $service ) {
		$command_map = [
			'global-nginx-proxy' => 'sh -c "/app/docker-entrypoint.sh /usr/local/bin/docker-gen /app/nginx.tmpl /etc/nginx/conf.d/default.conf; /usr/sbin/nginx -t; /usr/sbin/nginx -s reload"',
		];

		return array_key_exists( $service, $command_map ) ? $command_map[ $service ] : false;
	}
}
