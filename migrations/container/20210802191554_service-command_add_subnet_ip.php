<?php

namespace EE\Migration;

use EE;
use EE\Model\Site;
use function EE\Service\Utils\generate_global_docker_compose_yml;
use function EE\Site\Utils\get_site_info;

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

		$sites = Site::all();
		$enabled_sites = [];

		// Disable all site
		foreach ( $sites as $site ) {
			$site_type = $site->site_type === 'html' ? new EE\Site\Type\HTML() :
				( $site->site_type === 'php' ? new EE\Site\Type\PHP() :
					( $site->site_type === 'wp' ? new EE\Site\Type\WordPress() : EE::error('Unknown site type') ) );

			if ( $site->site_enabled ) {
				$enabled_sites[] = [
					'type' => $site_type,
					'url' => $site->site_url
				];

				$site_type->disable( [ $site->site_url ], [] );
			}
		}

		EE\Service\Utils\ensure_global_network_initialized();

		// Backup names of all the running service containers
		$running_services = [];
		$count            = 0;
		$whitelisted_services = [ 'nginx-proxy', 'db', 'redis' ];
		chdir( EE_SERVICE_DIR );

		foreach ( $whitelisted_services as $service ) {
			$running_services[ $count ]['name']  = $service;
			$launch                              = EE::launch( 'docker-compose ps -q global-' . $service );
			$running_services[ $count ]['state'] = $launch->stdout;
			$count++;
		}

		// Remove the service containers and (especially) networks
		EE::launch( \EE_DOCKER::docker_compose_with_custom() . ' down' );
		EE::launch( 'docker network rm ' . GLOBAL_FRONTEND_NETWORK );
		EE::launch( 'docker network rm ' . GLOBAL_BACKEND_NETWORK );

		\EE\Service\Utils\generate_global_docker_compose_yml( new \Symfony\Component\Filesystem\Filesystem() );

		// Start all the previously running service containers
		foreach ( $running_services as $service ) {
			if ( ! empty( $service['state'] ) ) {
				EE::exec( \EE_DOCKER::docker_compose_with_custom() . " up -d global-${service['name']}", true, true );
			}
		}

		// re-enable sites
		foreach ( $enabled_sites as $site ) {
			$site['type']->enable( [ $site['url'] ], [] );
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
