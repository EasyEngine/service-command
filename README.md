easyengine/service-command
==========================

Command to manager global containers/services in EasyEngine



Quick links: [Using](#using) | [Contributing](#contributing) | [Support](#support)

## Using

This package implements the following commands:

### ee service

Manages global services of EasyEngine.

~~~
ee service
~~~

**EXAMPLES**

    # Restarts global nginx proxy service
    $ ee service restart nginx-proxy



### ee service enable

Starts global services.

~~~
ee service enable <service-name>
~~~

**OPTIONS**

	<service-name>
		Name of service.

**EXAMPLES**

    # Enable global service
    $ ee service enable nginx-proxy



### ee service disable

Stops global services.

~~~
ee service disable <service-name>
~~~

**OPTIONS**

	<service-name>
		Name of service.

**EXAMPLES**

    # Disable global service
    $ ee service disable nginx-proxy



### ee service restart

Restarts global services.

~~~
ee service restart <service-name>
~~~

**OPTIONS**

	<service-name>
		Name of service.

**EXAMPLES**

    # Restart global service
    $ ee service restart nginx-proxy



### ee service reload

Reloads global service without restarting services.

~~~
ee service reload <service-name>
~~~

**OPTIONS**

	<service-name>
		Name of service.

**EXAMPLES**

    # Reload global service
    $ ee service reload nginx-proxy

## Contributing

We appreciate you taking the initiative to contribute to this project.

Contributing isn’t limited to just code. We encourage you to contribute in the way that best fits your abilities, by writing tutorials, giving a demo at your local meetup, helping other users with their support questions, or revising our documentation.

### Reporting a bug

Think you’ve found a bug? We’d love for you to help us get it fixed.

Before you create a new issue, you should [search existing issues](https://github.com/easyengine/service-command/issues?q=label%3Abug%20) to see if there’s an existing resolution to it, or if it’s already been fixed in a newer version.

Once you’ve done a bit of searching and discovered there isn’t an open or fixed issue for your bug, please [create a new issue](https://github.com/easyengine/service-command/issues/new). Include as much detail as you can, and clear steps to reproduce if possible.

### Creating a pull request

Want to contribute a new feature? Please first [open a new issue](https://github.com/easyengine/service-command/issues/new) to discuss whether the feature is a good fit for the project.

## Support

Github issues aren't for general support questions, but there are other venues you can try: https://easyengine.io/support/


*This README.md is generated dynamically from the project's codebase using `ee scaffold package-readme` ([doc](https://github.com/EasyEngine/scaffold-command)). To suggest changes, please submit a pull request against the corresponding part of the codebase.*
