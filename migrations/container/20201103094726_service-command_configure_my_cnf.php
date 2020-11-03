<?php

namespace EE\Migration;

use EE;

class ConfigureMyCNF extends Base {

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
			EE::debug( 'Skipping configure-my-cnf migration as it is not needed.' );
			return;
		}

		EE::debug( 'Starting configure-my-cnf' );

		$my_cnf = EE_SERVICE_DIR . '/mariadb/conf/my.cnf';

		if ( is_link( $my_cnf ) ) {
			unlink( readlink( $my_cnf ) );
			unlink( $my_cnf );

			EE::exec('rm -f ' . EE_SERVICE_DIR . '/mariadb/conf/mariadb.conf.d/*.cnf' );

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
