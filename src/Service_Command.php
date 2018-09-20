<?php

use \Symfony\Component\Filesystem\Filesystem;
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
		'mariadb',
		'elasticsearch',
		'memcached',
		'redis',
	];

	/**
	 * Service_Command constructor.
	 *
	 * Changes directory to EE_CONF_ROOT since that's where all docker-compose commands will be executed
	 */
	public function __construct() {
		chdir( EE_CONF_ROOT );
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
		$container = "ee-global-$service";

		if ( 'ee-global-nginx-proxy' === $container ) {
			self::nginx_proxy_check();
		} else {
			$status = EE::docker()::container_status( $container );
			if ( 'running' !== $status ) {

				$fs = new Filesystem();

				if ( ! $fs->exists( EE_CONF_ROOT . '/docker-compose.yml' ) ) {
					self::generate_global_docker_compose_yml( $fs );
				}
				chdir( EE_CONF_ROOT );
				EE::docker()::boot_container( $container, "docker-compose up -d $service" );

			}
		}

	}

	/**
	 * Boots up the container if it is stopped or not running.
	 * @throws \EE\ExitException
	 */
	public static function nginx_proxy_check() {
		$proxy_type = EE_PROXY_TYPE;

		if ( 'running' !== EE::docker()::container_status( $proxy_type ) ) {

			$port_80_status  = EE\Site\Utils\get_curl_info( 'localhost', 80, true );
			$port_443_status = EE\Site\Utils\get_curl_info( 'localhost', 443, true );

			// if any/both the port/s is/are occupied.
			if ( ! ( $port_80_status && $port_443_status ) ) {
				EE::error( 'Cannot create/start proxy container. Please make sure port 80 and 443 are free.' );
			} else {

				$fs = new Filesystem();

				if ( ! $fs->exists( EE_CONF_ROOT . '/docker-compose.yml' ) ) {
					self::generate_global_docker_compose_yml( $fs );
				}

				$EE_CONF_ROOT = EE_CONF_ROOT;
				if ( ! EE::docker()::docker_network_exists( 'ee-global-network' ) ) {
					if ( ! EE::docker()::create_network( 'ee-global-network' ) ) {
						EE::error( 'Unable to create network ee-global-network' );
					}
				}
				if ( EE::docker()::docker_compose_up( EE_CONF_ROOT, [ 'nginx-proxy' ] ) ) {
					$fs->dumpFile( "$EE_CONF_ROOT/nginx/conf.d/custom.conf", file_get_contents( EE_ROOT . '/templates/custom.conf.mustache' ) );
					EE::success( "$proxy_type container is up." );
				} else {
					EE::error( "There was some error in starting $proxy_type container. Please check logs." );
				}
			}
		}
	}

	/**
	 * Generates global docker-compose.yml at EE_CONF_ROOT
	 *
	 * @param Filesystem $fs Filesystem object to write file
	 */
	public static function generate_global_docker_compose_yml( Filesystem $fs ) {
		$img_versions = EE\Utils\get_image_versions();

		$data = [
			'services' => [
				[
					'name'           => 'nginx-proxy',
					'container_name' => EE_PROXY_TYPE,
					'image'          => 'easyengine/nginx-proxy:' . $img_versions['easyengine/nginx-proxy'],
					'restart'        => 'always',
					'ports'          => [
						'80:80',
						'443:443',
					],
					'environment'    => [
						'LOCAL_USER_ID=' . posix_geteuid(),
						'LOCAL_GROUP_ID=' . posix_getegid(),
					],
					'volumes'        => [
						EE_CONF_ROOT . '/nginx/certs:/etc/nginx/certs',
						EE_CONF_ROOT . '/nginx/dhparam:/etc/nginx/dhparam',
						EE_CONF_ROOT . '/nginx/conf.d:/etc/nginx/conf.d',
						EE_CONF_ROOT . '/nginx/htpasswd:/etc/nginx/htpasswd',
						EE_CONF_ROOT . '/nginx/vhost.d:/etc/nginx/vhost.d',
						EE_CONF_ROOT . '/nginx/html:/usr/share/nginx/html',
						'/var/run/docker.sock:/tmp/docker.sock:ro',
					],
					'networks'       => [
						'global-network',
					],

				],
				[
					'name'           => 'elasticsearch',
					'container_name' => 'ee-global-elasticsearch',
					'image'          => 'docker.elastic.co/elasticsearch/elasticsearch:6.4.0',
					'environment'    => [
						'bootstrap.memory_lock=true',
						'ES_JAVA_OPTS=-Xms2G -Xmx4G'
					],
					'ulimits'        => [
						'memlock' => [
							'soft=-1',
							'hard=-1'
						],
					],
					'volumes'        => [
						EE_CONF_ROOT . 'services/elasticsearch:/usr/share/elasticsearch/data',
					],

					'networks' => [
						'global-network',
					],
				],
				[
					'name'           => GLOBAL_DB,
					'container_name' => GLOBAL_DB_CONTAINER,
					'image'          => 'easyengine/mariadb:' . $img_versions['easyengine/mariadb'],
					'restart'        => 'always',
					'environment'    => [
						'MYSQL_ROOT_PASSWORD=' . \EE\Utils\random_password(),
					],
					'volumes'        => [ './app/db:/var/lib/mysql' ],
					'networks'       => [
						'global-network',
					],
				],
				[
					'name'           => 'memcached',
					'container_name' => 'ee-global-memcached',
					'image'          => 'easyengine/nginx-proxy:v4.0.0-beta.6',

				],
				[
					'name'           => 'redis',
					'container_name' => 'ee-global-redis',
					'image'          => 'easyengine/nginx-proxy:v4.0.0-beta.6',
				],
			],

		];

		$contents = EE\Utils\mustache_render( SERVICE_TEMPLATE_ROOT . '/global_docker_compose.yml.mustache', $data );
		$fs->dumpFile( EE_CONF_ROOT . '/docker-compose.yml', $contents );
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
		return $services[0];
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
		$command   = $this->service_reload_command( $service );
		EE::exec( "docker-compose exec $service $command", true, true );
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
			'nginx-proxy' => "sh -c 'nginx -t && service nginx reload'",
		];

		return $command_map[ $service ];
	}
}
