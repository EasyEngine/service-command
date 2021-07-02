<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;
use function EE\Service\Utils\generate_global_docker_compose_yml;

class RemoveSysctl extends Base {

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
			EE::debug( 'Skipping remove-sysctl migration as it is not needed.' );

			return;
		}

		chdir( EE_SERVICE_DIR );

		$whitelisted_services = [
			'nginx-proxy',
			'db',
			'redis',
			'newrelic-daemon',
		];

		$running_services = [];
		$count            = 0;
		foreach ( $whitelisted_services as $service ) {
			$running_services[ $count ]['name']  = $service;
			$launch                              = EE::launch( 'docker-compose ps -q global-' . $service );
			$running_services[ $count ]['state'] = $launch->stdout;
			$count++;
		}

		\EE\Service\Utils\generate_global_docker_compose_yml( $this->fs );

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
