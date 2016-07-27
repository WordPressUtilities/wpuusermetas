<?php

/*
Plugin Name: WPU User Metas
Plugin URI: http://github.com/Darklg/WPUtilities
Description: Simple admin for user metas
Version: 0.6
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Based On: http://blog.ftwr.co.uk/archives/2009/07/19/adding-extra-user-meta-fields/
*/

class WPUUserMetas {
    private $sections = array();
    private $fields = array();
    private $version = '0.6';

    public function __construct() {

        // Admin init
        if (is_admin()) {
            $this->admin_hooks();
        }
    }

    public function admin_hooks() {
        add_action('show_user_profile', array(&$this,
            'display_form'
        ));
        add_action('edit_user_profile', array(&$this,
            'display_form'
        ));
        add_action('personal_options_update', array(&$this,
            'update_user_meta'
        ));
        add_action('edit_user_profile_update', array(&$this,
            'update_user_meta'
        ));
        // Load assets
        add_action('admin_enqueue_scripts', array(&$this,
            'load_assets'
        ));
    }

    public function load_assets() {
        $screen = get_current_screen();
        if ($screen->base != 'profile') {
            return false;
        }
        wp_enqueue_media();
        wp_enqueue_script('wpuusermetas_scripts', plugins_url('/assets/global.js', __FILE__), array(), $this->version);
        wp_enqueue_style('wpuusermetas_style', plugins_url('assets/style.css', __FILE__));
    }

    /* Datas */

    public function get_datas() {
        $fields = apply_filters('wpu_usermetas_fields', array());
        $this->fields = array();
        foreach ($fields as $id_field => $field) {
            $id_field = str_replace('-', '', $id_field);
            if (!isset($field['name']) || empty($field['name'])) {
                $field['name'] = $id_field;
            }
            if (!isset($field['type']) || empty($field['type'])) {
                $field['type'] = 'text';
            }
            if (!isset($field['datas']) || !is_array($field['datas']) || empty($field['datas'])) {
                $field['datas'] = array(0, 1);
            }
            $this->fields[$id_field] = $field;
        }
        $this->sections = apply_filters('wpu_usermetas_sections', array());
    }

    public function get_section_fields($section_id) {
        $fields = array();
        foreach ($this->fields as $id_field => $field) {
            if (isset($field['section']) && $field['section'] == $section_id) {
                $fields[$id_field] = $field;
            }
        }
        return $fields;
    }

    /* Update */

    public function update_user_meta($user_id) {
        if (!isset($_POST['nonce_form-usermetas']) || !wp_verify_nonce($_POST['nonce_form-usermetas'], 'form-usermetas-' . $user_id)) {
            echo __('Sorry, your nonce did not verify.');
            exit;
        }
        $this->get_datas();
        foreach ($this->fields as $id_field => $field) {
            if (isset($_POST[$id_field])) {
                update_user_meta($user_id, $id_field, $this->validate_value($field, $_POST[$id_field]));
            }
        }
    }

    /* Validate */

    public function validate_value($field, $posted_value) {
        $new_value = '';
        switch ($field['type']) {
        case 'attachment':
            $new_value = !is_numeric($posted_value) ? false : $posted_value;
            break;
        case 'editor':
            $new_value = $posted_value;
            break;
        case 'email':
            $new_value = filter_var($posted_value, FILTER_VALIDATE_EMAIL) ? $posted_value : '';
            break;
        case 'url':
            $new_value = filter_var($posted_value, FILTER_VALIDATE_URL) ? $posted_value : '';
            break;
        case 'select':
            $data_keys = array_keys($field['datas']);
            $new_value = $data_keys[0];
            if (array_key_exists($posted_value, $field['datas'])) {
                $new_value = $posted_value;
            }

            break;
        default:
            $new_value = esc_attr($posted_value);
        }
        return $new_value;
    }

    /* Display */

    public function display_form($user) {
        $this->get_datas();
        wp_nonce_field('form-usermetas-' . $user->ID, 'nonce_form-usermetas');
        foreach ($this->sections as $id => $section) {
            echo $this->display_section($user, $id, $section['name']);
        }
    }

    public function display_section($user, $id, $name) {
        $content = '';
        $fields = $this->get_section_fields($id);
        if (!empty($fields)) {
            $content .= '<h3>' . $name . '</h3>';
            $content .= '<table class="form-table">';
            foreach ($fields as $id_field => $field) {
                $content .= $this->display_field($user, $id_field, $field);
            }
            $content .= '</table>';
        }
        return $content;
    }

    public function display_field($user, $id_field, $field) {

        // Set vars
        $idname = ' id="' . $id_field . '" name="' . $id_field . '" placeholder="' . esc_attr($field['name']) . '" ';
        $value = get_the_author_meta($id_field, $user->ID);
        $content = '';

        // Add a row by field
        $content .= '<tr>';
        $content .= '<th><label for="' . $id_field . '">' . $field['name'] . '</label></th>';
        $content .= '<td>';
        switch ($field['type']) {
        case 'attachment':
            $img = '';
            $btn_label = __('Add a picture', 'wpuusermetas');
            $btn_base_label = $btn_label;
            $btn_edit_label = __('Change this picture', 'wpuusermetas');
            if (is_numeric($value)) {
                $image = wp_get_attachment_image_src($value, 'big');
                if (isset($image[0])) {
                    $img = '<img class="wpu-usermetas-upload-preview" src="' . $image[0] . '" alt="" /><span data-for="' . $id_field . '" class="x">&times;</span>';
                    $btn_label = $btn_edit_label;
                }
            }
            $content .= '<div data-baselabel="' . esc_attr($btn_base_label) . '" data-label="' . esc_attr($btn_edit_label) . '" class="wpu-usermetas-upload-wrap" id="preview-' . $id_field . '">' . $img . '</div>';
            $content .= '<a href="#" data-for="' . $id_field . '" class="button button-small wpuusermetas_add_media">' . $btn_label . '</a>';
            $content .= '<input type="hidden" ' . $idname . ' value="' . $value . '" />';
            break;
        case 'editor':
            ob_start();
            wp_editor($value, $id_field);
            $content .= ob_get_clean();
            break;

        case 'textarea':
            $content .= '<textarea rows="5" cols="30" ' . $idname . '>' . esc_attr($value) . '</textarea>';
            break;

        case 'select':
            $content .= '<select ' . $idname . '>';
            foreach ($field['datas'] as $val => $label) {
                $content .= '<option value="' . $val . '" ' . ($val == $value ? 'selected="selected"' : '') . '>' . strip_tags($label) . '</option>';
            }
            $content .= '</select>';
            break;

        case 'email':
        case 'url':
            $content .= '<input type="' . $field['type'] . '" ' . $idname . ' value="' . esc_attr($value) . '" />';
            break;

        default:
            $content .= '<input type="text" ' . $idname . ' value="' . esc_attr($value) . '" />';
        }
        if (isset($field['description'])) {
            $content .= '<br /><span class="description">' . esc_attr($field['description']) . '</span>';
        }

        $content .= '</td>';
        $content .= '</tr>';
        return $content;
    }
}

new WPUUserMetas();
