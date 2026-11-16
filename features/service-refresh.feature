@destructive
Feature: ee service refresh

  refresh regenerates /opt/easyengine/services/docker-compose.yml and runs `up -d` for the services that are running. The compose file is backed up and restored by every scenario that modifies it. `--debug` shows which `up -d` calls refresh made. The image guard stops the feature before refresh could upgrade live containers to this checkout's image tags.

  Background:
    Given the EasyEngine global services are running
      And the running global service containers use the images from img-versions.json
      And a test site 'bh-service-command-3.test' of type 'html' exists
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-3.test' returns 200 up to 30 seconds

  Scenario: ee service refresh regenerates a modified global docker-compose.yml and updates the running services
    Given I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I append '# bh-service-command refresh marker' to the file '/opt/easyengine/services/docker-compose.yml'
    When I run 'bin/ee service refresh --debug'
    Then the return code should be 0
      And STDOUT should contain 'Success: Global services refreshed.'
      And STDERR should contain 'up -d global-nginx-proxy'
      And STDERR should contain 'up -d global-db'
      And STDERR should contain 'up -d global-redis'
      And the file '/opt/easyengine/services/docker-compose.yml' should not contain 'bh-service-command refresh marker'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-nginx-proxy_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-db_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-redis_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-cron_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-newrelic-daemon_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'name: ee-global-frontend-network'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'name: ee-global-backend-network'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain the value of the EasyEngine option 'global-db'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain the value of the EasyEngine option 'frontend_subnet_ip'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain the value of the EasyEngine option 'backend_subnet_ip'
      And the docker container 'services_global-nginx-proxy_1' should be running
      And the docker container 'services_global-db_1' should be running
      And the docker container 'services_global-redis_1' should be running
      And I wait until the global db accepts connections up to 60 seconds
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-3.test' returns 200 up to 30 seconds

  Scenario: ee service refresh recreates a missing global docker-compose.yml
    Given I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I save the start time of the docker container 'services_global-nginx-proxy_1'
      And I delete the file '/opt/easyengine/services/docker-compose.yml'
    When I run 'bin/ee service refresh'
    Then the return code should be 0
      And STDOUT should contain 'Success: Global services refreshed.'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-nginx-proxy_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain the value of the EasyEngine option 'global-db'
      And the docker container 'services_global-nginx-proxy_1' should be running
      And the docker container 'services_global-nginx-proxy_1' should not have been restarted
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-3.test' returns 200 up to 30 seconds

  Scenario: ee service refresh is idempotent
    Given I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I run 'bin/ee service refresh'
      And I save the checksum of the file '/opt/easyengine/services/docker-compose.yml'
      And I save the start time of the docker container 'services_global-nginx-proxy_1'
      And I save the start time of the docker container 'services_global-db_1'
    When I run 'bin/ee service refresh'
    Then the return code should be 0
      And the file '/opt/easyengine/services/docker-compose.yml' should be unchanged
      And the docker container 'services_global-nginx-proxy_1' should not have been restarted
      And the docker container 'services_global-db_1' should not have been restarted

  Scenario: ee service refresh leaves a disabled service stopped
    Given I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I run 'bin/ee service disable redis'
      And the docker container 'services_global-redis_1' should be stopped
    When I run 'bin/ee service refresh --debug'
    Then the return code should be 0
      And STDERR should not contain 'up -d global-redis'
      And STDERR should contain 'up -d global-nginx-proxy'
      And the docker container 'services_global-redis_1' should be stopped
      And the docker container 'services_global-nginx-proxy_1' should be running
      And the docker container 'services_global-db_1' should be running

  Scenario: ee service refresh does not create services that were never enabled
    Given I back up the file '/opt/easyengine/services/docker-compose.yml'
      And the docker container 'services_global-newrelic-daemon_1' should not exist
    When I run 'bin/ee service refresh'
    Then the return code should be 0
      And the docker container 'services_global-newrelic-daemon_1' should not exist
