<?php

/*
Plugin Name: WPU User Metas
Plugin URI: http://github.com/Darklg/WPUtilities
Description: Simple admin for user metas
Version: 0.23.1
Author: Darklg
Author URI: http://darklg.me/
License: MIT License
License URI: http://opensource.org/licenses/MIT
Based On: http://blog.ftwr.co.uk/archives/2009/07/19/adding-extra-user-meta-fields/
*/

class WPUUserMetas {
    private $sections = array();
    private $fields = array();
    private $version = '0.23.1';
    private $register_form_hook__name = 'woocommerce_register_form';

    public function __construct() {

        add_action('plugins_loaded', array(&$this,
            'plugins_loaded'
        ));

    }

    public function plugins_loaded() {
        load_plugin_textdomain('wpuusermetas', false, dirname(plugin_basename(__FILE__)) . '/lang/');

        include dirname(__FILE__) . '/inc/WPUBaseUpdate/WPUBaseUpdate.php';
        $this->settings_update = new \wpuusermetas\WPUBaseUpdate(
            'WordPressUtilities',
            'wpuusermetas',
            $this->version);

        // Admin init
        if (is_admin()) {
            $this->admin_hooks();
        }

        $this->fields = $this->get_datas();
        $hooks_user_editable = array('woocommerce_edit_account_form' => 'woocommerce_edit_account_form');
        foreach ($this->fields as $field) {
            if (isset($field['user_editable_hook'])) {
                $hooks_user_editable[$field['user_editable_hook']] = $field['user_editable_hook'];
            }
        }

        /* Account */
        foreach ($hooks_user_editable as $hook_user_editable) {
            add_action($hook_user_editable, array(&$this,
                'woocommerce_edit_account_form'
            ));
        }
        add_action('woocommerce_save_account_details', array(&$this,
            'woocommerce_save_account_details'
        ), 50, 1);

        /* Checkout */
        add_filter('woocommerce_checkout_fields', array(&$this, 'woocommerce_checkout_fields'), 10, 1);
        add_action('woocommerce_checkout_update_order_meta', array(&$this, 'checkout_update_order_meta'));

        /* Register */
        $register_form_hook__priority = apply_filters('wpuusermetas_register_form_hook__priority', 10);
        $this->register_form_hook__name = apply_filters('wpuusermetas_register_form_hook__name', $this->register_form_hook__name);
        $register_hooks = array(
            $this->register_form_hook__name,
            'woocommerce_register_form',
            'woocommerce_register_form_start',
            'woocommerce_register_form_end'
        );
        $register_hooks = array_unique($register_hooks);
        foreach ($register_hooks as $hook_name) {
            add_action($hook_name, array(&$this,
                'woocommerce_register_form'
            ), $register_form_hook__priority, 1);
        }
        add_action('woocommerce_created_customer', array(&$this,
            'woocommerce_created_customer'
        ), 10, 1);
        add_action('woocommerce_register_post', array(&$this, 'woocommerce_register_post'), 10, 3);
    }

    public function woocommerce_register_form() {
        $current_hook = current_action();
        foreach ($this->sections as $id => $section) {
            $fields = $this->get_section_fields($id);
            foreach ($fields as $id_field => $field) {
                /* Not editable in register form */
                if (!isset($field['register_editable']) || !$field['register_editable']) {
                    continue;
                }
                /* Not on default hook */
                if (!isset($field['register_editable_hook']) && $current_hook != $this->register_form_hook__name) {
                    continue;
                }
                /* Custom hook but not correct target */
                if (isset($field['register_editable_hook']) && $current_hook != $field['register_editable_hook']) {
                    continue;
                }
                echo $this->display_field(false, $id_field, $field, true);
            }
        }
    }

    public function woocommerce_register_post($username, $email, $validation_errors) {
        foreach ($this->fields as $id_field => $field) {
            if (!isset($field['register_editable']) || !$field['register_editable'] || !isset($field['required']) || !$field['required']) {
                continue;
            }
            if (!isset($_POST[$id_field]) || empty($_POST[$id_field])) {
                $validation_errors->add($id_field . '_error', sprintf(__('The field "%s" is required!', 'wpuusermetas'), $field['name']));
            }
        }
        return $validation_errors;
    }

    public function woocommerce_created_customer($user_id) {
        $this->update_from_post($user_id);
    }

    public function woocommerce_checkout_fields($checkout_fields) {
        $checkout_fields_types = array();
        foreach ($this->fields as $id => $field) {
            if (!$field['checkout_editable']) {
                continue;
            }
            $checkout_fields['account']['account_' . $id] = $field;
            $checkout_fields_types[$field['type']] = $field['type'];
        }

        /* Override display in checkout fields */
        foreach ($checkout_fields_types as $field_type) {
            add_filter('woocommerce_form_field_' . $field_type, array(&$this, 'custom_checkout_form_field'), 10, 4);
        }

        return $checkout_fields;
    }

    public function custom_checkout_form_field($field, $key, $args, $value) {
        $new_key = str_replace('account_', '', $key);
        if (array_key_exists($new_key, $this->fields) && $this->fields[$new_key]['checkout_editable']) {
            $field_details = $this->fields[$new_key];
            if (isset($field_details['default_value'])) {
                $field_details['value'] = $field_details['default_value'];
            }
            $field = $this->display_field(false, $new_key, $field_details, true, 'account_');
        }
        return $field;
    }

    public function checkout_update_order_meta($order_id) {
        $order = wc_get_order($order_id);
        $customer_id = $order->get_user_id();
        $this->update_from_post($customer_id, 'account_');
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
        add_action('pre_user_query', array(&$this,
            'user_extended_search'
        ));
        add_action('pre_user_query', array(&$this,
            'user_extended_filter'
        ));

        // Load assets
        add_action('admin_enqueue_scripts', array(&$this,
            'load_assets'
        ));

        // Columns
        add_filter('manage_users_columns', array(&$this,
            'modify_user_table'
        ), 10, 1);
        add_filter('manage_users_custom_column', array(&$this,
            'modify_user_table_row'
        ), 10, 3);
        add_filter('manage_users_sortable_columns', array(&$this,
            'sortable_columns'
        ), 10, 1);
        add_action('pre_get_users', array(&$this,
            'sort_columns'
        ), 10, 1);

    }

    public function load_assets() {
        $screen = get_current_screen();
        if ($screen->base != 'profile' && $screen->base != 'user-edit') {
            return false;
        }
        wp_enqueue_media();
        wp_enqueue_script('wpuusermetas_scripts', plugins_url('/assets/global.js', __FILE__), array(), $this->version);
        wp_enqueue_style('wpuusermetas_style', plugins_url('assets/style.css', __FILE__));
    }

    /* Datas */

    public function get_datas($user_id = false) {
        $fields = apply_filters('wpu_usermetas_fields', array());
        $this->sections = $this->get_sections();
        $this->fields = array();
        foreach ($fields as $id_field => $field) {
            $id_field = str_replace('-', '', $id_field);
            if (!isset($field['name']) || empty($field['name'])) {
                $field['name'] = $id_field;
            }
            if (!isset($field['label']) || empty($field['label'])) {
                $field['label'] = $field['name'];
            }
            if (!isset($field['type']) || empty($field['type'])) {
                $field['type'] = 'text';
            }
            if (!isset($field['required'])) {
                $field['required'] = false;
            }
            if (!isset($field['taxonomy_type'])) {
                $field['taxonomy_type'] = 'category';
            }
            if (!isset($field['post_type'])) {
                $field['post_type'] = 'post';
            }
            if (!isset($field['checkout_editable'])) {
                $field['checkout_editable'] = false;
            }
            /* Admin column */
            if (!isset($field['admin_column'])) {
                $field['admin_column'] = false;
            }
            if (!isset($field['admin_searchable'])) {
                $field['admin_searchable'] = false;
            } else {
                if ($field['admin_searchable']) {
                    $field['admin_column'] = true;
                }
            }
            if (!isset($field['admin_filterable'])) {
                $field['admin_filterable'] = false;
            } else {
                if ($field['admin_filterable']) {
                    $field['admin_column'] = true;
                }
            }
            if (!isset($field['admin_column_sortable'])) {
                $field['admin_column_sortable'] = false;
            } else {
                if ($field['admin_column_sortable']) {
                    $field['admin_column'] = true;
                }
            }
            if (!isset($field['section']) || empty($field['section'])) {
                $field['section'] = 'default';
            }
            if (!isset($field['datas']) || !is_array($field['datas']) || empty($field['datas'])) {
                $field['datas'] = array(__('No'), __('Yes'));
            }
            if (is_numeric($user_id)) {
                /* Store value */
                $field['value'] = get_user_meta($user_id, $id_field, 1);
                if (isset($this->sections[$field['section']]) && !user_can($user_id, $this->sections[$field['section']]['capability'])) {
                    continue;
                } else {
                    $field['capability'] = $this->sections[$field['section']]['capability'];
                }
            }

            $this->fields[$id_field] = $field;
        }

        return $this->fields;
    }

    public function get_sections() {
        $sections = apply_filters('wpu_usermetas_sections', array());
        if (!isset($sections['default'])) {
            $sections['default'] = array(
                'name' => __('Metas', 'wpuusermetas')
            );
        }
        foreach ($sections as $id => $section) {
            if (!isset($section['name'])) {
                $section['name'] = $id;
            }
            $sections[$id]['name'] = esc_html(trim($section['name']));
            if (!isset($section['description'])) {
                $section['description'] = '';
            }
            if (!isset($section['capability'])) {
                $sections[$id]['capability'] = 'read';
            }
            $sections[$id]['description'] = esc_html(trim($section['description']));
        }
        return $sections;
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

    public function woocommerce_save_account_details($user_id) {
        if (!isset($_POST['nonce_form-usermetas']) || !wp_verify_nonce($_POST['nonce_form-usermetas'], 'form-usermetas-' . $user_id)) {
            echo __('Sorry, your nonce did not verify.', 'wpuusermetas');
            exit;
        }
        $this->update_from_post($user_id);
    }

    public function update_user_meta($user_id) {
        if (!isset($_POST['nonce_form-usermetas']) || !wp_verify_nonce($_POST['nonce_form-usermetas'], 'form-usermetas-' . $user_id)) {
            echo __('Sorry, your nonce did not verify.', 'wpuusermetas');
            exit;
        }
        $this->update_from_post($user_id);
    }

    public function update_from_post($user_id, $prefix = '') {
        $this->get_datas($user_id);
        foreach ($this->fields as $id_field => $field) {
            if (!user_can($user_id, $field['capability'])) {
                continue;
            }
            $old_value = get_user_meta($user_id, $id_field, 1);
            $value = false;
            if ($field['type'] == 'checkbox') {
                $value = isset($_POST[$prefix . $id_field]) ? '1' : '0';
            } elseif (isset($_POST[$prefix . $id_field])) {
                $value = $this->validate_value($field, $_POST[$prefix . $id_field]);
            }
            if ($value !== false) {
                update_user_meta($user_id, $id_field, $value);
                do_action('wpuusermetas_update_user_meta', $user_id, $id_field, $value, $old_value);
                do_action('wpuusermetas_update_user_meta__' . $id_field, $user_id, $value, $old_value);
            }
        }
    }

    /* Validate */

    public function validate_value($field, $posted_value) {
        $new_value = isset($field['value']) ? $field['value'] : '';
        switch ($field['type']) {
        case 'image':
            if (is_numeric($posted_value)) {
                $img_value = wp_get_attachment_image_src($posted_value);
                if (isset($img_value[0])) {
                    $new_value = $posted_value;
                }
            }
            break;
        case 'post':
        case 'taxonomy':
        case 'image':
        case 'attachment':
            $new_value = !is_numeric($posted_value) ? false : $posted_value;
            break;
        case 'editor':
            $new_value = $posted_value;
            break;
        case 'email':
            $new_value = filter_var($posted_value, FILTER_VALIDATE_EMAIL) ? $posted_value : '';
            break;
        case 'number':
            $new_value = is_numeric($posted_value) ? $posted_value : '';
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

    public function woocommerce_edit_account_form() {
        $current_filter = current_filter();
        $default_filter = ($current_filter == 'woocommerce_edit_account_form');
        $user = wp_get_current_user();
        $this->get_datas($user->ID);
        /* Nonce on one only */
        if ($default_filter) {
            wp_nonce_field('form-usermetas-' . $user->ID, 'nonce_form-usermetas');
        }
        foreach ($this->sections as $id => $section) {
            if (!user_can($user, $section['capability'])) {
                continue;
            }
            $fields = $this->get_section_fields($id);
            foreach ($fields as $id_field => $field) {
                if (!isset($field['user_editable']) || !$field['user_editable']) {
                    continue;
                }
                if (!isset($field['user_editable_hook'])) {
                    $field['user_editable_hook'] = $default_filter;
                }
                if ($field['user_editable_hook'] == $current_filter) {
                    echo $this->display_field($user, $id_field, $field, true);
                }
            }
        }
    }

    public function display_form($user) {
        $this->get_datas();
        wp_nonce_field('form-usermetas-' . $user->ID, 'nonce_form-usermetas');
        foreach ($this->sections as $id => $section) {
            if (!user_can($user, $section['capability'])) {
                continue;
            }
            echo $this->display_section($user, $id, $section);
        }
    }

    public function display_section($user, $id, $section) {
        $content = '';
        $fields = $this->get_section_fields($id);
        if (!empty($fields)) {
            $content .= '<h3>' . $section['name'] . '</h3>';
            if (!empty($section['description'])) {
                $content .= wpautop(trim($section['description']));
            }
            $content .= '<table class="form-table">';
            foreach ($fields as $id_field => $field) {
                $content .= $this->display_field($user, $id_field, $field);
            }
            $content .= '</table>';
        }
        return $content;
    }

    public function display_field($user, $id_field, $field, $user_editable = false, $prefix = '') {

        // Set vars
        $idname = ' id="' . $prefix . $id_field . '" name="' . $prefix . $id_field . '" placeholder="' . esc_attr($field['name']) . '" ';
        $value = isset($field['value']) ? $field['value'] : '';
        if (is_object($user) && !$value) {
            $value = get_the_author_meta($id_field, $user->ID);
        }
        $content = '';

        $label_html = '<label for="' . $prefix . $id_field . '">' . $field['name'] . ($field['required'] ? ' <span class="required">*</span>' : '') . '</label>';
        $input_class = $user_editable ? 'class="' . apply_filters('wpuusermetas_public_field_input_classname', 'woocommerce-Input woocommerce-Input--email input-text', $user, $id_field) . '"' : '';

        $before_label_html = $field['type'] == 'checkbox' ? '<p class="woocommerce-form-row form-row">' : '<p class="woocommerce-form-row woocommerce-form-row--wide form-row form-row-wide">';

        // Add a row by field
        if ($user_editable) {
            $content .= apply_filters('wpuusermetas_public_field_before_label_html', $before_label_html, $user, $id_field);
            $content .= $field['type'] == 'checkbox' ? '' : $label_html;
            $content .= apply_filters('wpuusermetas_public_field_after_label_html', '', $user, $id_field);
        } else {
            $content .= '<tr>';
            $content .= '<th>' . $label_html . '</th>';
            $content .= '<td>';
        }
        switch ($field['type']) {
        case 'attachment':
        case 'image':
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
            $content .= '<input data-fieldtype="' . $field['type'] . '" type="hidden" ' . $idname . ' value="' . $value . '" />';
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

        case 'checkbox':
            if ($user_editable) {
                $label_check = $field['label_checkbox'];
                if (empty($label_check)) {
                    $label_check = $field['label_checkbox'];
                }
                $content .= '<label class="woocommerce-form__label woocommerce-form__label-for-checkbox inline">';
                $content .= '<input class="woocommerce-form__input woocommerce-form__input-checkbox" type="checkbox" ' . $idname . ' value="' . esc_attr($value) . '" ' . ($value == '1' ? 'checked="checked"' : '') . ' />';
                $content .= '<span>' . $label_check . '</span>';
                $content .= '</label>';
            } else {
                $content .= '<input ' . $input_class . ' type="checkbox" ' . $idname . ' value="' . esc_attr($value) . '" ' . ($value == '1' ? 'checked="checked"' : '') . ' />';
                if (isset($field['label_checkbox'])) {
                    $content .= '<label for="' . $prefix . $id_field . '">' . esc_html($field['label_checkbox']) . '</label>';
                }
            }
            break;

        case 'post':
            $lastposts = get_posts(array(
                'posts_per_page' => 100,
                'order' => 'ASC',
                'orderby' => 'title',
                'post_type' => $field['post_type']
            ));
            if (!empty($lastposts)) {
                $content .= '<select ' . $idname . '>';
                if ($field['required']) {
                    $content .= '<option value="" disabled selected style="display:none;">' . __('Select a value', 'wputaxometas') . '</option>';
                } else {
                    $content .= '<option value="0">' . __('Select a value', 'wputaxometas') . '</option>';
                }
                foreach ($lastposts as $post) {
                    $content .= '<option value="' . $post->ID . '" ' . ($post->ID == $value ? 'selected="selected"' : '') . '>' . $post->post_title . '</option>';
                }
                $content .= '</select>';
            }
            break;

        case 'taxonomy':
            $allterms = get_terms(array(
                'taxonomy' => $field['taxonomy_type'],
                'hide_empty' => false,
                'orderby' => 'name'
            ));
            if (!empty($allterms)) {
                $content .= '<select ' . $idname . '>';
                if ($field['required']) {
                    $content .= '<option value="" disabled selected style="display:none;">' . __('Select a value', 'wputaxometas') . '</option>';
                } else {
                    $content .= '<option value="0">' . __('Select a value', 'wputaxometas') . '</option>';
                }
                foreach ($allterms as $term) {
                    $content .= '<option value="' . $term->term_id . '" ' . ($term->term_id == $value ? 'selected="selected"' : '') . '>' . $term->name . '</option>';
                }
                $content .= '</select>';
            }

            break;

        case 'number':
        case 'email':
        case 'url':
            $content .= '<input ' . $input_class . ' type="' . $field['type'] . '" ' . $idname . ' value="' . esc_attr($value) . '" />';
            break;

        default:
            $content .= '<input ' . $input_class . ' type="text" ' . $idname . ' value="' . esc_attr($value) . '" />';
        }
        if (isset($field['description'])) {
            $content .= '<br /><span class="description">' . esc_attr($field['description']) . '</span>';
        }

        if ($user_editable) {
            $content .= apply_filters('wpuusermetas_public_field_after_input_html', '</p>', $user, $id_field);
        } else {
            $content .= '</td>';
            $content .= '</tr>';
        }
        return $content;
    }

    /* ----------------------------------------------------------
      Search
    ---------------------------------------------------------- */

    public function user_extended_search($q) {
        global $wpdb;

        /* Avoid special cases */
        if (!is_admin() || strpos($q->query_where, '@') !== false || empty($_GET["s"])) {
            return;
        }

        /* Choose searchable fields */
        $search_fields = array();
        foreach ($this->fields as $id => $field) {
            if (!$field['admin_searchable']) {
                continue;
            }
            $search_fields[] = $id;
        }

        if (empty($search_fields)) {
            return;
        }

        // Extend query
        $user_with_meta = $wpdb->get_col("SELECT DISTINCT user_id FROM $wpdb->usermeta WHERE (meta_key IN('" . implode(',', esc_sql($search_fields)) . "')) AND LOWER(meta_value) LIKE '%" . esc_sql($_GET["s"]) . "%'");
        $id_string = implode(",", $user_with_meta);
        if (!empty($id_string)) {
            $q->query_where = str_replace("user_login LIKE", "ID IN(" . $id_string . ") OR user_login LIKE", $q->query_where);
        }
    }

    /* ----------------------------------------------------------
      Filter
    ---------------------------------------------------------- */

    public function user_extended_filter($q) {
        global $wpdb;

        /* Avoid special cases */
        if (!is_admin()) {
            return;
        }

        if (!isset($_GET['meta_key']) || !isset($_GET['meta_value'])) {
            return;
        }

        /* Choose searchable fields */
        $search_fields = array();
        foreach ($this->fields as $field_id => $field) {
            if (!$field['admin_filterable']) {
                continue;
            }
            if ($field_id == $_GET['meta_key']) {
                $user_with_meta = $wpdb->get_col($wpdb->prepare("SELECT DISTINCT user_id FROM $wpdb->usermeta WHERE meta_key IN(%s) AND meta_value = %s", $field_id, $_GET['meta_value']));
                if (!empty($user_with_meta)) {
                    $q->query_where .= ' AND ID IN(' . implode(",", $user_with_meta) . ')';
                }
            }
        }
    }

    /* ----------------------------------------------------------
      Sort columns
    ---------------------------------------------------------- */

    public function modify_user_table($column) {
        foreach ($this->fields as $id => $field) {
            if (!$field['admin_column']) {
                continue;
            }
            $column[$id] = $field['name'];
        }
        return $column;
    }

    public function modify_user_table_row($val, $column_name, $user_id) {
        foreach ($this->fields as $id => $field) {
            if (!$field['admin_column']) {
                continue;
            }
            if ($column_name == $id) {
                return $this->get_user_data($user_id, $id, $field);
            }
        }
        return $val;
    }

    public function sortable_columns($columns) {
        foreach ($this->fields as $id => $field) {
            if (!$field['admin_column_sortable']) {
                continue;
            }
            $columns[$id] = $id;
        }
        return $columns;
    }

    public function sort_columns($query) {
        if (!is_admin()) {
            return;
        }
        foreach ($this->fields as $id => $field) {
            if (!$field['admin_column_sortable']) {
                continue;
            }
            if ($query->get('orderby') == $id) {
                $query->set('orderby', in_array($field['type'], array('number', 'post', 'taxonomy', 'attachment', 'image')) ? 'meta_value_num' : 'meta_value');
                $query->set('meta_key', $id);
            }
        }
    }

    public function get_user_data($user_id, $field_id, $field) {
        $value = get_user_meta($user_id, $field_id, 1);
        $raw_value = $value;
        if ($field['type'] == 'post') {
            $tmp_value = get_the_title($value);
            $value = $tmp_value ? $tmp_value : $value;
        }
        if ($field['type'] == 'taxonomy') {
            $tmp_value = get_term_by('id', $value, $field['taxonomy_type']);
            if (!is_wp_error($tmp_value) && $tmp_value->name) {
                $value = $tmp_value->name;
            }
        }
        if ($field['type'] == 'attachment') {
            $tmp_value = wp_get_attachment_image_src($value, 'thumbnail');
            if (is_array($tmp_value) && isset($tmp_value[0])) {
                $value = '<img height="50" width="50" src="' . $tmp_value[0] . '" alt="" />';
            }
        }
        if ($value && $field['admin_filterable']) {
            $value = '<a href="' . admin_url('users.php?meta_key=' . urlencode($field_id) . '&meta_value=' . urlencode($raw_value)) . '">' . $value . '</a>';
        }
        return $value;
    }

}

new WPUUserMetas();
