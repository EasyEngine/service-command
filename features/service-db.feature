@destructive
Feature: Global db (MariaDB) service

  A WordPress site on the global DB checks that sites really lose and regain their database. The @destructive hooks restart the DB if a scenario leaves it stopped.

  Background:
    Given the EasyEngine global services are running
      And a test site 'bh-service-command-2.test' of type 'wp' exists
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-2.test' returns 200 up to 60 seconds

  Scenario: ee service disable db stops the global DB and WordPress sites fail
    When I run 'bin/ee service disable db'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-db disabled.'
      And the docker container 'services_global-db_1' should be stopped
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-2.test' returns 500 up to 30 seconds

  Scenario: ee service disable db is idempotent
    When I run 'bin/ee service disable db'
      And I run 'bin/ee service disable db'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-db disabled.'
      And the docker container 'services_global-db_1' should be stopped

  Scenario: ee service enable db starts a stopped DB and sites recover
    Given I run 'bin/ee service disable db'
    When I run 'bin/ee service enable db'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-db enabled.'
      And the docker container 'services_global-db_1' should be running
      And I wait until the global db accepts connections up to 60 seconds
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-2.test' returns 200 up to 60 seconds

  Scenario: ee service enable db recreates a removed DB container and keeps the data
    # Stop MariaDB cleanly first: rm -f would SIGKILL the live DB and force crash recovery.
    Given I run 'docker stop -t 60 services_global-db_1 && docker rm services_global-db_1'
      And the docker container 'services_global-db_1' should not exist
    When I run 'bin/ee service enable db'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-db enabled.'
      And the docker container 'services_global-db_1' should be running
      And I wait until the global db accepts connections up to 90 seconds
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-2.test' returns 200 up to 60 seconds

  Scenario: ee service restart db restarts the DB and sites recover
    Given I save the start time of the docker container 'services_global-db_1'
    When I run 'bin/ee service restart db'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-db restarted.'
      And the docker container 'services_global-db_1' should have been restarted
      And I wait until the global db accepts connections up to 60 seconds
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-2.test' returns 200 up to 60 seconds

  Scenario: ee service restart db starts a stopped DB
    Given I run 'bin/ee service disable db'
      And the docker container 'services_global-db_1' should be stopped
    When I run 'bin/ee service restart db'
    Then the return code should be 0
      And the docker container 'services_global-db_1' should be running
      And I wait until the global db accepts connections up to 60 seconds
