<?php
// Paystack webhook code goes here. /// Change this.

/// define
define( 'PMPRO_PAYSTACK_WEBHOOK_DEBUG', 'log' );

// Let's make sure the request came from Paystack by checking the secret key
if ( ( strtoupper( $_SERVER['REQUEST_METHOD'] ) != 'POST' ) || ! array_key_exists( 'HTTP_X_PAYSTACK_SIGNATURE', $_SERVER ) ) {
    pmpro_paystack_webhook_log( 'Paystack signature not found' );
    pmpro_paystack_webhook_exit();
}

// Log all the post data.
pmpro_paystack_webhook_log( 'Request:' . print_r( $_REQUEST, true ) );

// Get the relevant secret key based on gateway environment.
$gateway_environment = get_option( 'pmpro_gateway_environment' );

if ( $gateway_environment == 'sandbox' ) {
    $secret_key = get_option( 'pmpro_paystack_tsk' );
    $public_key = get_option( 'pmpro_paystack_tpk' );
} else {
    $secret_key = get_option( 'pmpro_paystack_lsk' );
    $public_key = get_option( 'pmpro_paystack_lpk' );
}

// Get the input from Paystack.
$body = @file_get_contents( 'php://input' );
$post_event = json_decode( $body );

// The Paystack signature doesn't match the secret key, let's bail.
if ( $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] !== hash_hmac('sha512', $body, $secret_key ) ) {
    pmpro_paystack_webhook_log( 'Paystack signature does not match.' );
    pmpro_paystack_webhook_exit();
}

pmpro_paystack_webhook_log( 'Event: ' . print_r( $post_event, true ) );

switch( $post_event->event ){
case 'subscription.create':
    // This also runs from time to time? I think this is the first time it runs.
    pmpro_paystack_renew_payment( $post_event );
    break;
case 'subscription.disable': /// Cancel the membership since it's done via the IPN.
    pmpro_handle_subscription_cancellation_at_gateway( $subscription_id, 'paystack', $gateway_environment );
    pmpro_paystack_webhook_exit();
    break;
case 'charge.success': // This can change too.
    $morder =  new MemberOrder($post_event->data->reference);
    $morder->getMembershipLevel();
    $morder->getUser();
    pmpro_paystack_complete_order( $post_event->data->reference, $morder );
    pmpro_paystack_confirm_subscription( $post_event->data->reference, $morder ); // This will be for recurring subscriptions/levels.
    $pstk_logger = new pmpro_paystack_plugin_tracker( 'pm-pro', $public_key );
    $pstk_logger->log_transaction_success( $post_event->data->reference );
    pmpro_paystack_webhook_log( 'Charge success. Reference: ' . $post_event->data->reference );
    break;
case 'invoice.create':
    pmpro_paystack_renew_payment($post_event);
    break;
case 'invoice.update':
    pmpro_paystack_renew_payment($post_event);
    break;
}
http_response_code(200);
pmpro_paystack_webhook_exit();


/**
 * Complete the 
 *
 * @param [type] $reference
 * @param [type] $morder
 * @return void
 */
function pmpro_paystack_complete_order( $reference, &$order ) {

    // Only run this if we got an order.
    if ( empty( $order ) ) {
        return false;
    }

	// update order status and transaction ids
	$order->payment_transaction_id = $reference;

    /// Change this.
	if ( ! empty( $_POST['token'] ) ) {
		$order->subscription_transaction_id = sanitize_text_field( $_POST['m_payment_id'] );
	}

	$order->saveOrder(); // Temporarily save the order before processing it.

    
	// Change level and complete the order.
	pmpro_pull_checkout_data_from_order( $order );
	return pmpro_complete_async_checkout( $order );
	
}

// Confirm the subscription
function pmpro_paystack_confirm_subscription( $reference, $order ) {
    global $wpdb, $current_user, $pmpro_invoice, $pmpro_currency,$gateway;

    if (empty($pmpro_invoice)) {
        $morder =  new MemberOrder($reference);
        if (!empty($morder) && $morder->gateway == "paystack") $pmpro_invoice = $morder;
    }

    if (!empty($pmpro_invoice) && $pmpro_invoice->gateway == "paystack" && isset($pmpro_invoice->total) && $pmpro_invoice->total > 0) {
            $morder = $pmpro_invoice;
        if ($morder->code == $reference ) {

            /// Use pmpro_getLevel instead of a DB query.
            $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
            
            /// Don't need filters.
            $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
            $startdate = apply_filters("pmpro_checkout_start_date", "'" . current_time("mysql") . "'", $morder->user_id, $pmpro_level);

            // The level object from the order that can be filtered when returning from Paystack and user is getting their membership level.
            /// Deprecate this.
            $morder->membership_level = apply_filters( 'pmpro_paystack_webhook_level', $morder->membership_level, $morder->user_id );

            /// Fix this mode?
            $mode = pmpro_getOption("gateway_environment");
            if ($mode == "sandbox") {
                $key = pmpro_getOption("paystack_tsk");
                $pk = pmpro_getOption("paystack_tpk");
            } else {
                $key = pmpro_getOption("paystack_lsk");
                $pk = pmpro_getOption("paystack_lpk");
            }
            $paystack_url = 'https://api.paystack.co/transaction/verify/' . $reference;
            $headers = array(
                'Authorization' => 'Bearer ' . $key
            );
            $args = array(
                'headers'   => $headers,
                'timeout'   => 60
            );
            $request = wp_remote_get( $paystack_url, $args );
            
            if (!is_wp_error($request) && 200 == wp_remote_retrieve_response_code($request) ) {
                $paystack_response = json_decode(wp_remote_retrieve_body($request));

                if ( 'success' == $paystack_response->data->status ) {
                    $customer_code = $paystack_response->data->customer->customer_code;

                    //Add logger here
                    $pstk_logger = new pmpro_paystack_plugin_tracker('pm-pro',$pk);
                    $pstk_logger->log_transaction_success($reference);

                    // Get level from the order.
                    $pmpro_level = $morder->getMembershipLevel();

                    // There's recurring settings, lets convert to Paystack intervals now.
                    if ( $pmpro_level->billing_amount > 0 ) {

                        // Convert the PMPro cycle to match that of paystacks.
                        $pmpro_paystack = new PMProGateway_paystack();
                        $interval = $pmpro_paystack->convert_interval_for_paystack( $pmpro_level->cycle_period );

                        // Biannual and quarterly conversion for special cases.
                        if ( $pmpro_level->cycle_number == 3 && $pmpro_level->cycle_period == 'Month' ) {
                            $interval = 'quarterly';
                        }

                        if ( $pmpro_level->cycle_number == 6 && $pmpro_level->cycle_period == 'Month' ) {
                            $interval = 'biannually';
                        }

                        $amount = $pmpro_level->billing_amount;
                        $koboamount = $amount*100;
                        //Create Plan
                        $paystack_url = 'https://api.paystack.co/plan';
                        $subscription_url = 'https://api.paystack.co/subscription';
                        $check_url = 'https://api.paystack.co/plan?amount='.$koboamount.'&interval='.$interval;
                        $headers = array(
                            'Content-Type'  => 'application/json',
                            'Authorization' => 'Bearer ' . $key
                        );

                        $checkargs = array(
                            'headers' => $headers,
                            'timeout' => 60
                        );
                        // Check if plan exist
                        $checkrequest = wp_remote_get($check_url, $checkargs);
                        if (!is_wp_error($checkrequest)) {
                            $response = json_decode(wp_remote_retrieve_body($checkrequest));
                            if ($response->meta->total >= 1) {
                                $plan = $response->data[0];
                                $plancode = $plan->plan_code;

                            } else {
                                //Create Plan
                                $body = array(
                                    'name'      => '('.number_format($amount).') - '.$interval.' - ['.$pmpro_level->cycle_number.' - '.$pmpro_level->cycle_period.']' ,
                                    'amount'    => $koboamount,
                                    'interval'  => $interval
                                );
                                $args = array(
                                    'body'      => json_encode($body),
                                    'headers'   => $headers,
                                    'timeout'   => 60
                                );

                                $request = wp_remote_post($paystack_url, $args);
                                if (!is_wp_error($request)) {
                                    $paystack_response = json_decode(wp_remote_retrieve_body($request));
                                    $plancode = $paystack_response->data->plan_code;
                                }
                            }

                        }
                        
                        $subscription_delay = get_option( 'pmpro_subscription_delay_' . $pmpro_level->id, 0 );

                        $body = array(
                            'customer'  => $customer_code,
                            'plan'      => $plancode
                        );
                        
                        if ( $subscription_delay ) {
                                if ( ! is_numeric( $subscription_delay ) ) {
                                $start_date = kkd_pmprosd_convert_date( $subscription_delay );
                            } else {
                                $start_date = date( 'Y-m-d', strtotime( '+ ' . intval( $subscription_delay ) . ' Days', current_time( 'timestamp' ) ) );
                            }
                        } else {
                            // $start_date = current_time( 'mysql' );
                            $start_date = NULL;
                        }

                        // If we are tweaking the start date via Subscription Delays Add On, let's set that to the subscription.
                        if ( ! empty( $start_date ) ) {
                            $body['start_date'] = apply_filters( 'pmpro_paystack_subscription_start_date', $start_date );
                        }
                        
                        
                        $args = array(
                            'body'      => json_encode($body),
                            'headers'   => $headers,
                            'timeout'   => 60
                        );

                        $request = wp_remote_post($subscription_url, $args);
                        if (!is_wp_error($request)) {
                            $paystack_response = json_decode(wp_remote_retrieve_body($request));
                            if ( isset( $paystack_response->data->status ) && 'active' == $paystack_response->data->status ) {
                                $subscription_code = $paystack_response->data->subscription_code;
                                $token = $paystack_response->data->email_token;
                                $morder->subscription_transaction_id = $subscription_code;
                                $morder->subscription_token = $token;
                                $morder->saveOrder();
                            }
                        }
                    }

                    // get discount code     (NOTE: but discount_code isn't set here. How to handle discount codes for PayPal Standard?)
                    $morder->getDiscountCode();
                    if ( ! empty( $morder->discount_code ) ) {
                        // update membership level
                        $morder->getMembershipLevel( true );
                        $discount_code_id = $morder->discount_code->id;
                    } else {
                        $discount_code_id = '';
                    }

                    // Get the expiration date.
                    if ( ! empty( $morder->membership_level->expiration_number ) ) {
                        $enddate = "'" . date_i18n( "Y-m-d", strtotime( "+ " . $morder->membership_level->expiration_number . " " . $morder->membership_level->expiration_period, current_time( "timestamp" ) ) ) . "'";
                    } else {
                        $enddate = "NULL";
                    }

                    $custom_level = array(
                            'user_id'           => $morder->user_id,
                            'membership_id'     => $morder->membership_level->id,
                            'code_id'           => $discount_code_id,
                            'initial_payment'   => $morder->membership_level->initial_payment,
                            'billing_amount'    => $morder->membership_level->billing_amount,
                            'cycle_number'      => $morder->membership_level->cycle_number,
                            'cycle_period'      => $morder->membership_level->cycle_period,
                            'billing_limit'     => $morder->membership_level->billing_limit,
                            'trial_amount'      => $morder->membership_level->trial_amount,
                            'trial_limit'       => $morder->membership_level->trial_limit,
                            'startdate'         => $startdate,
                            'enddate'           => $enddate
                        );
                    if ($morder->status != 'success') {
                         
                        if (pmpro_changeMembershipLevel($custom_level, $morder->user_id, 'changed')) {
                            $morder->membership_id = $morder->membership_level->id;
                            $morder->payment_transaction_id = $reference;
                            $morder->status = "success";
                            $morder->saveOrder();
                        }

                    }
                    
                    if (!empty($morder)) {
                        $pmpro_invoice = new MemberOrder($morder->id);
                    } else {
                        $pmpro_invoice = null;
                    }

                    $current_user->membership_level = $pmpro_level; //make sure they have the right level info
                    $current_user->membership_level->enddate = $enddate;
                    if ($current_user->ID) {
                        $current_user->membership_level = pmpro_getMembershipLevelForUser($current_user->ID);
                        // echo "interesting";
                    }

                }
            }
        }
    }
}

// First stab at renewal payments. This is for recurring subs yo!
function pmpro_paystack_renew_payment( $post_event ) {
    global $wp,$wpdb;

    if (isset($event->data->paid) && ($event->data->paid == 1)) {

        $amount = $event->data->subscription->amount/100;
        $old_order = new MemberOrder();
        $subscription_code = $event->data->subscription->subscription_code;
        $email = $event->data->customer->email;
        $old_order->getLastMemberOrderBySubscriptionTransactionID($subscription_code);

       
        if (empty($old_order)) {
            pmpro_paystack_webhook_log( 'Could not find last order for subscription code: ' . $subscription_code );
            pmpro_paystack_webhook_exit();
        }
        $user_id = $old_order->user_id;
        $user = get_userdata($user_id);

        if (empty($user)) {
            pmpro_paystack_webhook_log( 'Could not get user for renewal payment' );
            pmpro_paystack_webhook_exit();
        }

        $morder = new MemberOrder();
        
        // Set the orders date to time it was paid.
        if ( ! empty( $event->data->paid_at ) ) {
            $morder->timestamp = strtotime( sanitize_text_field( $event->data->paid_at ) );
        }

        $morder->user_id = $old_order->user_id;
        $morder->membership_id = $old_order->membership_id;
        $morder->InitialPayment = $amount;  //not the initial payment, but the order class is expecting this
        $morder->PaymentAmount = $amount;
        $morder->payment_transaction_id = $event->data->invoice_code;
        $morder->subscription_transaction_id = $subscription_code;

        $morder->gateway = $old_order->gateway;
        $morder->gateway_environment = $old_order->gateway_environment;

        $morder->Email = $email;
        $pmpro_level = $wpdb->get_row("SELECT * FROM $wpdb->pmpro_membership_levels WHERE id = '" . (int)$morder->membership_id . "' LIMIT 1");
        $pmpro_level = apply_filters("pmpro_checkout_level", $pmpro_level);
        $startdate = apply_filters( 'pmpro_checkout_start_date', "'" . current_time( 'mysql' ) . "'", $morder->user_id, $morder->membership_level );

        // get discount code     (NOTE: but discount_code isn't set here. How to handle discount codes for PayPal Standard?)
        $morder->getDiscountCode();
        if ( ! empty( $morder->discount_code ) ) {
            // update membership level
            $morder->getMembershipLevel( true );
            $discount_code_id = $morder->discount_code->id;
        } else {
            $discount_code_id = '';
        }

        
        //fix expiration date
        if ( ! empty( $morder->membership_level->expiration_number ) ) {
            $enddate = "'" . date_i18n( "Y-m-d", strtotime( "+ " . $morder->membership_level->expiration_number . " " . $morder->membership_level->expiration_period, current_time( "timestamp" ) ) ) . "'";
        } else {
            $enddate = "NULL";
        }
        
        $custom_level = array(
            'user_id'           => $morder->user_id,
            'membership_id'     => $morder->memberhsip_level->id,
            'code_id'           => $discount_code_id,
            'initial_payment'   => $morder->memberhsip_level->initial_payment,
            'billing_amount'    => $morder->memberhsip_level->billing_amount,
            'cycle_number'      => $morder->memberhsip_level->cycle_number,
            'cycle_period'      => $morder->memberhsip_level->cycle_period,
            'billing_limit'     => $morder->memberhsip_level->billing_limit,
            'trial_amount'      => $morder->memberhsip_level->trial_amount,
            'trial_limit'       => $morder->memberhsip_level->trial_limit,
            'startdate'         => $startdate,
            'enddate'           => $enddate
        );

        //get CC info that is on file
        $morder->expirationmonth = get_user_meta($user_id, "pmpro_ExpirationMonth", true);
        $morder->expirationyear = get_user_meta($user_id, "pmpro_ExpirationYear", true);
        $morder->ExpirationDate = $morder->expirationmonth . $morder->expirationyear;
        $morder->ExpirationDate_YdashM = $morder->expirationyear . "-" . $morder->expirationmonth;


        // Save entire order data to IPN Log - loop through the order object.
        $order_data = array();
        foreach ( $morder as $key => $value ) {
            $order_data[ $key ] = $value;
        }
        pmpro_paystack_webhook_log( 'Order data: ' . print_r( $order_data, true ) );
        
        
        //save
        if ($morder->status != 'success') {

            $_REQUEST['cancel_membership'] = false; // Do NOT cancel gateway subscription

            if ( pmpro_changeMembershipLevel( $custom_level, $morder->user_id, 'changed' ) !== false  ) {
                $morder->status = "success";
            }

        }

        // Save the order before emailing the customer about it.
        $morder->saveOrder();
        $morder->getMemberOrderByID( $morder->id );

        //email the user their invoice
        $pmproemail = new PMProEmail();
        $pmproemail->sendInvoiceEmail($user, $morder);

        do_action('pmpro_subscription_payment_completed', $morder);
        pmpro_paystack_webhook_log( sprintf( 'Subscription payment completed for user with ID: %d. Order ID: %s', $user_id, $morder->code ) );
        pmpro_paystack_webhook_exit();
    }
}