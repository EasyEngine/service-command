<?php

namespace EE\Service\Utils;

use EE;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots up the container if it is stopped or not running.
 * @throws EE\ExitException
 */
function nginx_proxy_check() {
	$proxy_type = EE_PROXY_TYPE;

	if ( 'running' !== EE::docker()::container_status( $proxy_type ) ) {

		$port_80_status  = EE\Site\Utils\get_curl_info( 'localhost', 80, true );
		$port_443_status = EE\Site\Utils\get_curl_info( 'localhost', 443, true );

		// if any/both the port/s is/are occupied.
		if ( ! ( $port_80_status && $port_443_status ) ) {
			EE::error( 'Cannot create/start proxy container. Please make sure port 80 and 443 are free.' );
		} else {

			$fs = new Filesystem();

			if ( ! $fs->exists( EE_ROOT_DIR . '/docker-compose.yml' ) ) {
				self::generate_global_docker_compose_yml( $fs );
			}

			$EE_ROOT_DIR = EE_ROOT_DIR;
			if ( ! EE::docker()::docker_network_exists( 'ee-global-network' ) ) {
				if ( ! EE::docker()::create_network( 'ee-global-network' ) ) {
					EE::error( 'Unable to create network ee-global-network' );
				}
			}
			if ( EE::docker()::docker_compose_up( EE_ROOT_DIR, [ 'nginx-proxy' ] ) ) {
				$fs->dumpFile( "$EE_ROOT_DIR/services/nginx-proxy/conf.d/custom.conf", file_get_contents( EE_ROOT . '/templates/custom.conf.mustache' ) );
				EE::success( "$proxy_type container is up." );
			} else {
				EE::error( "There was some error in starting $proxy_type container. Please check logs." );
			}
		}
	}
}

/**
 * Generates global docker-compose.yml at EE_ROOT_DIR
 *
 * @param Filesystem $fs Filesystem object to write file
 */
function generate_global_docker_compose_yml( Filesystem $fs ) {
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
					EE_ROOT_DIR . '/services/nginx-proxy/certs:/etc/nginx/certs',
					EE_ROOT_DIR . '/services/nginx-proxy/dhparam:/etc/nginx/dhparam',
					EE_ROOT_DIR . '/services/nginx-proxy/conf.d:/etc/nginx/conf.d',
					EE_ROOT_DIR . '/services/nginx-proxy/htpasswd:/etc/nginx/htpasswd',
					EE_ROOT_DIR . '/services/nginx-proxy/vhost.d:/etc/nginx/vhost.d',
					EE_ROOT_DIR . '/services/nginx-proxy/html:/usr/share/nginx/html',
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
					'ES_JAVA_OPTS=-Xms2G -Xmx4G',
				],
				'ulimits'        => [
					'memlock' => [
						'soft=-1',
						'hard=-1',
					],
				],
				'volumes'        => [
					EE_ROOT_DIR . 'services/elasticsearch:/usr/share/elasticsearch/data',
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
		],

	];

	$contents = EE\Utils\mustache_render( SERVICE_TEMPLATE_ROOT . '/global_docker_compose.yml.mustache', $data );
	$fs->dumpFile( EE_ROOT_DIR . '/docker-compose.yml', $contents );
}
