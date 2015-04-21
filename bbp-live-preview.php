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
	 * @todo Move JS and inline CSS to static files. Allow timeout variable to be configured.
	 */
	public function preview() {
		global $tinymce_version;

		$type = current_filter();
		if ( strpos( $type, 'topic' ) !== false ) {
			$type = 'topic';
		} else {
			$type = 'reply';
		}
	?>

		<div id="bbp-post-preview-wrapper" style="clear:both; display:none;margin:1em auto;">
			<label for="bbp-post-preview" style="clear:both; display:block;"><?php _e( 'Preview:', 'bbp-live-preview' ); ?></label>
			<div id="bbp-post-preview" style="border:1px solid #ababab; margin-top:.5em; padding:5px; color:#333;"></div>
		</div>


		<script type="text/javascript">
			var bbp_preview_is_visible = false;
			var bbp_preview_timer      = null;
			var bbp_preview_ajaxurl    = '<?php echo plugin_dir_url( __FILE__ ) . 'ajax.php'; ?>';

			function bbp_preview_post( text, type, tinymce ) {
				tinymce = typeof tinymce !== 'undefined' ? true : false;
				clearTimeout(bbp_preview_timer);
				bbp_preview_timer = setTimeout(function(){
					var post = jQuery.post(
						bbp_preview_ajaxurl,
						{
							action: 'bbp_live_preview',
							'text': text,
							'type': type,
							'tinymce' : tinymce
						}
					);

					post.success( function (data) {
						jQuery("#bbp-post-preview").html(data);
                                                if ( ! bbp_preview_is_visible ) {
                                                        jQuery( '#bbp-post-preview-wrapper' ).show();
                                                        bbp_preview_is_visible = true;
                                                }
					});
				}, 1500);

			}

			// tinymce capture
			<?php if ( version_compare( $tinymce_version, '4.0.0' ) >= 0 ) : ?>
				window.onload = function () {
					tinymce.get('bbp_<?php echo $type; ?>_content').on('keyup',function(e){
						bbp_preview_post( this.getContent(), '<?php echo $type; ?>', true );
					});
				}
			<?php else : ?>
				function bbp_preview_tinymce_capture(e) {
					if ( e.type == 'keyup' ) {
						var id = e.view.frameElement.id.split('_');

						bbp_preview_post( e.target.innerHTML, id[1] );
					}
				}
			<?php endif; ?>

			// regular textarea capture
			jQuery(document).ready( function($) {

				// keyboard input
				$(".wp-editor-container").on("keyup", "#bbp_topic_content, #bbp_reply_content", function() {
					var textarea = $(this);

					bbp_preview_post( textarea.val(), textarea.attr('id').split('_')[1] );

				});


			});
		</script>

	<?php
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
			remove_filter( 'bbp_new_' . $type . '_pre_content', 'bbp_filter_kses' );
		}

		// Disable GD bbPress attachments plugin from preview
		global $gdbbpress_attachments_front;
		if ( class_exists( 'gdbbAtt_Front' ) && ! empty( $gdbbpress_attachments_front ) && is_a( $gdbbpress_attachments_front, 'gdbbAtt_Front' ) ) {
			remove_filter( "bbp_get_{$type}_content", array( $gdbbpress_attachments_front, 'embed_attachments' ), 100, 2 );
		}

		$content = $_POST['text'];

		// tinymce requires applying another filter
		if ( true === filter_var( $_POST['tinymce'], FILTER_VALIDATE_BOOLEAN ) ) {
			$content = apply_filters( "bbp_get_form_{$type}_content", $content );
		}

		// run bbP filters
		$content = apply_filters( 'bbp_new_' . $type . '_pre_content', $content );
		$content = stripslashes( $content );
		$content = apply_filters( 'bbp_get_' . $type . '_content',     $content );

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
