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

	/**
	 * Starts global reverse proxy container.
	 */
	public function start( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker start ee-nginx-proxy" );
	}

	/**
	 * Stops global reverse proxy container.
	 */
	public function stop( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker stop ee-nginx-proxy" );
	}

	/**
	 * Restarts global reverse proxy container.
	 */
	public function restart( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker restart ee-nginx-proxy" );
	}

	/**
	 * Reloads global reverse proxy service without .
	 */
	public function reload( $cmd, $descriptors = null ) {
		\EE\Utils\default_launch( "docker exec ee-nginx-proxy sh -c 'nginx -t && service nginx reload'" );
	}

}
