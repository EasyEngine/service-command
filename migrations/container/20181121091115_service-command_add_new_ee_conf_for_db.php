<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Migration\SiteContainers;
use EE\RevertableStepProcessor;
use EE\Model\Site;

class AddNewEeConfForDb extends Base {

	private $sites;
	/** @var RevertableStepProcessor $rsp Keeps track of migration state. Reverts on error */
	private static $rsp;

	public function __construct() {

		parent::__construct();
		$this->sites = Site::all();
		if ( $this->is_first_execution || ! $this->sites ) {
			$this->skip_this_migration = true;
		} else {
			$this->skip_this_migration = true;
			foreach ( $this->sites as $site ) {
				if ( ! empty( $site->db_host ) && 'global-db' === $site->db_host ) {
					$this->skip_this_migration = false;
					break;
				}
			}
		}
	}

	/**
	 * Execute nginx config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping binlog-update update migration as it is not needed.' );

			return;
		}
		self::$rsp = new RevertableStepProcessor();

		EE::debug( 'Starting binlog-update' );

		$ee_conf_path   = EE_SERVICE_DIR . '/mariadb/conf/conf.d/ee.cnf';
		$backup_ee_conf = EE_BACKUP_DIR . '/mariadb/conf/conf.d/ee.cnf';

		$this->fs->mkdir( dirname( $backup_ee_conf ) );
		$download_url = 'https://raw.githubusercontent.com/EasyEngine/dockerfiles/v4.0.0/mariadb/ee.cnf';
		$headers      = [];
		$options      = [
			'timeout'  => 600,  // 10 minutes ought to be enough for everybody.
			'filename' => $backup_ee_conf,
		];
		\EE\Utils\http_request( 'GET', $download_url, null, $headers, $options );

		self::$rsp->add_step(
			'add-new-ee-conf-to-global-db',
			'EE\Migration\SiteContainers::backup_restore',
			null,
			[ $backup_ee_conf, $ee_conf_path ],
			null
		);

		// Not restarting global-db as it will happen so in container migrations.

		if ( ! self::$rsp->execute() ) {
			throw new \Exception( 'Unable to run binlog-update upadte migrations.' );
		}

	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
