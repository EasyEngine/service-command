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
