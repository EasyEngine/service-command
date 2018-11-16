<?php

use Symfony\Component\Filesystem\Filesystem;

/**
 * Manages global services of EasyEngine.
 *
 * ## EXAMPLES
 *
 *     # Restarts global nginx proxy service
 *     $ ee service restart nginx-proxy
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
	 * : Name of service.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable global service
	 *     $ ee service enable nginx-proxy
	 *
	 */
	public function enable( $args, $assoc_args ) {
		$service   = $this->filter_service( $args );
		$container = "ee-$service";

		if ( EE_PROXY_TYPE === $container ) {
			\EE\Service\Utils\nginx_proxy_check();
		} else {
			\EE\Service\Utils\init_global_container( $service );
		}

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
	 * : Name of service.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable global service
	 *     $ ee service disable nginx-proxy
	 *
	 */
	public function disable( $args, $assoc_args ) {
		$service = $this->filter_service( $args );
		EE::exec( "docker-compose stop $service", true, true );
	}

	/**
	 * Restarts global services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service.
	 *
	 * ## EXAMPLES
	 *
	 *     # Restart global service
	 *     $ ee service restart nginx-proxy
	 *
	 */
	public function restart( $args, $assoc_args ) {
		$service = $this->filter_service( $args );
		EE::exec( "docker-compose restart $service", true, true );
	}

	/**
	 * Reloads global service without restarting services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service.
	 *
	 * ## EXAMPLES
	 *
	 *     # Reload global service
	 *     $ ee service reload nginx-proxy
	 *
	 */
	public function reload( $args, $assoc_args ) {
		$service = $this->filter_service( $args );
		$command = $this->service_reload_command( $service );
		if ( $command ) {
			EE::exec( "docker-compose exec $service $command", true, true );
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
