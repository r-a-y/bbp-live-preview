bbP Live Preview
================

A plugin for bbPress that allows users to preview their forum post before submitting.

Works with the fancy editor (TinyMCE) and the the regular textarea.

**Minimum requirements:** WordPress 3.5, bbPress 2.2.4  
**Tested up to:** WordPress 4.7+, bbPress 2.6-bleeding

How to use?
- 
* Make sure bbPress is already activated and installed in WordPress.
* Download, install and activate this plugin.
* Navigate to a forum topic and reply to a post or create a brand-new topic
* In the textarea, start typing and in the "Preview" area below, the container should start mirroring your changes

Caveats
-
* Requires javascript to be enabled
* If autoembeds are enabled in bbPress, autoembed previews will not work without BuddyPress.
 * Why?  Because BuddyPress has a more, flexible implementation of autoembeds than WordPress.
 * Could look into including BP's embed class before public release.

Version
-
0.1 - Pre-release


License
-
GPLv2 or later.