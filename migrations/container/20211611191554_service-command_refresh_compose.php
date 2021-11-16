<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;

class RefreshCompose extends Base {

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
			EE::debug( 'Skipping refresh-compose migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting refresh-compose' );

		EE\Service\Utils\ensure_global_network_initialized();

		// Backup names of all the running service containers
		$running_services     = [];
		$count                = 0;
		$whitelisted_services = [ 'nginx-proxy', 'db', 'redis' ];
		chdir( EE_SERVICE_DIR );

		foreach ( $whitelisted_services as $service ) {
			$running_services[ $count ]['name']  = $service;
			$launch                              = EE::launch( 'docker-compose ps -q global-' . $service );
			$running_services[ $count ]['state'] = $launch->stdout;
			$count++;
		}

		\EE\Service\Utils\generate_global_docker_compose_yml( new \Symfony\Component\Filesystem\Filesystem() );

		// Start all the previously running service containers
		foreach ( $running_services as $service ) {
			if ( ! empty( $service['state'] ) ) {
				EE::exec( \EE_DOCKER::docker_compose_with_custom() . " up -d global-${service['name']}", true, true );
			}
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
