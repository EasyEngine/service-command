@destructive
Feature: Global redis service

  The @destructive hooks restart redis if a scenario leaves it stopped.

  Background:
    Given the EasyEngine global services are running

  Scenario: ee service disable redis stops the global redis
    When I run 'bin/ee service disable redis'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-redis disabled.'
      And the docker container 'services_global-redis_1' should be stopped

  Scenario: ee service disable redis is idempotent
    When I run 'bin/ee service disable redis'
      And I run 'bin/ee service disable redis'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-redis disabled.'
      And the docker container 'services_global-redis_1' should be stopped

  Scenario: ee service enable redis starts a stopped redis
    Given I run 'bin/ee service disable redis'
    When I run 'bin/ee service enable redis'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-redis enabled.'
      And the docker container 'services_global-redis_1' should be running
      And I wait until the command 'docker exec services_global-redis_1 redis-cli ping' outputs 'PONG' up to 30 seconds

  Scenario: ee service enable redis recreates a removed redis container
    Given I run 'docker stop services_global-redis_1 && docker rm services_global-redis_1'
      And the docker container 'services_global-redis_1' should not exist
    When I run 'bin/ee service enable redis'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-redis enabled.'
      And the docker container 'services_global-redis_1' should be running
      And I wait until the command 'docker exec services_global-redis_1 redis-cli ping' outputs 'PONG' up to 30 seconds

  Scenario: ee service restart redis restarts redis
    Given I save the start time of the docker container 'services_global-redis_1'
    When I run 'bin/ee service restart redis'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-redis restarted.'
      And the docker container 'services_global-redis_1' should have been restarted
      And I wait until the command 'docker exec services_global-redis_1 redis-cli ping' outputs 'PONG' up to 30 seconds

  Scenario: ee service restart redis starts a stopped redis
    Given I run 'bin/ee service disable redis'
      And the docker container 'services_global-redis_1' should be stopped
    When I run 'bin/ee service restart redis'
    Then the return code should be 0
      And the docker container 'services_global-redis_1' should be running
      And I wait until the command 'docker exec services_global-redis_1 redis-cli ping' outputs 'PONG' up to 30 seconds

  Scenario: ee service enable redis regenerates a missing global docker-compose.yml without restarting redis
    Given the running global service containers use the images from img-versions.json
      And I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I save the start time of the docker container 'services_global-redis_1'
      And I delete the file '/opt/easyengine/services/docker-compose.yml'
    When I run 'bin/ee service enable redis'
    Then the return code should be 0
      And STDOUT should contain 'Notice: Service global-redis already enabled.'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-redis_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain the value of the EasyEngine option 'global-db'
      And the docker container 'services_global-redis_1' should not have been restarted

  Scenario: ee service restart redis falls back to the default compose file when --custom-compose is missing
    Given I save the start time of the docker container 'services_global-redis_1'
    When I run 'bin/ee service restart redis --custom-compose=bh-service-command-missing.yml'
    Then the return code should be 0
      And STDERR should contain 'Warning: File: user-docker-compose/bh-service-command-missing.yml does not exist. Falling back to default compose file.'
      And STDOUT should contain 'Success: Service global-redis restarted.'
      And the docker container 'services_global-redis_1' should have been restarted
