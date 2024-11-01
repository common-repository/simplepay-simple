<?php
/**
 *
 * Plugin Name: SimplePay Plugin for WooCommerce
 * Plugin URI: https://addons.simplepay.com.au
 * Description: Enables WooCommerce to accept payments through SimplePay
 * Author: SimplePay
 * Author URI: https://www.simplepay.com.au
 * Version: 1.0.0
 *
 * @package   WC-SimplePay
 * @author    SimplePay
 * @category  Payment Gateway
 */

if ( ! defined( 'ABSPATH' ) ) exit; // Exit if accessed directly

/**
 * Check if WooCommerce is active
 **/
if (in_array('woocommerce/woocommerce.php', apply_filters('active_plugins', get_option('active_plugins')))) {

    if (!class_exists('WC_SimplePay')) {
        // Load the main class
        add_action('plugins_loaded', 'wc_simplepay_init');

        // Add SimplePay as a WooCommerce gateway
        add_filter('woocommerce_payment_gateways', 'wc_simplepay_add_gateway');
    }
}

function wc_simplepay_init()
{

    /**
     * Main class
     */
    class WC_SimplePay extends WC_Payment_Gateway
    {

        /**
         * Token generation URLs
         */
        const TOKEN_URL      = 'https://simplepays.com/frontend/GenerateToken';
        const TOKEN_TEST_URL = 'https://test.simplepays.com/frontend/GenerateToken';

        /**
         * Payment form URLs
         */
        const FORM_URL      = 'https://simplepays.com/frontend/widget/v3/widget.js?language=en&style=card';
        const FORM_TEST_URL = 'https://test.simplepays.com/frontend/widget/v3/widget.js?language=en&style=card';

        /**
         * Transaction status URLs
         */
        const STATUS_URL      = 'https://simplepays.com/frontend/GetStatus;jsessionid=';
        const STATUS_TEST_URL = 'https://test.simplepays.com/frontend/GetStatus;jsessionid=';

        /**
         * Constructor for the gateway.
         */
        public function __construct() {

            $this->id                 = 'wc_simplepay';
            $this->method_title       = __('SimplePay', 'wc_simplepay');
            $this->method_description = __('Accept payments using SimplePay.', 'wc_simplepay');
            $this->has_fields         = false;

            // Base response URL
            $this->responseUrl = str_replace('https:', 'http:', add_query_arg('wc-api', 'WC_SimplePay', home_url('/')));

            // Load the settings
            $this->init_form_fields();
            // Initialise gateway settings
            $this->init_settings();

            // Settings
            $this->title               = $this->get_option('title');
            $this->description         = $this->get_option('description');
            $this->instructions        = $this->get_option('instructions');
            $this->accepted_brands     = $this->get_option('accepted_brands');
            $this->testmode            = $this->get_option('testmode');
            // Payment gateway fields
            $this->security_sender     = $this->get_option('security_sender');
            $this->transaction_channel = $this->get_option('transaction_channel');
            $this->transaction_mode    = $this->get_option('transaction_mode');
            $this->user_login          = $this->get_option('user_login');
            $this->user_pwd            = $this->get_option('user_pwd');
            $this->payment_type        = $this->get_option('payment_type');


            // Save the options to the database
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));

            // Payment response listener/API hook
            add_action('woocommerce_api_wc_simplepay', array($this, 'checkResponse'));

            // Pay page
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));

        }

        /**
         * Check whether in test mode or not
         *
         * @return boolean
         */
        public function inTestMode()
        {
            return ($this->testmode === 'yes') ? true : false;
        }

        public function getTokenUrl()
        {
            if ($this->inTestMode()) {
                return self::TOKEN_TEST_URL;
            } else {
                return self::TOKEN_URL;
            }
        }

        public function getFormUrl()
        {
            if ($this->inTestMode()) {
                return self::FORM_TEST_URL;
            } else {
                return self::FORM_URL;
            }
        }

        public function getStatusUrl()
        {
            if ($this->inTestMode()) {
                return self::STATUS_TEST_URL;
            } else {
                return self::STATUS_URL;
            }
        }

        private function getPayPageUrl($order)
        {
            return add_query_arg(
                       'key',
                       $order->order_key,
                       add_query_arg(
                           'order',
                           $order->id,
                           get_permalink(woocommerce_get_page_id('pay'))
                       )
                   );
        }

        private function generatePaymentToken(WC_Order $order)
        {

            // Get the token generation URL
            $tokenUrl = $this->getTokenUrl();

            if (!is_null($order)) {

                // Prepare the data for generating a payment token
                $data = array(
                    'SECURITY.SENDER'          => $this->security_sender,
                    'TRANSACTION.CHANNEL'      => $this->transaction_channel,
                    'TRANSACTION.MODE'         => $this->transaction_mode,
                    'USER.LOGIN'               => $this->user_login,
                    'USER.PWD'                 => $this->user_pwd,
                    'PAYMENT.TYPE'             => $this->payment_type,
                    'PRESENTATION.AMOUNT'      => $order->get_total(),
                    'PRESENTATION.CURRENCY'    => get_woocommerce_currency(),
                    'IDENTIFICATION.INVOICEID' => $order->id,
                );

                $options = array(
                    'headers' => array('Content-Type'=> 'application/x-www-form-urlencoded'),
                    'method'  => 'POST',
                    'body'    => http_build_query($data),
                    'timeout' => 60,
                );

                $response = wp_remote_post($tokenUrl, $options);

                // Check if the response is valid, return null if no response was received
                if (is_wp_error($response)) {
                    return null;
                }

                $resultJson = json_decode($response['body'], true);

                // Return the payment token
                if (isset($resultJson['transaction']['token'])) {
                    return $resultJson['transaction']['token'];
                }
            }

            // If order is null, return null
            return null;

        }

        /**
         * Checks the payment response to see if the payment was successful
         */
        public function checkResponse()
        {
            global $woocommerce;

            // Check that the required query parameters are provided
            if (!isset($_GET['token']) || !isset($_GET['order_id']) || !isset($_GET['order_key'])) {
                // Throw error
                wp_die('Invalid response.');
            }

            // Get the url query values
            $token = $_GET['token'];
            $orderId = $_GET['order_id'];
            $orderKey = $_GET['order_key'];

            $order = new WC_Order($orderId);

            // Check if the order key in the url matches the one in the order
            if ($order->key_is_valid($orderKey)) {

                $statusUrl = $this->getStatusUrl() . $token;

                // Check the status of the payment
                $ch = curl_init();
                curl_setopt($ch, CURLOPT_URL, $statusUrl);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
                $resultPayment = curl_exec($ch);
                curl_close($ch);

                // Check if the response is valid
                if ($resultPayment === false) {
                    // Could not get a response from the payment server

                    // Put this order on-hold for manual checking
                    $order->update_status('on-hold', __('Could not check payment status.' , 'wc_simplepay'));

                    $woocommerce->add_error(__('Could not check payment status.', 'wc_simplepay'));

                    wp_safe_redirect($this->get_return_url($order));

                    exit;

                }

                $resultJson = json_decode($resultPayment, true);

                // Check if the payment was successful
                if (isset($resultJson['errorMessage']) ||
                    (isset($resultJson['transaction']) && $resultJson['transaction']['processing']['result'] === 'NOK'))
                {

                    // Payment was unsuccessful
                    // Display an error message to the user
                    // Also list the error in the order comments
                    $errorMsg = __('Payment unsuccessful.', 'wc_simplepay');

                    // Append an error message if set
                    if (isset($resultJson['errorMessage'])) {

                       $errorMsg .= '<br>Error: ' . $resultJson['errorMessage'];

                    } else if (isset($resultJson['transaction']['processing']['return']['message'])) {

                        $errorMsg .= '<br>Error: ' . $resultJson['transaction']['processing']['return']['message'];

                    }

                    // Add a note about the error
                    $order->add_order_note($errorMsg);

                    // Display the error to the user
                    $woocommerce->add_error($errorMsg);

                    // Redirect back to the payment page to retry payment
                    wp_safe_redirect($this->getPayPageUrl($order));

                    exit;

                } else if (isset($resultJson['transaction']) &&
                           isset($resultJson['transaction']['processing']['result']) &&
                           $resultJson['transaction']['processing']['result'] === 'ACK')
                {

                    // Payment was successful

                    // Prepare a success message
                    $successMsg = __('Payment has been authorised.', 'wc_simplepay');

                    // Append the transaction ID
                    if (isset($resultJson['transaction']['identification']['uniqueId'])) {
                        $successMsg .=  '<br>Transaction ID: ' . $resultJson['transaction']['identification']['uniqueId'];
                    }

                    // Append the response message
                    if (isset($resultJson['transaction']['processing']['return']['message'])) {
                        $successMsg .=  '<br>Response: ' . $resultJson['transaction']['processing']['return']['message'];
                    }

                    // Add a success note to the order
                    $order->add_order_note($successMsg);

                    // Payment complete
                    $order->payment_complete();

                    // Redirect to the order received page
                    wp_safe_redirect($this->get_return_url($order));

                    exit;

                } else {

                    // Put this order on-hold for manual checking
                    $order->update_status('on-hold', __('Unknown payment status.' , 'wc_simplepay'));

                    // Display an error to the user
                    $woocommerce->add_error(__('Could not determine payment status.', 'wc_simplepay'));

                    wp_safe_redirect($this->get_return_url($order));

                    exit;

                }
            }
        }

        /**
         * Process the payment and return the result
         *
         * @access public
         * @param int $order_id
         * @return array
         */
        public function process_payment($order_id) {

            global $woocommerce;

            $order = new WC_Order($order_id);

            $paymentToken = $this->generatePaymentToken($order);

            if (is_null($paymentToken)) {
                $woocommerce->add_error(__('Could not connect to payment gateway.', 'wc_simplepay'));
                return;
            }

            // Save the payment token to the session for faster loading of the payment page
            $woocommerce->session->paymentToken = $paymentToken;

            // Redirect to the pay page
            return array(
                'result'   => 'success',
                'redirect' => add_query_arg('key', $order->order_key, add_query_arg('order', $order_id, get_permalink(woocommerce_get_page_id('pay'))))
            );

        }

        public function getResponseUrl($order)
        {
            // Add the order ID and key to the response URL, used to identify which order is being processed
            return add_query_arg('order_key', $order->order_key, add_query_arg('order_id', $order->id, $this->responseUrl));
        }

        public function receipt_page($orderId)
        {
            global $woocommerce;

            $order = new WC_Order($orderId);

            // Check if a payment token is available in the session
            if (!isset($woocommerce->session->paymentToken)) {

                $paymentToken = $this->generatePaymentToken($order);

                if ($paymentToken === null) {
                    $woocommerce->add_error(__('Could not connect to payment gateway.', 'wc_simplepay'));

                    // Redirect back to the payment page to retry payment
                    wp_safe_redirect($this->getPayPageUrl($order));

                    exit;
                }

            } else {
                $paymentToken = $woocommerce->session->paymentToken;
                unset($woocommerce->session->paymentToken);
            }

            echo '<script type="text/javascript" src="' . $this->getFormUrl() . '"></script>';

            echo '<style>.simplepay-text { margin-top: 40px; text-align: center; }.simplepay-form { margin: 40px 0 40px; }.simplepay-form select { max-width: inherit; }</style>';

            echo '<p class="simplepay-text">' . __('Thank you for your order, please use the form below to pay for your order.', 'wc_simplepay') . '</p>';

            echo '<div class="simplepay-form">' .
                     '<form action="' . $this->getResponseUrl($order) . '" id="' . $paymentToken . '">' .
                         implode(' ', $this->accepted_brands) .
                     '</form>' .
                 '</div>';

            echo '<a class="button cancel" href="' . esc_url($order->get_cancel_order_url()) . '">' .
                     __('Cancel order &amp; restore cart', 'wc_simplepay') .
                 '</a>';

        }

        /**
         * Initialise Gateway Settings Form Fields
         */
        public function init_form_fields()
        {

            // Brand names and values
            $brands = array(
                'MASTER' => 'MasterCard',
                'VISA'   => 'Visa',
                'AMEX'   => 'American Express',
                'DINERS' => 'Diners',
                'JCB'    => 'JCB',
            );

            $this->form_fields = array(
                'enabled' => array(
                    'title'   => __('Enable/Disable', 'wc_simplepay'),
                    'type'    => 'checkbox',
                    'label'   => __('Enable SimplePay', 'wc_simplepay'),
                    'default' => '0'
                ),
                'title' => array(
                    'title'       => __('Title', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'wc_simplepay'),
                    'default'     => __('Credit Card', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'description' => array(
                    'title'       => __('Description', 'wc_simplepay'),
                    'type'        => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'wc_simplepay'),
                    'default'     => __('Pay securely with your credit card.', 'wc_simplepay')
                ),
                'config' => array(
                    'title'       => __('Configuration', 'wc_simplepay'),
                    'type'        => 'title',
                    'description' => '',
                ),
                'security_sender' => array(
                    'title'       => __('Security Sender', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('Copy from your SimplePay account.', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'transaction_channel' => array(
                    'title'       => __('Transaction Channel', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('Copy from your SimplePay account.', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'transaction_mode' => array(
                    'title'       => __('Transaction Mode', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('Copy from your SimplePay account.', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'user_login' => array(
                    'title'       => __('User Login', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('Copy from your SimplePay account.', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'user_pwd' => array(
                    'title'       => __('User Password', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('Copy from your SimplePay account.', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'payment_type' => array(
                    'title'       => __('Payment Type', 'wc_simplepay'),
                    'type'        => 'text',
                    'description' => __('Copy from your SimplePay account.', 'wc_simplepay'),
                    'desc_tip'    => true,
                ),
                'accepted_brands' => array(
                    'title'       => __('Accepted brands', 'wc_simplepay'),
                    'type'        => 'multiselect',
                    'class'       => 'chosen_select',
                    'css'         => 'width: 450px;',
                    'default'     => '',
                    'description' => __('Check your SimplePay account to see which brands your account accepts.', 'wc_simplepay'),
                    'options'     => $brands,
                    'desc_tip'    => true,
                ),
                'testing' => array(
                    'title'       => __('Payment Testing', 'wc_simplepay'),
                    'type'        => 'title',
                    'description' => '',
                ),
                'testmode' => array(
                    'title'       => __('Test Mode', 'wc_simplepay'),
                    'type'        => 'checkbox',
                    'label'       => __('Enable test mode', 'wc_simplepay'),
                    'default'     => '1',
                    'description' => __('Test mode can be used to test payments.', 'wc_simplepay'),
                ),
            );

        }

    }
}

/**
 * Add SimplePay as a gateway to WooCommerce
 */
function wc_simplepay_add_gateway($methods) {
    $methods[] = 'WC_SimplePay';
    return $methods;
}
