@destructive
Feature: Global cron service

  With no site cron jobs the cron container can restart-loop (an older cron config, before EasyEngine/cron-command#60), so "running or restarting" is accepted wherever it should be up. The global cron container only exists once something enabled it, so scenarios that need it enable it first.

  Background:
    Given the EasyEngine global services are running

  Scenario: ee service enable cron starts the cron container
    When I run 'bin/ee service enable cron'
    Then the return code should be 0
      And STDOUT should contain 'Service global-cron'
      And the docker container 'services_global-cron_1' should be running or restarting

  Scenario: ee service disable cron stops the cron container
    Given I run 'bin/ee service enable cron'
    When I run 'bin/ee service disable cron'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-cron disabled.'
      And the docker container 'services_global-cron_1' should be stopped

  Scenario: ee service disable cron is idempotent
    Given I run 'bin/ee service enable cron'
    When I run 'bin/ee service disable cron'
      And I run 'bin/ee service disable cron'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-cron disabled.'
      And the docker container 'services_global-cron_1' should be stopped

  Scenario: ee service enable cron starts a stopped cron container
    Given I run 'bin/ee service enable cron'
      And I run 'bin/ee service disable cron'
      And the docker container 'services_global-cron_1' should be stopped
    When I run 'bin/ee service enable cron'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-cron enabled.'
      And the docker container 'services_global-cron_1' should be running or restarting

  Scenario: ee service enable cron recreates a removed cron container
    Given I run 'bin/ee service enable cron'
      And I run 'docker rm -f services_global-cron_1'
      And the docker container 'services_global-cron_1' should not exist
    When I run 'bin/ee service enable cron'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-cron enabled.'
      And the docker container 'services_global-cron_1' should be running or restarting

  Scenario: ee service restart cron restarts the cron container
    Given I run 'bin/ee service enable cron'
      And the docker container 'services_global-cron_1' should be running or restarting
    When I run 'bin/ee service restart cron'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-cron restarted.'
      And the docker container 'services_global-cron_1' should be running or restarting

  Scenario: ee service restart cron starts a stopped cron container
    Given I run 'bin/ee service enable cron'
      And I run 'bin/ee service disable cron'
      And the docker container 'services_global-cron_1' should be stopped
    When I run 'bin/ee service restart cron'
    Then the return code should be 0
      And the docker container 'services_global-cron_1' should be running or restarting
