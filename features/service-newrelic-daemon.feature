@destructive @newrelic
Feature: Global newrelic-daemon service

  The newrelic-daemon container is not created by default. These scenarios expect it to be absent at the start (the @destructive hooks remove it again afterwards), and enabling it pulls easyengine/newrelic-daemon from Docker Hub.

  Background:
    Given the EasyEngine global services are running
      And the docker container 'services_global-newrelic-daemon_1' should not exist

  Scenario: ee service disable newrelic-daemon is harmless when it was never created
    When I run 'bin/ee service disable newrelic-daemon'
    Then the return code should be 0
      And the docker container 'services_global-newrelic-daemon_1' should not exist

  Scenario: ee service enable newrelic-daemon creates and starts the daemon
    When I run 'bin/ee service enable newrelic-daemon'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-newrelic-daemon enabled.'
      And I wait until the docker container 'services_global-newrelic-daemon_1' is running up to 60 seconds

  Scenario: ee service enable newrelic-daemon twice is a no-op the second time
    Given I run 'bin/ee service enable newrelic-daemon'
      And I wait until the docker container 'services_global-newrelic-daemon_1' is running up to 60 seconds
    When I run 'bin/ee service enable newrelic-daemon'
    Then the return code should be 0
      And STDOUT should contain 'Notice: Service global-newrelic-daemon already enabled.'

  Scenario: ee service disable and restart newrelic-daemon after it was enabled
    Given I run 'bin/ee service enable newrelic-daemon'
      And I wait until the docker container 'services_global-newrelic-daemon_1' is running up to 60 seconds
    When I run 'bin/ee service disable newrelic-daemon'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-newrelic-daemon disabled.'
      And the docker container 'services_global-newrelic-daemon_1' should be stopped
    When I run 'bin/ee service restart newrelic-daemon'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-newrelic-daemon restarted.'
      And the docker container 'services_global-newrelic-daemon_1' should be running
