<?php
/**
 * Plugin Name: Pulse Commissions
 * Plugin URI: https://github.com/pardus79/pulse-commissions
 * Description: Automated item-specific commissions for WooCommerce using Bitcoin Lightning Network and BTCPayServer
 * Version: 0.1.0
 * Author: BtcPins
 * Author URI: https://btcpins.com
 * License: The Unlicense
 * License URI: https://unlicense.org
 * Text Domain: pulse-commissions
 * Domain Path: /languages
 *
 * @package PulseCommissions
 */

if (!defined('ABSPATH')) {
    exit; // Exit if accessed directly
}

// Define plugin constants
define('PULSE_COMMISSIONS_VERSION', '0.1.1');
define('PULSE_COMMISSIONS_PATH', plugin_dir_path(__FILE__));
define('PULSE_COMMISSIONS_URL', plugin_dir_url(__FILE__));
require_once PULSE_COMMISSIONS_PATH . 'includes/class-btcpay-integration.php';

class Pulse_Commissions {
    private static $instance = null;

    public static function get_instance() {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    private function __construct() {
        $this->init_hooks();
    }

    private function init_hooks() {
        add_action('admin_menu', array($this, 'add_admin_menu'));
        add_action('admin_init', array($this, 'register_settings'));
        add_action('admin_enqueue_scripts', array($this, 'enqueue_admin_scripts'));
        add_action('woocommerce_product_options_general_product_data', array($this, 'add_custom_field_to_products'));
        add_action('woocommerce_process_product_meta', array($this, 'save_custom_field'));
        add_action('woocommerce_checkout_create_order_line_item', array($this, 'add_commission_data_to_order_item'), 10, 4);
        add_action('woocommerce_order_status_completed', array($this, 'process_order_commissions'));
        add_action('wp_ajax_pulse_get_commission_details', array($this, 'get_commission_details'));
	    add_action('woocommerce_ajax_add_order_item_meta', array($this, 'add_commission_data_to_manual_order_item'), 10, 3);
    }

    public function enqueue_admin_scripts($hook) {
        if ('settings_page_pulse-commissions' === $hook) {
            wp_enqueue_style('pulse-commissions-admin', PULSE_COMMISSIONS_URL . 'css/admin.css', array(), PULSE_COMMISSIONS_VERSION);
            wp_enqueue_script('pulse-commissions-admin', PULSE_COMMISSIONS_URL . 'js/admin.js', array('jquery', 'wp-util'), PULSE_COMMISSIONS_VERSION, true);
            
            wp_localize_script('pulse-commissions-admin', 'pulseCommissionsAdmin', array(
                'currency' => get_woocommerce_currency()
            ));
        }

        if (in_array($hook, array('post.php', 'post-new.php'))) {
            $screen = get_current_screen();
            if (is_object($screen) && 'product' == $screen->post_type) {
                wp_enqueue_script('woocommerce_admin');
            }
        }
    }

    public function add_admin_menu() {
        add_options_page(
            __('Pulse Commissions Settings', 'pulse-commissions'),
            __('Pulse Commissions', 'pulse-commissions'),
            'manage_options',
            'pulse-commissions',
            array($this, 'display_settings_page')
        );
    }

    public function register_settings() {
        // Register a single option to store all our settings
        register_setting(
            'pulse_commissions_options',
            'pulse_commissions_options',
            array($this, 'sanitize_settings')
        );

        // BTCPay Server Settings Section
        add_settings_section(
            'pulse_commissions_btcpay',
            __('BTCPay Server Settings', 'pulse-commissions'),
            array($this, 'btcpay_section_callback'),
            'pulse-commissions'
        );

        // BTCPay Server URL
        add_settings_field(
            'btcpay_url',
            __('BTCPay Server URL', 'pulse-commissions'),
            array($this, 'btcpay_url_callback'),
            'pulse-commissions',
            'pulse_commissions_btcpay'
        );

        // BTCPay Server API Key
        add_settings_field(
            'btcpay_api_key',
            __('BTCPay Server API Key', 'pulse-commissions'),
            array($this, 'btcpay_api_key_callback'),
            'pulse-commissions',
            'pulse_commissions_btcpay'
        );

        // BTCPay Server Store ID
        add_settings_field(
            'btcpay_store_id',
            __('BTCPay Server Store ID', 'pulse-commissions'),
            array($this, 'btcpay_store_id_callback'),
            'pulse-commissions',
            'pulse_commissions_btcpay'
        );

        // Auto-approve Claims
        add_settings_field(
            'auto_approve_claims',
            __('Auto-approve Claims', 'pulse-commissions'),
            array($this, 'auto_approve_claims_callback'),
            'pulse-commissions',
            'pulse_commissions_btcpay'
        );
		
        // Payout name
        add_settings_field(
            'payout_name',
            __('Payout Name', 'pulse-commissions'),
            array($this, 'payout_name_callback'),
            'pulse-commissions',
            'pulse_commissions_btcpay'
        );
		
        // Commission Settings Section
        add_settings_section(
            'pulse_commissions_payouts',
            __('Commission Payout Settings', 'pulse-commissions'),
            array($this, 'payouts_section_callback'),
            'pulse-commissions'
        );

        // Payout Setups
        add_settings_field(
            'payout_setups',
            __('Payout Setups', 'pulse-commissions'),
            array($this, 'payout_setups_callback'),
            'pulse-commissions',
            'pulse_commissions_payouts'
        );
    }

    public function sanitize_settings($input) {
        $sanitary_values = array();

        if (isset($input['btcpay_url'])) {
            $sanitary_values['btcpay_url'] = esc_url_raw($input['btcpay_url']);
        }

        if (isset($input['btcpay_api_key'])) {
            $sanitary_values['btcpay_api_key'] = sanitize_text_field($input['btcpay_api_key']);
        }

        if (isset($input['btcpay_store_id'])) {
            $sanitary_values['btcpay_store_id'] = sanitize_text_field($input['btcpay_store_id']);
        }

        if (isset($input['auto_approve_claims'])) {
            $sanitary_values['auto_approve_claims'] = (bool) $input['auto_approve_claims'];
        }

        if (isset($input['payout_setups']) && is_array($input['payout_setups'])) {
            $sanitary_values['payout_setups'] = array();
            foreach ($input['payout_setups'] as $setup) {
                $sanitary_setup = array(
                    'product_string' => sanitize_text_field($setup['product_string']),
                    'payouts' => array()
                );
                if (isset($setup['payouts']) && is_array($setup['payouts'])) {
                    foreach ($setup['payouts'] as $payout) {
                        $sanitary_payout = array(
                            'lightning_address' => sanitize_email($payout['lightning_address']),
                            'payout_type' => in_array($payout['payout_type'], array('percentage', 'flat_rate')) ? $payout['payout_type'] : 'percentage',
                            'payout_amount' => floatval($payout['payout_amount'])
                        );
                        $sanitary_setup['payouts'][] = $sanitary_payout;
                    }
                }
                $sanitary_values['payout_setups'][] = $sanitary_setup;
            }
        }
		
		if (isset($input['payout_name'])) {
            $sanitary_values['payout_name'] = sanitize_text_field($input['payout_name']);
        }

        return $sanitary_values;
    }

    // Callback functions for sections
    public function btcpay_section_callback() {
        echo '<p>' . __('Enter your BTCPay Server details below:', 'pulse-commissions') . '</p>';
    }

    public function payouts_section_callback() {
        echo '<p>' . __('Configure your commission payout settings here:', 'pulse-commissions') . '</p>';
    }

    // Callback functions for individual settings fields
    public function btcpay_url_callback() {
        $options = get_option('pulse_commissions_options');
        $value = isset($options['btcpay_url']) ? $options['btcpay_url'] : '';
        echo '<input type="url" id="btcpay_url" name="pulse_commissions_options[btcpay_url]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function btcpay_api_key_callback() {
        $options = get_option('pulse_commissions_options');
        $value = isset($options['btcpay_api_key']) ? $options['btcpay_api_key'] : '';
        echo '<input type="text" id="btcpay_api_key" name="pulse_commissions_options[btcpay_api_key]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function btcpay_store_id_callback() {
        $options = get_option('pulse_commissions_options');
        $value = isset($options['btcpay_store_id']) ? $options['btcpay_store_id'] : '';
        echo '<input type="text" id="btcpay_store_id" name="pulse_commissions_options[btcpay_store_id]" value="' . esc_attr($value) . '" class="regular-text">';
    }

    public function auto_approve_claims_callback() {
        $options = get_option('pulse_commissions_options');
        $checked = isset($options['auto_approve_claims']) ? $options['auto_approve_claims'] : false;
        echo '<input type="checkbox" id="auto_approve_claims" name="pulse_commissions_options[auto_approve_claims]" value="1" ' . checked(1, $checked, false) . '>';
        echo '<label for="auto_approve_claims">' . __('Automatically approve payout claims', 'pulse-commissions') . '</label>';
    }

    public function payout_setups_callback() {
        $options = get_option('pulse_commissions_options');
        $payout_setups = isset($options['payout_setups']) ? $options['payout_setups'] : array();
        
        echo '<div id="payout-setups">';
        foreach ($payout_setups as $index => $setup) {
            $this->render_payout_setup($index, $setup);
        }
        echo '</div>';
        echo '<button type="button" id="add-payout-setup" class="button">' . __('Add Payout Setup', 'pulse-commissions') . '</button>';
    }
	
	// Callback function for payout name field
    public function payout_name_callback() {
        $options = get_option('pulse_commissions_options');
        $value = isset($options['payout_name']) ? $options['payout_name'] : 'Commission Payout';
        echo '<input type="text" id="payout_name" name="pulse_commissions_options[payout_name]" value="' . esc_attr($value) . '" class="regular-text">';
        echo '<p class="description">' . __('Enter the name you want to use for payouts in BTCPayServer. Default is "Commission Payout".', 'pulse-commissions') . '</p>';
    }

    private function render_payout_setup($index, $setup) {
        echo '<div class="payout-setup">';
        echo '<h4>' . __('Payout Setup', 'pulse-commissions') . '</h4>';
        echo '<label>' . __('Product String:', 'pulse-commissions') . '</label>';
        echo '<input type="text" name="pulse_commissions_options[payout_setups][' . $index . '][product_string]" value="' . esc_attr($setup['product_string']) . '" required>';
        
        echo '<div class="payouts">';
        if (isset($setup['payouts']) && is_array($setup['payouts'])) {
            foreach ($setup['payouts'] as $payout_index => $payout) {
                $this->render_payout($index, $payout_index, $payout);
            }
        }
        echo '</div>';
        
        echo '<button type="button" class="add-payout button">' . __('Add Payout', 'pulse-commissions') . '</button>';
        echo '<button type="button" class="remove-payout-setup button">' . __('Remove Payout Setup', 'pulse-commissions') . '</button>';
        echo '</div>';
    }

    private function render_payout($setup_index, $payout_index, $payout) {
        $currency = get_woocommerce_currency();
        echo '<div class="payout">';
        echo '<label>' . __('Lightning Address:', 'pulse-commissions') . '</label>';
        echo '<input type="email" name="pulse_commissions_options[payout_setups][' . $setup_index . '][payouts][' . $payout_index . '][lightning_address]" value="' . esc_attr($payout['lightning_address']) . '" required>';
        
        echo '<label>' . __('Payout Type:', 'pulse-commissions') . '</label>';
        echo '<select name="pulse_commissions_options[payout_setups][' . $setup_index . '][payouts][' . $payout_index . '][payout_type]">';
        echo '<option value="percentage" ' . selected($payout['payout_type'], 'percentage', false) . '>' . __('Percentage', 'pulse-commissions') . '</option>';
        echo '<option value="flat_rate" ' . selected($payout['payout_type'], 'flat_rate', false) . '>' . __('Flat Rate', 'pulse-commissions') . '</option>';
        echo '</select>';
        
        echo '<label>' . __('Payout Amount:', 'pulse-commissions') . '</label>';
        echo '<input type="number" step="0.01" name="pulse_commissions_options[payout_setups][' . $setup_index . '][payouts][' . $payout_index . '][payout_amount]" value="' . esc_attr($payout['payout_amount']) . '" required>';
        echo '<span class="currency">' . ($payout['payout_type'] === 'flat_rate' ? $currency : '%') . '</span>';
        
        echo '<button type="button" class="remove-payout button">' . __('Remove Payout', 'pulse-commissions') . '</button>';
        echo '</div>';
    }
	
	public function display_settings_page() {
        // Check user capabilities
        if (!current_user_can('manage_options')) {
            return;
        }

        // Show error/update messages
        settings_errors('pulse_commissions_messages');
        ?>
        <div class="wrap">
            <h1><?php echo esc_html(get_admin_page_title()); ?></h1>
            <form action="options.php" method="post">
                <?php
                // Output security fields for the registered setting "pulse_commissions_options"
                settings_fields('pulse_commissions_options');
                // Output setting sections and their fields
                do_settings_sections('pulse-commissions');
                // Output save settings button
                submit_button(__('Save Settings', 'pulse-commissions'));
                ?>
            </form>
        </div>

        <div class="pulse-commissions-info">
            <h2><?php _e('How to Use Pulse Commissions', 'pulse-commissions'); ?></h2>
            <ol>
                <li><?php _e('Configure your BTCPay Server settings above.', 'pulse-commissions'); ?></li>
                <li><?php _e('Create payout setups for your products. Each setup includes:', 'pulse-commissions'); ?>
                    <ul>
                        <li><?php _e('A product string (this will be used as a custom field on products)', 'pulse-commissions'); ?></li>
                        <li><?php _e('One or more payouts, each with a Lightning address and commission details', 'pulse-commissions'); ?></li>
                    </ul>
                </li>
                <li><?php _e('Edit a product and look for the "Pulse Commissions" field in the product data metabox.', 'pulse-commissions'); ?></li>
                <li><?php _e('Enter the corresponding product string to enable commissions for that product.', 'pulse-commissions'); ?></li>
                <li><?php _e('When an order containing a product with commissions is completed, payouts will be automatically created in BTCPay Server.', 'pulse-commissions'); ?></li>
            </ol>
        </div>

        <script type="text/html" id="tmpl-payout-setup">
            <div class="payout-setup">
                <h4><?php _e('Payout Setup', 'pulse-commissions'); ?></h4>
                <label><?php _e('Product String:', 'pulse-commissions'); ?></label>
                <input type="text" name="pulse_commissions_options[payout_setups][{{data.index}}][product_string]" required>
                <div class="payouts"></div>
                <button type="button" class="add-payout button"><?php _e('Add Payout', 'pulse-commissions'); ?></button>
                <button type="button" class="remove-payout-setup button"><?php _e('Remove Payout Setup', 'pulse-commissions'); ?></button>
            </div>
        </script>

        <script type="text/html" id="tmpl-payout">
            <div class="payout">
                <label><?php _e('Lightning Address:', 'pulse-commissions'); ?></label>
                <input type="email" name="pulse_commissions_options[payout_setups][{{data.setupIndex}}][payouts][{{data.payoutIndex}}][lightning_address]" required>
                
                <label><?php _e('Payout Type:', 'pulse-commissions'); ?></label>
                <select name="pulse_commissions_options[payout_setups][{{data.setupIndex}}][payouts][{{data.payoutIndex}}][payout_type]">
                    <option value="percentage"><?php _e('Percentage', 'pulse-commissions'); ?></option>
                    <option value="flat_rate"><?php _e('Flat Rate', 'pulse-commissions'); ?></option>
                </select>
                
                <label><?php _e('Payout Amount:', 'pulse-commissions'); ?></label>
                <input type="number" step="0.01" name="pulse_commissions_options[payout_setups][{{data.setupIndex}}][payouts][{{data.payoutIndex}}][payout_amount]" required>
                <span class="currency">%</span>
                
                <button type="button" class="remove-payout button"><?php _e('Remove Payout', 'pulse-commissions'); ?></button>
            </div>
        </script>
        <?php
    }

    public function add_custom_field_to_products() {
        global $post;

        // Get all payout setups
        $options = get_option('pulse_commissions_options');
        $payout_setups = isset($options['payout_setups']) ? $options['payout_setups'] : array();

        // Prepare options for select field
        $setup_options = array(
            '' => __('Select a commission setup', 'pulse-commissions')
        );
        foreach ($payout_setups as $setup) {
            $setup_options[$setup['product_string']] = $setup['product_string'];
        }

        // Get the current value
        $commission_setup = get_post_meta($post->ID, '_pulse_commission_setup', true);

        echo '<div class="options_group">';
        
        // Commission Setup Select Field
        woocommerce_wp_select(
            array(
                'id' => '_pulse_commission_setup',
                'label' => __('Commission Setup', 'pulse-commissions'),
                'description' => __('Select a commission setup for this product.', 'pulse-commissions'),
                'desc_tip' => true,
                'options' => $setup_options,
                'value' => $commission_setup
            )
        );

        // Commission Details (read-only)
        echo '<p class="form-field"><label>' . __('Commission Details', 'pulse-commissions') . '</label>';
        echo '<span id="pulse-commission-details">';
        if ($commission_setup) {
            $this->display_commission_details($commission_setup);
        } else {
            _e('No commission setup selected', 'pulse-commissions');
        }
        echo '</span></p>';

        echo '</div>';

        // Add JavaScript to update commission details when selection changes
        ?>
        <script type="text/javascript">
        jQuery(document).ready(function($) {
            $('#_pulse_commission_setup').change(function() {
                var setup = $(this).val();
                if (setup) {
                    $.ajax({
                        url: ajaxurl,
                        type: 'POST',
                        data: {
                            action: 'pulse_get_commission_details',
                            setup: setup,
                            nonce: '<?php echo wp_create_nonce('pulse_get_commission_details'); ?>'
                        },
                        success: function(response) {
                            $('#pulse-commission-details').html(response);
                        }
                    });
                } else {
                    $('#pulse-commission-details').html('<?php _e('No commission setup selected', 'pulse-commissions'); ?>');
                }
            });
        });
        </script>
        <?php
    }

    private function display_commission_details($setup_string) {
        $options = get_option('pulse_commissions_options');
        $payout_setups = isset($options['payout_setups']) ? $options['payout_setups'] : array();

        foreach ($payout_setups as $setup) {
            if ($setup['product_string'] === $setup_string) {
                echo '<ul>';
                foreach ($setup['payouts'] as $payout) {
                    echo '<li>';
                    echo esc_html($payout['lightning_address']) . ': ';
                    if ($payout['payout_type'] === 'percentage') {
                        echo esc_html($payout['payout_amount']) . '%';
                    } else {
                        echo get_woocommerce_currency_symbol() . esc_html($payout['payout_amount']);
                    }
                    echo '</li>';
                }
                echo '</ul>';
                return;
            }
        }

        _e('Selected commission setup not found', 'pulse-commissions');
    }

    public function save_custom_field($post_id) {
        $commission_setup = isset($_POST['_pulse_commission_setup']) ? sanitize_text_field($_POST['_pulse_commission_setup']) : '';
        update_post_meta($post_id, '_pulse_commission_setup', $commission_setup);
        
        error_log("Pulse Commissions: Saved commission setup '$commission_setup' for product $post_id");
    }

    public function add_commission_data_to_order_item($item, $cart_item_key, $values, $order) {
        $product = $item->get_product();
        $commission_setup = get_post_meta($product->get_id(), '_pulse_commission_setup', true);
        
        if (!empty($commission_setup)) {
            $item->add_meta_data('_pulse_commission_setup', $commission_setup, true);
            error_log("Pulse Commissions: Added commission setup '$commission_setup' to order item for product " . $product->get_id());
        }
    }
	
	    public function add_commission_data_to_manual_order_item($item_id, $item, $order_id) {
        if (!$item instanceof WC_Order_Item_Product) {
            return;
        }

        $product = $item->get_product();
        if (!$product) {
            return;
        }

        $commission_setup = get_post_meta($product->get_id(), '_pulse_commission_setup', true);
        
        if (!empty($commission_setup)) {
            $item->add_meta_data('_pulse_commission_setup', $commission_setup, true);
            $item->save();
            error_log("Pulse Commissions: Added commission setup '$commission_setup' to manually added order item for product " . $product->get_id());
        }
    }

    public function get_commission_details() {
        check_ajax_referer('pulse_get_commission_details', 'nonce');

        if (!isset($_POST['setup'])) {
            wp_send_json_error(__('No setup provided', 'pulse-commissions'));
        }

        $setup_string = sanitize_text_field($_POST['setup']);
        ob_start();
        $this->display_commission_details($setup_string);
        $details = ob_get_clean();

        wp_send_json_success($details);
    }

    public function process_order_commissions($order_id) {
        error_log("Pulse Commissions: Processing commissions for order $order_id");
        
        $order = wc_get_order($order_id);
        if (!$order) {
            error_log("Pulse Commissions: Invalid order ID " . $order_id);
            return;
        }

        $options = get_option('pulse_commissions_options');
        $payout_setups = isset($options['payout_setups']) ? $options['payout_setups'] : array();

        $btcpay_integration = new Pulse_Commissions_BTCPay_Integration();

        $commission_totals = array();

        foreach ($order->get_items() as $item_id => $item) {
            $commission_setup = $item->get_meta('_pulse_commission_setup');
            error_log("Pulse Commissions: Checking commission setup '$commission_setup' for item $item_id");

            if (!empty($commission_setup)) {
                foreach ($payout_setups as $setup) {
                    if ($setup['product_string'] === $commission_setup) {
                        $this->calculate_item_commission($order, $item, $setup, $commission_totals);
                    }
                }
            }
        }

        $this->process_commission_payouts($order, $commission_totals, $btcpay_integration);
    }
	
	private function calculate_item_commission($order, $item, $setup, &$commission_totals) {
        $product = $item->get_product();
        $product_id = $product->get_id();
        $item_total = $item->get_total();

        error_log("Pulse Commissions: Calculating commission for product $product_id, total: $item_total " . $order->get_currency());

        foreach ($setup['payouts'] as $payout) {
            $lightning_address = $payout['lightning_address'];
            $payout_type = $payout['payout_type'];
            $payout_amount = $payout['payout_amount'];

            $commission = $this->calculate_commission($payout_type, $payout_amount, $item_total, $item->get_quantity());

            if ($commission > 0) {
                if (!isset($commission_totals[$lightning_address])) {
                    $commission_totals[$lightning_address] = array(
                        'total' => 0,
                        'details' => array()
                    );
                }
                $commission_totals[$lightning_address]['total'] += $commission;
                $commission_totals[$lightning_address]['details'][] = array(
                    'product_id' => $product_id,
                    'product_name' => $product->get_name(),
                    'commission' => $commission
                );

                error_log("Pulse Commissions: Calculated commission of $commission " . $order->get_currency() . " for order " . $order->get_id() . ", product " . $product_id . ", lightning address " . $lightning_address);
            }
        }
    }
	
    private function process_commission_payouts($order, $commission_totals, $btcpay_integration) {
        if (empty($commission_totals)) {
            error_log("Pulse Commissions: No commissions to process for order " . $order->get_id());
            return;
        }

        $total_commission = array_sum(array_column($commission_totals, 'total'));
        $payout_id = $btcpay_integration->create_payout($commission_totals, $total_commission, $order->get_currency(), $order->get_id());

        if ($payout_id) {
            $this->add_commission_to_order($order, $commission_totals, $payout_id);
        } else {
            error_log("Pulse Commissions: Failed to create payout for order " . $order->get_id());
            $order->add_order_note(__('Failed to create commission payout. Please check the logs.', 'pulse-commissions'));
        }
    }
	
    private function calculate_commission($payout_type, $payout_amount, $item_total, $quantity) {
        if ($payout_type === 'percentage') {
            return $item_total * ($payout_amount / 100);
        } elseif ($payout_type === 'flat_rate') {
            return $payout_amount * $quantity;
        }
        return 0;
    }

    private function add_commission_to_order($order, $commission_totals, $payout_id) {
        $total_commission = array_sum(array_column($commission_totals, 'total'));
        
        $order->add_order_note(
            sprintf(
                __('Commission payout created. Total Amount: %s %s, Payout ID: %s', 'pulse-commissions'),
                $total_commission,
                $order->get_currency(),
                $payout_id
            )
        );

        foreach ($commission_totals as $lightning_address => $data) {
            $order->add_order_note(
                sprintf(
                    __('Commission breakdown for %s: %s %s', 'pulse-commissions'),
                    $lightning_address,
                    $data['total'],
                    $order->get_currency()
                )
            );

            foreach ($data['details'] as $detail) {
                $order->add_order_note(
                    sprintf(
                        __('- Product: %s, Commission: %s %s', 'pulse-commissions'),
                        $detail['product_name'],
                        $detail['commission'],
                        $order->get_currency()
                    )
                );
            }
        }

        $order->update_meta_data('_pulse_commissions', array(
            'payout_id' => $payout_id,
            'total_commission' => $total_commission,
            'breakdown' => $commission_totals
        ));
        $order->save();

        error_log("Pulse Commissions: Added commission information to order " . $order->get_id());
    }	
	
    private function process_item_commission($order, $item, $setup, $btcpay_integration) {
        $product = $item->get_product();
        $product_id = $product->get_id();
        $item_total = $item->get_total();

        error_log("Pulse Commissions: Processing commission for product $product_id, total: $item_total " . $order->get_currency());

        foreach ($setup['payouts'] as $payout) {
            $lightning_address = $payout['lightning_address'];
            $payout_type = $payout['payout_type'];
            $payout_amount = $payout['payout_amount'];

            $commission = $this->calculate_commission($payout_type, $payout_amount, $item_total, $item->get_quantity());

            if ($commission > 0) {
                error_log("Pulse Commissions: Calculated commission of $commission " . $order->get_currency() . " for order " . $order->get_id() . ", product " . $product_id);

                $payout_id = $btcpay_integration->create_payout($lightning_address, $commission, $order->get_currency());

                if ($payout_id) {
                    $this->add_commission_to_order($order, $product, $commission, $payout_id, $lightning_address);
                } else {
                    error_log("Pulse Commissions: Failed to create payout for order " . $order->get_id() . ", product " . $product_id);
                    $order->add_order_note(sprintf(__('Failed to create commission payout for product %s. Please check the logs.', 'pulse-commissions'), $product->get_name()));
                }
            }
        }
    }

    public static function activate() {
        // Set default options if they don't exist
        $default_options = array(
            'btcpay_url' => '',
            'btcpay_api_key' => '',
            'btcpay_store_id' => '',
            'auto_approve_claims' => true,
            'payout_setups' => array()
        );

        $existing_options = get_option('pulse_commissions_options', array());
        $merged_options = array_merge($default_options, $existing_options);
        update_option('pulse_commissions_options', $merged_options);

        // You can add more activation tasks here, such as creating custom database tables if needed
    }
}

register_activation_hook(__FILE__, array('Pulse_Commissions', 'activate'));

function pulse_commissions() {
    return Pulse_Commissions::get_instance();
}

pulse_commissions();