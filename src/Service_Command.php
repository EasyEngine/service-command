<?php

/**
 * Executes wp-cli command on a site.
 *
 * ## EXAMPLES
 *
 *     # Create simple WordPress site
 *     $ ee wp test.local plugin list
 *
 * @package ee-cli
 */

use EE\Utils;

class Service_Command extends EE_Command {

	private $container_name = 'ee-nginx-proxy';
	/**
	 * Starts global reverse proxy container.
	 */
	public function start( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker start $this->container_name" );
	}

	/**
	 * Stops global reverse proxy container.
	 */
	public function stop( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker stop $this->container_name" );
	}

	/**
	 * Restarts global reverse proxy container.
	 */
	public function restart( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker restart $this->container_name" );
	}

	/**
	 * Reloads global reverse proxy service without .
	 */
	public function reload( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker exec $this->container_name sh -c 'nginx -t && service nginx reload'" );
	}

}
