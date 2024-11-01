'use strict';

jQuery(document).ready(function() {
    wputh_usermetas_set_media();
    wputh_usermetas_set_roles();
});

/* ----------------------------------------------------------
  Set role
---------------------------------------------------------- */

var wputh_usermetas_set_roles = function() {

    if(typeof wpucapabilities == 'undefined'){
        return;
    }

    var $select_role = jQuery('select[name="role"]');

    if($select_role.length < 1){
        return;
    }

    function set_sections() {

        /* Hide all sections */
        var $sections = jQuery('.wpuusermetas-section');
        $sections.hide();

        /* Get current role */
        var role = $select_role.val();

        /* Display sections which have capacities compatible with this role */
        $sections.each(function() {
            var $this = jQuery(this),
                capability = $this.attr('data-capability');
            if (wpucapabilities[capability].indexOf(role) > -1) {
                $this.show();
            }
        })
    }

    $select_role.on('change', set_sections);
    set_sections();
}

/* ----------------------------------------------------------
  Upload files
---------------------------------------------------------- */

var wpuusermetas_file_frame,
    wpuusermetas_datafor;

var wputh_usermetas_set_media = function() {

    function unset_media($this) {
        var $button = false,
            $preview = false,
            $parent = false;

        wpuusermetas_datafor = $this.data('for');
        $preview = jQuery('#preview-' + wpuusermetas_datafor);
        $parent = $preview.parent();
        $button = $parent.find('.wpuusermetas_add_media');

        // Delete preview HTML
        $preview.html('');

        // Change button text
        $button.html($preview.attr('data-baselabel'));

        // Reset attachment value
        jQuery('#' + wpuusermetas_datafor).attr('value', '');
    }

    jQuery('body').on('click', '.wpu-usermetas-upload-wrap .x', function(e) {
        e.preventDefault();
        unset_media(jQuery(this));
    });

    jQuery('body').on('unset_media', '.wpuusermetas_add_media', function() {
        unset_media(jQuery(this));
    });

    jQuery('body').on('click', '.wpuusermetas_add_media', function(event) {
        event.preventDefault();
        var $this = jQuery(this),
            $for,
            forType;

        wpuusermetas_datafor = $this.data('for');
        $for = jQuery('#' + wpuusermetas_datafor);
        forType = $for.attr('data-fieldtype');

        // If the media frame already exists, reopen it.
        if (wpuusermetas_file_frame) {
            wpuusermetas_file_frame.open();
            return;
        }

        // Create the media frame.
        var media_settings = {
            title: $this.data('uploader_title'),
            button: {
                text: $this.data('uploader_button_text'),
            },
            multiple: false // Set to true to allow multiple files to be selected
        };
        if (forType == 'image') {
            media_settings.library = {
                type: 'image'
            };
        }
        wpuusermetas_file_frame = wp.media.frames.wpuusermetas_file_frame = wp.media(media_settings);

        // When an image is selected, run a callback.
        wpuusermetas_file_frame.on('select', function() {
            // We set multiple to false so only get one image from the uploader
            var attachment = wpuusermetas_file_frame.state().get('selection').first().toJSON(),
                $preview = jQuery('#preview-' + wpuusermetas_datafor);

            if (attachment.type != 'image') {
                return;
            }

            // Set attachment ID
            jQuery('#' + wpuusermetas_datafor).attr('value', attachment.id);

            // Set preview image
            $preview.html('<img class="wpu-usermetas-upload-preview" src="' + attachment.url + '" /><span data-for="' + wpuusermetas_datafor + '" class="x">&times;</span>');

            // Change button label
            $this.html($preview.attr('data-label'));

        });

        // Finally, open the modal
        wpuusermetas_file_frame.open();
    });
};
