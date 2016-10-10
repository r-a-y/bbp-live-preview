var bbp_preview_is_visible = false;
var bbp_preview_timer      = null;

function bbp_preview_post( text, type, tinymce ) {
	tinymce = typeof tinymce !== 'undefined';
	clearTimeout(bbp_preview_timer);

	// @todo Allow timeout variable to be configured.
	bbp_preview_timer = setTimeout(function(){
		var post = jQuery.post(
			bbpLivePreviewInfo.ajaxUrl,
			{
				action: 'bbp_live_preview',
				'text': text,
				'type': type,
				'tinymce' : tinymce,
				'ajaxNonce' : bbpLivePreviewInfo.ajaxNonce
			}
		);

		post.success( function (data) {
			var preview = jQuery('#bbp-post-preview'),
				wrapper = jQuery('#bbp-post-preview-wrapper');

			preview.html(data);
			if ( ! bbp_preview_is_visible ) {
				wrapper.slideDown();

				bbp_preview_is_visible = true;
			}
			preview.removeClass('loading');
			preview.trigger('loaded.bbp_live_preview');
		});
	}, 1500);

}

// tinymce capture
if ( bbpLivePreviewInfo.isTinyMCE4 ) {
	jQuery(document).load(function(){
		var contentSelector = 'bbp_' + bbpLivePreviewInfo.type + '_content';
		tinymce.get(contentSelector).on('keyup', function (e) {
			bbp_preview_post(this.getContent(), bbpLivePreviewInfo.type, true);
		});
	});
} else {
	function bbp_preview_tinymce_capture(e) {
		if (e.type == 'keyup') {
			var id = e.view.frameElement.id.split('_');

			bbp_preview_post(e.target.innerHTML, id[1]);
		}
	}
}

// regular textarea capture
jQuery(document).ready( function($) {
	// override default 'hide' jQuery method to add a trigger
	// used for the quicktags 'link' button
	var _oldhide = $.fn.hide;
	$.fn.hide = function(speed, callback) {
		$(this).trigger('hide');
		return _oldhide.apply(this,arguments);
	};

	// keyboard input
	$(".wp-editor-container").on("keyup", "#bbp_topic_content, #bbp_reply_content", function() {
		var textarea = $(this);
		jQuery("#bbp-post-preview").addClass('loading');
		bbp_preview_post( textarea.val(), textarea.attr('id').split('_')[1] );

	});

	// quicktags toolbar support
	$(".wp-editor-container").on("click", ".quicktags-toolbar", function(e) {
		// do not do anything for 'link' button that's handled below
		if ( 'link' === e.target.value ) {
			return;
		}

		var textarea = $(this).parent().find('textarea');
		jQuery("#bbp-post-preview").addClass('loading');
		bbp_preview_post( textarea.val(), textarea.attr('id').split('_')[1] );
	});

	// quicktags link button
	$(document).on('hide','#wp-link-wrap', function() {
		var textarea, type;
		if ( $('#bbp_topic_title').length ) {
			type = 'topic';

		} else {
			type = 'reply';
		}

		textarea = $( "#bbp_" + type + "_content");
		jQuery("#bbp-post-preview").addClass('loading');
		bbp_preview_post( textarea.val(), type );
	});

});