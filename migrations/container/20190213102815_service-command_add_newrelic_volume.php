<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;

class AddNewrelicVolume extends Base {

	/** @var RevertableStepProcessor $rsp Keeps track of migration state. Reverts on error */
	private static $rsp;

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution || IS_DARWIN ) {
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
			EE::debug( 'Skipping add-newrelic-volume migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting add-newrelic-volume' );
		EE::exec( 'docker volume create \
		--label "org.label-schema.vendor=EasyEngine" \
		--label "io.easyengine.site=global-newrelic-daemon" \
		global-newrelic-daemon_newrelic_sock' );

	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
