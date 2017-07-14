<?php
/**
 * WooCommerce Jilt
 *
 * This source file is subject to the GNU General Public License v3.0
 * that is bundled with this package in the file license.txt.
 * It is also available through the world-wide-web at this URL:
 * http://www.gnu.org/licenses/gpl-3.0.html
 * If you did not receive a copy of the license and are unable to
 * obtain it through the world-wide-web, please send an email
 * to license@jilt.com so we can send you a copy immediately.
 *
 * DISCLAIMER
 *
 * Do not edit or add to this file if you wish to upgrade WooCommerce Jilt to newer
 * versions in the future. If you wish to customize WooCommerce Jilt for your
 * needs please refer to http://help.jilt.com/collection/176-jilt-for-woocommerce
 *
 * @package   WC-Jilt/Frontend
 * @author    Jilt
 * @category  Frontend
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * WC-API handler class
 *
 * @since 1.0.0
 */
class WC_Jilt_WC_API_Handler {

	/** @var  \WC_Jilt_Integration_API instance */
	protected $integration_api;


	/**
	 * Setup class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		add_action( 'woocommerce_api_jilt', array( $this, 'route' ) );
	}


	/**
	 * Handle requests to the Jilt WC API endpoint
	 *
	 * @since 1.0.0
	 */
	public function route() {

		// recovery URL
		if ( ! empty( $_REQUEST['token'] ) && ! empty( $_REQUEST['hash'] ) ) {
			$this->handle_recreate_cart();
		}

		// server-to-server API request
		if ( ! empty( $_REQUEST['resource'] ) ) {
			$this->get_integration_api()->handle_api_request( $_REQUEST );
		}

		// unhandled response: identify the response as coming from the Jilt for WooCommerce plugin
		@header( 'x-jilt-version: ' . wc_jilt()->get_version() );
	}


	/**
	 * Attempt to recreate the cart. Log an error/display storefront notice on
	 * failure, and either way redirect to the checkout page
	 *
	 * @since 1.1.0
	 */
	protected function handle_recreate_cart() {

		$checkout_url = wc_get_checkout_url();

		try {

			$this->recreate_cart();

			// if a coupon was provided in the recovery URL, set it so it can be applied after redirecting to checkout
			if ( isset( $_REQUEST['coupon'] ) && $coupon = rawurldecode( $_REQUEST['coupon'] ) ) {

				$checkout_url = add_query_arg( array( 'coupon' => wc_clean( $coupon ) ), $checkout_url );
			}

		} catch ( SV_WC_Plugin_Exception $e ) {

			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_WARN, 'Could not recreate cart: ' . $e->getMessage() );

			wc_add_notice( __( 'Oops, something went wrong. Please try again.', 'jilt-for-woocommerce' ), 'error' );
		}

		wp_safe_redirect( $checkout_url );
		exit;
	}


	/**
	 * Get the integration API class instance
	 *
	 * @since 1.1.0
	 * @return WC_Jilt_Integration_API the integration API instance
	 */
	private function get_integration_api() {
		if ( null === $this->integration_api ) {
			$this->integration_api = new WC_Jilt_Integration_API();
		}

		return $this->integration_api;
	}


	/** Recreate Cart Helpers ******************************************************/


	/**
	 * Recreate & checkout a cart from a Jilt checkout link
	 *
	 * Note: This behavior is not bypassed when the integration is disabled as
	 * it's always going to be valuable to a merchant to have functional
	 * recovery URLs
	 *
	 * @since 1.0.0
	 * @throws \SV_WC_Plugin_Exception if hash verification fails
	 */
	protected function recreate_cart() {

		$data = rawurldecode( $_REQUEST['token'] );
		$hash = $_REQUEST['hash'];

		// verify hash
		if ( ! hash_equals( hash_hmac( 'sha256', $data, wc_jilt()->get_integration()->get_secret_key() ), $hash ) ) {
			throw new SV_WC_Plugin_Exception( 'hash verification failed' );
		}

		// decode
		$data = json_decode( base64_decode( $data ) );

		// readability
		$jilt_order_id = $data->order_id;
		$cart_token    = $data->cart_token;

		if ( ! $jilt_order_id || ! $cart_token ) {
			throw new SV_WC_Plugin_Exception( 'Jilt order ID and/or cart token are empty.' );
		}

		// get Jilt order for verifying URL and recreating cart if session is not present
		$jilt_order = wc_jilt()->get_integration()->get_api()->get_order( (int) $jilt_order_id );

		// verify cart token matches
		if ( ! hash_equals( $jilt_order->cart_token, $cart_token ) ) {
			throw new SV_WC_Plugin_Exception( "cart token verification failed for Jilt order ID: {$jilt_order_id}" );
		}

		// check if the order for this cart has already been placed
		$order_id = $this->get_order_id_for_cart_token( $cart_token );

		if ( $order_id && $order = wc_get_order( $order_id ) ) {

			$note = __( 'Customer visited Jilt order recovery URL.', 'jilt-for-woocommerce' );

			// re-enable a cancelled order for payment
			if ( $order->has_status( 'cancelled' ) ) {
				$order->update_status( 'pending', $note );
			} else {
				$order->add_order_note( $note );
			}

			$redirect = $order->needs_payment() ? $order->get_checkout_payment_url() : $order->get_checkout_order_received_url();

			WC()->session->set( 'wc_jilt_pending_recovery', true );

			// set (or refresh, if already set) session
			WC()->session->set_customer_session_cookie( true );

			wp_safe_redirect( $redirect );
			exit;
		}

		// check if cart is associated with a registered user / persistent cart
		$user_id = $this->get_user_id_for_cart_token( $cart_token );

		// order id is associated with a registered user
		if ( $user_id ) {

			$this->recreate_cart_for_user( $user_id, $jilt_order );

		} else {

			// set customer note in session, if present
			if ( $jilt_order->note ) {
				WC()->session->set( 'wc_jilt_order_note', $jilt_order->note );
			}

			// guest user
			$session = WC()->session->get_session( $jilt_order->client_session->token );

			if ( empty( $session ) || empty( $session['cart'] ) ) {
				$this->recreate_cart_from_jilt_order( $jilt_order );
			} else {
				$this->recreate_cart_for_guest( $session, $jilt_order );
			}
		}
	}


	/**
	 * Recreate cart for a user
	 *
	 * @since 1.0.0
	 * @param int $user_id The user ID
	 * @param \stdClass $jilt_order
	 */
	protected function recreate_cart_for_user( $user_id, $jilt_order ) {

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, "Recreating cart for registered user: {$user_id}" );

		if ( is_user_logged_in() ) {

			// another user is logged in
			if ( (int) $user_id !== get_current_user_id() ) {

				// log the current user out, log in the new one
				if ( $this->allow_cart_recovery_user_login( $user_id ) ) {

					wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, "Another user is logged in, logging them out & logging in user {$user_id}" );

					wp_logout();
					wp_set_current_user( $user_id );
					wp_set_auth_cookie( $user_id );

				// safety check fail: do not let an admin to be logged in automatically
				} else {

					wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_WARN, "Not logging in user {$user_id} with admin rights" );
					return;
				}

			} else {

				wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'User is already logged in' );
			}

		} else {

			// log the user in automatically
			if ( $this->allow_cart_recovery_user_login( $user_id ) ) {

				wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'User is not logged in, logging in' );
				wp_set_current_user( $user_id );
				wp_set_auth_cookie( $user_id );

			// safety check fail: do not let an admin to be logged in automatically
			} else {

				wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_WARN, "Not logging in user {$user_id} with admin rights" );
				return;
			}
		}

		update_user_meta( $user_id, '_wc_jilt_pending_recovery', true );

		// save order note to be applied after redirect
		if ( $jilt_order->note ) {
			update_user_meta( $user_id, '_wc_jilt_order_note', $jilt_order->note );
		}

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'Cart recreated from persistent cart' );
	}


	/**
	 * Check if a user is allowed to be logged in for cart recovery
	 *
	 * @since 1.0.0
	 * @param int $user_id WP_User id
	 * @return bool
	 */
	private function allow_cart_recovery_user_login( $user_id ) {

		/**
		 * Filter users who do not possess high level rights
		 * to be logged in automatically upon cart recovery
		 *
		 * @since 1.0.0
		 * @param bool $allow_user_login Whether to allow or disallow
		 * @param int $user_id The user to log in
		 */
		$allow_user_login = apply_filters( 'wc_jilt_allow_cart_recovery_user_login', ! user_can( $user_id, 'edit_others_posts' ), $user_id );

		return (bool) $allow_user_login;
	}


	/**
	 * Recreate cart for a guest
	 *
	 * @TODO: this method is now very similar to the recreate_cart_from_jilt_order()
	 * method and can probably be merged/refactored to be more DRY {MR 2016-05-18}
	 *
	 * @since 1.0.0
	 * @param array $session retrieved session from db
	 * @param stdClass $jilt_order
	 */
	protected function recreate_cart_for_guest( $session, $jilt_order ) {

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'Recreating cart for guest user with active session' );

		// recreate cart
		$cart = maybe_unserialize( $session['cart'] );

		$existing_cart_hash = md5( wp_json_encode( WC()->session->cart ) );
		$loaded_cart_hash   = md5( wp_json_encode( $cart ) );

		// avoid re-setting the cart object if it matches the existing session cart
		if ( ! hash_equals( $existing_cart_hash, $loaded_cart_hash ) ) {

			WC()->session->set( 'cart', $cart );

			// apply any valid coupons
			$applied_coupons = maybe_unserialize( $session['applied_coupons'] );
			WC()->session->set( 'applied_coupons', $this->get_valid_coupons( $applied_coupons ) );

			// select the chosen shipping methods if any
			$chosen_shipping_methods = maybe_unserialize( $session['chosen_shipping_methods'] );
			WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );

			$shipping_method_counts = maybe_unserialize( $session['shipping_method_counts'] );
			WC()->session->set( 'shipping_method_counts', $shipping_method_counts );

			// select the chosen payment method if any
			WC()->session->set( 'chosen_payment_method', $session['chosen_payment_method'] );
		}

		// set customer data
		$this->set_customer_session_data( $session, $jilt_order );

		// set Jilt data in session
		WC()->session->set( 'wc_jilt_cart_token', $jilt_order->cart_token );
		WC()->session->set( 'wc_jilt_order_id', $jilt_order->id );
		WC()->session->set( 'wc_jilt_pending_recovery', true );

		// set (or refresh, if already set) session
		WC()->session->set_customer_session_cookie( true );

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'Cart recreated from session' );
	}


	/**
	 * Recreate the entire cart from a Jilt order. Generally used when a guest
	 * customer's existing session has expired
	 *
	 * TODO: this generates a new session entry each time the checkout recovery
	 * URL is visited because the customer ID for a new session can't be set (WC
	 * generates it internally). Extra sessions really don't matter too much
	 * (they still have the 48 hour expiration) but it's not super clean either.
	 * May be worth a PR to WC core to allow customer IDs to be set (perhaps via
	 * the WC_Session_Handler::set_customer_session_cookie() method) {MR 2016-05-18}
	 *
	 * @since 1.0.0
	 * @param stdClass $jilt_order
	 * @throws SV_WC_Plugin_Exception if required data is missing
	 */
	protected function recreate_cart_from_jilt_order( $jilt_order ) {

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'Recreating cart for guest user with no active session' );

		// cart data must be array, JSON encode/decode is a hack to recursively convert object to array
		$cart                    = json_decode( wp_json_encode( $jilt_order->client_session->cart ), true );
		$applied_coupons         = (array) $jilt_order->client_session->applied_coupons;
		$chosen_shipping_methods = (array) $jilt_order->client_session->chosen_shipping_methods;
		$shipping_method_counts  = (array) $jilt_order->client_session->shipping_method_counts;
		$chosen_payment_method   = $jilt_order->client_session->chosen_payment_method;

		// sanity check
		if ( empty( $cart ) ) {
			throw new SV_WC_Plugin_Exception( 'Cart missing from Jilt order client session' );
		}

		// base session data
		WC()->session->set( 'cart', $cart );
		WC()->session->set( 'applied_coupons', $this->get_valid_coupons( $applied_coupons ) );
		WC()->session->set( 'chosen_shipping_methods', $chosen_shipping_methods );
		WC()->session->set( 'shipping_method_counts', $shipping_method_counts );
		WC()->session->set( 'chosen_payment_method', $chosen_payment_method );

		// customer
		$this->set_customer_session_data( array(), $jilt_order );

		// Jilt data
		WC()->session->set( 'wc_jilt_cart_token', $jilt_order->cart_token );
		WC()->session->set( 'wc_jilt_order_id', $jilt_order->id );
		WC()->session->set( 'wc_jilt_pending_recovery', true );

		// set (or refresh, if already set) session
		WC()->session->set_customer_session_cookie( true );

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, 'Cart recreated from Jilt order' );
	}


	/** Helper methods ******************************************************/


	/**
	 * Set the customer session data after merging the existing (if any) customer
	 * data in the session with the customer data provided in the Jilt order.
	 *
	 * TODO: This is a compatibility method that can be refactored when WC 3.0+
	 * is required. {MR 2017-03-28}
	 *
	 * @since 1.1.0
	 * @param array $session session data
	 * @param \stdClass $jilt_order Jilt order retrieved from the API
	 */
	protected function set_customer_session_data( $session, $jilt_order ) {

		$session_customer = isset( $session['customer'] ) ? maybe_unserialize( $session['customer'] ) : array();

		$customer_data = array();

		$has_billing_address  = isset( $jilt_order->billing_address )  && ! empty( $jilt_order->billing_address );
		$has_shipping_address = isset( $jilt_order->shipping_address ) && ! empty( $jilt_order->shipping_address );

		foreach ( WC_Jilt_Order::get_jilt_order_address_mapping() as $wc_field => $jilt_field ) {

			if ( $has_billing_address && isset( $jilt_order->billing_address->{$jilt_field} ) ) {
				$customer_data[ $wc_field ] = $jilt_order->billing_address->{$jilt_field};
			}

			if ( $has_shipping_address && isset( $jilt_order->shipping_address->{$jilt_field} ) ) {
				$customer_data[ 'shipping_' . $wc_field ] = $jilt_order->shipping_address->{$jilt_field};
			}
		}

		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

			foreach ( $customer_data as $key => $value ) {

				$method = SV_WC_Helper::str_starts_with( $key, 'shipping_' ) ? "set_{$key}" : "set_billing_{$key}";

				// note that the set_*() methods _must_ be used, as any customer data that's set
				// directly in the session is overwritten on the shutdown hook, see
				// WooCommerce::init()
				if ( is_callable( array( WC()->customer, $method ) ) ) {
					WC()->customer->$method( $value );
				}
			}

		} else {

			WC()->session->set( 'customer', array_merge( $session_customer, $customer_data ) );
		}
	}


	/**
	 * Returns $coupons, with any invalid coupons removed
	 *
	 * @since 1.0.3
	 * @param array $coupons array of string coupon codes
	 * @return array $coupons with any invalid codes removed
	 */
	private function get_valid_coupons( $coupons ) {
		$valid_coupons = array();

		if ( $coupons ) {
			foreach ( $coupons as $coupon_code ) {
				$the_coupon = new WC_Coupon( $coupon_code );

				if ( ! $the_coupon->is_valid() ) {
					continue;
				}

				$valid_coupons[] = $coupon_code;
			}
		}

		return $valid_coupons;
	}


	/**
	 * Get order ID for the provided cart token
	 *
	 * @since 1.0.0
	 * @param string $cart_token
	 * @return int|null Order ID, if found, null otherwise
	 */
	private function get_order_id_for_cart_token( $cart_token ) {

		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "
			SELECT post_id
			FROM {$wpdb->postmeta}
			WHERE meta_key = '_wc_jilt_cart_token'
			AND meta_value = %s
		", $cart_token ) );
	}


	/**
	 * Get user ID for the provided cart token
	 *
	 * @since 1.0.0
	 * @param string $cart_token
	 * @return int|null User ID, if found, null otherwise
	 */
	private function get_user_id_for_cart_token( $cart_token ) {

		global $wpdb;

		return $wpdb->get_var( $wpdb->prepare( "
			SELECT user_id
			FROM {$wpdb->usermeta}
			WHERE meta_key = '_wc_jilt_cart_token'
			AND meta_value = %s
		", $cart_token ) );
	}


}
