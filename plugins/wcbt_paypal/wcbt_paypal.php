<?php
/**
 * Plugin Name: Braintree v.Zero with Paypal
 * Plugin URI:
 * Description: Processes Paypal Payments through
 * Version: 1.0
 * Author: Juan Carlos Moreno
 * Author URI:
 * License: Apache2
 */

if ( ! defined( 'ABSPATH' ) ) exit;

require_once(untrailingslashit( plugin_dir_path(__FILE__) )  . '/lib/Braintree.php' );

add_action( 'plugins_loaded', 'init_v_zero_gateway_class' );

function init_v_zero_gateway_class() {
    class WC_Braintree_vZero extends WC_Payment_Gateway {

        public function __construct() {
            $this->id = "braintree_v_dot_zero";
            $this->icon = "";
            $this->has_fields = true;
            $this->method_title  = "Braintree v.Zero";
            $this->method_description  = "Braintree v.Zero";
            $this->chosen = true;
            $this->environment;
            $this->merchant_id;
            $this->public_key;
            $this->private_key;

            $this->init_form_fields();
            $this->init_settings();

            $this->title = $this->get_option( 'title' );

            add_action( 'woocommerce_update_options_payment_gateways_' . $this->id, array( $this, 'process_admin_options' ) );

            foreach ( $this->settings as $setting_key => $setting ) {
                $settings_name = "WCBT_".strtoupper ( "$setting_key" );
                if (defined($settings_name))
                {
                    $this->$setting_key = constant($settings_name);
                }
            }
            add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_js' ) );
        }

        public function payment_fields() {
            $template = locate_template('woocommerce/checkout/wcbt_paypal_checkout_fields.php');
            if(! $template)
            {
                $template = wc_locate_template('checkout/wcbt_paypal_checkout_fields.php');
            }
            include_once($template);
        }

        public function enqueue_js() {
            wp_enqueue_script( 'braintree-js-v2', 'https://js.braintreegateway.com/v2/braintree.js');
        }

        public function configure_environment()
        {
            if ( ! $this->is_available() ) {
			    return;
    		}

            Braintree_Configuration::environment($this->environment);
            Braintree_Configuration::merchantId($this->merchant_id );
            Braintree_Configuration::publicKey($this->public_key);
            Braintree_Configuration::privateKey($this->private_key);
        }

        public function get_client_token()
        {
            $this->configure_environment();
            $clientToken = Braintree_ClientToken::generate();
            return $clientToken;
        }

        private function get_bt_user_by_email($email)
        {
            if (!$email)
                return false;

            $this->configure_environment();
            $collection = Braintree_Customer::search(array(
                Braintree_CustomerSearch::email()->is($email),
            ));

            foreach($collection as $coll)
            {
                return $coll->id;
            }
            return false;
        }

        public function get_bt_user()
        {
            $customer_id = get_user_meta( get_current_user_id(), '_wc_btv0_customer_id', true );
            if (!$customer_id)
            {
                // Second attempt try to get for existing customers of woocommerce's version of the plugin
                $customer_id = get_user_meta( get_current_user_id(), '_wc_braintree_customer_id', true );
                if (!$customer_id)
                {
                    $user = get_userdata(get_current_user_id());
                    $email = $user->user_email;
                    $customer_id = $this->get_bt_user_by_email($email);
                }
            }
            return $customer_id;
        }

        public function get_my_cards( ) {
            $cards = array();
            $customer_id = $this->get_bt_user();

            if ( $customer_id ) {
                try {
                    $this->configure_environment();
                    $customer = Braintree_Customer::find( $customer_id );
                    $card_uiniques = array();
                    $bt_cards = ( ! empty( $customer->creditCards ) ) ? $customer->creditCards : array();
                    $cards = array();
                    foreach($bt_cards as $card)
                    {
                        if (in_array($card->uniqueNumberIdentifier, $card_uiniques))
                        {
                            $response = Braintree_PaymentMethod::delete($card->token);
                            if ($response->success){
                                continue;
                            }
                        }
                        else
                        {
                            array_push($card_uiniques, $card->uniqueNumberIdentifier);
                            array_push($cards, $card);
                        }
                    }
                    return $cards;

                } catch ( Exception $e ) {
                    error_log(print_r($e, true));
                }
            }

            return $cards;
        }

        function do_order($order, $response){
            // Upon guest checkout attach order to existing user
            if ( !is_user_logged_in() || 0 == $order->user_id )
            {
                $wpuser  = get_user_by('email', $order->billing_email);
                if ($wpuser)
                {
                    update_post_meta( $order->id, '_customer_user', $wpuser->ID );
                }
            }

            // Reduce stock levels
            //$order->reduce_order_stock();

            global $woocommerce;

            $woocommerce->cart->empty_cart();

            // Return thankyou redirect
            $order->add_order_note( sprintf( 'BrainTree Transaction ID: %s Env: %s Card Type: %s Last 4: %s Customer Token %s' ,
					$response->transaction->id,
					$this->environment,
					$response->transaction->creditCardDetails->cardType,
					$response->transaction->creditCardDetails->last4,
					$response->transaction->creditCardDetails->token )
			);

						// save transaction info as order meta
			update_post_meta( $order->id, 'Braintree Transaction Id', $response->transaction->id );

			// update customer ID and credit card token
			update_post_meta( $order->id, 'Braintree Customer',    $response->transaction->customerDetails->id );

			// update braintree customer ID saved to user
			update_user_meta( $order->user_id, 'braintree_v0_customer_id', $response->transaction->customerDetails->id );


            $order->payment_complete();
        }


        public function process_payment($order_id )
        {
            global $woocommerce;
            $order = new WC_Order( $order_id );
            $nonce = $_POST["payment-method-nonce"];
            $this->configure_environment();
            $sale_args = array(
              'amount' =>  $order->get_total(),
            );

            if ($nonce)
            {
                $sale_args['paymentMethodNonce'] = $nonce;
                $sale_args['options'] = array('submitForSettlement' => true);

                if ($_POST['btvz-payment-type'] !== 'paypal')
                {
                    if ( is_user_logged_in() || 0 != $order->user_id )
                    {
                        $options = $sale_args['options'];
                        $options['storeInVaultOnSuccess']= true;
                        $sale_args['options'] = $options;
                    }

                    $sale_args['billing'] = array(
                        'firstName'         => $order->billing_first_name,
                        'lastName'          => $order->billing_last_name,
                        //'streetAddress'     => $order->billing_address_1,
                        //'extendedAddress'   => $order->billing_address_2,
                        //'locality'          => $order->billing_city,
                        //'region'            => $order->billing_state,
                        //'postalCode'        => $order->billing_postcode,
                        //'countryCodeAlpha2' => $order->billing_country,
                    );

                    // Hack to get the Zip from the cc different than the shipping
                    if (isset($_POST["wcbtv0-cc-zip"]) && !empty($_POST['wcbtv0-cc-zip'])){
                        $sale_args['billing']["postalCode"] = $_POST['wcbtv0-cc-zip'];
                    }
                    else
                    {
                        $woocommerce->add_error("No Credit Card Zip was provided");
                        return array("result"=>"failure",
                                        "reload"=>"false");
                    }
                }
            }
            else
            {
                $cctoken = $_POST["ccid"];
                if ($cctoken)
                {
                    $sale_args['paymentMethodToken'] = $cctoken;
                    $sale_args['options'] = array('submitForSettlement' => true);
                    if (isset($_POST["stored_cc_cvv"]) && !empty($_POST['stored_cc_cvv'])){
                        $sale_args['creditCard']['cvv'] = $_POST['stored_cc_cvv'];
                    }
                    if (isset($_POST["stored_cc_zip"]) && !empty($_POST['stored_cc_zip'])){
                        $sale_args['billing']["postalCode"] = $_POST['stored_cc_zip'];
                    }
                }
            }


            $bt_customer_id = $this->get_bt_user();
            if (!$bt_customer_id)
            {
                // Create customer
                $result = Braintree_Customer::create(array(
                    'firstName' => $order->billing_first_name,
                    'lastName' => $order->billing_last_name,
                    'email' => $order->billing_email,
                ));
                if ($result->success)
                {
                    $bt_customer_id = $result->customer->id;
                }
            }

            if ($bt_customer_id)
            {
                $sale_args['customerId'] = $bt_customer_id;
            }

            $response = Braintree_Transaction::sale($sale_args);

            if ($response->success)
            {
                $this->do_order($order, $response);

                return array(
                	'result' => 'success',
            	    'redirect' => $this->get_return_url( $order )
                );
            }
            $errors = $response->errors->deepAll();

            if (count($errors)==0)
            {
                $trans = $response->transaction;
                $rejection = $trans->gatewayRejectionReason;
                if (isset($rejection)  && !empty($rejection))
                {
                    $woocommerce->add_error("Looks like there's an issue with your credit card, please check your information and try again.");
                }
                return array("result"=>"failure",
                        "reload"=>"false");
            }
            $has_general_error = false;
            /// Ghetto array that shows errors that are ok to show customers
            $passthrough_errors = array(Braintree_Error_Codes::CREDIT_CARD_CVV_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_CVV_IS_REQUIRED,
                                        Braintree_Error_Codes::CREDIT_CARD_CVV_VERIFICATION_FAILED,
                                        Braintree_Error_Codes::CREDIT_CARD_CVV_VERIFICATION_FAILED,
                                        Braintree_Error_Codes::CREDIT_CARD_NUMBER_INVALID_LENGTH,
                                        Braintree_Error_Codes::CREDIT_CARD_NUMBER_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_NUMBER_IS_REQUIRED,
                                        Braintree_Error_Codes::CREDIT_CARD_NUMBER_LENGTH_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_EXPIRATION_DATE_CONFLICT,
                                        Braintree_Error_Codes::CREDIT_CARD_EXPIRATION_DATE_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_EXPIRATION_DATE_IS_REQUIRED,
                                        Braintree_Error_Codes::CREDIT_CARD_EXPIRATION_DATE_YEAR_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_EXPIRATION_MONTH_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_EXPIRATION_YEAR_IS_INVALID,
                                        Braintree_Error_Codes::CREDIT_CARD_BILLING_ADDRESS_CONFLICT,
                                        Braintree_Error_Codes::CREDIT_CARD_BILLING_ADDRESS_ID_IS_INVALID);
            foreach ($errors as $error)
            {
                if ( in_array($error->code, $passthrough_errors))
                {
                    $woocommerce->add_error($error->message);
                }
                else
                {
                    if (!$has_general_error)
                    {
                        error_log("Error ". print_r($error->code, true) ." " . print_r($error->message, true));
                        $woocommerce->add_error("There is an error with the payment form supplied, please verify and resubmit");
                        $has_general_error = true;
                    }
                }
            }

            return array("result"=>"failure",
                        "reload"=>"false");
        }

        function init_form_fields() {

            $this->form_fields = array(
                    'enabled' => array(
                        'title' => __( 'Enable/Disable', 'woocommerce' ),
                        'type' => 'checkbox',
                        'default' => 'yes'
                    ),
                    'environment' => array(
                        'title'       => __( 'Environment', 'woocommerce' ),
                        'type'        => 'select',
                        'description' => __( 'What environment do you want your transactions posted to?', 'woocommerce'),
                        'default'     => 'production',
                        'options'     => array(
                                'sandbox'     => __( 'Sandbox', 'woocommerce'),
                                'production'  => __( 'Production', 'woocommerce' ),
                        ),
                    ),
                    'merchant_id' => array(
                        'title'       => __( 'Merchant ID', 'woocommerce' ),
                        'type'        => 'text',
                        'desc_tip'    => __( 'The Merchant ID for your Braintree account.', 'woocommerce-bt-vzero'),
                        'default'     => '',
                    ),
                    'merchant_account_id' => array(
                        'title'    => __( 'Merchant Account ID','woocommerce' ),
                        'type'     => 'text',
                        'desc_tip' => __( 'Optional merchant account ID. Leave blank to use your default merchant account, or if you have multiple accounts specify which one to use for processing transactions.', 'woocommerce-bt-vzero' ),
                        'default'  => '',
                    ),
                    'public_key' => array(
                        'title'       => __( 'Public Key','woocommerce'),
                        'type'        => 'text',
                        'desc_tip'    => __( 'The Public Key for your Braintree account.', 'woocommerce'),
                        'default'     => '',
                    ),
                    'private_key' => array(
                        'title'       => __( 'Private Key', 'woocommerce' ),
                        'type'        => 'password',
                        'desc_tip'    => __( 'The Private Key for your Braintree account.', 'woocommerce' ),
                        'default'     => '',
                    ),
                    'cse_key' => array(
                        'title'       => __( 'Client-Side Encryption Key', 'woocommerce' ),
                        'type'        => 'textarea',
                        'desc_tip'    => __( 'The Client-Side Encryption Key for your Braintree account.', 'woocommerce' ),
                        'default'     => '',
                        'css'         => 'max-width: 300px;',
                    ),
                );
        }
    }

    function add_braintree_paypal_gateway( $methods ) {
        $methods[] = 'WC_Braintree_vZero';
        return $methods;
    }

    function wcbtv0_get_credit_cards()
    {
        $gateway = new WC_Braintree_vZero();

        $cards = $gateway->get_my_cards( );

        $template = locate_template('woocommerce/myaccount/my-cards.php');
        if(! $template)
        {
            $template = wc_locate_template('myaccount/my-cards.php');
        }
        include_once($template);

        return $cards;
    }

    function wcbtv0_get_credit_cards_cb()
    {
        $token =  $_POST['vid'];
        if ( !is_user_logged_in())
        {
            http_response_code(401);
            die();
        }

        if (!isset($token) || empty($token))
        {
            http_response_code(401);
            die();
        }

        $gateway = new WC_Braintree_vZero();
        $cards = $gateway->get_my_cards( );

        foreach($cards as $card)
        {
            if ($card->token == $token)
            {
                $gateway->configure_environment();
                $response = Braintree_PaymentMethod::delete($token);
                if ($response->success){

                    http_response_code(200);
                }
                die();
            }
        }
        http_response_code(403);
        die();
    }

    add_action( 'wp_ajax_wcbtv0_remove_card', 'wcbtv0_get_credit_cards_cb' );

    add_filter( 'woocommerce_payment_gateways', 'add_braintree_paypal_gateway' );
}


if ( in_array( 'woocommerce/woocommerce.php', apply_filters( 'active_plugins', get_option( 'active_plugins' ) ) ) ) {
    // Put your plugin code here

}




?>
