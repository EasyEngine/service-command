<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;

class Rc1DbFix extends Base {

	private $sites;

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

		if ( 'running' === \EE_DOCKER::container_status( GLOBAL_DB_CONTAINER ) ) {
			if ( EE::exec( 'docker exec -it ' . GLOBAL_DB_CONTAINER . " bash -c 'mysql -uroot -p\$MYSQL_ROOT_PASSWORD -e\"exit\"'" ) ) {
				$this->skip_this_migration = true;
			}
		}

		if ( ! $this->fs->exists( EE_SERVICE_DIR . '/docker-compose.yml' ) ) {
			$this->skip_this_migration = true;
		}

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping rc1-gloabl-db-fix update migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting rc1-gloabl-db-fix' );

		$launch   = EE::launch( sprintf( 'cat %s | grep MYSQL_ROOT_PASSWORD | cut -d"=" -f2', EE_SERVICE_DIR . '/docker-compose.yml' ) );
		$password = trim( $launch->stdout );

		chdir( EE_SERVICE_DIR );

		EE::exec( 'docker-compose rm --stop --force global-db' );
		EE::exec( 'docker-compose run -d --name=ee-global-db global-db --skip-grant-tables' );
		$health_script  = 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e"exit"';
		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';
		file_put_contents( $db_script_path, $health_script );
		$mysql_unhealthy = true;
		EE::exec( sprintf( 'docker cp %s ee-global-db:/db_exec', $db_script_path ) );
		$count = 0;
		while ( $mysql_unhealthy ) {
			$mysql_unhealthy = ! EE::exec( 'docker exec ee-global-db sh db_exec' );
			if ( $count ++ > 60 ) {
				break;
			}
			sleep( 1 );
		}

		$reset_script   = "FLUSH PRIVILEGES;\nALTER USER 'root'@'localhost' IDENTIFIED BY '$password';";
		$db_script_path = \EE\Utils\get_temp_dir() . 'db_exec';
		file_put_contents( $db_script_path, $reset_script );
		EE::exec( sprintf( 'docker cp %s ee-global-db:/db_exec', $db_script_path ) );
		EE::exec( 'docker exec ee-global-db bash -c "mysql < db_exec"' );
		EE::exec( 'docker rm -f ee-global-db' );
		EE::exec( 'docker-compose up -d global-db' );
	}

	/**
	 * Get the original container up.
	 *
	 * @throws EE\ExitException
	 */
	public function down() {
		chdir( EE_SERVICE_DIR );
		EE::exec( 'docker rm -f ee-global-db' );
		EE::exec( 'docker-compose up -d global-db' );
	}
}
