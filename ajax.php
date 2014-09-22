<?php
/**
 * Lightweight, frontend version of admin-ajax.php.
 *
 * Only loads wp-load.php and sets up the headers and AJAX actions.
 */

// Setup 'DOING_AJAX' constant to be compatible with native WP functionality
define( 'DOING_AJAX', true );

// Load WordPress Bootstrap
require_once( '../../../wp-load.php' );

// Allow for cross-domain requests (from the frontend)
send_origin_headers();

// Require an action parameter
if ( empty( $_REQUEST['action'] ) )
	die( '0' );

// Setup headers
@header( 'Content-Type: text/html; charset=' . get_option( 'blog_charset' ) );
@header( 'X-Robots-Tag: noindex' );

send_nosniff_header();
nocache_headers();

// Setup ajax action hooks
//
// Authenticated actions
if ( is_user_logged_in() ) {
	do_action( 'wp_ajax_' .        $_REQUEST['action'] );

// Non-admin actions
} else {
	do_action( 'wp_ajax_nopriv_' . $_REQUEST['action'] );
}

// Default status
die( '0' );