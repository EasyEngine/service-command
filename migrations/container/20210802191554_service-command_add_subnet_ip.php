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

		$backend_containers  = EE::launch( 'docker network inspect -f \'{{ range $key, $value := .Containers }}{{ printf "%s\n" $value.Name}}{{ end }}\' ' . GLOBAL_BACKEND_NETWORK )->stdout;
		$frontend_containers = EE::launch( 'docker network inspect -f \'{{ range $key, $value := .Containers }}{{ printf "%s\n" $value.Name}}{{ end }}\' ' . GLOBAL_FRONTEND_NETWORK )->stdout;

		$backend_containers  = explode( "\n", $backend_containers );
		$frontend_containers = explode( "\n", $frontend_containers );

		EE::log( 'Disconnecting containers from global backend and frontend networks for network update.' );

		foreach( $backend_containers as $container ) {
			EE::launch( 'docker network disconnect ' . GLOBAL_BACKEND_NETWORK . ' ' . $container );
		}
		foreach( $frontend_containers as $container ) {
			EE::launch( 'docker network disconnect ' . GLOBAL_FRONTEND_NETWORK . ' ' . $container );
		}

		EE::launch( 'docker network rm ' . GLOBAL_FRONTEND_NETWORK );
		EE::launch( 'docker network rm ' . GLOBAL_BACKEND_NETWORK );

		$service_command = new \Service_Command();
		$service_command->refresh( [], [] );

		EE::log( 'Reconnecting containers from global backend and frontend networks for network update.' );
		foreach( $backend_containers as $container ) {
			EE::launch( 'docker network connect ' . GLOBAL_BACKEND_NETWORK . ' ' . $container );
		}
		foreach( $frontend_containers as $container ) {
			EE::launch( 'docker network connect ' . GLOBAL_FRONTEND_NETWORK . ' ' . $container );
		}

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
