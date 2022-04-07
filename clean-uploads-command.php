<?php

namespace WP_CLI\Wpify;

use WP_CLI;

if ( ! class_exists( '\WP_CLI' ) ) {
	return;
}

$wpify_clean_uploads = __DIR__ . '/vendor/autoload.php';

if ( file_exists( $wpify_clean_uploads ) ) {
	require_once $wpify_clean_uploads;
}

WP_CLI::add_command( 'clean-uploads', CleanUploadsCommand::class );
