<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;

class GlobalCron extends Base {

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
			EE::debug( 'Skipping global-cron migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting global-cron' );
		self::$rsp = new EE\RevertableStepProcessor();

		$global_compose_file_path        = EE_ROOT_DIR . '/services/docker-compose.yml';
		$global_compose_file_backup_path = EE_BACKUP_DIR . '/services/docker-compose.yml.bak';

		$old_cron_file_path        = EE_ROOT_DIR . '/services/cron/config.ini';
		$old_cron_file_backup_path = EE_BACKUP_DIR . '/services/cron/config.ini.bak';

		$old_containers = [ 'ee-cron-scheduler' ];

		$running_containers = [];
		foreach ( $old_containers as $container ) {
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
			'EE\Migration\GlobalCron::restore_yml_file',
			[ $global_compose_file_path, $global_compose_file_backup_path ],
			[ $global_compose_file_backup_path, $global_compose_file_path, $running_containers ]
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
		 * Remove global service ee-container.
		 */
		self::$rsp->add_step(
			'remove-global-ee-containers',
			'EE\Migration\GlobalCron::remove_old_cron_containers',
			null,
			[ $running_containers ],
			null
		);

		/**
		 * Backup old docker-compose file.
		 */
		self::$rsp->add_step(
			'backup-old-cron-config-file',
			'EE\Migration\SiteContainers::backup_restore',
			'EE\Migration\GlobalCron::restore_yml_file',
			[ $old_cron_file_path, $old_cron_file_backup_path ],
			[ $old_cron_file_backup_path, $old_cron_file_path, $running_containers ]
		);

		/**
		 * Start global container.
		 */
		self::$rsp->add_step(
			'start-renamed-containers',
			'EE\Migration\GlobalCron::start_global_service_container',
			'EE\Migration\GlobalCron::stop_default_containers',
			null,
			null
		);

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable run global-cron migrations.' );
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
	 * @param $source      string path of source file.
	 * @param $destination string path of destination.
	 * @param $containers  array of running containers.
	 *
	 * @throws \Exception
	 */
	public static function restore_yml_file( $source, $destination, $containers ) {
		EE\Migration\SiteContainers::backup_restore( $source, $destination );
		chdir( EE_SERVICE_DIR );

		if ( empty( $containers ) ) {
			return;
		}

		$services = '';
		foreach ( $containers as $container ) {
			$services .= ltrim( $container, 'ee-' ) . ' ';
		}

		if ( ! EE::exec( sprintf( 'docker-compose up -d %s', $services ) ) ) {
			throw new \Exception( 'Unable to start ee-containers' );
		}
	}

	/**
	 * Remove running global ee-containers.
	 *
	 * @param $containers array of running global containers.
	 *
	 * @throws \Exception
	 */
	public static function remove_old_cron_containers( $containers ) {
		$removable_containers = implode( ' ', $containers );
		if ( ! empty( trim( $removable_containers ) ) && ! EE::exec( "docker rm -f $removable_containers" ) ) {
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

		if ( ! EE::exec( 'docker-compose stop global-cron' ) ) {
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
	public static function start_global_service_container() {

		\EE\Cron\Utils\update_cron_config();
		GlobalContainers::global_service_up( 'global-cron' );
	}

}
