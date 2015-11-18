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
		global $tinymce_version;

		// page injection
		add_action( 'bbp_theme_after_topic_form_content', array( $this, 'preview' ) );
		add_action( 'bbp_theme_after_reply_form_content', array( $this, 'preview' ) );

		// ajax handlers
		add_action( 'wp_ajax_bbp_live_preview'       , array( $this, 'ajax_callback' ) );
		add_action( 'wp_ajax_nopriv_bbp_live_preview', array( $this, 'ajax_callback' ) );

		// autoembed hacks - uses BuddyPress
		add_action( 'bp_core_setup_oembed',            array( $this, 'autoembed_hacks' ) );

		// tinymce setup
		if ( version_compare( $tinymce_version, '4.0.0' ) < 0 ) {
			add_action( 'bbp_theme_before_reply_form',     array( $this, 'tinymce_setup' ) );
			add_action( 'bbp_theme_before_topic_form',     array( $this, 'tinymce_setup' ) );
		}
	}

	/**
	 * Outputs the AJAX placeholder as well as the accompanying javascript.
	 *
	 */
	public function preview() {
		$this->enqueue_assets();

		echo '
            <div id="bbp-post-preview-wrapper">
                <label for="bbp-post-preview">' . __( 'Preview:', 'bbp-live-preview' ) . '</label>
                <div id="bbp-post-preview"></div>
            </div>
        ';
	}

	/**
	 * Enqueue needed styles and scripts
	 */
	private function enqueue_assets() {
		wp_enqueue_script(
				'bbp-live-preview',
				plugins_url( 'assets/scripts.js', __FILE__ ),
				array( 'jquery' ),
				filemtime( plugin_dir_path( __FILE__ ) . 'assets/scripts.js' )
		);
		wp_localize_script(
				'bbp-live-preview',
				'bbpLivePreviewInfo',
				$this->prepare_scripts_info()
		);

		wp_enqueue_style(
				'bbp-live-preview',
				plugins_url( 'assets/style.css', __FILE__ ),
				array(),
				filemtime( plugin_dir_path( __FILE__ ) .  'assets/style.css' )
		);
	}

	/**
	 * Prepare info needed for JS
	 * @return array
	 */
	private function prepare_scripts_info() {
		global $tinymce_version;
		$tinymce_fourthplus_version = version_compare( $tinymce_version, '4.0.0' ) >= 0;

		$scripts_info = array(
				'formType' => $this->get_form_type(),
				'tinymceFourthPlusVersion' => $tinymce_fourthplus_version ,
				'ajaxUrl' => plugin_dir_url( __FILE__ ) . 'ajax.php'
		);

		return $scripts_info;
	}

	/**
	 * Get form type
	 * @return string
	 */
	private function get_form_type() {
		$type = current_filter();
		if ( strpos( $type, 'topic' ) !== false ) {
			$type = 'topic';
		} else {
			$type = 'reply';
		}

		return $type;
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

		if ( empty( $type ) )
			die();

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

		// Disable GD bbPress attachments plugin from preview
		global $gdbbpress_attachments_front;
		if ( class_exists( 'gdbbAtt_Front' ) && ! empty( $gdbbpress_attachments_front ) && is_a( $gdbbpress_attachments_front, 'gdbbAtt_Front' ) ) {
			remove_filter( "bbp_get_{$type}_content", array( $gdbbpress_attachments_front, 'embed_attachments' ), 100, 2 );
		}

		$content = stripslashes( $_POST['text'] );

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
		$mce['handle_event_callback'] = 'bbp_preview_tinymce_capture';

		return $mce;
	}

	/**
	 * Setup TinyMCE.
	 */
	public function tinymce_setup() {
		add_filter( 'teeny_mce_before_init', array( $this, 'tinymce_callback' ) );
		add_filter( 'tiny_mce_before_init',  array( $this, 'tinymce_callback' ) );
	}

}

add_action( 'bbp_includes', array( 'bbP_Live_Preview', 'init' ) );

endif;
