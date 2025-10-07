<?php
/**
 * Plugin Name: Guru Bulk Editor Pro
 * Description: Comprehensive bulk editing solution for WooCommerce products with variable product support
 * Version: 2.0.0
 * Author: alireza fatemi
 * Author URI: alirezafatemi.ir
 * Plugin URI: github.com/deveguru
 * Text Domain: wc-bulk-editor
 * Requires at least: 5.8
 * Requires PHP: 7.4
 * WC requires at least: 6.0
 * WC tested up to: 8.3
 */

if (!defined('ABSPATH')) {
    exit;
}

if (!in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {
    return;
}

class WC_Advanced_Bulk_Editor {
    
    private static $instance = null;
    private $version = '2.0.0';
    
    public static function get_instance() {
        if (null === self::$instance) {
            self::$instance = new self();
        }
        return self::$instance;
    }
    
    private function __construct() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('wp_ajax_wc_bulk_load_products', array($this, 'ajax_load_products'));
        add_action('wp_ajax_wc_bulk_save_products', array($this, 'ajax_save_products'));
        add_action('wp_ajax_wc_bulk_load_variations', array($this, 'ajax_load_variations'));
        add_action('wp_ajax_wc_bulk_apply_bulk_action', array($this, 'ajax_apply_bulk_action'));
    }
    
    public function add_admin_menu() {
        add_menu_page(
            'WC Bulk Editor',
            'Guru Bulk Editor',
            'manage_woocommerce',
            'wc-bulk-editor',
            array($this, 'render_admin_page'),
            'dashicons-editor-table',
            56
        );
    }
    
    public function enqueue_admin_scripts($hook) {
        if ('toplevel_page_wc-bulk-editor' !== $hook) {
            return;
        }
        
        wp_enqueue_script('jquery');
        wp_enqueue_script('jquery-ui-dialog');
        wp_enqueue_style('wp-jquery-ui-dialog');
        
        wp_add_inline_script('jquery', $this->get_inline_javascript());
        wp_add_inline_style('wp-admin', $this->get_inline_styles());
        
        wp_localize_script('jquery', 'wc_bulk_editor', array(
            'ajax_url' => admin_url('admin-ajax.php'),
            'nonce' => wp_create_nonce('wc_bulk_editor_nonce')
        ));
    }
    
    public function render_admin_page() {
        ?>
        <div class="wrap wc-bulk-editor-wrap">
            <h1>WooCommerce Advanced Bulk Editor</h1>
            
            <div class="wc-bulk-toolbar">
                <div class="filter-section">
                    <select id="product-type-filter">
                        <option value="">All Product Types</option>
                        <option value="simple">Simple Products</option>
                        <option value="variable">Variable Products</option>
                        <option value="grouped">Grouped Products</option>
                        <option value="external">External Products</option>
                    </select>
                    
                    <select id="category-filter">
                        <option value="">All Categories</option>
                        <?php
                        $categories = get_terms('product_cat', array('hide_empty' => false));
                        foreach ($categories as $category) {
                            echo '<option value="' . esc_attr($category->term_id) . '">' . esc_html($category->name) . '</option>';
                        }
                        ?>
                    </select>
                    
                    <select id="stock-status-filter">
                        <option value="">All Stock Status</option>
                        <option value="instock">In Stock</option>
                        <option value="outofstock">Out of Stock</option>
                        <option value="onbackorder">On Backorder</option>
                    </select>
                    
                    <input type="text" id="search-products" placeholder="Search products...">
                    <button class="button button-primary" onclick="loadProducts()">Load Products</button>
                </div>
                
                <div class="bulk-actions-section">
                    <select id="bulk-action-select">
                        <option value="">Bulk Actions</option>
                        <option value="price-increase">Increase Price by %</option>
                        <option value="price-decrease">Decrease Price by %</option>
                        <option value="set-sale-price">Set Sale Price</option>
                        <option value="update-stock">Update Stock</option>
                        <option value="change-status">Change Status</option>
                        <option value="add-category">Add Category</option>
                        <option value="remove-category">Remove Category</option>
                        <option value="duplicate">Duplicate Products</option>
                    </select>
                    <input type="text" id="bulk-action-value" placeholder="Value">
                    <button class="button" onclick="applyBulkAction()">Apply</button>
                </div>
            </div>
            
            <div class="wc-bulk-editor-container">
                <div id="products-grid" class="products-grid-container">
                    <table class="wp-list-table widefat fixed striped">
                        <thead>
                            <tr>
                                <th width="30"><input type="checkbox" id="select-all"></th>
                                <th width="50">ID</th>
                                <th width="80">Image</th>
                                <th>Product Name</th>
                                <th width="80">Type</th>
                                <th width="100">SKU</th>
                                <th width="80">Regular Price</th>
                                <th width="80">Sale Price</th>
                                <th width="80">Stock</th>
                                <th width="100">Categories</th>
                                <th width="80">Status</th>
                                <th width="100">Actions</th>
                            </tr>
                        </thead>
                        <tbody id="products-list">
                            <tr><td colspan="12" style="text-align:center;">Click "Load Products" to start</td></tr>
                        </tbody>
                    </table>
                </div>
                
                <div id="loading-overlay" style="display:none;">
                    <div class="spinner is-active"></div>
                </div>
            </div>
            
            <div class="wc-bulk-footer">
                <button class="button button-primary button-large" onclick="saveAllChanges()">Save All Changes</button>
                <span id="save-status"></span>
            </div>
            
            <div id="variation-dialog" title="Edit Variations" style="display:none;">
                <div id="variation-content"></div>
            </div>
        </div>
        <?php
    }
    
    public function ajax_load_products() {
        check_ajax_referer('wc_bulk_editor_nonce', 'nonce');
        
        $product_type = sanitize_text_field($_POST['product_type'] ?? '');
        $category = intval($_POST['category'] ?? 0);
        $stock_status = sanitize_text_field($_POST['stock_status'] ?? '');
        $search = sanitize_text_field($_POST['search'] ?? '');
        
        $args = array(
            'post_type' => 'product',
            'posts_per_page' => 100,
            'post_status' => 'any',
            'orderby' => 'ID',
            'order' => 'DESC'
        );
        
        if ($search) {
            $args['s'] = $search;
        }
        
        if ($category) {
            $args['tax_query'] = array(
                array(
                    'taxonomy' => 'product_cat',
                    'field' => 'term_id',
                    'terms' => $category
                )
            );
        }
        
        if ($stock_status) {
            $args['meta_query'] = array(
                array(
                    'key' => '_stock_status',
                    'value' => $stock_status,
                    'compare' => '='
                )
            );
        }
        
        $products = new WP_Query($args);
        $output = array();
        
        if ($products->have_posts()) {
            while ($products->have_posts()) {
                $products->the_post();
                $product_id = get_the_ID();
                $product = wc_get_product($product_id);
                
                if ($product_type && $product->get_type() !== $product_type) {
                    continue;
                }
                
                $categories = wp_get_post_terms($product_id, 'product_cat', array('fields' => 'names'));
                
                $output[] = array(
                    'id' => $product_id,
                    'image' => get_the_post_thumbnail_url($product_id, 'thumbnail'),
                    'name' => get_the_title(),
                    'type' => $product->get_type(),
                    'sku' => $product->get_sku(),
                    'regular_price' => $product->get_regular_price(),
                    'sale_price' => $product->get_sale_price(),
                    'stock' => $product->get_stock_quantity(),
                    'stock_status' => $product->get_stock_status(),
                    'categories' => implode(', ', $categories),
                    'status' => get_post_status(),
                    'is_variable' => $product->is_type('variable')
                );
            }
            wp_reset_postdata();
        }
        
        wp_send_json_success($output);
    }
    
    public function ajax_save_products() {
        check_ajax_referer('wc_bulk_editor_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $products_data = $_POST['products'] ?? array();
        $updated_count = 0;
        
        foreach ($products_data as $product_data) {
            $product_id = intval($product_data['id']);
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            if (isset($product_data['regular_price'])) {
                $product->set_regular_price($product_data['regular_price']);
            }
            
            if (isset($product_data['sale_price'])) {
                $product->set_sale_price($product_data['sale_price']);
            }
            
            if (isset($product_data['sku'])) {
                $product->set_sku($product_data['sku']);
            }
            
            if (isset($product_data['stock']) && $product->managing_stock()) {
                $product->set_stock_quantity($product_data['stock']);
            }
            
            if (isset($product_data['stock_status'])) {
                $product->set_stock_status($product_data['stock_status']);
            }
            
            if (isset($product_data['status'])) {
                wp_update_post(array(
                    'ID' => $product_id,
                    'post_status' => $product_data['status']
                ));
            }
            
            $product->save();
            $updated_count++;
        }
        
        wp_send_json_success(array('updated' => $updated_count));
    }
    
    public function ajax_load_variations() {
        check_ajax_referer('wc_bulk_editor_nonce', 'nonce');
        
        $product_id = intval($_POST['product_id']);
        $product = wc_get_product($product_id);
        
        if (!$product || !$product->is_type('variable')) {
            wp_send_json_error('Invalid product');
        }
        
        $variations = $product->get_available_variations();
        $attributes = $product->get_variation_attributes();
        
        $output = '<div class="variations-editor">';
        $output .= '<h3>Editing variations for: ' . esc_html($product->get_name()) . '</h3>';
        $output .= '<table class="widefat">';
        $output .= '<thead><tr>';
        $output .= '<th>Variation</th>';
        $output .= '<th>SKU</th>';
        $output .= '<th>Regular Price</th>';
        $output .= '<th>Sale Price</th>';
        $output .= '<th>Stock</th>';
        $output .= '<th>Weight</th>';
        $output .= '<th>Enabled</th>';
        $output .= '</tr></thead><tbody>';
        
        foreach ($variations as $variation_data) {
            $variation = wc_get_product($variation_data['variation_id']);
            $attr_string = array();
            
            foreach ($variation_data['attributes'] as $attr_key => $attr_value) {
                if ($attr_value) {
                    $attr_string[] = $attr_value;
                }
            }
            
            $output .= '<tr>';
            $output .= '<td>' . implode(', ', $attr_string) . '</td>';
            $output .= '<td><input type="text" class="var-sku" data-id="' . $variation_data['variation_id'] . '" value="' . esc_attr($variation->get_sku()) . '"></td>';
            $output .= '<td><input type="number" class="var-regular-price" data-id="' . $variation_data['variation_id'] . '" value="' . esc_attr($variation->get_regular_price()) . '" step="0.01"></td>';
            $output .= '<td><input type="number" class="var-sale-price" data-id="' . $variation_data['variation_id'] . '" value="' . esc_attr($variation->get_sale_price()) . '" step="0.01"></td>';
            $output .= '<td><input type="number" class="var-stock" data-id="' . $variation_data['variation_id'] . '" value="' . esc_attr($variation->get_stock_quantity()) . '"></td>';
            $output .= '<td><input type="text" class="var-weight" data-id="' . $variation_data['variation_id'] . '" value="' . esc_attr($variation->get_weight()) . '"></td>';
            $output .= '<td><input type="checkbox" class="var-enabled" data-id="' . $variation_data['variation_id'] . '" ' . ($variation->get_status() === 'publish' ? 'checked' : '') . '></td>';
            $output .= '</tr>';
        }
        
        $output .= '</tbody></table>';
        $output .= '<div class="variation-actions">';
        $output .= '<button class="button button-primary" onclick="saveVariations(' . $product_id . ')">Save Variations</button>';
        $output .= '</div></div>';
        
        wp_send_json_success($output);
    }
    
    public function ajax_apply_bulk_action() {
        check_ajax_referer('wc_bulk_editor_nonce', 'nonce');
        
        if (!current_user_can('manage_woocommerce')) {
            wp_send_json_error('Permission denied');
        }
        
        $action = sanitize_text_field($_POST['action_type']);
        $value = sanitize_text_field($_POST['value']);
        $product_ids = array_map('intval', $_POST['product_ids'] ?? array());
        
        $updated_count = 0;
        
        foreach ($product_ids as $product_id) {
            $product = wc_get_product($product_id);
            
            if (!$product) {
                continue;
            }
            
            switch ($action) {
                case 'price-increase':
                    $current_price = $product->get_regular_price();
                    if ($current_price) {
                        $new_price = $current_price * (1 + ($value / 100));
                        $product->set_regular_price($new_price);
                        
                        if ($product->is_type('variable')) {
                            $variations = $product->get_children();
                            foreach ($variations as $variation_id) {
                                $variation = wc_get_product($variation_id);
                                $var_price = $variation->get_regular_price();
                                if ($var_price) {
                                    $variation->set_regular_price($var_price * (1 + ($value / 100)));
                                    $variation->save();
                                }
                            }
                        }
                    }
                    break;
                    
                case 'price-decrease':
                    $current_price = $product->get_regular_price();
                    if ($current_price) {
                        $new_price = $current_price * (1 - ($value / 100));
                        $product->set_regular_price($new_price);
                        
                        if ($product->is_type('variable')) {
                            $variations = $product->get_children();
                            foreach ($variations as $variation_id) {
                                $variation = wc_get_product($variation_id);
                                $var_price = $variation->get_regular_price();
                                if ($var_price) {
                                    $variation->set_regular_price($var_price * (1 - ($value / 100)));
                                    $variation->save();
                                }
                            }
                        }
                    }
                    break;
                    
                case 'set-sale-price':
                    $product->set_sale_price($value);
                    break;
                    
                case 'update-stock':
                    if ($product->managing_stock()) {
                        $product->set_stock_quantity($value);
                    }
                    break;
                    
                case 'change-status':
                    wp_update_post(array(
                        'ID' => $product_id,
                        'post_status' => $value
                    ));
                    break;
                    
                case 'add-category':
                    wp_set_object_terms($product_id, intval($value), 'product_cat', true);
                    break;
                    
                case 'remove-category':
                    wp_remove_object_terms($product_id, intval($value), 'product_cat');
                    break;
                    
                case 'duplicate':
                    $duplicate = clone $product;
                    $duplicate->set_id(0);
                    $duplicate->set_name($product->get_name() . ' - Copy');
                    $duplicate->set_status('draft');
                    $duplicate->save();
                    break;
            }
            
            $product->save();
            $updated_count++;
        }
        
        wp_send_json_success(array('updated' => $updated_count));
    }
    
    private function get_inline_javascript() {
        return "
        var modifiedProducts = {};
        
        function loadProducts() {
            jQuery('#loading-overlay').show();
            jQuery.post(wc_bulk_editor.ajax_url, {
                action: 'wc_bulk_load_products',
                nonce: wc_bulk_editor.nonce,
                product_type: jQuery('#product-type-filter').val(),
                category: jQuery('#category-filter').val(),
                stock_status: jQuery('#stock-status-filter').val(),
                search: jQuery('#search-products').val()
            }, function(response) {
                jQuery('#loading-overlay').hide();
                if (response.success) {
                    renderProducts(response.data);
                }
            });
        }
        
        function renderProducts(products) {
            var html = '';
            products.forEach(function(product) {
                html += '<tr data-product-id=\"' + product.id + '\">';
                html += '<td><input type=\"checkbox\" class=\"product-select\" value=\"' + product.id + '\"></td>';
                html += '<td>' + product.id + '</td>';
                html += '<td>' + (product.image ? '<img src=\"' + product.image + '\" width=\"50\">' : '-') + '</td>';
                html += '<td><strong>' + product.name + '</strong></td>';
                html += '<td>' + product.type + '</td>';
                html += '<td><input type=\"text\" class=\"edit-sku\" value=\"' + (product.sku || '') + '\" data-id=\"' + product.id + '\"></td>';
                html += '<td><input type=\"number\" class=\"edit-regular-price\" value=\"' + (product.regular_price || '') + '\" step=\"0.01\" data-id=\"' + product.id + '\"></td>';
                html += '<td><input type=\"number\" class=\"edit-sale-price\" value=\"' + (product.sale_price || '') + '\" step=\"0.01\" data-id=\"' + product.id + '\"></td>';
                html += '<td><input type=\"number\" class=\"edit-stock\" value=\"' + (product.stock || '') + '\" data-id=\"' + product.id + '\"></td>';
                html += '<td>' + product.categories + '</td>';
                html += '<td><select class=\"edit-status\" data-id=\"' + product.id + '\">';
                html += '<option value=\"publish\"' + (product.status === 'publish' ? ' selected' : '') + '>Published</option>';
                html += '<option value=\"draft\"' + (product.status === 'draft' ? ' selected' : '') + '>Draft</option>';
                html += '<option value=\"pending\"' + (product.status === 'pending' ? ' selected' : '') + '>Pending</option>';
                html += '<option value=\"private\"' + (product.status === 'private' ? ' selected' : '') + '>Private</option>';
                html += '</select></td>';
                html += '<td>';
                if (product.is_variable) {
                    html += '<button class=\"button button-small\" onclick=\"editVariations(' + product.id + ')\">Edit Variations</button>';
                }
                html += '</td>';
                html += '</tr>';
            });
            jQuery('#products-list').html(html);
            attachEventHandlers();
        }
        
        function attachEventHandlers() {
            jQuery('.edit-regular-price, .edit-sale-price, .edit-stock, .edit-sku, .edit-status').on('change', function() {
                var productId = jQuery(this).data('id');
                if (!modifiedProducts[productId]) {
                    modifiedProducts[productId] = {id: productId};
                }
                
                if (jQuery(this).hasClass('edit-regular-price')) {
                    modifiedProducts[productId].regular_price = jQuery(this).val();
                } else if (jQuery(this).hasClass('edit-sale-price')) {
                    modifiedProducts[productId].sale_price = jQuery(this).val();
                } else if (jQuery(this).hasClass('edit-stock')) {
                    modifiedProducts[productId].stock = jQuery(this).val();
                } else if (jQuery(this).hasClass('edit-sku')) {
                    modifiedProducts[productId].sku = jQuery(this).val();
                } else if (jQuery(this).hasClass('edit-status')) {
                    modifiedProducts[productId].status = jQuery(this).val();
                }
                
                jQuery(this).closest('tr').addClass('modified');
            });
        }
        
        function saveAllChanges() {
            if (Object.keys(modifiedProducts).length === 0) {
                alert('No changes to save');
                return;
            }
            
            jQuery('#loading-overlay').show();
            jQuery('#save-status').text('Saving...');
            
            var productsArray = Object.values(modifiedProducts);
            
            jQuery.post(wc_bulk_editor.ajax_url, {
                action: 'wc_bulk_save_products',
                nonce: wc_bulk_editor.nonce,
                products: productsArray
            }, function(response) {
                jQuery('#loading-overlay').hide();
                if (response.success) {
                    jQuery('#save-status').text('Saved ' + response.data.updated + ' products successfully!');
                    modifiedProducts = {};
                    jQuery('tr.modified').removeClass('modified');
                    setTimeout(function() {
                        jQuery('#save-status').text('');
                    }, 3000);
                }
            });
        }
        
        function editVariations(productId) {
            jQuery('#loading-overlay').show();
            jQuery.post(wc_bulk_editor.ajax_url, {
                action: 'wc_bulk_load_variations',
                nonce: wc_bulk_editor.nonce,
                product_id: productId
            }, function(response) {
                jQuery('#loading-overlay').hide();
                if (response.success) {
                    jQuery('#variation-content').html(response.data);
                    jQuery('#variation-dialog').dialog({
                        width: 800,
                        height: 500,
                        modal: true
                    });
                }
            });
        }
        
        function saveVariations(productId) {
            var variations = [];
            jQuery('.variations-editor tbody tr').each(function() {
                var row = jQuery(this);
                variations.push({
                    id: row.find('.var-sku').data('id'),
                    sku: row.find('.var-sku').val(),
                    regular_price: row.find('.var-regular-price').val(),
                    sale_price: row.find('.var-sale-price').val(),
                    stock: row.find('.var-stock').val(),
                    weight: row.find('.var-weight').val(),
                    enabled: row.find('.var-enabled').is(':checked')
                });
            });
            
            jQuery('#loading-overlay').show();
            jQuery.post(wc_bulk_editor.ajax_url, {
                action: 'wc_bulk_save_products',
                nonce: wc_bulk_editor.nonce,
                products: variations
            }, function(response) {
                jQuery('#loading-overlay').hide();
                if (response.success) {
                    jQuery('#variation-dialog').dialog('close');
                    alert('Variations saved successfully!');
                }
            });
        }
        
        function applyBulkAction() {
            var action = jQuery('#bulk-action-select').val();
            var value = jQuery('#bulk-action-value').val();
            var selectedProducts = [];
            
            jQuery('.product-select:checked').each(function() {
                selectedProducts.push(jQuery(this).val());
            });
            
            if (selectedProducts.length === 0) {
                alert('Please select products first');
                return;
            }
            
            if (!action) {
                alert('Please select an action');
                return;
            }
            
            jQuery('#loading-overlay').show();
            jQuery.post(wc_bulk_editor.ajax_url, {
                action: 'wc_bulk_apply_bulk_action',
                nonce: wc_bulk_editor.nonce,
                action_type: action,
                value: value,
                product_ids: selectedProducts
            }, function(response) {
                jQuery('#loading-overlay').hide();
                if (response.success) {
                    alert('Bulk action applied to ' + response.data.updated + ' products');
                    loadProducts();
                }
            });
        }
        
        jQuery(document).ready(function($) {
            $('#select-all').on('change', function() {
                $('.product-select').prop('checked', $(this).is(':checked'));
            });
            
            $('#search-products').on('keypress', function(e) {
                if (e.which === 13) {
                    loadProducts();
                }
            });
        });
        ";
    }
    
    private function get_inline_styles() {
        return "
        .wc-bulk-editor-wrap {
            background: #fff;
            padding: 20px;
            margin: 20px 20px 20px 0;
            border-radius: 5px;
        }
        .wc-bulk-toolbar {
            background: #f5f5f5;
            padding: 15px;
            margin-bottom: 20px;
            border-radius: 3px;
            display: flex;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
        }
        .filter-section, .bulk-actions-section {
            display: flex;
            gap: 10px;
            align-items: center;
        }
        .filter-section select, .filter-section input {
            min-width: 150px;
        }
        .products-grid-container {
            overflow-x: auto;
            margin-bottom: 20px;
        }
        .products-grid-container table {
            min-width: 1200px;
        }
        .products-grid-container input[type='text'],
        .products-grid-container input[type='number'],
        .products-grid-container select {
            width: 100%;
            padding: 5px;
        }
        .wc-bulk-footer {
            background: #f5f5f5;
            padding: 15px;
            text-align: center;
            border-radius: 3px;
        }
        #loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        #loading-overlay .spinner {
            float: none;
            margin: 0;
        }
        tr.modified {
            background-color: #fff3cd !important;
        }
        .variations-editor {
            padding: 20px;
        }
        .variations-editor table {
            margin-top: 20px;
        }
        .variations-editor input {
            width: 100%;
        }
        .variation-actions {
            margin-top: 20px;
            text-align: right;
        }
        #save-status {
            margin-left: 15px;
            color: #46b450;
            font-weight: bold;
        }
        ";
    }
}

add_action('init', array('WC_Advanced_Bulk_Editor', 'get_instance'));
