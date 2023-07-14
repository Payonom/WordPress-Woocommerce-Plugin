<?php

/**
 * Plugin Name: Payonom Payment Gateway
 * Description: Integrate Payonom payment gateway with WooCommerce.
 * Version: 1.0.0
 * Author: Payonom.com
 */

if (!defined('ABSPATH')) {
    exit;
}

/**
 * Add Payonom Payment Gateway
 */
add_action('plugins_loaded', 'payonom_payment_gateway_init');
function payonom_payment_gateway_init()
{
    if (!class_exists('WC_Payment_Gateway')) {
        return;
    }

    class WC_Gateway_Payonom extends WC_Payment_Gateway
    {
        /**
         * Constructor for the gateway.
         */
        public function __construct()
        {
            $this->id = 'payonom';
            $this->icon = ''; // Set the path to your payment gateway logo
            $this->has_fields = false;
            $this->method_title = 'Payonom';
            $this->method_description = 'Payonom Payment Gateway for WooCommerce';

            $this->supports = array(
                'products',
            );

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->client_id = $this->get_option('client_id');
            $this->client_secret = $this->get_option('client_secret');
            $this->mode = $this->get_option('mode');

            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_api_payonom_callback', array($this, 'callback_handler'));
        }

        /**
         * Initialize Gateway Settings Form Fields
         */
        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => 'Enable/Disable',
                    'type' => 'checkbox',
                    'label' => 'Enable Payonom Payment Gateway',
                    'default' => 'yes',
                ),
                'title' => array(
                    'title' => 'Title',
                    'type' => 'text',
                    'description' => 'This controls the title displayed on the payment method selection page.',
                    'default' => 'Payonom',
                    'desc_tip' => true,
                ),
                'description' => array(
                    'title' => 'Description',
                    'type' => 'textarea',
                    'description' => 'This controls the description displayed on the payment method selection page.',
                    'default' => 'Pay with Payonom',
                ),
                'client_id' => array(
                    'title' => 'Client ID',
                    'type' => 'text',
                    'description' => 'Enter your Payonom Client ID',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'client_secret' => array(
                    'title' => 'Client Secret',
                    'type' => 'text',
                    'description' => 'Enter your Payonom Client Secret',
                    'default' => '',
                    'desc_tip' => true,
                ),
                'mode' => array(
                    'title' => 'Mode',
                    'type' => 'select',
                    'description' => 'Select the Payonom API mode.',
                    'default' => 'sandbox',
                    'options' => array(
                        'sandbox' => 'Sandbox',
                        'live' => 'Live',
                    ),
                ),
            );
        }
        
        /**
         * Clear Cart
         */
        public function clear_cart_if_new_order($order_id) {
            $order = wc_get_order($order_id);
        
            // Get the order creation timestamp
            $order_created_timestamp = $order->get_date_created()->getTimestamp();
        
            // Get the current timestamp
            $current_timestamp = current_time('timestamp');
        
            // Calculate the time difference in seconds
            $time_difference = $current_timestamp - $order_created_timestamp;
        
            // Check if the order is new (created within the last 15 seconds)
            if ($time_difference <= 15) {
                // Clear the cart
                WC()->cart->empty_cart();
            }
        }

        /**
         * Process the payment
         */
        public function process_payment($order_id)
        {
            global $woocommerce;
            $order = new WC_Order($order_id);

            // Generate a token
            $token = md5(uniqid());

            // Store the token in the WordPress session
            WC()->session->set('payonom_token', $token);

            // Get the necessary order data
            $currency = $order->get_currency() == 'BDT' ? 6 : 0;
            $amount = $order->get_total();
            $system_url = get_site_url();

            // Generate the payment URL
            $payment_url = $this->mode === 'live' ? 'https://live.payonom.com/payment/merchant' : 'https://sandbox.payonom.com/payment/merchant';
            $payment_url .= '?token=' . $token;
            $payment_url .= '&merchant=' . $this->client_secret;
            $payment_url .= '&merchant_id=' . $this->client_id;
            $payment_url .= '&item_name=Order-' . $order_id;
            $payment_url .= '&currency_id=' . $currency;
            $payment_url .= '&order=' . $order_id;
            $payment_url .= '&amount=' . $amount;
            $payment_url .= '&callback_url=' . $system_url . '/wc-api/payonom_callback';
        
            // Redirect to the payment page
            self::clear_cart_if_new_order($order_id);
            
            return array(
                'result' => 'success',
                'redirect' => $payment_url,
            );

        }

        /**
         * Handle the callback request
         */
        public function callback_handler(){
            
            // Retrieve the callback data
            $token = isset($_POST['token']) ? $_POST['token'] : '';
            $status = isset($_POST['status']) ? $_POST['status'] : '';
            $order_no = isset($_POST['order_no']) ? $_POST['order_no'] : '';
            $amount = isset($_POST['amount']) ? $_POST['amount'] : '';
            $trx = isset($_POST['trx']) ? $_POST['trx'] : '';
            $action = isset($_POST['action']) ? $_POST['action'] : '';

            // Get the CSRF token from the API
            $csrfURL = $this->mode === 'live' ? 'https://live.payonom.com/csrf/token' : 'https://sandbox.payonom.com/csrf/token';
            $ch = curl_init();
            curl_setopt($ch, CURLOPT_URL, $csrfURL);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            $csrf_response = curl_exec($ch);
            $csrf_token = json_decode($csrf_response)->token;

            // Execute the payment API with the required data and CSRF token
            $url = $this->mode === 'live' ? 'https://live.payonom.com/payment/execute' : 'https://sandbox.payonom.com/payment/execute';
            // Set the query parameters
            $params = [
                'trx' => $trx,
                'api' => $this->client_secret,
                'id' => $this->client_id,
            ];
            // Build the query string
            $query_string = http_build_query($params);
            // Set the full API endpoint URL with the query string
            $url .= '?' . $query_string;
            // Initialize the cURL session
            $ch = curl_init($url);

            // Set the cURL options
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true); // Return the response as a string
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/json']); // Set the Content-Type header
            // Execute the cURL session and get the response
            $response = curl_exec($ch);
            // Close the cURL session
            curl_close($ch);
            // Decode the JSON response
            $payment_data = json_decode($response, true);

            $order = wc_get_order($order_no);
            
            // Perform action based on the callback status
            if ($status === 'success' && $payment_data['status'] === 'success' && $token === WC()->session->get('payonom_token') && $order_no == $order->get_id() && $amount == $order->get_total()) {
                // Payment was successful
                // Mark the order as paid
                $order->payment_complete($trx);

                // Redirect to the thank you page or order details page
                wp_redirect($order->get_checkout_order_received_url());
                exit;
            } else {
                // Payment failed
                // Take appropriate action for a failed payment
                $payment_failed_message = 'Payment failed with ' .$this->method_title. '. Please try again.';
                $my_account_orders_url = wc_get_account_endpoint_url('orders');
                
                wc_add_notice($payment_failed_message, 'error');
                wp_redirect($my_account_orders_url);
                exit;
            }
        }

    }

    /**
     * Add the Payonom Payment Gateway to WooCommerce
     */
    function add_payonom_payment_gateway($methods)
    {
        $methods[] = 'WC_Gateway_Payonom';
        return $methods;
    }
    add_filter('woocommerce_payment_gateways', 'add_payonom_payment_gateway');
}

add_filter('plugin_action_links', 'payonom_add_action_plugin', 10, 2);
    function payonom_add_action_plugin($actions, $plugin_file){
        if (plugin_basename(__FILE__) === $plugin_file) {
            $settings = array('settings' => '<a href="' . admin_url('admin.php?page=wc-settings&tab=checkout&section=payonom') . '">' . __('Settings') . '</a>');
            $actions = array_merge($settings, $actions);
        }

        return $actions;
}