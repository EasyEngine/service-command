<?php
/**
 * Behat tests.
 *
 * @package ee-cli
 */

/* Start: Loading required files to enable EE::launch() in tests. */
define( 'EE_ROOT', __DIR__ . '/../..' );

include_once( EE_ROOT . '/php/class-ee.php' );
include_once( EE_ROOT . '/php/EE/Runner.php' );
include_once( EE_ROOT . '/php/utils.php' );

define( 'EE', true );
define( 'EE_VERSION', trim( file_get_contents( EE_ROOT . '/VERSION' ) ) );

$root_alias = '/opt';
if ( 'Darwin' === php_uname( 's' ) ) {
	$sys_name   = posix_getpwuid(posix_geteuid())['name'];
	if ( ! empty( $sys_name ) ) {
		$root_alias = '/Users/' . $sys_name;
	}
}
// Composer's autoload (php/init-ee.php) may already define it.
if ( ! defined( 'EE_ROOT_DIR' ) ) {
	define( 'EE_ROOT_DIR', $root_alias . '/easyengine' );
}

require_once EE_ROOT . '/php/bootstrap.php';

if ( ! class_exists( 'EE\Runner' ) ) {
	require_once EE_ROOT . '/php/EE/Runner.php';
}

if ( ! class_exists( 'EE\Configurator' ) ) {
	require_once EE_ROOT . '/php/EE/Configurator.php';
}

$logger_dir = EE_ROOT . '/php/EE/Loggers';
$iterator   = new \DirectoryIterator( $logger_dir );

// Make sure the base class is declared first.
include_once "$logger_dir/Base.php";

foreach ( $iterator as $filename ) {
	if ( '.php' !== pathinfo( $filename, PATHINFO_EXTENSION ) ) {
		continue;
	}

	include_once "$logger_dir/$filename";
}
$runner = \EE::get_runner();
$runner->init_logger();
$runner->init_config();
/* End. Loading required files to enable EE::launch() in tests. */

use Behat\Behat\Context\Context;
use Behat\Behat\Hook\Scope\AfterFeatureScope;
use Behat\Behat\Hook\Scope\AfterScenarioScope;
use Behat\Behat\Hook\Scope\BeforeScenarioScope;

use Behat\Gherkin\Node\PyStringNode,
	Behat\Gherkin\Node\TableNode;

define( 'EE_SITE_ROOT', EE_ROOT_DIR . '/sites' );

class FeatureContext implements Context
{
	public $command;
	public $commands;
	public $webroot_path;
	public $ee_path;

	/**
	 * Only sites with this prefix are created by the suite and deleted after each feature.
	 *
	 * @var string
	 */
	public static $site_prefix = 'bh-service-command-';

	/** File backups and the pending restore state; outside /tmp and kept until restored. */
	const STATE_DIR = '/var/tmp/bh-service-command-state';

	/**
	 * Initializes context.
	 */
	public function __construct()
	{
		$this->commands = [];
		$this->ee_path = getcwd();
		$this->loadPendingState();
	}

	/**
	 * @Given ee phar is generated
	 */
	public function eePharIsPresent()
	{
		// Checks if phar already exists, replaces it
		if(file_exists('ee-old.phar')) {
			// Below exec call is required as currenly `ee cli update` is ran with root
			// which updates ee.phar with root privileges.
			exec("sudo rm ee.phar");
			copy('ee-old.phar','ee.phar');
			return 0;
		}
		exec("php -dphar.readonly=0 utils/make-phar.php ee.phar", $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception("Unable to generate phar" . $return_status);
		}

		// Cache generaed phar as it is expensive to generate one
		copy('ee.phar','ee-old.phar');
	}

	/**
	 * @Given :command is installed
	 */
	public function isInstalled($command)
	{
		exec("type " . $command, $output, $return_status);
		if (0 !== $return_status) {
			throw new Exception($command . " is not installed! Exit code is:" . $return_status);
		}
	}

	/**
	 * Anchored with a branch-reset group: the upstream pattern matched any step containing a double-quoted string.
	 *
	 * @When /^I run (?|'(.*)'|"(.*)")$/
	 */
	public function iRun($command)
	{
		$this->commands[] = EE::launch($command, false, true);
	}

	/**
	 * @Then return value should be 0
	 */
	public function returnValueShouldBe0()
	{
		if ( 0 !== $this->commands[0]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then return value of command :index should be 0
	 */
	public function returnValueOfCommandShouldBe0($index)
	{
		if ( 0 !== $this->commands[ $index - 1 ]->return_code ) {
			throw new Exception("Actual return code is not zero: \n" . $this->command);
		}
	}

	/**
	 * @Then After delay of :time seconds
	 */
	public function afterDelayOfSeconds( $time )
	{
		sleep( $time );
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return exactly$/
	 */
	public function stdoutShouldReturnExactly($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return exactly$/
	 */
	public function stdoutOfCommandShouldReturnExactly($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		$command_output = str_replace(["\033[1;31m","\033[0m"],'',$command_output);

		if ( $expected_output->getRaw() !== trim($command_output)) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then /^(STDOUT|STDERR) should return something like$/
	 */
	public function stdoutShouldReturnSomethingLike($output_stream, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[0]->stdout : $this->commands[0]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}


	/**
	 * @Then /^(STDOUT|STDERR) of command (\d+) should return something like$/
	 */
	public function stdoutOfCommandShouldReturnSomethingLike($output_stream, $index, PyStringNode $expected_output)
	{
		$command_output = $output_stream === "STDOUT" ? $this->commands[ $index - 1 ]->stdout : $this->commands[ $index - 1 ]->stderr;

		if (strpos($command_output, $expected_output->getRaw()) === false) {
			throw new Exception("Actual output is:\n" . $command_output);
		}
	}

	/**
	 * @Then The site :site should be :type multisite
	 */
	public function theSiteShouldBeMultisite( $site, $type )
	{
		$result = EE::launch( "cd " . EE_SITE_ROOT . "/$site && docker-compose exec --user='www-data' php sh -c 'wp config get SUBDOMAIN_INSTALL'", false, true );

		if( $result->stderr ) {
			throw new Exception("Found error while executing command: $result->stderr");
		}

		if($type === 'subdomain' && trim($result->stdout) !== '1') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be 1. Got: $result->stdout");
		}
		else if($type === 'subdir' && trim($result->stdout) !== '') {
			throw new Exception("Expecting SUBDOMAIN_INSTALL to be empty. Got: $result->stdout");
		}
		else if($type !== 'subdomain' && $type !== 'subdir') {
			throw new Exception("Found unknown site type: $type");
		}
	}

	/**
	 * @Then The :site db entry should be removed
	 */
	public function theDbEntryShouldBeRemoved($site)
	{
		$out = shell_exec("bin/ee site list --format=text");
		if (strpos($out, $site) !== false) {
			throw new Exception("$site db entry not been removed!");
		}

	}

	/**
	 * @Then The :site webroot should be removed
	 */
	public function theWebrootShouldBeRemoved($site)
	{
		if ( file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been removed!");
		}
	}

	/**
	 * @Then Following containers of site :site should be removed:
	 */
	public function followingContainersOfSiteShouldBeRemoved($site, TableNode $table)
	{
		$containers = $table->getHash();
		$site_name = implode(explode('.', $site));

		foreach ($containers as $container) {

			$sevice = $container['container'];
			$container_name = $site_name . '_' . $sevice . '_1';

			exec("docker inspect -f '{{.State.Running}}' $container_name > /dev/null 2>&1", $exec_out, $return);
			if (!$return) {
				throw new Exception("$container_name has not been removed!");
			}
		}
	}

	/**
	 * @Then The site :site should have webroot
	 */
	public function theSiteShouldHaveWebroot($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site ) ) {
			throw new Exception("Webroot has not been created!");
		}
	}

	/**
	 * @Then The site :site should have WordPress
	 */
	public function theSiteShouldHaveWordpress($site)
	{
		if ( ! file_exists( EE_SITE_ROOT . '/' . $site . '/app/wp-config.php' ) ) {
			throw new Exception("WordPress data not found!");
		}
	}

	/**
	 * @Then Request on :site should contain following headers:
	 */
	public function requestOnShouldContainFollowingHeaders($site, TableNode $table)
	{
		$url = 'http://' . $site;

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		self::closeCurl( $ch );

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with header :header should contain following headers:
	 */
	public function requestOnWithHeaderShouldContainFollowingHeaders($host, $header, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_HTTPHEADER, [ $header ]);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);

		self::closeCurl( $ch );

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * @Then Request on :host with resolve option :resolve should contain following headers:
	 */
	public function requestOnWithResolveOptionShouldContainFollowingHeaders($host, $resolve, TableNode $table)
	{

		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $host);
		curl_setopt($ch, CURLOPT_HEADER, true);
		curl_setopt($ch, CURLOPT_NOBODY, true);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_RESOLVE, [ $resolve ]);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_VERBOSE, true);
		$headers = curl_exec($ch);
		self::closeCurl( $ch );

		$rows = $table->getHash();

		foreach ($rows as $row) {
			if (strpos($headers, $row['header']) === false) {
				throw new Exception("Unable to find " . $row['header'] . "\nActual output is : " . $headers);
			}
		}
	}

	/**
	 * Restores files and global services touched by the scenario, in that order (services may need the restored compose file).
	 *
	 * @AfterScenario
	 */
	public function cleanupScenario(AfterScenarioScope $scope)
	{
		chdir($this->ee_path);
		$this->commands = [];
		foreach ( $this->listeners as $socket ) {
			fclose( $socket );
		}
		$this->listeners = [];
		$this->restorePendingState();
	}

	/**
	 * Repairs what a killed or failed earlier run left behind before anything else runs.
	 *
	 * @BeforeSuite
	 */
	public static function recoverLeftoverState()
	{
		$context = new self();
		$context->restorePendingState();
	}

	/**
	 * @Then There should be :expected_running_containers containers with labels
	 */
	public function thereShouldBeContainersWithLabel($expected_running_containers, PyStringNode $pyStringNode)
	{
		$labels = $pyStringNode->getStrings();
		$label_string = implode(' -f label=',$labels);

		$result = EE::launch( "docker ps -qf label=$label_string | wc -l", false, true );
		$running_containers = (int) trim($result->stdout);

		// Upstream compared with === (string vs int), so the check could never fail as intended.
		if ( (int) $expected_running_containers !== $running_containers ) {
			throw new Exception("Expected $expected_running_containers running containers. Found: $running_containers");
		}
	}

	/**
	 * Deletes only the sites created by this suite (prefix match), never every site.
	 *
	 * @AfterFeature
	 */
	public static function cleanup(AfterFeatureScope $scope)
	{
		$failed = [];
		foreach ( self::listSites() as $site ) {
			if ( 0 === strpos( $site, self::$site_prefix ) ) {
				$result = EE::launch( 'bin/ee site delete ' . escapeshellarg( $site ) . ' --yes', false, true );
				if ( 0 !== $result->return_code ) {
					$failed[] = "$site: " . trim( $result->stderr );
				}
			}
		}
		if ( $failed ) {
			throw new Exception( "Deleting the suite's sites failed:\n" . implode( "\n", $failed ) );
		}
	}

	/* ---------------------------------------------------------------------
	 * Shared generic steps (added by the service-command suite).
	 * Names are package-neutral so they can move to a common context later.
	 * ------------------------------------------------------------------- */

	/**
	 * Global service => container, as named in the global docker-compose.yml.
	 *
	 * @var array
	 */
	public static $global_services = [
		'nginx-proxy'     => 'services_global-nginx-proxy_1',
		'db'              => 'services_global-db_1',
		'redis'           => 'services_global-redis_1',
		'cron'            => 'services_global-cron_1',
		'newrelic-daemon' => 'services_global-newrelic-daemon_1',
	];

	/** @var array|null Container state per global service, taken before a @destructive scenario. */
	public $service_snapshot = null;

	/** @var array Original path => backup path (null when the file did not exist). */
	public $file_backups = [];

	/** @var array Files created by `a file :path with:` that did not exist before. */
	public $created_files = [];

	/** @var bool Whether the cleanup restored nginx-proxy files that a running proxy has to reload. */
	private $reload_proxy = false;

	/** @var array Saved container start times, by container name. */
	public $saved_start_times = [];

	/** @var array Saved command outputs, by command. */
	public $saved_outputs = [];

	/** @var array Saved file checksums, by path. */
	public $saved_checksums = [];

	/** @var resource[] Listening sockets opened by `a TCP listener is open on port :port`. */
	public $listeners = [];

	/**
	 * @return \EE\ProcessRun
	 */
	private function lastCommand() {
		if ( empty( $this->commands ) ) {
			throw new Exception( 'No command has been run in this scenario.' );
		}

		return end( $this->commands );
	}

	private function describeLastCommand() {
		$run = $this->lastCommand();

		return sprintf( "Command: %s\nReturn code: %d\nSTDOUT:\n%s\nSTDERR:\n%s", $run->command, $run->return_code, $run->stdout, $run->stderr );
	}

	private function lastOutput( $stream ) {
		$run = $this->lastCommand();
		$out = 'STDOUT' === $stream ? $run->stdout : $run->stderr;

		// Strip ANSI colour codes.
		return preg_replace( '/\e\[[0-9;]*m/', '', $out );
	}

	private static function listSites() {
		$result = EE::launch( 'bin/ee site list --format=text', false, true );
		// An empty install exits 1 with "No sites found!"; any other failure must not look like an empty list.
		if ( 0 !== $result->return_code && false === strpos( $result->stderr, 'No sites found!' ) ) {
			throw new Exception( "Unable to list sites:\n" . $result->stderr );
		}

		return array_filter( array_map( 'trim', explode( "\n", $result->stdout ) ) );
	}

	/**
	 * Returns the container's State.Status (running, exited, restarting, created, ...) or 'absent'.
	 */
	private static function containerStatus( $container ) {
		$result = EE::launch( "docker inspect -f '{{.State.Status}}' " . escapeshellarg( $container ), false, true );
		if ( 0 === $result->return_code ) {
			return trim( $result->stdout );
		}
		// Any other failure (e.g. the daemon is down) must not become an 'absent' restore baseline.
		if ( preg_match( '/No such (object|container)/i', $result->stderr ) ) {
			return 'absent';
		}
		throw new Exception( "Unable to inspect container $container:\n" . $result->stderr );
	}

	private static function containerStartedAt( $container ) {
		$result = EE::launch( "docker inspect -f '{{.State.StartedAt}}' " . escapeshellarg( $container ), false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Container $container does not exist:\n" . $result->stderr );
		}

		return trim( $result->stdout );
	}

	/**
	 * Same file list as EE_DOCKER::docker_compose_with_custom(), so recreated services keep user overrides.
	 */
	private static function compose( $args ) {
		$dir   = EE_ROOT_DIR . '/services';
		$files = [ 'docker-compose.yml' ];
		foreach ( array_merge( glob( "$dir/user-docker-compose/*.yml" ) ?: [], glob( "$dir/user-docker-compose/*.yaml" ) ?: [] ) as $file ) {
			$files[] = 'user-docker-compose/' . basename( $file );
		}
		if ( file_exists( "$dir/docker-compose-custom.yml" ) ) {
			$files[] = 'docker-compose-custom.yml';
		}
		$flags = '';
		foreach ( $files as $file ) {
			$flags .= ' -f ' . escapeshellarg( $file );
		}

		return EE::launch( 'cd ' . escapeshellarg( $dir ) . ' && docker-compose' . $flags . ' ' . $args, false, true );
	}

	/**
	 * Polls $check every second until it returns true or $seconds elapse.
	 */
	private function waitUntil( callable $check, $seconds, $failure_message ) {
		$deadline = time() + (int) $seconds;
		do {
			if ( $check() ) {
				return;
			}
			sleep( 1 );
		} while ( time() < $deadline );

		if ( $check() ) {
			return;
		}
		throw new Exception( is_callable( $failure_message ) ? $failure_message() : $failure_message );
	}

	private static function closeCurl( $ch ) {
		// curl_close() is a no-op since PHP 8.0 and deprecated in 8.5 (Behat turns the deprecation into a failure).
		if ( PHP_VERSION_ID < 80000 ) {
			curl_close( $ch );
		}
	}

	/**
	 * Masks secrets (the global DB root password in the global docker-compose.yml) in failure output.
	 */
	private static function redact( $text ) {
		return preg_replace( '/(MYSQL_ROOT_PASSWORD=)\S+/', '$1******', $text );
	}

	private static function httpRequest( $url, $host, &$headers = null ) {
		$ch = curl_init();
		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_HTTPHEADER, [ 'Host: ' . $host ] );
		curl_setopt( $ch, CURLOPT_HEADER, true );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYHOST, false );
		curl_setopt( $ch, CURLOPT_SSL_VERIFYPEER, false );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 5 );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 20 );
		$response = curl_exec( $ch );
		$code     = (int) curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$size     = (int) curl_getinfo( $ch, CURLINFO_HEADER_SIZE );
		$headers  = false === $response ? curl_error( $ch ) : substr( $response, 0, $size );
		self::closeCurl( $ch );

		return $code;
	}

	/**
	 * @Then the return code should be :code
	 */
	public function theReturnCodeShouldBe( $code ) {
		if ( (int) $code !== $this->lastCommand()->return_code ) {
			throw new Exception( "Expected return code $code.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then the return code should not be :code
	 */
	public function theReturnCodeShouldNotBe( $code ) {
		if ( (int) $code === $this->lastCommand()->return_code ) {
			throw new Exception( "Expected a return code other than $code.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDOUT should contain :text
	 */
	public function stdoutShouldContain( $text ) {
		if ( false === strpos( $this->lastOutput( 'STDOUT' ), $text ) ) {
			throw new Exception( "STDOUT does not contain '$text'.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDOUT should not contain :text
	 */
	public function stdoutShouldNotContain( $text ) {
		if ( false !== strpos( $this->lastOutput( 'STDOUT' ), $text ) ) {
			throw new Exception( "STDOUT unexpectedly contains '$text'.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDERR should contain :text
	 */
	public function stderrShouldContain( $text ) {
		if ( false === strpos( $this->lastOutput( 'STDERR' ), $text ) ) {
			throw new Exception( "STDERR does not contain '$text'.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDERR should not contain :text
	 */
	public function stderrShouldNotContain( $text ) {
		if ( false !== strpos( $this->lastOutput( 'STDERR' ), $text ) ) {
			throw new Exception( "STDERR unexpectedly contains '$text'.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDOUT should be empty
	 */
	public function stdoutShouldBeEmpty() {
		if ( '' !== trim( $this->lastOutput( 'STDOUT' ) ) ) {
			throw new Exception( "STDOUT is not empty.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then STDERR should be empty
	 */
	public function stderrShouldBeEmpty() {
		if ( '' !== trim( $this->lastOutput( 'STDERR' ) ) ) {
			throw new Exception( "STDERR is not empty.\n" . $this->describeLastCommand() );
		}
	}

	/**
	 * @Then the docker container :name should be running
	 */
	public function theDockerContainerShouldBeRunning( $name ) {
		$status = self::containerStatus( $name );
		if ( 'running' !== $status ) {
			throw new Exception( "Expected container $name to be running, its status is: $status" );
		}
	}

	/**
	 * Tolerates restart loops (e.g. global cron with no jobs on an older cron config).
	 *
	 * @Then the docker container :name should be running or restarting
	 */
	public function theDockerContainerShouldBeRunningOrRestarting( $name ) {
		$status = self::containerStatus( $name );
		if ( ! in_array( $status, [ 'running', 'restarting' ], true ) ) {
			throw new Exception( "Expected container $name to be running or restarting, its status is: $status" );
		}
	}

	/**
	 * @Then the docker container :name should not be running
	 */
	public function theDockerContainerShouldNotBeRunning( $name ) {
		$status = self::containerStatus( $name );
		if ( in_array( $status, [ 'running', 'restarting' ], true ) ) {
			throw new Exception( "Expected container $name not to be running, its status is: $status" );
		}
	}

	/**
	 * Exists but is not running (stopped, not removed).
	 *
	 * @Then the docker container :name should be stopped
	 */
	public function theDockerContainerShouldBeStopped( $name ) {
		$status = self::containerStatus( $name );
		if ( ! in_array( $status, [ 'exited', 'created' ], true ) ) {
			throw new Exception( "Expected container $name to be stopped (exited), its status is: $status" );
		}
	}

	/**
	 * @Then the docker container :name should exist
	 */
	public function theDockerContainerShouldExist( $name ) {
		if ( 'absent' === self::containerStatus( $name ) ) {
			throw new Exception( "Expected container $name to exist." );
		}
	}

	/**
	 * @Then the docker container :name should not exist
	 */
	public function theDockerContainerShouldNotExist( $name ) {
		$status = self::containerStatus( $name );
		if ( 'absent' !== $status ) {
			throw new Exception( "Expected container $name not to exist, its status is: $status" );
		}
	}

	/**
	 * @When I wait until the docker container :name is running up to :seconds seconds
	 */
	public function iWaitUntilTheDockerContainerIsRunning( $name, $seconds ) {
		$this->waitUntil(
			function () use ( $name ) {
				return 'running' === self::containerStatus( $name );
			},
			$seconds,
			function () use ( $name, $seconds ) {
				return "Container $name is not running after $seconds seconds, status: " . self::containerStatus( $name );
			}
		);
	}

	/**
	 * @Given I save the start time of the docker container :name
	 */
	public function iSaveTheStartTimeOfTheDockerContainer( $name ) {
		$this->saved_start_times[ $name ] = self::containerStartedAt( $name );
	}

	/**
	 * @Then the docker container :name should have been restarted
	 */
	public function theDockerContainerShouldHaveBeenRestarted( $name ) {
		if ( ! isset( $this->saved_start_times[ $name ] ) ) {
			throw new Exception( "No start time saved for $name." );
		}
		$now = self::containerStartedAt( $name );
		if ( $now === $this->saved_start_times[ $name ] ) {
			throw new Exception( "Container $name was not restarted (StartedAt still $now)." );
		}
	}

	/**
	 * @Then the docker container :name should not have been restarted
	 */
	public function theDockerContainerShouldNotHaveBeenRestarted( $name ) {
		if ( ! isset( $this->saved_start_times[ $name ] ) ) {
			throw new Exception( "No start time saved for $name." );
		}
		$now = self::containerStartedAt( $name );
		if ( $now !== $this->saved_start_times[ $name ] ) {
			throw new Exception( "Container $name was restarted (StartedAt {$this->saved_start_times[ $name ]} -> $now)." );
		}
	}

	/**
	 * @When I wait until the command :command succeeds up to :seconds seconds
	 */
	public function iWaitUntilTheCommandSucceeds( $command, $seconds ) {
		$last = null;
		$this->waitUntil(
			function () use ( $command, &$last ) {
				$last = EE::launch( $command, false, true );

				return 0 === $last->return_code;
			},
			$seconds,
			function () use ( $command, $seconds, &$last ) {
				return "Command did not succeed within $seconds seconds: $command\nReturn code: {$last->return_code}\nSTDOUT:\n{$last->stdout}\nSTDERR:\n{$last->stderr}";
			}
		);
	}

	/**
	 * @When I wait until the command :command outputs :text up to :seconds seconds
	 */
	public function iWaitUntilTheCommandOutputs( $command, $text, $seconds ) {
		$last = null;
		$this->waitUntil(
			function () use ( $command, $text, &$last ) {
				$last = EE::launch( $command, false, true );

				return false !== strpos( $last->stdout, $text );
			},
			$seconds,
			function () use ( $command, $text, $seconds, &$last ) {
				return "Command output did not contain '$text' within $seconds seconds: $command\nSTDOUT:\n{$last->stdout}\nSTDERR:\n{$last->stderr}";
			}
		);
	}

	/**
	 * @Then the file :path should exist
	 */
	public function theFileShouldExist( $path ) {
		if ( ! file_exists( $path ) ) {
			throw new Exception( "File $path does not exist." );
		}
	}

	/**
	 * @Then the file :path should not exist
	 */
	public function theFileShouldNotExist( $path ) {
		clearstatcache();
		if ( file_exists( $path ) ) {
			throw new Exception( "File $path exists." );
		}
	}

	/**
	 * @Then the file :path should contain :text
	 */
	public function theFileShouldContain( $path, $text ) {
		$this->theFileShouldExist( $path );
		$contents = file_get_contents( $path );
		if ( false === strpos( $contents, $text ) ) {
			throw new Exception( "File $path does not contain '$text'. Contents:\n" . self::redact( $contents ) );
		}
	}

	/**
	 * @Then the file :path should not contain :text
	 */
	public function theFileShouldNotContain( $path, $text ) {
		$this->theFileShouldExist( $path );
		$contents = file_get_contents( $path );
		if ( false !== strpos( $contents, $text ) ) {
			throw new Exception( "File $path unexpectedly contains '$text'. Contents:\n" . self::redact( $contents ) );
		}
	}

	/**
	 * Backups are restored automatically after the scenario.
	 *
	 * @Given I back up the file :path
	 */
	public function iBackUpTheFile( $path ) {
		if ( array_key_exists( $path, $this->file_backups ) ) {
			return;
		}
		if ( ! file_exists( $path ) ) {
			$this->file_backups[ $path ] = null;
			$this->saveState();

			return;
		}
		self::ensureStateDir();
		$backup = self::STATE_DIR . '/' . sha1( $path );
		// The backup keeps the original mode, which the restore puts back (the state dir is 0700).
		if ( ! copy( $path, $backup ) || ! chmod( $backup, fileperms( $path ) & 0777 ) ) {
			throw new Exception( "Unable to back up $path." );
		}
		$this->file_backups[ $path ] = $backup;
		$this->saveState();
	}

	/**
	 * @When I restore the file :path
	 */
	public function iRestoreTheFile( $path ) {
		if ( ! array_key_exists( $path, $this->file_backups ) ) {
			throw new Exception( "File $path has not been backed up." );
		}
		$backup = $this->file_backups[ $path ];
		// Journaled before the backup entry goes, so a run stopped before the reload still reloads the proxy.
		if ( ! $this->reload_proxy && 0 === strpos( $path, EE_ROOT_DIR . '/services/nginx-proxy/' ) ) {
			$this->reload_proxy = true;
			$this->saveState();
		}
		if ( null === $backup ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		} else {
			// A recreated file (e.g. a deleted private key) is never readable by others, even briefly.
			$umask  = umask( 0077 );
			$copied = copy( $backup, $path );
			umask( $umask );
			if ( ! $copied || ! chmod( $path, fileperms( $backup ) & 0777 ) ) {
				throw new Exception( "Unable to restore $path from $backup." );
			}
		}
		unset( $this->file_backups[ $path ] );
		// Journal first: a run killed in between replays the restore instead of missing the backup.
		$this->saveState();
		if ( null !== $backup && file_exists( $backup ) ) {
			unlink( $backup );
		}
	}

	private function requireBackup( $path ) {
		if ( ! array_key_exists( $path, $this->file_backups ) ) {
			throw new Exception( "Refusing to modify $path: back it up first with 'I back up the file'." );
		}
	}

	/**
	 * @When I append :text to the file :path
	 */
	public function iAppendToTheFile( $text, $path ) {
		$this->requireBackup( $path );
		if ( false === file_put_contents( $path, "\n" . $text . "\n", FILE_APPEND ) ) {
			throw new Exception( "Unable to append to $path." );
		}
	}

	/**
	 * @When I delete the file :path
	 */
	public function iDeleteTheFile( $path ) {
		$this->requireBackup( $path );
		if ( file_exists( $path ) && ! unlink( $path ) ) {
			throw new Exception( "Unable to delete $path." );
		}
	}

	/**
	 * Created files are removed after the scenario.
	 *
	 * @Given a file :path with:
	 */
	public function aFileWith( $path, PyStringNode $contents ) {
		// Only this suite's own leftover temp files (killed run) may be replaced, never through a symlink.
		if ( file_exists( $path ) || is_link( $path ) ) {
			clearstatcache();
			$uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : 0;
			if ( 0 !== strpos( $path, '/var/tmp/bh-service-command-' ) || is_link( $path ) || ! is_file( $path ) || fileowner( $path ) !== $uid ) {
				throw new Exception( "Refusing to overwrite existing file $path." );
			}
			unlink( $path );
		}
		$this->created_files[] = $path;
		$this->saveState();
		// Exclusive create: fails instead of following anything put at the path in shared /var/tmp.
		$fh = @fopen( $path, 'x' );
		if ( false === $fh || false === fwrite( $fh, $contents->getRaw() . "\n" ) || ! fclose( $fh ) ) {
			throw new Exception( "Unable to write $path." );
		}
	}

	/**
	 * @Given I save the checksum of the file :path
	 */
	public function iSaveTheChecksumOfTheFile( $path ) {
		$this->theFileShouldExist( $path );
		$this->saved_checksums[ $path ] = sha1_file( $path );
	}

	/**
	 * @Then the file :path should be unchanged
	 */
	public function theFileShouldBeUnchanged( $path ) {
		if ( ! isset( $this->saved_checksums[ $path ] ) ) {
			throw new Exception( "No checksum saved for $path." );
		}
		$this->theFileShouldExist( $path );
		if ( sha1_file( $path ) !== $this->saved_checksums[ $path ] ) {
			throw new Exception( "File $path has changed. Contents:\n" . self::redact( file_get_contents( $path ) ) );
		}
	}

	/**
	 * @Then the file :path should contain the value of the EasyEngine option :option
	 */
	public function theFileShouldContainTheValueOfTheEasyEngineOption( $path, $option ) {
		$pdo  = new PDO( 'sqlite:' . EE_ROOT_DIR . '/db/ee.sqlite' );
		$stmt = $pdo->prepare( 'SELECT value FROM options WHERE key = ?' );
		$stmt->execute( [ $option ] );
		$value = $stmt->fetchColumn();
		if ( false === $value || '' === $value ) {
			throw new Exception( "EasyEngine option '$option' is not set." );
		}
		$this->theFileShouldExist( $path );
		if ( false === strpos( file_get_contents( $path ), $value ) ) {
			// Don't print the value: options can hold secrets (e.g. the global DB root password).
			throw new Exception( "File $path does not contain the value of the EasyEngine option '$option'." );
		}
	}

	/**
	 * A code of 0 means the connection failed.
	 *
	 * @Then a GET request to :url with Host :host should return :code
	 */
	public function aGetRequestWithHostShouldReturn( $url, $host, $code ) {
		$actual = self::httpRequest( $url, $host, $headers );
		if ( (int) $code !== $actual ) {
			throw new Exception( "GET $url (Host: $host) returned $actual, expected $code.\n$headers" );
		}
	}

	/**
	 * @When I wait until a GET request to :url with Host :host returns :code up to :seconds seconds
	 */
	public function iWaitUntilAGetRequestWithHostReturns( $url, $host, $code, $seconds ) {
		$actual  = null;
		$headers = null;
		$this->waitUntil(
			function () use ( $url, $host, $code, &$actual, &$headers ) {
				$actual = self::httpRequest( $url, $host, $headers );

				return (int) $code === $actual;
			},
			$seconds,
			function () use ( $url, $host, $code, $seconds, &$actual, &$headers ) {
				return "GET $url (Host: $host) returned $actual, expected $code within $seconds seconds.\n$headers";
			}
		);
	}

	/**
	 * Holds the port busy from this process (the kernel completes the handshake without accept()); closed after the scenario.
	 *
	 * @Given a TCP listener is open on port :port
	 */
	public function aTcpListenerIsOpenOnPort( $port ) {
		$socket = @stream_socket_server( 'tcp://0.0.0.0:' . (int) $port, $errno, $errstr );
		if ( false === $socket ) {
			throw new Exception( "Unable to listen on port $port: $errstr" );
		}
		$this->listeners[] = $socket;
	}

	/**
	 * @Then the site :site should exist
	 */
	public function theSiteShouldExist( $site ) {
		if ( ! in_array( $site, self::listSites(), true ) ) {
			throw new Exception( "Site $site does not exist. Sites: " . implode( ', ', self::listSites() ) );
		}
	}

	/**
	 * Creates the site once per feature; only suite-prefixed names so the AfterFeature cleanup removes it.
	 *
	 * @Given a test site :site of type :type exists
	 */
	public function aTestSiteOfTypeExists( $site, $type ) {
		if ( 0 !== strpos( $site, self::$site_prefix ) ) {
			throw new Exception( "Test site names must start with '" . self::$site_prefix . "': $site" );
		}
		if ( in_array( $site, self::listSites(), true ) ) {
			return;
		}
		$result = EE::launch( 'bin/ee site create ' . escapeshellarg( $site ) . ' --type=' . escapeshellarg( $type ), false, true );
		if ( 0 !== $result->return_code ) {
			throw new Exception( "Unable to create $site:\nSTDOUT:\n{$result->stdout}\nSTDERR:\n{$result->stderr}" );
		}
	}

	/**
	 * Precondition for global-service scenarios: starts the core services if needed and waits until they answer.
	 *
	 * @Given the EasyEngine global services are running
	 */
	public function theEasyEngineGlobalServicesAreRunning() {
		foreach ( [ 'nginx-proxy', 'db', 'redis' ] as $service ) {
			if ( 'running' !== self::containerStatus( self::$global_services[ $service ] ) ) {
				$result = EE::launch( 'bin/ee service enable ' . $service, false, true );
				if ( 0 !== $result->return_code ) {
					throw new Exception( "Unable to enable $service:\nSTDOUT:\n{$result->stdout}\nSTDERR:\n{$result->stderr}" );
				}
			}
		}
		$this->waitForCoreServices( 60 );
	}

	/**
	 * Guard for scenarios that regenerate the global compose file and run `up -d`: with other image tags
	 * than this checkout's img-versions.json, they would upgrade the live containers with no way back.
	 *
	 * @Given the running global service containers use the images from img-versions.json
	 */
	public function theRunningGlobalServiceContainersUseTheImagesFromImgVersions() {
		$versions = json_decode( (string) file_get_contents( EE_ROOT . '/img-versions.json' ), true );
		$images   = [
			'nginx-proxy'     => 'easyengine/nginx-proxy',
			'db'              => 'easyengine/mariadb',
			'redis'           => 'easyengine/redis',
			'cron'            => 'easyengine/cron',
			'newrelic-daemon' => 'easyengine/newrelic-daemon',
		];
		$mismatches = [];
		foreach ( $images as $service => $image ) {
			$container = self::$global_services[ $service ];
			if ( 'absent' === self::containerStatus( $container ) ) {
				continue;
			}
			$actual   = trim( EE::launch( "docker inspect -f '{{.Config.Image}}' " . escapeshellarg( $container ), false, true )->stdout );
			$expected = $image . ':' . ( isset( $versions[ $image ] ) ? $versions[ $image ] : '?' );
			if ( $actual !== $expected ) {
				$mismatches[] = "$container runs $actual, this checkout would use $expected";
			}
		}
		if ( $mismatches ) {
			throw new Exception( "Refusing to regenerate the global services:\n" . implode( "\n", $mismatches ) );
		}
	}

	/**
	 * @Given I save the output of the command :command
	 */
	public function iSaveTheOutputOfTheCommand( $command ) {
		$result = EE::launch( $command, false, true );
		if ( 0 !== $result->return_code || '' === trim( $result->stdout ) ) {
			throw new Exception( "Command failed or printed nothing: $command\nReturn code: {$result->return_code}\nSTDOUT:\n{$result->stdout}\nSTDERR:\n{$result->stderr}" );
		}
		$this->saved_outputs[ $command ] = $result->stdout;
	}

	/**
	 * @Then the output of the command :command should have changed
	 */
	public function theOutputOfTheCommandShouldHaveChanged( $command ) {
		if ( ! isset( $this->saved_outputs[ $command ] ) ) {
			throw new Exception( "No output saved for: $command" );
		}
		$saved = $this->saved_outputs[ $command ];
		$last  = null;
		// Worker replacement after a reload is asynchronous.
		$this->waitUntil(
			function () use ( $command, $saved, &$last ) {
				$last = EE::launch( $command, false, true );

				return 0 === $last->return_code && '' !== trim( $last->stdout ) && $last->stdout !== $saved;
			},
			30,
			function () use ( $command, &$last ) {
				return "Output of '$command' did not change within 30 seconds:\nReturn code: {$last->return_code}\nSTDOUT:\n{$last->stdout}\nSTDERR:\n{$last->stderr}";
			}
		);
	}

	/**
	 * @When I wait until the global db accepts connections up to :seconds seconds
	 */
	public function iWaitUntilTheGlobalDbAcceptsConnections( $seconds ) {
		$this->iWaitUntilTheCommandSucceeds( self::globalDbPingCommand(), $seconds );
	}

	private static function globalDbPingCommand() {
		// mariadb >= 11 images ship only the mariadb-* client names.
		$sql = 'if command -v mariadb >/dev/null 2>&1; then c=mariadb; else c=mysql; fi; $c -uroot -p"$MYSQL_ROOT_PASSWORD" -e "SELECT 1"';

		return 'docker exec ' . self::$global_services['db'] . ' sh -c ' . escapeshellarg( $sql );
	}

	private function waitForCoreServices( $seconds, array $services = [ 'nginx-proxy', 'db', 'redis' ] ) {
		if ( in_array( 'nginx-proxy', $services, true ) ) {
			$this->iWaitUntilTheDockerContainerIsRunning( self::$global_services['nginx-proxy'], $seconds );
		}
		if ( in_array( 'db', $services, true ) ) {
			$this->iWaitUntilTheGlobalDbAcceptsConnections( $seconds );
		}
		if ( in_array( 'redis', $services, true ) ) {
			$this->iWaitUntilTheCommandOutputs( 'docker exec ' . self::$global_services['redis'] . ' redis-cli ping', 'PONG', $seconds );
		}
	}

	/**
	 * @BeforeScenario @destructive
	 */
	public function snapshotGlobalServices( BeforeScenarioScope $scope ) {
		// A snapshot left by a failed restore or a killed run is the real baseline: keep it.
		if ( null !== $this->service_snapshot ) {
			return;
		}
		$this->service_snapshot = [];
		foreach ( self::$global_services as $service => $container ) {
			$this->service_snapshot[ $service ] = self::containerStatus( $container );
		}
		$this->saveState();
	}

	/**
	 * Puts every global service container back in the state it had before the scenario.
	 */
	private function restoreGlobalServices() {
		$errors      = [];
		$was_up_list = [];
		foreach ( $this->service_snapshot as $service => $before ) {
			$container = self::$global_services[ $service ];
			$now       = self::containerStatus( $container );
			$was_up    = in_array( $before, [ 'running', 'restarting' ], true );
			$is_up     = in_array( $now, [ 'running', 'restarting' ], true );
			if ( $was_up ) {
				$was_up_list[] = $service;
			}

			if ( $was_up && 'absent' === $now ) {
				$result = self::compose( 'up -d global-' . $service );
			} elseif ( $was_up && ! $is_up ) {
				$result = EE::launch( 'docker start ' . $container, false, true );
			} elseif ( 'absent' === $before && 'absent' !== $now ) {
				// Graceful stop first: a killed MariaDB/Redis would leave its data volume dirty.
				$result = EE::launch( 'docker stop -t 30 ' . $container . ' && docker rm ' . $container, false, true );
			} elseif ( 'absent' !== $before && 'absent' === $now ) {
				$result = self::compose( 'up --no-start global-' . $service );
			} elseif ( ! $was_up && $is_up ) {
				$result = EE::launch( 'docker stop ' . $container, false, true );
			} else {
				continue;
			}
			if ( 0 !== $result->return_code ) {
				$errors[] = "Restoring $service ($before -> $now) failed: {$result->stderr}";
			}
		}
		if ( $errors ) {
			throw new Exception( implode( "\n", $errors ) );
		}
		$this->waitForCoreServices( 180, $was_up_list );
		// Only forget the baseline once it is really back.
		$this->service_snapshot = null;
	}

	private function restoreBackedUpFiles() {
		foreach ( array_reverse( array_keys( $this->file_backups ) ) as $path ) {
			$this->iRestoreTheFile( $path );
		}
		$this->saved_checksums   = [];
		$this->saved_start_times = [];
	}

	private function removeCreatedFiles() {
		foreach ( $this->created_files as $path ) {
			if ( file_exists( $path ) ) {
				unlink( $path );
			}
		}
		$this->created_files = [];
	}

	/**
	 * Restores files, then global services (they may need the restored compose file); each step runs even if an earlier one fails.
	 */
	private function restorePendingState() {
		try {
			$this->restoreBackedUpFiles();
		} finally {
			try {
				$this->removeCreatedFiles();
			} finally {
				try {
					if ( null !== $this->service_snapshot ) {
						$this->restoreGlobalServices();
					}
					$this->reloadProxyAfterRestore();
				} finally {
					$this->saveState();
				}
			}
		}
	}

	/**
	 * A running proxy keeps serving what it loaded (e.g. a test certificate) until it reloads the restored files.
	 */
	private function reloadProxyAfterRestore() {
		if ( ! $this->reload_proxy ) {
			return;
		}
		$container = self::$global_services['nginx-proxy'];
		if ( 'running' === self::containerStatus( $container ) ) {
			$result = EE::launch( 'docker exec ' . $container . ' nginx -s reload', false, true );
			if ( 0 !== $result->return_code ) {
				throw new Exception( "Reloading nginx-proxy after restoring its files failed: {$result->stderr}" );
			}
		}
		$this->reload_proxy = false;
	}

	private static function ensureStateDir() {
		if ( ! file_exists( self::STATE_DIR ) && ! is_link( self::STATE_DIR ) && ! mkdir( self::STATE_DIR, 0700, true ) ) {
			throw new Exception( 'Unable to create ' . self::STATE_DIR );
		}
		self::assertTrustedStateDir();
	}

	/**
	 * The journal names files that recovery overwrites or deletes as root, so only a directory we own and nobody else can write is used.
	 */
	private static function assertTrustedStateDir() {
		clearstatcache();
		$uid = function_exists( 'posix_geteuid' ) ? posix_geteuid() : 0;
		if ( is_link( self::STATE_DIR ) || ! is_dir( self::STATE_DIR ) || fileowner( self::STATE_DIR ) !== $uid || ( fileperms( self::STATE_DIR ) & 0022 ) ) {
			throw new Exception( self::STATE_DIR . ' is not a directory owned by uid ' . $uid . ' and writable only by it: refusing to use it.' );
		}
	}

	/**
	 * Persists what still has to be restored, so a killed run is repaired by the next one (see recoverLeftoverState()).
	 */
	private function saveState() {
		$file = self::STATE_DIR . '/state.json';
		if ( empty( $this->file_backups ) && empty( $this->created_files ) && null === $this->service_snapshot && ! $this->reload_proxy ) {
			if ( file_exists( $file ) ) {
				unlink( $file );
			}

			return;
		}
		self::ensureStateDir();
		$state = [
			'files'    => $this->file_backups,
			'created'  => $this->created_files,
			'services' => $this->service_snapshot,
			'reload'   => $this->reload_proxy,
		];
		// Atomic replace, so a killed run never leaves a truncated journal.
		$tmp = $file . '.tmp';
		if ( false === file_put_contents( $tmp, json_encode( $state ) ) || ! rename( $tmp, $file ) ) {
			throw new Exception( "Unable to write $file" );
		}
	}

	private function loadPendingState() {
		$file = self::STATE_DIR . '/state.json';
		if ( ! file_exists( $file ) ) {
			return;
		}
		self::assertTrustedStateDir();
		$state = json_decode( (string) file_get_contents( $file ), true );
		if ( ! is_array( $state ) ) {
			throw new Exception( "Corrupt state file $file: restore its entries by hand, then delete it." );
		}
		$this->file_backups     = isset( $state['files'] ) ? (array) $state['files'] : [];
		$this->created_files    = isset( $state['created'] ) ? (array) $state['created'] : [];
		$this->service_snapshot = isset( $state['services'] ) ? $state['services'] : null;
		$this->reload_proxy     = ! empty( $state['reload'] );
	}
}
