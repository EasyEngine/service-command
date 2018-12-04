<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use EE\Model\Option;

class SaveGLobalDbPass extends Base {

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Execute nginx config updates.
	 *
	 * @throws EE\ExitException
	 */
	public function up() {

		if ( $this->fs->exists( EE_SERVICE_DIR . '/docker-compose.yml' ) ) {
			$this->skip_this_migration = false;
		}

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping save-global-db-pass migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting save-global-db-pass' );

		$launch   = EE::launch( sprintf( 'cat %s | grep MYSQL_ROOT_PASSWORD | cut -d"=" -f2', EE_SERVICE_DIR . '/docker-compose.yml' ) );
		$password = trim( $launch->stdout );

		Option::set( GLOBAL_DB, $password );
	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
