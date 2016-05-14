<?php

/*
Plugin Name: WPU User Metas
Plugin URI: http://github.com/Darklg/WPUtilities
Description: Simple admin for user metas
Version: 0.4.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Based On: http://blog.ftwr.co.uk/archives/2009/07/19/adding-extra-user-meta-fields/
*/

class WPUUserMetas {
    private $sections = array();
    private $fields = array();

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
            $new_value = '';
            if (isset($_POST[$id_field])) {
                $posted_value = $_POST[$id_field];
                switch ($field['type']) {
                case 'editor':
                    $new_value = $posted_value;
                    break;

                default:
                    $new_value = esc_attr($posted_value);
                }
                update_usermeta($user_id, $id_field, $new_value);
            }
        }
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
        $label = $field['name'];
        $type = $field['type'];
        $datas = array(
            0,
            1
        );
        $idname = ' id="' . $id_field . '" name="' . $id_field . '" placeholder="' . $label . '" ';
        $value = get_the_author_meta($id_field, $user->ID);
        $content = '';

        if (isset($field['datas']) && is_array($field['datas'])) {
            $datas = $field['datas'];
        }

        // Add a row by field
        $content .= '<tr>';
        $content .= '<th><label for="' . $id_field . '">' . $label . '</label></th>';
        $content .= '<td>';
        switch ($type) {
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
            foreach ($datas as $val => $label) {
                $content .= '<option value="' . $val . '" ' . ($val == $value ? 'selected="selected"' : '') . '>' . strip_tags($label) . '</option>';
            }
            $content .= '</select>';
            break;

        case 'email':
        case 'url':
            $content .= '<input type="' . $type . '" ' . $idname . ' value="' . esc_attr($value) . '" />';
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
