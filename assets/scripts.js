var bbp_preview_is_visible = false;
var bbp_preview_timer      = null;
var bbp_preview_ajaxurl    = bbpLivePreviewInfo.ajaxUrl;

function bbp_preview_post( text, type, tinymce ) {
    tinymce = typeof tinymce !== 'undefined';
    clearTimeout(bbp_preview_timer);

    // @todo Allow timeout variable to be configured.
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
            jQuery("#bbp-post-preview").removeClass('loading');
        });
    }, 1500);

}

// tinymce capture
if ( bbpLivePreviewInfo.tinymceFourthPlusVersion ) {
    jQuery(document).load(function(){
        var formType = bbpLivePreviewInfo.formType;
        var contentSelector = 'bbp_' + formType + '_content';
        tinymce.get(contentSelector).on('keyup', function (e) {
            bbp_preview_post(this.getContent(), formType, true);
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