<?php

namespace EE\Service\Utils;

use EE;
use EE\Model\Option;
use Symfony\Component\Filesystem\Filesystem;

/**
 * Boots up the container if it is stopped or not running.
 * @throws EE\ExitException
 */
function nginx_proxy_check() {

	$proxy_type = EE_PROXY_TYPE;

	$config_80_port  = \EE\Utils\get_config_value( 'proxy_80_port', '80' );
	$config_443_port = \EE\Utils\get_config_value( 'proxy_443_port', '443' );

	if ( 'running' === \EE_DOCKER::container_status( $proxy_type ) ) {
		$launch_80_test  = EE::launch( 'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "80/tcp") 0).HostPort }}\' ee-global-nginx-proxy' );
		$launch_443_test = EE::launch( 'docker inspect --format \'{{ (index (index .NetworkSettings.Ports "443/tcp") 0).HostPort }}\' ee-global-nginx-proxy' );

		if ( $config_80_port !== trim( $launch_80_test->stdout ) || $config_443_port !== trim( $launch_443_test->stdout ) ) {
			EE::error( "Ports of current running nginx-proxy and ports specified in EasyEngine config file don't match." );
		}

		return false;
	}

	/**
	 * Checking ports.
	 */
	$port_80_status  = \EE\Utils\get_curl_info( 'localhost', $config_80_port, true );
	$port_443_status = \EE\Utils\get_curl_info( 'localhost', $config_443_port, true );

	// if any/both the port/s is/are occupied.
	if ( ! ( $port_80_status && $port_443_status ) ) {
		EE::error( "Cannot create/start proxy container. Please make sure port $config_80_port and $config_443_port are free." );
	} else {

		$fs = new Filesystem();

		if ( ! $fs->exists( EE_SERVICE_DIR . '/docker-compose.yml' ) ) {
			generate_global_docker_compose_yml( $fs );
		}

		boot_global_networks();
		if ( ! \EE_DOCKER::docker_compose_up( EE_SERVICE_DIR . '', [ 'global-nginx-proxy' ] ) ) {
			EE::error( "There was some error in starting $proxy_type container. Please check logs." );
		}
		set_nginx_proxy_version_conf();
	}

	return true;
}

/**
 * Function to start global conainer if it is not running.
 *
 * @param string $container Global container to be brought up.
 */
function init_global_container( $service, $container = '' ) {

	if ( empty( $container ) ) {
		$container = 'ee-' . $service;
	}

	boot_global_networks();

	$fs = new Filesystem();

	if ( ! $fs->exists( EE_SERVICE_DIR . '/docker-compose.yml' ) ) {
		generate_global_docker_compose_yml( $fs );
	}

	if ( 'running' !== \EE_DOCKER::container_status( $container ) ) {

		chdir( EE_SERVICE_DIR . '' );
		$db_conf_file = EE_SERVICE_DIR . '/mariadb/conf/my.cnf';
		if ( IS_DARWIN && GLOBAL_DB === $service && ! $fs->exists( $db_conf_file ) ) {
			$fs->copy( SERVICE_TEMPLATE_ROOT . '/my.cnf.mustache', $db_conf_file );
		}
		\EE_DOCKER::boot_container( $container, 'docker-compose up -d ' . $service );
		return true;
	} else {
		return false;
	}
}

/**
 * Start required global networks if they don't exist.
 */
function boot_global_networks() {
	if ( ! \EE_DOCKER::docker_network_exists( GLOBAL_BACKEND_NETWORK ) &&
	     ! \EE_DOCKER::create_network( GLOBAL_BACKEND_NETWORK ) ) {
		EE::error( 'Unable to create network ' . GLOBAL_BACKEND_NETWORK );
	}
	if ( ! \EE_DOCKER::docker_network_exists( GLOBAL_FRONTEND_NETWORK ) &&
	     ! \EE_DOCKER::create_network( GLOBAL_FRONTEND_NETWORK ) ) {
		EE::error( 'Unable to create network ' . GLOBAL_FRONTEND_NETWORK );
	}
}

/**
 * Generates global docker-compose.yml at EE_ROOT_DIR
 *
 * @param Filesystem $fs Filesystem object to write file.
 */
function generate_global_docker_compose_yml( Filesystem $fs ) {

	$img_versions    = EE\Utils\get_image_versions();
	$config_80_port  = \EE\Utils\get_config_value( 'proxy_80_port', 80 );
	$config_443_port = \EE\Utils\get_config_value( 'proxy_443_port', 443 );

	$db_password = Option::get( GLOBAL_DB );
	$password    = empty( $db_password ) ? \EE\Utils\random_password() : $db_password;

	$volumes_nginx_proxy = [
		[
			'name'            => 'certs',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/certs',
			'container_path'  => '/etc/nginx/certs',
		],
		[
			'name'            => 'dhparam',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/dhparam',
			'container_path'  => '/etc/nginx/dhparam',
		],
		[
			'name'            => 'confd',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/conf.d',
			'container_path'  => '/etc/nginx/conf.d',
		],
		[
			'name'            => 'htpasswd',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/htpasswd',
			'container_path'  => '/etc/nginx/htpasswd',
		],
		[
			'name'            => 'vhostd',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/vhost.d',
			'container_path'  => '/etc/nginx/vhost.d',
		],
		[
			'name'            => 'html',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/html',
			'container_path'  => '/usr/share/nginx/html',
		],
		[
			'name'            => 'nginx_proxy_logs',
			'path_to_symlink' => EE_SERVICE_DIR . '/nginx-proxy/logs',
			'container_path'  => '/var/log/nginx',
		],
		[
			'name'            => '/var/run/docker.sock',
			'path_to_symlink' => '/var/run/docker.sock',
			'container_path'  => '/tmp/docker.sock:ro',
			'skip_volume'     => true,
		],
	];

	$volumes_db    = [
		[
			'name'            => 'db_data',
			'path_to_symlink' => EE_SERVICE_DIR . '/mariadb/data',
			'container_path'  => '/var/lib/mysql',
		],
		[
			'name'            => 'db_conf',
			'path_to_symlink' => EE_SERVICE_DIR . '/mariadb/conf',
			'container_path'  => '/etc/mysql',
			'skip_darwin'     => true,
		],
		[
			'name'            => 'db_conf',
			'path_to_symlink' => EE_SERVICE_DIR . '/mariadb/conf/my.cnf',
			'container_path'  => '/etc/mysql/my.cnf',
			'skip_linux'      => true,
			'skip_volume'     => true,
		],
		[
			'name'            => 'db_logs',
			'path_to_symlink' => EE_SERVICE_DIR . '/mariadb/logs',
			'container_path'  => '/var/log/mysql',
		],
	];
	$volumes_redis = [
		[
			'name'            => 'redis_data',
			'path_to_symlink' => EE_SERVICE_DIR . '/redis/data',
			'container_path'  => '/data',
			'skip_darwin'     => true,
		],
		[
			'name'            => 'redis_conf',
			'path_to_symlink' => EE_SERVICE_DIR . '/redis/conf',
			'container_path'  => '/usr/local/etc/redis',
			'skip_darwin'     => true,
		],
		[
			'name'            => 'redis_logs',
			'path_to_symlink' => EE_SERVICE_DIR . '/redis/logs',
			'container_path'  => '/var/log/redis',
		],
	];

	if ( ! IS_DARWIN ) {

		$data['created_volumes'] = [
			'external_vols' => [
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'certs' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'dhparam' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'confd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'htpasswd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'vhostd' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'html' ],
				[ 'prefix' => 'global-nginx-proxy', 'ext_vol_name' => 'nginx_proxy_logs' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'db_data' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'db_conf' ],
				[ 'prefix' => GLOBAL_DB, 'ext_vol_name' => 'db_logs' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'redis_data' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'redis_conf' ],
				[ 'prefix' => GLOBAL_REDIS, 'ext_vol_name' => 'redis_logs' ],
			],
		];

		if ( empty( \EE_DOCKER::get_volumes_by_label( 'global-nginx-proxy' ) ) ) {
			\EE_DOCKER::create_volumes( 'global-nginx-proxy', $volumes_nginx_proxy, false );
		}

		if ( empty( \EE_DOCKER::get_volumes_by_label( GLOBAL_DB ) ) ) {
			\EE_DOCKER::create_volumes( GLOBAL_DB, $volumes_db, false );
		}

		if ( empty( \EE_DOCKER::get_volumes_by_label( GLOBAL_REDIS ) ) ) {
			\EE_DOCKER::create_volumes( GLOBAL_REDIS, $volumes_redis, false );
		}
	}

	$data['services'] = [
		[
			'name'           => 'global-nginx-proxy',
			'container_name' => EE_PROXY_TYPE,
			'image'          => 'easyengine/nginx-proxy:' . $img_versions['easyengine/nginx-proxy'],
			'restart'        => 'always',
			'ports'          => [
				"$config_80_port:80",
				"$config_443_port:443",
			],
			'environment'    => [
				'LOCAL_USER_ID=' . posix_geteuid(),
				'LOCAL_GROUP_ID=' . posix_getegid(),
			],
			'volumes'        => \EE_DOCKER::get_mounting_volume_array( $volumes_nginx_proxy ),
			'networks'       => [
				'global-frontend-network',
			],
		],
		[
			'name'           => GLOBAL_DB,
			'container_name' => GLOBAL_DB_CONTAINER,
			'image'          => 'easyengine/mariadb:' . $img_versions['easyengine/mariadb'],
			'restart'        => 'always',
			'environment'    => [
				'MYSQL_ROOT_PASSWORD=' . $password,
			],
			'volumes'        => \EE_DOCKER::get_mounting_volume_array( $volumes_db ),
			'networks'       => [
				'global-backend-network',
			],
		],
		[
			'name'           => GLOBAL_REDIS,
			'container_name' => GLOBAL_REDIS_CONTAINER,
			'image'          => 'easyengine/redis:' . $img_versions['easyengine/redis'],
			'restart'        => 'always',
			'command'        => '["redis-server", "/usr/local/etc/redis/redis.conf"]',
			'volumes'        => \EE_DOCKER::get_mounting_volume_array( $volumes_redis ),
			'networks'       => [
				'global-backend-network',
			],
		],
	];

	Option::set( GLOBAL_DB, $password );
	$contents = EE\Utils\mustache_render( SERVICE_TEMPLATE_ROOT . '/global_docker_compose.yml.mustache', $data );
	$fs->dumpFile( EE_SERVICE_DIR . '/docker-compose.yml', $contents );
}

/**
 * Function to set nginx-proxy version.conf file.
 */
function set_nginx_proxy_version_conf() {

	if ( 'running' !== \EE_DOCKER::container_status( EE_PROXY_TYPE ) ) {
		return;
	}
	chdir( EE_SERVICE_DIR );
	$version_line    = sprintf( 'add_header X-Powered-By \"EasyEngine v%s\";', EE_VERSION );
	$version_file    = '/version.conf';
	$version_success = EE::exec( sprintf( 'docker-compose exec global-nginx-proxy bash -c \'echo "%s" > %s\'', $version_line, $version_file ), false, false, [
		$version_file,
		$version_line,
	] );
	if ( $version_success ) {
		EE::exec( 'docker-compose exec global-nginx-proxy bash -c "nginx -t && nginx -s reload"' );
	}
}
