<?php

use EE\Utils;

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
	 */
	public function start( $args, $assoc_args ) {
		$service = $this->filter_service( $args );

		EE::exec( "docker-compose start $service", true, true );
	}

	/**
	 * Returns valid service name from arguments.
	 */
	private function filter_service( $args ) {
		$services = array_intersect( $this->whitelisted_services, $args );

		if ( empty( $services ) ) {
			EE::error( "Unable to find global EasyEngine service $args[0]" );
		}

		return $services[0];
	}

	/**
	 * Stops global services.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of service.
	 */
	public function stop( $args, $assoc_args ) {
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
