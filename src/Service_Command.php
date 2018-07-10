<?php

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

use EE\Utils;

class Service_Command extends EE_Command {

	private $whitelisted_containers = [
		'ee-nginx-proxy'
	];

	/**
	 * Starts global containers.
	 *
	 * ## OPTIONS
	 *
	 * <container-name>
	 * : Name of container.
	 */
	public function start( $args, $assoc_args ) {
		$container = $this->filter_container( $args );
		\EE\Utils\default_launch( "docker start $container" );
	}

	/**
	 * Stops global containers.
	 *
	 * ## OPTIONS
	 *
	 * <container-name>
	 * : Name of container.
	 */
	public function stop( $args, $assoc_args ) {
		$container = $this->filter_container( $args );
		\EE\Utils\default_launch( "docker stop $container" );
	}

	/**
	 * Restarts global containers.
	 *
	 * ## OPTIONS
	 *
	 * <container-name>
	 * : Name of container.
	 */
	public function restart( $args, $assoc_args ) {
		$container = $this->filter_container( $args );
		\EE\Utils\default_launch( "docker restart $container" );
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
		$command = $this->container_reload_command( $container );
		\EE\Utils\default_launch( "docker exec $container $command" );
	}

	/**
	 * Returns valid container name from arguments.
	 */
	private function filter_container( $args ) {
		$containers = array_intersect( $this->whitelisted_containers, $args );

		if( empty( $containers ) ) {
			EE::error( "Unable to find global EasyEngine container $args[0]" );
		}

		return $containers[0];
	}

	private function container_reload_command( $container ) {
		$command_map = [
			'ee-nginx-proxy' => "sh -c 'nginx -t && service nginx reload'"
		];
		return $command_map[ $container ];
	}
}
