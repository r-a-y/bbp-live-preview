<?php
/*
Plugin Name: bbP Live Preview
Description: Preview your bbPress forum posts before posting.
Author: r-a-y
Author URI: http://profiles.wordpress.org/r-a-y
Version: 0.1
License: GPLv2 or later
*/

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) exit;

if ( ! class_exists( 'bbP_Live_Preview' ) ) :

class bbP_Live_Preview {
	/**
	 * Init method.
	 */
	public static function init() {
		return new self();
	}

	/**
	 * Constructor.
	 */
	function __construct() {
		// assets
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// page injection
		add_action( 'bbp_theme_after_topic_form_content', array( $this, 'preview' ) );
		add_action( 'bbp_theme_after_reply_form_content', array( $this, 'preview' ) );

		// ajax handlers
		add_action( 'wp_ajax_bbp_live_preview'       , array( $this, 'ajax_callback' ) );
		add_action( 'wp_ajax_nopriv_bbp_live_preview', array( $this, 'ajax_callback' ) );

		// autoembed hacks - uses BuddyPress
		add_action( 'bp_core_setup_oembed',            array( $this, 'autoembed_hacks' ) );

		// tinymce setup
		add_action( 'bbp_theme_before_reply_form', array( $this, 'tinymce_setup' ) );
		add_action( 'bbp_theme_before_topic_form', array( $this, 'tinymce_setup' ) );
	}

	/**
	 * Outputs the HTML preview placeholder.
	 */
	public function preview() {
		$label  = esc_html__( 'Preview:', 'bbp-live-preview' );
		$markup = <<<EOD

	<div id="bbp-post-preview-wrapper">
		<label for="bbp-post-preview">{$label}</label>
		<div id="bbp-post-preview"></div>
	</div>

EOD;
		echo $markup;
	}

	/**
	 * Enqueue needed styles and scripts.
	 */
	public function enqueue_assets() {
		if ( false === $this->get_bbpress_type() ) {
			return;
		}

		/**
		 * Filters the animation when the preview container is shown.
		 *
		 * @param string $animation Should be a jQuery method name such as 'slideDown'. Default: 'show'.
		 */
		$animation = apply_filters( 'bbp_live_preview_animation', 'show' );

		/**
		 * Filters the timeout value when the preview container should be shown.
		 *
		 * @param int $timeout Timeout value in milliseconds. Default: 1500.
		 */
		$timeout = (int) apply_filters( 'bbp_live_preview_timeout', 1500 );

		wp_enqueue_script(
				'bbp-live-preview',
				plugins_url( 'assets/scripts.js', __FILE__ ),
				array( 'jquery' ),
				filemtime( plugin_dir_path( __FILE__ ) . 'assets/scripts.js' )
		);

		wp_localize_script(
				'bbp-live-preview',
				'bbpLivePreviewInfo',
				array(
					'type'      => $this->get_bbpress_type(),
					'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
					'ajaxNonce' => wp_create_nonce( 'bbp-live-preview-nonce' ),
					'animation' => $animation,
					'timeout'   => $timeout
				)
		);

		wp_enqueue_style(
				'bbp-live-preview',
				plugins_url( 'assets/style.css', __FILE__ ),
				array(),
				filemtime( plugin_dir_path( __FILE__ ) .  'assets/style.css' )
		);
	}

	/**
	 * Get bbPress type to use.
	 *
	 * Handles both bbPress and BuddyPress group forums.
	 *
	 * @return string|bool String of the post type we want to use. Boolean false on failure.
	 */
	private function get_bbpress_type() {
		// Check if we're on a topic.
		if ( bbp_is_single_topic() ) {
			return 'reply';

		/*
		 * Topic check for BuddyPress group forums workaround.
		 *
		 * @see https://bbpress.trac.wordpress.org/ticket/2974
		 */
		} elseif ( function_exists( 'bp_is_group' ) && bp_is_group() && 'topic' === bp_action_variable() && bp_action_variable( 1 ) ) {
			return 'reply';
		}

		// Check if we're on a forum.
		if ( bbp_is_single_forum() ) {
			return 'topic';

		// Forum check for BuddyPress group forums.
		} elseif ( function_exists( 'bp_is_group' ) && bp_is_group() && bp_is_current_action( 'forum' ) ) {
			return 'topic';
		}

		return false;
	}

	/**
	 * AJAX callback to output the preview text.
	 *
	 * Runs bbPress' filters before output.
	 *
	 * Autoembed preview is only supported when BuddyPress is installed.
	 */
	public function ajax_callback() {
		$type = $_POST['type'];

		if ( empty( $type ) ) {
			die();
		}

		// Verify nonce.
		check_ajax_referer( 'bbp-live-preview-nonce', 'ajaxNonce' );

		// if autoembeds are allowed and BP exists, allow autoembeds in preview
		//
		// this only works if BP is installed b/c WP's autoembed is too restrictive
		// (relies on an actual WP post).  BP's autoembed doesn't require an actual WP
		// post to be recorded to run autoembeds
		if ( bbp_use_autoembed() && ! empty( $GLOBALS['bp'] ) ) {
			global $wp_embed;

			// remove default bbP autoembed filters /////////////////////////////////
			//
			// newer version of bbP
			remove_filter( 'bbp_get_'. $type . '_content', array( $wp_embed, 'autoembed' ), 2 );

			// older version of bbP
			remove_filter( 'bbp_get_'. $type . '_content', array( $wp_embed, 'autoembed' ), 8 );

			// hack: provide a dummy post ID so embeds will run
			// this is important!
			add_filter( 'embed_post_id', create_function( '', 'return 1;' ) );
		}

		// Remove wp_filter_kses filters from content for capable users
		if ( current_user_can( 'unfiltered_html' ) ) {
			remove_filter( "bbp_new_{$type}_pre_content", 'bbp_encode_bad',  10 );
			remove_filter( "bbp_new_{$type}_pre_content", 'bbp_filter_kses', 30 );
		}

		// We need this here for proper attributes generation
		add_filter( "bbp_new_{$type}_pre_content", 'stripslashes', 100 );

		// Disable GD bbPress attachments plugin from preview
		global $gdbbpress_attachments_front;
		if ( class_exists( 'gdbbAtt_Front' ) && ! empty( $gdbbpress_attachments_front ) && is_a( $gdbbpress_attachments_front, 'gdbbAtt_Front' ) ) {
			remove_filter( "bbp_get_{$type}_content", array( $gdbbpress_attachments_front, 'embed_attachments' ), 100 );
		}

		$content = $_POST['text'];

		// run bbP filters
		$content = apply_filters( "bbp_new_{$type}_pre_content", $content );

		// tinymce requires applying another filter
		if ( true === filter_var( $_POST['tinymce'], FILTER_VALIDATE_BOOLEAN ) ) {
			remove_filter( "bbp_get_form_{$type}_content", 'esc_textarea' );

			$content = apply_filters( "bbp_get_form_{$type}_content", $content );
		}

		$content = apply_filters( "bbp_get_{$type}_content", $content );

		echo $content;
		die;
	}

	/**
	 * Add autoembed filters for bbPress to BuddyPress' Embeds handler.
	 *
	 * Piggyback off of BuddyPress' {@link BP_Embed} class as it is less
	 * restrictive than WordPress' {@link WP_Embed} class.
	 *
	 * Runs on AJAX only.
	 */
	public function autoembed_hacks( $embed ) {
		// if we're not running AJAX, we don't need to do this
		if ( ! defined( 'DOING_AJAX' ) )
			return;

		// make sure bbP allows autoembeds
		if ( bbp_use_autoembed() ) {
			// replies
			add_filter( 'bbp_get_reply_content', array( $embed, 'autoembed' ), 2 );
			add_filter( 'bbp_get_reply_content', array( $embed, 'run_shortcode' ), 1 );

			// topics
			add_filter( 'bbp_get_topic_content', array( $embed, 'autoembed' ), 2 );
			add_filter( 'bbp_get_topic_content', array( $embed, 'run_shortcode' ), 1 );
		}
	}

	/**
	 * Register our JS function with TinyMCE.
	 */
	public function tinymce_callback( $mce ) {
		// Older TinyMCE versions.
		if ( version_compare( $GLOBALS['tinymce_version'], '4.0.0' ) < 0 ) {
			$mce['handle_event_callback'] = 'bbp_preview_tinymce_capture';

		// TinyMCE 4+
		} else {
			$mce['setup'] = 'bbp_preview_tinymce4_capture';
		}

		return $mce;
	}

	/**
	 * Set up TinyMCE.
	 */
	public function tinymce_setup() {
		add_filter( 'teeny_mce_before_init', array( $this, 'tinymce_callback' ) );
		add_filter( 'tiny_mce_before_init',  array( $this, 'tinymce_callback' ) );
	}

}

add_action( 'bbp_includes', array( 'bbP_Live_Preview', 'init' ) );

endif;
