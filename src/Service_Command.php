<?php

use EE\Utils;

/**
 * Manages global containers of EasyEngine.
 *
 * ## EXAMPLES
 *
 *     # Restarts global nginx proxy containers
 *     $ ee service restart nginx-proxy
 *
 * @package ee-cli
 */
class Service_Command extends EE_Command {

	/**
	 * @var array Array of containers defined in global docker-compose.yml
	 */
	private $whitelisted_containers = [
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
	 * Starts global containers.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of container.
	 */
	public function start( $args, $assoc_args ) {
		$container = $this->filter_container( $args );

		EE::exec( "docker-compose start $container", true, true );
	}

	/**
	 * Returns valid container name from arguments.
	 */
	private function filter_container( $args ) {
		$containers = array_intersect( $this->whitelisted_containers, $args );

		if ( empty( $containers ) ) {
			EE::error( "Unable to find global EasyEngine container $args[0]" );
		}

		return $containers[0];
	}

	/**
	 * Stops global containers.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of container.
	 */
	public function stop( $args, $assoc_args ) {
		$container = $this->filter_container( $args );
		EE::exec( "docker-compose stop $container", true, true );
	}

	/**
	 * Restarts global containers.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of container.
	 */
	public function restart( $args, $assoc_args ) {
		$container = $this->filter_container( $args );
		EE::exec( "docker-compose restart $container", true, true );
	}

	/**
	 * Reloads global service without restarting containers.
	 *
	 * ## OPTIONS
	 *
	 * <service-name>
	 * : Name of container.
	 */
	public function reload( $args, $assoc_args ) {
		$container = $this->filter_container( $args );
		$command   = $this->container_reload_command( $container );
		EE::exec( "docker-compose exec $container $command", true, true );
	}

	/**
	 * Returns reload command of a container.
	 * This is necessary since command to reload each service can be different.
	 *
	 * @param $container name of conntainer
	 *
	 * @return mixed
	 */
	private function container_reload_command( string $container ) {
		$command_map = [
			'nginx-proxy' => "sh -c 'nginx -t && service nginx reload'",
		];

		return $command_map[ $container ];
	}
}
