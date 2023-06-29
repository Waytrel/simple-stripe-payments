<?php
/*
Plugin Name: Simple Stripe Payments
Plugin URI: https://waytrel.pro
Description: Simple Stripe Payments with user redirects to stripe payment page, without card insert on checkout page
Version: 1.0
Author: Vitaliy Shutenko
Author URI: https://waytrel.pro
*/

require_once(plugin_dir_path(__FILE__) . 'stripe-lib/init.php');

// Add a custom payment gateway
add_filter('woocommerce_payment_gateways', 'add_stripe_payment_gateway');
function add_stripe_payment_gateway($gateways)
{
    $gateways[] = 'WC_Stripe_Payment_Gateway';
    return $gateways;
}

// Define the custom payment gateway class
add_action('plugins_loaded', 'init_stripe_payment_gateway');
function init_stripe_payment_gateway()
{
    class WC_Stripe_Payment_Gateway extends WC_Payment_Gateway
    {
        public function __construct()
        {
            $this->id = 'stripe';
            $this->method_title = 'Stripe';
            $this->method_description = 'Pay with Stripe';
            $this->has_fields = false;
            $this->init_form_fields();
            $this->init_settings();
            $this->title = $this->get_option('title');
            $this->description = $this->get_option('description');
            $this->enabled = $this->get_option('enabled');
            $this->supports = array('products');
            add_action('woocommerce_update_options_payment_gateways_' . $this->id, array($this, 'process_admin_options'));
            add_action('woocommerce_receipt_' . $this->id, array($this, 'receipt_page'));
        }

        public function init_form_fields()
        {
            $this->form_fields = array(
                'enabled' => array(
                    'title' => __('Enable/Disable', 'woocommerce'),
                    'type' => 'checkbox',
                    'label' => __('Enable Stripe Payment', 'woocommerce'),
                    'default' => 'yes'
                ),
                'title' => array(
                    'title' => __('Title', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('This controls the title which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Stripe', 'woocommerce'),
                    'desc_tip' => true
                ),
                'description' => array(
                    'title' => __('Description', 'woocommerce'),
                    'type' => 'textarea',
                    'description' => __('This controls the description which the user sees during checkout.', 'woocommerce'),
                    'default' => __('Pay with Stripe', 'woocommerce'),
                    'desc_tip' => true
                ),
                'stripe_api_key' => array(
                    'title' => __('Stripe Secret API Key', 'woocommerce'),
                    'type' => 'text',
                    'description' => __('Enter your Stripe Secret API Key.', 'woocommerce'),
                    'desc_tip' => true
                )
            );
        }

        public function process_payment($order_id)
        {
            $order = wc_get_order($order_id);
            $order->reduce_order_stock();
            WC()->cart->empty_cart();

            $url = $this->create_stripe_checkout_session($order);

            if ($url) {
                return array(
                    'result' => 'success',
                    'redirect' => $url
                );
            } else {
                wc_add_notice(__('Stripe session creation failed.', 'woocommerce'), 'error');
                return;
            }
        }

        public function receipt_page($order)
        {
            // Not used in this implementation
        }

        public function create_stripe_checkout_session($order)
        {
            $stripe_api_key = $this->get_option('stripe_api_key');

            if (empty($stripe_api_key)) {
                wc_add_notice(__('Stripe API Key is missing. Please configure the plugin settings.', 'woocommerce'), 'error');
                return;
            }

            \Stripe\Stripe::setApiKey($stripe_api_key);

            $session_args = array(
                'payment_method_types' => array('card'),
                'line_items' => array(
                    array(
                        'price_data' => array(
                            'currency' => get_woocommerce_currency(),
                            'unit_amount' => wc_format_decimal($order->get_total() * 100, 0),
                            'product_data' => array(
                                'name' => get_bloginfo('name') . ' Order'
                            ),
                        ),
                        'quantity' => 1,
                    ),
                ),
                'success_url' => $this->get_return_url($order),
                'cancel_url' => $order->get_cancel_order_url(),
                'client_reference_id' => $order->get_id(),
                'mode' => 'payment',
            );

            $session = \Stripe\Checkout\Session::create($session_args);

            return $session->url;
        }
    }
}