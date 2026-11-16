@destructive
Feature: Global nginx-proxy service

  Every scenario changes the global proxy; the @destructive hooks put each global service container back in its previous state afterwards.

  Background:
    Given the EasyEngine global services are running
      And a test site 'bh-service-command-1.test' of type 'html' exists
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service disable nginx-proxy stops the proxy and takes sites offline
    When I run 'bin/ee service disable nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-nginx-proxy disabled.'
      And the docker container 'services_global-nginx-proxy_1' should be stopped
      And a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' should return 0

  Scenario: ee service disable nginx-proxy is idempotent
    When I run 'bin/ee service disable nginx-proxy'
      And I run 'bin/ee service disable nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-nginx-proxy disabled.'
      And the docker container 'services_global-nginx-proxy_1' should be stopped

  Scenario: ee service enable nginx-proxy starts a stopped proxy and sites come back
    Given I run 'bin/ee service disable nginx-proxy'
      And the docker container 'services_global-nginx-proxy_1' should be stopped
    When I run 'bin/ee service enable nginx-proxy'
    Then the return code should be 0
      And STDERR should not contain 'Error'
      And STDOUT should contain 'Service nginx_proxy'
      And the docker container 'services_global-nginx-proxy_1' should be running
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service enable nginx-proxy generates a missing default certificate, writes the version and reports success
    Given I back up the file '/opt/easyengine/services/nginx-proxy/certs/default.key'
      And I back up the file '/opt/easyengine/services/nginx-proxy/certs/default.crt'
      And I run 'bin/ee service disable nginx-proxy'
      And the docker container 'services_global-nginx-proxy_1' should be stopped
      And I delete the file '/opt/easyengine/services/nginx-proxy/certs/default.key'
      And I delete the file '/opt/easyengine/services/nginx-proxy/certs/default.crt'
    When I run 'bin/ee service enable nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service nginx_proxy enabled.'
      And the file '/opt/easyengine/services/nginx-proxy/certs/default.key' should exist
      And the file '/opt/easyengine/services/nginx-proxy/certs/default.crt' should exist
      And I wait until the command 'openssl x509 -noout -subject -in /opt/easyengine/services/nginx-proxy/certs/default.crt' outputs 'default' up to 5 seconds
      And I wait until the command 'docker exec services_global-nginx-proxy_1 cat /version.conf' outputs 'EasyEngine v4.' up to 10 seconds
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds
    # Put the original cert back and make nginx load it again.
    When I restore the file '/opt/easyengine/services/nginx-proxy/certs/default.key'
      And I restore the file '/opt/easyengine/services/nginx-proxy/certs/default.crt'
      And I run 'bin/ee service reload nginx-proxy'
    Then the return code should be 0

  Scenario: ee service enable nginx-proxy fails when docker-compose cannot start the proxy
    Given I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I run 'bin/ee service disable nginx-proxy'
      And the docker container 'services_global-nginx-proxy_1' should be stopped
      And I append 'bh-service-command-broken: [' to the file '/opt/easyengine/services/docker-compose.yml'
    When I run 'bin/ee service enable nginx-proxy'
    Then the return code should be 1
      And STDERR should contain 'Error: There was some error in starting services_global-nginx-proxy_1 container. Please check logs.'
      And the docker container 'services_global-nginx-proxy_1' should be stopped

  Scenario: ee service enable nginx-proxy recreates a removed proxy container
    Given I run 'docker rm -f services_global-nginx-proxy_1'
      And the docker container 'services_global-nginx-proxy_1' should not exist
    When I run 'bin/ee service enable nginx-proxy'
    Then the return code should be 0
      And the docker container 'services_global-nginx-proxy_1' should be running
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service enable nginx-proxy regenerates a missing global docker-compose.yml
    Given the running global service containers use the images from img-versions.json
      And I back up the file '/opt/easyengine/services/docker-compose.yml'
      And I run 'docker rm -f services_global-nginx-proxy_1'
      And I delete the file '/opt/easyengine/services/docker-compose.yml'
    When I run 'bin/ee service enable nginx-proxy'
    Then the return code should be 0
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-nginx-proxy_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain 'container_name: services_global-db_1'
      And the file '/opt/easyengine/services/docker-compose.yml' should contain the value of the EasyEngine option 'global-db'
      And the docker container 'services_global-nginx-proxy_1' should be running
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service enable nginx-proxy refuses to start when the configured ports are busy
    Given I run 'bin/ee service disable nginx-proxy'
      And a TCP listener is open on port 18080
      And a file '/var/tmp/bh-service-command-ports-busy.yml' with:
      """
      proxy_80_port: "18080"
      proxy_443_port: "18443"
      """
    When I run 'EE_CONFIG_PATH=/var/tmp/bh-service-command-ports-busy.yml bin/ee service enable nginx-proxy'
    Then the return code should be 1
      And STDERR should contain 'Error: Cannot create/start proxy container. Please make sure port 18080 and 18443 are free.'
      And the docker container 'services_global-nginx-proxy_1' should be stopped

  Scenario: ee service restart nginx-proxy restarts the proxy and keeps sites served
    Given I save the start time of the docker container 'services_global-nginx-proxy_1'
    When I run 'bin/ee service restart nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-nginx-proxy restarted.'
      And the docker container 'services_global-nginx-proxy_1' should have been restarted
      And the docker container 'services_global-nginx-proxy_1' should be running
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service restart nginx-proxy twice in a row succeeds
    When I run 'bin/ee service restart nginx-proxy'
      And I run 'bin/ee service restart nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Service global-nginx-proxy restarted.'
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service restart nginx-proxy starts a stopped proxy
    Given I run 'bin/ee service disable nginx-proxy'
      And the docker container 'services_global-nginx-proxy_1' should be stopped
    When I run 'bin/ee service restart nginx-proxy'
    Then the return code should be 0
      And the docker container 'services_global-nginx-proxy_1' should be running
      And I wait until a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' returns 200 up to 30 seconds

  Scenario: ee service reload nginx-proxy regenerates the proxy config without restarting the container
    Given I back up the file '/opt/easyengine/services/nginx-proxy/conf.d/default.conf'
      And I append '# bh-service-command reload marker' to the file '/opt/easyengine/services/nginx-proxy/conf.d/default.conf'
      And I save the start time of the docker container 'services_global-nginx-proxy_1'
      And I save the output of the command "docker top services_global-nginx-proxy_1 -eo pid,args | grep -F 'nginx: worker' | awk '{print $1}'"
    When I run 'bin/ee service reload nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Reloaded global-nginx-proxy.'
      And the docker container 'services_global-nginx-proxy_1' should not have been restarted
      And the output of the command "docker top services_global-nginx-proxy_1 -eo pid,args | grep -F 'nginx: worker' | awk '{print $1}'" should have changed
      And the file '/opt/easyengine/services/nginx-proxy/conf.d/default.conf' should not contain 'bh-service-command reload marker'
      And the file '/opt/easyengine/services/nginx-proxy/conf.d/default.conf' should contain 'bh-service-command-1.test'
      And a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' should return 200

  Scenario: ee service reload nginx-proxy twice in a row succeeds
    When I run 'bin/ee service reload nginx-proxy'
      And I run 'bin/ee service reload nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Success: Reloaded global-nginx-proxy.'
      And a GET request to 'http://127.0.0.1' with Host 'bh-service-command-1.test' should return 200
