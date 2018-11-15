<?php

if ( ! defined( 'SERVICE_TEMPLATE_ROOT' ) ) {
	define( 'SERVICE_TEMPLATE_ROOT', __DIR__ . '/templates' );
}

if ( ! defined( 'GLOBAL_DB' ) ) {
	define( 'GLOBAL_DB', 'global-db' );
}

if ( ! defined( 'GLOBAL_DB_CONTAINER' ) ) {
	define( 'GLOBAL_DB_CONTAINER', 'ee-global-db' );
}

if ( ! defined( 'GLOBAL_FRONTEND_NETWORK' ) ) {
	define( 'GLOBAL_FRONTEND_NETWORK', 'ee-global-frontend-network' );
}

if ( ! defined( 'GLOBAL_BACKEND_NETWORK' ) ) {
	define( 'GLOBAL_BACKEND_NETWORK', 'ee-global-backend-network' );
}

if ( ! defined( 'GLOBAL_REDIS' ) ) {
	define( 'GLOBAL_REDIS', 'global-redis' );
}

if ( ! defined( 'GLOBAL_REDIS_CONTAINER' ) ) {
	define( 'GLOBAL_REDIS_CONTAINER', 'ee-global-redis' );
}

if ( ! defined( 'EE_SERVICE_DIR' ) ) {
	define( 'EE_SERVICE_DIR', EE_ROOT_DIR . '/services' );
}

if ( ! class_exists( 'EE' ) ) {
	return;
}

$autoload = dirname( __FILE__ ) . '/vendor/autoload.php';
if ( file_exists( $autoload ) ) {
	require_once $autoload;
}

EE::add_command( 'service', 'Service_Command' );
