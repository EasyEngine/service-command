# EasyEngine/service-command

Manages global `ee-nginx-proxy` container.

## About `ee-nginx-proxy`
`ee-nginx-proxy` is the main container which routes all incoming request to site-specific containers.

So let's say you have foo.com and bar.com on same ee server. When anyone requests either website, the request will first go to `ee-nginx-proxy` which will forward the request to appropriate nginx container of site (In this case foo.com or bar.com).

## Usage:

```
ee service [start|stop|restart|reload] <service-name>

ee service start ee-nginx-proxy   # starts ee-nginx-proxy container
ee service restart ee-nginx-proxy   # restarts ee-nginx-proxy container
ee service stop ee-nginx-proxy   # stops ee-nginx-proxy container
ee service reload ee-nginx-proxy   # reloads the configuration of ee-nginx-proxy container
```

For more info run ee service --help
