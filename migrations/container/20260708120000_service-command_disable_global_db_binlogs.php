<?php

namespace EE\Migration;

use EE;
use EE\Migration\Base;

class DisableGlobalDbBinlogs extends Base {

	public function __construct() {

		parent::__construct();
		if ( $this->is_first_execution || ! $this->fs->exists( EE_SERVICE_DIR . '/docker-compose.yml' ) ) {
			$this->skip_this_migration = true;
		}
	}

	/**
	 * Disable global DB binary logs and clean up existing binlog files.
	 *
	 * @throws EE\ExitException
	 * @throws \Exception
	 */
	public function up() {

		if ( $this->skip_this_migration ) {
			EE::debug( 'Skipping disable-global-db-binlogs migration as it is not needed.' );

			return;
		}

		EE::debug( 'Starting disable-global-db-binlogs migration.' );

		$global_db_status   = \EE_DOCKER::container_status( GLOBAL_DB_CONTAINER );
		$global_db_running  = 'running' === $global_db_status;
		$use_default_binlog = $this->uses_default_binlog_path( $global_db_running );
		$restart_global_db  = $global_db_running || $this->global_db_restart_pending();

		if ( $global_db_running && $use_default_binlog ) {
			$this->reset_master();
			$this->mark_global_db_restart_pending();
		}

		if ( $use_default_binlog ) {
			$this->disable_binlog_config();

			if ( false !== $global_db_status ) {
				$this->copy_config_to_container();
			}

		}

		if ( $restart_global_db ) {
			$this->restart_global_db();
			$this->clear_global_db_restart_pending();
		}

		if ( $use_default_binlog || $this->has_default_binlog_files() ) {
			$this->delete_binlog_files();
		}
	}

	public function down() {
	}

	private function reset_master() {

		$command = sprintf(
			'docker exec %s sh -c %s',
			GLOBAL_DB_CONTAINER,
			escapeshellarg( 'mysql -uroot -p"$MYSQL_ROOT_PASSWORD" -e "RESET MASTER"' )
		);

		if ( ! EE::exec( $command ) ) {
			EE::warning( 'Unable to reset MariaDB binary logs before disabling them. Continuing with config update and file cleanup.' );
		}
	}

	private function disable_binlog_config() {

		$ee_conf_path = EE_SERVICE_DIR . '/mariadb/conf/conf.d/ee.cnf';
		$contents     = "[mysqld]\n";

		$this->fs->mkdir( dirname( $ee_conf_path ) );

		if ( $this->fs->exists( $ee_conf_path ) ) {
			$contents = file_get_contents( $ee_conf_path );
		}
		if ( false === $contents ) {
			$contents = "[mysqld]\n";
		}

		if ( 1 === preg_match( '/^\s*log[_-]bin\s*=\s*\/var\/log\/mysql\/mariadb-bin\s*$/mi', $contents ) ) {
			$contents = preg_replace(
				'/^\s*log[_-]bin\s*=\s*\/var\/log\/mysql\/mariadb-bin\s*\R?/mi',
				'',
				$contents
			);
			$contents = preg_replace(
				'/^\s*log[_-]bin[_-]index\s*=\s*\/var\/log\/mysql\/mariadb-bin\.index\s*\R?/mi',
				'',
				$contents
			);
			$contents = preg_replace( '/^\s*binlog[_-]format\s*=\s*statement\s*\R?/mi', '', $contents );
			$contents = preg_replace( '/^\s*#\s*not fab for performance, but safer\R?/mi', '', $contents );
			$contents = preg_replace( '/^\s*#?\s*sync[_-]binlog\s*=\s*1\s*\R?/mi', '', $contents );
			$contents = preg_replace( '/^\s*expire[_-]logs[_-]days\s*=\s*10\s*\R?/mi', '', $contents );
			$contents = preg_replace( '/^\s*max[_-]binlog[_-]size\s*=\s*100M\s*\R?/mi', '', $contents );
		}

		if ( false === strpos( $contents, '[mysqld]' ) ) {
			$contents = "[mysqld]\n" . $contents;
		}

		$this->fs->dumpFile( $ee_conf_path, rtrim( $contents ) . "\n" );
	}

	private function uses_default_binlog_path( $global_db_running ) {

		if ( $global_db_running ) {
			$command = sprintf(
				'docker exec %s sh -c %s',
				GLOBAL_DB_CONTAINER,
				escapeshellarg( 'mysql -N -B -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SHOW VARIABLES LIKE \'log_bin_basename\'"' )
			);
			$launch  = EE::launch( $command );

			if ( 0 === $launch->return_code ) {
				$binlog_variable = preg_split( '/\s+/', trim( $launch->stdout ) );
				$binlog_basename = end( $binlog_variable );

				return '/var/log/mysql/mariadb-bin' === $binlog_basename;
			}
		}

		$ee_conf_path = EE_SERVICE_DIR . '/mariadb/conf/conf.d/ee.cnf';

		if ( ! $this->fs->exists( $ee_conf_path ) ) {
			// No persisted override means the existing container/image likely has EE's stock binlog config.
			return true;
		}

		$contents = file_get_contents( $ee_conf_path );

		return false !== $contents && 1 === preg_match( '/^\s*log[_-]bin\s*=\s*\/var\/log\/mysql\/mariadb-bin\s*$/mi', $contents );
	}

	private function has_default_binlog_files() {

		return ! empty( $this->get_default_binlog_files() );
	}

	private function global_db_restart_pending() {

		return $this->fs->exists( $this->get_restart_marker_path() );
	}

	private function mark_global_db_restart_pending() {

		$restart_marker = $this->get_restart_marker_path();

		$this->fs->mkdir( dirname( $restart_marker ) );
		$this->fs->dumpFile( $restart_marker, '1' );
	}

	private function clear_global_db_restart_pending() {

		$restart_marker = $this->get_restart_marker_path();

		if ( $this->fs->exists( $restart_marker ) ) {
			$this->fs->remove( $restart_marker );
		}
	}

	private function get_restart_marker_path() {

		return EE_BACKUP_DIR . '/mariadb/disable-binlogs-global-db-restart-pending';
	}

	private function restart_global_db() {

		chdir( EE_SERVICE_DIR );
		$global_db_status = \EE_DOCKER::container_status( GLOBAL_DB_CONTAINER );
		$command          = 'running' === $global_db_status ? ' restart ' : ' up -d ';

		if ( ! EE::exec( \EE_DOCKER::docker_compose_with_custom() . $command . GLOBAL_DB ) ) {
			throw new \Exception( 'Unable to restart global-db after disabling MariaDB binary logs.' );
		}
	}

	private function copy_config_to_container() {

		$ee_conf_path = EE_SERVICE_DIR . '/mariadb/conf/conf.d/ee.cnf';
		$command      = sprintf(
			'docker cp %s %s:/etc/mysql/conf.d/ee.cnf',
			escapeshellarg( $ee_conf_path ),
			GLOBAL_DB_CONTAINER
		);

		if ( EE::exec( $command ) ) {
			return;
		}

		if ( IS_DARWIN ) {
			throw new \Exception( 'Unable to copy updated MariaDB config into global-db container.' );
		} else {
			EE::warning( 'Unable to copy updated MariaDB config into global-db container. Continuing with host config update.' );
		}
	}

	private function delete_binlog_files() {

		$binlog_files = $this->get_default_binlog_files();

		if ( empty( $binlog_files ) ) {
			return;
		}

		$this->fs->remove( array_filter( $binlog_files, 'is_file' ) );
	}

	private function get_default_binlog_files() {

		$log_dir = EE_SERVICE_DIR . '/mariadb/logs';

		if ( ! $this->fs->exists( $log_dir ) ) {
			return [];
		}

		$binlog_files = glob( $log_dir . '/mariadb-bin.*' );

		if ( false === $binlog_files ) {
			return [];
		}

		return array_filter( $binlog_files, function ( $path ) {
			return 1 === preg_match( '/^mariadb-bin\.(index|[0-9]+)$/', basename( $path ) );
		} );
	}
}
