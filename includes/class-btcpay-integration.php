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

    public function __construct() {
        $options = get_option('pulse_commissions_options');
        $this->api_url = isset($options['btcpay_url']) ? trailingslashit($options['btcpay_url']) : '';
        $this->api_key = isset($options['btcpay_api_key']) ? $options['btcpay_api_key'] : '';
        $this->store_id = isset($options['btcpay_store_id']) ? $options['btcpay_store_id'] : '';
        $this->auto_approve_claims = isset($options['auto_approve_claims']) ? (bool) $options['auto_approve_claims'] : false;
		$this->payout_name = isset($options['payout_name']) ? $options['payout_name'] : 'Commission Payout';
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

        $body = array(
            'name' => $this->payout_name . ' - Order #' . $order_id,
            'description' => $this->payout_name,
            'amount' => strval($amount),
            'currency' => $currency,
            'paymentMethods' => ['BTC-LightningNetwork'],
            'autoApproveClaims' => $this->auto_approve_claims
        );

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

        $body = array(
            'pullPaymentId' => $pull_payment_id,
            'destination' => $lightning_address,
            'amount' => strval($amount),
            'paymentMethod' => 'BTC-LightningLike'
        );

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