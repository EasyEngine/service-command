<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;

class ChangeGlobalServiceContainerNames extends Base {

	/** @var RevertableStepProcessor $rsp Keeps track of migration state. Reverts on error */
	private static $rsp;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute global service container name update.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping change-global-service-container-name migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting change-global-service-container-name' );
		self::$rsp = new EE\RevertableStepProcessor();

		/**
		 * Sites wp-config changes for global-cache.
		 */
		$cache_sites = EE\Model\Site::where( 'cache_host', 'global-redis' );
		foreach ( $cache_sites as $site ) {

			self::$rsp->add_step(
				sprintf( 'update-cache-host-%s', $site->site_url ),
				'EE\Migration\ChangeGlobalServiceContainerNames::update_cache_host',
				null,
				[ $site ],
				null
			);

		}

		$global_compose_file_path        = EE_ROOT_DIR . '/services/docker-compose.yml';
		$global_compose_file_backup_path = EE_BACKUP_DIR . '/services/docker-compose.yml.backup';

		$old_containers = ['ee-global-nginx-proxy', 'ee-global-redis', 'ee-global-db'];

		$running_containers = [];
		foreach (  $old_containers as $container) {
			if ( 'running' === \EE_DOCKER::container_status( $container ) ) {
				$running_containers[] = $container;
			}
		}

		/**
		 * Backup old docker-compose file.
		 */
		self::$rsp->add_step(
			'backup-global-docker-compose-file',
			'EE\Migration\SiteContainers::backup_restore',
			'EE\Migration\ChangeGlobalServiceContainerNames::restore_yml_file',
			[ $global_compose_file_path, $global_compose_file_backup_path ],
			[ $global_compose_file_backup_path, $global_compose_file_path ]
		);

		/**
		 * Generate new docker-compose file.
		 */
		self::$rsp->add_step(
			'generate-global-docker-compose-file',
			'EE\Service\Utils\generate_global_docker_compose_yml',
			null,
			[ new \Symfony\Component\Filesystem\Filesystem() ],
			null
		);

		/**
		 * Start support containers.
		 */
		self::$rsp->add_step(
			'create-support-global-containers',
			'EE\Migration\GlobalContainers::enable_support_containers',
			'EE\Migration\GlobalContainers::disable_support_containers',
			null,
			null
		);

		/**
		 * Remove global service ee-container.
		 */
		self::$rsp->add_step(
			'remove-global-ee-containers',
			'EE\Migration\ChangeGlobalServiceContainerNames::remove_global_ee_containers',
			null,
			[ $running_containers ],
			null
		);

		/**
		 * Start nginx-proxy container.
		 */
		self::$rsp->add_step(
			'start-renamed-containers',
			'EE\Migration\ChangeGlobalServiceContainerNames::start_global_service_containers',
			'EE\Migration\ChangeGlobalServiceContainerNames::stop_default_containers',
			[ $running_containers ],
			null
		);

		/**
		 * Start other service containers.
		 */
		self::$rsp->add_step(
			'remove-support-containers',
			'EE\Migration\GlobalContainers::disable_support_containers',
			null,
			null,
			null
		);

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable run change-global-service-container-name migrations.' );
		}

	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}

	/**
	 * Restore docker-compose.yml and start old ee-containers.
	 *
	 * @param $source string path of source file.
	 * @param $destination string path of destination.
	 */
	public static function restore_yml_file( $source, $destination ) {
		EE\Migration\SiteContainers::backup_restore( $source, $destination );

		chdir( EE_SERVICE_DIR );

		EE::exec( 'docker-compose up -d' );
	}

	/**
	 * Remove running global ee-containers.
	 *
	 * @param $containers array of running global containers.
	 *
	 * @throws \Exception
	 */
	public static function remove_global_ee_containers( $containers ) {
		$removable_containers = implode( ' ', $containers );
		if ( ! EE::exec( "docker rm -f $removable_containers" ) ) {
			throw new \Exception( 'Unable to remove global service containers' );
		}
	}

	/**
	 * Stop default global containers.
	 *
	 * @throws \Exception
	 */
	public static function stop_default_containers() {

		chdir( EE_SERVICE_DIR );

		if ( ! EE::exec( 'docker-compose stop && docker-compose rm -f' ) ) {
			throw new \Exception( 'Unable to remove default global service containers' );
		}
	}

	/**
	 * Start global services with renamed containers names.
	 *
	 * @param $containers array of running global containers.
	 *
	 * @throws \Exception
	 */
	public static function start_global_service_containers( $containers ) {

		foreach ( $containers as $container ) {
			$service = ltrim( $container, 'ee-' );
			GlobalContainers::global_service_up( $service );
		}

	}

	/**
	 * Update redis cache host name.
	 *
	 * @param $site_info EE\Model\Site site information.
	 *
	 * @throws \Exception
	 */
	public static function update_cache_host( $site_info ) {
		$update_hostname_constant = "docker-compose exec --user='www-data' php wp config set RT_WP_NGINX_HELPER_REDIS_HOSTNAME global-redis --add=true --type=constant";
		$redis_plugin_constant    = 'docker-compose exec --user=\'www-data\' php wp config set --type=variable redis_server "array(\'host\'=> \'global-redis\',\'port\'=> 6379,)" --raw';

		if( ! chdir( $site_info->site_fs_path ) ) {
			throw new \Exception( sprintf( '%s path not exists', $site_info->site_fs_path ) );
		}

		if ( ! EE::exec( $update_hostname_constant ) ) {
			throw new \Exception( sprintf( 'Unable to update cache host of %s', $site_info->site_url ) );
		}

		if ( ! EE::exec( $redis_plugin_constant ) ) {
			throw new \Exception( sprintf( 'Unable to update plugin constant %s', $site_info->site_url ) );
		}
		EE::log( sprintf( '%s Updated cache-host successfully', $site_info->site_url ) );
	}

}
