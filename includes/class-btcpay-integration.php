<?php
/**
 * BTCPay Server Integration Class for Pulse Commissions
 *
 * @package PulseCommissions
 */

// If this file is called directly, abort.
if (!defined('ABSPATH')) {
    exit;
}

class Pulse_Commissions_BTCPay_Integration {
    private $api_url;
    private $api_key;
    private $store_id;
    private $auto_approve_claims;
    private $payout_name;
    private $is_v2 = null;
    private $cache_expiration = 86400; // 24 hours in seconds

    public function __construct() {
        $options = get_option('pulse_commissions_options');
        $this->api_url = isset($options['btcpay_url']) ? trailingslashit($options['btcpay_url']) : '';
        $this->api_key = isset($options['btcpay_api_key']) ? $options['btcpay_api_key'] : '';
        $this->store_id = isset($options['btcpay_store_id']) ? $options['btcpay_store_id'] : '';
        $this->auto_approve_claims = isset($options['auto_approve_claims']) ? (bool) $options['auto_approve_claims'] : false;
        $this->payout_name = isset($options['payout_name']) ? $options['payout_name'] : 'Commission Payout';
    }
    
    /**
     * Get API URL
     * 
     * @return string
     */
    public function get_api_url() {
        return $this->api_url;
    }
    
    /**
     * Get API Key
     * 
     * @return string
     */
    public function get_api_key() {
        return $this->api_key;
    }
    
    /**
     * Get Store ID
     * 
     * @return string
     */
    public function get_store_id() {
        return $this->store_id;
    }

    /**
     * Detect BTCPay Server version
     *
     * @return bool|null True for v2, false for v1, null if couldn't detect
     */
    public function detect_btcpay_version() {
        // Check cached version first
        $cached_version = get_transient('pulse_commissions_btcpay_version');
        if ($cached_version !== false) {
            $this->is_v2 = $cached_version === 'v2';
            return $this->is_v2;
        }

        // If no valid settings, can't detect
        if (empty($this->api_url) || empty($this->api_key)) {
            $this->is_v2 = null;
            return null;
        }

        // Try to detect by calling the server info endpoint
        $endpoint = $this->api_url . 'api/v1/server/info';

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'token ' . $this->api_key
            )
        ));

        if (is_wp_error($response)) {
            error_log('Pulse Commissions: Error detecting BTCPay version: ' . $response->get_error_message());
            return null;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        
        if ($response_code !== 200) {
            error_log('Pulse Commissions: Failed to detect BTCPay version. Response code: ' . $response_code);
            return null;
        }

        $data = json_decode($response_body, true);
		
		error_log('Pulse: BTCPay Server version data: ' . print_r($data, true));
        
        // Check for version in the response
        if (isset($data['version'])) {
            // Check if the version starts with "2."
			$is_v2 = version_compare($data['version'], '2.0.0', '>=');
            
            // Cache the result
            set_transient('pulse_commissions_btcpay_version', $is_v2 ? 'v2' : 'v1', $this->cache_expiration);
            
            $this->is_v2 = $is_v2;
            return $is_v2;
        }

        return null;
    }

    /**
     * Check if the connected BTCPay Server is v2
     *
     * @return bool|null
     */
    public function is_v2() {
        if ($this->is_v2 === null) {
            $this->detect_btcpay_version();
        }
        return $this->is_v2;
    }
    
    /**
     * Get server version string for display
     * 
     * @return string
     */
    public function get_version_status() {
        $version = $this->is_v2();
        
        if ($version === true) {
            return 'BTCPay Server 2.0+';
        } elseif ($version === false) {
            return 'BTCPay Server 1.x';
        }
        
        return 'Not detected';
    }

    /**
     * Clear cached version detection
     */
    public function clear_version_cache() {
        delete_transient('pulse_commissions_btcpay_version');
        $this->is_v2 = null;
    }

    public function create_payout($commission_totals, $total_amount, $currency, $order_id) {
        error_log('Pulse Commissions: Creating payout for total amount: ' . $total_amount . ' ' . $currency . ' for order ' . $order_id);

        // Step 1: Create a Pull Payment
        $pull_payment_id = $this->create_pull_payment($total_amount, $currency, $order_id);
        if (!$pull_payment_id) {
            error_log('Pulse Commissions: Failed to create pull payment for order ' . $order_id);
            return false;
        }

        error_log('Pulse Commissions: Pull payment created successfully with ID: ' . $pull_payment_id . ' for order ' . $order_id);

        // Step 2: Verify Pull Payment
        $pull_payment = $this->get_pull_payment($pull_payment_id);
        if (!$pull_payment) {
            error_log('Pulse Commissions: Failed to verify pull payment after creation');
            return false;
        }

        error_log('Pulse Commissions: Pull payment verified successfully');

        // Step 3: Create Payouts for each lightning address
        $payout_ids = array();
        foreach ($commission_totals as $lightning_address => $data) {
            $payout_id = $this->create_payout_for_pull_payment($pull_payment_id, $lightning_address, $data['total']);
            if ($payout_id) {
                $payout_ids[] = $payout_id;
            } else {
                error_log('Pulse Commissions: Failed to create payout for lightning address: ' . $lightning_address);
            }
        }

        if (empty($payout_ids)) {
            error_log('Pulse Commissions: Failed to create any payouts for pull payment');
            return false;
        }

        error_log('Pulse Commissions: Payouts created successfully. IDs: ' . implode(', ', $payout_ids));
        return $pull_payment_id; // Return the pull payment ID as the main payout ID
    }
    
    private function get_pull_payment($pull_payment_id) {
        $endpoint = $this->api_url . 'api/v1/pull-payments/' . $pull_payment_id;

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Authorization' => 'token ' . $this->api_key
            )
        ));

        if (is_wp_error($response)) {
            error_log('Pulse Commissions: BTCPay Server API error when verifying pull payment: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('Pulse Commissions: BTCPay Server API response code for pull payment verification: ' . $response_code);
        error_log('Pulse Commissions: BTCPay Server API response body for pull payment verification: ' . $response_body);

        if ($response_code !== 200) {
            error_log('Pulse Commissions: Failed to verify pull payment. Response code: ' . $response_code);
            return false;
        }

        return json_decode($response_body, true);
    }

    private function create_pull_payment($amount, $currency, $order_id) {
        if (empty($this->api_url) || empty($this->api_key) || empty($this->store_id)) {
            error_log('Pulse Commissions: BTCPay Server API URL, key, or Store ID is not set');
            return false;
        }

        $endpoint = $this->api_url . 'api/v1/stores/' . $this->store_id . '/pull-payments';

        $is_v2 = $this->is_v2();
        
        // In v2, paymentMethods was renamed to payoutMethods
        if ($is_v2) {
            $body = array(
                'name' => $this->payout_name . ' - Order #' . $order_id,
                'description' => $this->payout_name,
                'amount' => strval($amount),
                'currency' => $currency,
                'payoutMethods' => ['BTC-LN'], // New format in v2
                'autoApproveClaims' => $this->auto_approve_claims
            );
        } else {
            $body = array(
                'name' => $this->payout_name . ' - Order #' . $order_id,
                'description' => $this->payout_name,
                'amount' => strval($amount),
                'currency' => $currency,
                'paymentMethods' => ['BTC-LightningNetwork'], // Old format in v1
                'autoApproveClaims' => $this->auto_approve_claims
            );
        }

        error_log('Pulse Commissions: Sending pull payment request to BTCPay Server. Endpoint: ' . $endpoint . ', Body: ' . json_encode($body));

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'token ' . $this->api_key
            ),
            'body' => json_encode($body)
        ));

        if (is_wp_error($response)) {
            error_log('Pulse Commissions: BTCPay Server API error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);

        error_log('Pulse Commissions: BTCPay Server API response code for pull payment: ' . $response_code);
        error_log('Pulse Commissions: BTCPay Server API response body for pull payment: ' . $response_body);

        $data = json_decode($response_body, true);

        if ($response_code !== 200 && $response_code !== 201) {
            error_log('Pulse Commissions: Failed to create pull payment. Response code: ' . $response_code . ', Response body: ' . $response_body);
            return false;
        }

        if (!isset($data['id'])) {
            error_log('Pulse Commissions: Pull payment ID not found in response. Response body: ' . $response_body);
            return false;
        }

        return $data['id'];
    }
    
    private function create_payout_for_pull_payment($pull_payment_id, $lightning_address, $amount) {
        if (empty($this->api_url) || empty($this->api_key) || empty($this->store_id)) {
            error_log('Pulse Commissions: BTCPay Server API URL, key, or Store ID is not set');
            return false;
        }

        $endpoint = $this->api_url . 'api/v1/stores/' . $this->store_id . '/payouts';

        $is_v2 = $this->is_v2();
        
        // In v2, paymentMethod was renamed to payoutMethodId
        if ($is_v2) {
            $body = array(
                'pullPaymentId' => $pull_payment_id,
                'destination' => $lightning_address,
                'amount' => strval($amount),
                'payoutMethodId' => 'BTC-LN' // New format in v2
            );
        } else {
            $body = array(
                'pullPaymentId' => $pull_payment_id,
                'destination' => $lightning_address,
                'amount' => strval($amount),
                'paymentMethod' => 'BTC-LightningLike' // Old format in v1
            );
        }

        error_log('Pulse Commissions: Sending payout request to BTCPay Server. Endpoint: ' . $endpoint . ', Body: ' . json_encode($body));

        $response = wp_remote_post($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'token ' . $this->api_key
            ),
            'body' => json_encode($body)
        ));

        if (is_wp_error($response)) {
            error_log('Pulse Commissions: BTCPay Server API error: ' . $response->get_error_message());
            return false;
        }

        $response_code = wp_remote_retrieve_response_code($response);
        $response_body = wp_remote_retrieve_body($response);
        $response_headers = wp_remote_retrieve_headers($response);

        error_log('Pulse Commissions: BTCPay Server API response code for payout: ' . $response_code);
        error_log('Pulse Commissions: BTCPay Server API response body for payout: ' . $response_body);
        error_log('Pulse Commissions: BTCPay Server API response headers for payout: ' . print_r($response_headers, true));

        if ($response_code !== 200 && $response_code !== 201) {
            error_log('Pulse Commissions: Failed to create payout. Response code: ' . $response_code . ', Response body: ' . $response_body);
            return false;
        }

        $data = json_decode($response_body, true);

        if (!isset($data['id'])) {
            error_log('Pulse Commissions: Payout ID not found in response. Response body: ' . $response_body);
            return false;
        }

        return $data['id'];
    }

    public function get_payout_status($payout_id) {
        if (empty($this->api_url) || empty($this->api_key) || empty($this->store_id)) {
            error_log('Pulse Commissions: BTCPay Server API URL, key, or Store ID is not set');
            return false;
        }

        $endpoint = $this->api_url . 'api/v1/stores/' . $this->store_id . '/payouts/' . $payout_id;

        error_log('Pulse Commissions: Sending payout status request to BTCPay Server. Endpoint: ' . $endpoint);

        $response = wp_remote_get($endpoint, array(
            'headers' => array(
                'Content-Type' => 'application/json',
                'Authorization' => 'token ' . $this->api_key
            )
        ));

        if (is_wp_error($response)) {
            error_log('Pulse Commissions: BTCPay Server API error: ' . $response->get_error_message());
            return false;
        }

        $body = wp_remote_retrieve_body($response);
        $data = json_decode($body, true);

        error_log('Pulse Commissions: BTCPay Server API response: ' . print_r($data, true));

        if (isset($data['state'])) {
            return $data['state'];
        } else {
            error_log('Pulse Commissions: Failed to get payout status. Response: ' . print_r($data, true));
            return false;
        }
    }
}