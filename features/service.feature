Feature: Service command (usage, validation and read-only behaviour)

  These scenarios never change a global service: they fail before any docker call, or act on services that are already in the requested state (checked, never started here).

  Scenario: ee service shows its subcommands
    When I run 'bin/ee service'
    Then the return code should be 0
      And STDOUT should contain 'usage: ee service disable <service-name>'
      And STDOUT should contain 'ee service enable <service-name>'
      And STDOUT should contain 'ee service refresh'
      And STDOUT should contain 'ee service reload <service-name>'
      And STDOUT should contain 'ee service restart <service-name>'
      And STDOUT should contain "See 'ee help service <command>' for more information on a specific command."

  Scenario: ee service rejects an unknown subcommand
    When I run 'bin/ee service start nginx-proxy'
    Then the return code should be 1
      And STDERR should contain "Error: 'start' is not a registered subcommand of 'service'."

  Scenario: ee help service describes the command
    When I run 'PAGER=cat bin/ee help service'
    Then the return code should be 0
      And STDOUT should contain 'Manages global services of EasyEngine.'
      And STDOUT should contain 'ee service <command>'

  Scenario Outline: ee help service <subcommand> shows its synopsis
    When I run 'PAGER=cat bin/ee help service <subcommand>'
    Then the return code should be 0
      And STDOUT should contain '<synopsis>'

    Examples:
      | subcommand | synopsis                              |
      | enable     | ee service enable <service-name>      |
      | disable    | ee service disable <service-name>     |
      | restart    | ee service restart <service-name>     |
      | reload     | ee service reload <service-name>      |
      | refresh    | ee service refresh                    |

  Scenario Outline: ee service <subcommand> without a service name prints usage
    When I run 'bin/ee service <subcommand>'
    Then the return code should be 1
      And STDOUT should contain 'usage: ee service <subcommand> <service-name>'

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | restart    |
      | reload     |

  Scenario Outline: ee service <subcommand> rejects an unknown service "<service>"
    When I run 'bin/ee service <subcommand> <service>'
    Then the return code should be 1
      And STDERR should contain 'Error: Unable to find global EasyEngine service <service>'
      And STDOUT should not contain 'Success'

    Examples:
      | subcommand | service     |
      | enable     | foo         |
      | disable    | foo         |
      | restart    | foo         |
      | reload     | foo         |
      | enable     | global-db   |
      | disable    | nginx_proxy |
      | restart    | DB          |
      | reload     | mailhog     |
      | restart    | php         |

  Scenario Outline: ee service <subcommand> accepts only one service
    When I run 'bin/ee service <subcommand> redis db'
    Then the return code should be 1
      And STDERR should contain 'Error: Too many positional arguments: db'

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | restart    |
      | reload     |

  Scenario Outline: ee service <subcommand> rejects unknown parameters
    When I run 'bin/ee service <subcommand> redis --foo=bar'
    Then the return code should be 1
      And STDERR should contain 'unknown --foo parameter'

    Examples:
      | subcommand |
      | enable     |
      | disable    |
      | restart    |
      | reload     |

  Scenario Outline: ee service enable <service> is a no-op when the service is already running
    # enable regenerates a missing global docker-compose.yml, so these read-only scenarios refuse that state.
    Given the file '/opt/easyengine/services/docker-compose.yml' should exist
      And the docker container '<container>' should be running
      And I save the start time of the docker container '<container>'
    When I run 'bin/ee service enable <service>'
    Then the return code should be 0
      And STDOUT should contain '<notice>'
      And STDOUT should not contain 'Success'
      And the docker container '<container>' should be running
      And the docker container '<container>' should not have been restarted

    Examples:
      | service     | container                     | notice                                        |
      | nginx-proxy | services_global-nginx-proxy_1 | Notice: Service nginx_proxy already enabled.  |
      | db          | services_global-db_1          | Notice: Service global-db already enabled.    |
      | redis       | services_global-redis_1       | Notice: Service global-redis already enabled. |

  Scenario: ee service enable twice in a row stays a no-op
    Given the file '/opt/easyengine/services/docker-compose.yml' should exist
      And the docker container 'services_global-redis_1' should be running
      And I save the start time of the docker container 'services_global-redis_1'
    When I run 'bin/ee service enable redis'
      And I run 'bin/ee service enable redis'
    Then the return code should be 0
      And STDOUT should contain 'Notice: Service global-redis already enabled.'
      And the docker container 'services_global-redis_1' should not have been restarted

  Scenario Outline: ee service reload <service> warns that the service can't be reloaded
    Given the file '/opt/easyengine/services/docker-compose.yml' should exist
      And the docker container 'services_global-<service>_1' should be running
      And I save the start time of the docker container 'services_global-<service>_1'
    When I run 'bin/ee service reload <service>'
    Then the return code should be 0
      And STDERR should contain 'Warning: global-<service> can not be reloaded.'
      And STDOUT should not contain 'Success'
      And the docker container 'services_global-<service>_1' should not have been restarted

    Examples:
      | service |
      | db      |
      | redis   |

  Scenario Outline: ee service reload <service> warns before any docker call
    When I run 'bin/ee service reload <service>'
    Then the return code should be 0
      And STDERR should contain 'Warning: global-<service> can not be reloaded.'

    Examples:
      | service         |
      | cron            |
      | newrelic-daemon |

  Scenario Outline: ee service enable nginx-proxy refuses to continue when the configured ports (<port_80>, <port_443>) differ from the running proxy
    Given the file '/opt/easyengine/services/docker-compose.yml' should exist
      And the docker container 'services_global-nginx-proxy_1' should be running
      And I save the start time of the docker container 'services_global-nginx-proxy_1'
      And a file '/var/tmp/bh-service-command-ports-mismatch.yml' with:
      """
      proxy_80_port: "<port_80>"
      proxy_443_port: "<port_443>"
      """
    When I run 'EE_CONFIG_PATH=/var/tmp/bh-service-command-ports-mismatch.yml bin/ee service enable nginx-proxy'
    Then the return code should be 1
      And STDERR should contain "Error: Ports of current running nginx-proxy and ports specified in EasyEngine config file don't match."
      And the docker container 'services_global-nginx-proxy_1' should not have been restarted

    Examples:
      | port_80 | port_443 |
      | 8081    | 8443     |
      | 80      | 8443     |
      | 8081    | 443      |

  Scenario: ee service enable nginx-proxy accepts quoted port values matching the running proxy
    Given the file '/opt/easyengine/services/docker-compose.yml' should exist
      And the docker container 'services_global-nginx-proxy_1' should be running
      And a file '/var/tmp/bh-service-command-ports-quoted.yml' with:
      """
      proxy_80_port: "80"
      proxy_443_port: "443"
      """
    When I run 'EE_CONFIG_PATH=/var/tmp/bh-service-command-ports-quoted.yml bin/ee service enable nginx-proxy'
    Then the return code should be 0
      And STDOUT should contain 'Notice: Service nginx_proxy already enabled.'
