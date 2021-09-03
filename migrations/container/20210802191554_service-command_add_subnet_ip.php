<?php

namespace EE\Migration;

use EE;
use function EE\Service\Utils\generate_global_docker_compose_yml;

class AddSubnetIp extends Base {

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
			EE::debug( 'Skipping add-subnet-ip migration as it is not needed.' );

			return;
		}

		$service_command = new \Service_Command();
		$service_command->refresh( [], [] );

		EE::debug( 'Starting add-subnet-ip' );
	}

	/**
	 * No need for down.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
	}
}
