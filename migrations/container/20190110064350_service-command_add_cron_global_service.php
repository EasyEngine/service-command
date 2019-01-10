<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use http\Exception;

class AddCronGlobalService extends Base {

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
			EE::debug( 'Skipping add-cron-global-service migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting add-cron-global-service' );
		self::$rsp = new EE\RevertableStepProcessor();

		$global_compose_file_path        = EE_ROOT_DIR . '/services/docker-compose.yml';
		$global_compose_file_backup_path = EE_BACKUP_DIR . '/services/docker-compose.yml.backup';

		$old_container = 'running' === \EE_DOCKER::container_status( 'ee-cron-scheduler' );

		/**
		 * Backup old docker-compose file.
		 */
		self::$rsp->add_step(
			'backup-global-docker-compose-file',
			'EE\Migration\SiteContainers::backup_restore',
			'EE\Migration\SiteContainers::backup_restore',
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

		if ( $old_container ) {
			/**
			 * Start global-cron service container.
			 */
			self::$rsp->add_step(
				'enable-global-cron-service',
				'EE\Migration\GlobalContainers::global_service_up',
				null,
				[ EE_CRON_SERVICE ],
				[]
			);

			/**
			 * Remove ee-cron-scheduler container.
			 */
			self::$rsp->add_step(
				'remove-ee-cron-scheduler-container',
				'EE\Migration\AddCronGlobalService::remove_old_cron_container',
				null,
				[],
				[]
			);
		}

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable run add-cron-global-service migrations.' );
		}
	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}

	public static function remove_old_cron_container() {

		if ( ! EE::exec( 'docker rm -f ee-cron-scheduler' ) ) {
			throw new \Exception( 'Unable to remove ee-cron-scheduler container' );
		}
	}
}
