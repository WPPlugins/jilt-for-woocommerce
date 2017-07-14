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
 * @package   WC-Jilt/Cart
 * @author    Jilt
 * @category  Frontend
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Cart class
 *
 * Handles cart interactions
 *
 * @since 1.0.0
 */
class WC_Jilt_Cart_Handler extends WC_Jilt_Handler {


	/**
	 * Setup class
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		$this->init();
	}


	/**
	 * Add hooks
	 *
	 * @since 1.0.0
	 */
	protected function init() {

		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'handle_persistent_cart' ) );

		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_scripts' ) );

		add_action( 'woocommerce_cart_updated', array( $this, 'cart_updated' ) );

		add_action( 'wp_login', array( $this, 'cart_updated' ) );
	}


	/**
	 * Enqueue frontend scripts and styles
	 *
	 * @since 1.0.0
	 */
	public function enqueue_scripts() {

		if ( wc_jilt()->get_integration()->is_disabled() ) {
			return;
		}

		// only load javascript once
		if ( wp_script_is( 'wc-jilt', 'enqueued' ) ) {
			return;
		}

		wp_enqueue_script( 'wc-jilt', wc_jilt()->get_plugin_url() . '/assets/js/frontend/wc-jilt-frontend.min.js', array(), wc_jilt()->get_version(), true );

		// script data
		$params = array(
			'public_key'            => wc_jilt()->get_integration()->get_public_key(),
			'order_address_mapping' => WC_Jilt_Order::get_jilt_order_address_mapping(),
			'endpoint'              => $this->get_api()->get_api_endpoint(),
			'order_id'              => $this->get_jilt_order_id(),
			'cart_token'            => $this->get_cart_token(),
			'ajax_url'              => WC_AJAX::get_endpoint( '%%endpoint%%' ),
			'nonce'                 => wp_create_nonce( 'jilt-for-wc' ),
			'log_level'             => wc_jilt()->get_integration()->get_log_level(),
			'x_jilt_shop_domain'    => wc_jilt()->get_shop_domain(),
		);

		wp_localize_script( 'wc-jilt', 'wc_jilt_params', $params );
	}


	/**
	 * Handle loading/setting Jilt data for the persistent cart. This is important
	 * for two key situations:
	 *
	 * 1) when a user logs in and their persistent cart is loaded, the cart token
	 * and Jilt order ID is set in the session. This most commonly occurs when
	 * the customer visits the recovery URL and is logged in and their persistent
	 * cart loaded. If not done properly, this can result in duplicate Jilt orders.
	 * Note this is only done if the cart token exists in user meta AND there is
	 * no existing cart token in the session.
	 *
	 * 2) when a guest user (with an existing cart/session) logs in (usually on
	 * the checkout page), the cart token and Jilt order ID is saved to user meta,
	 * at roughly the same time as the persistent cart. This ensures that if the user
	 * leaves and logs back in days later, their persistent cart will be loaded along
	 * with the correct Jilt order that it was originally associated with. Note this
	 * is done only if the cart token exists in the session but NOT in user meta.
	 *
	 * This method is hooked into woocommerce_cart_loaded_from_session,
	 * which runs prior to the woocommerce_cart_updated action, which is where
	 * we hook in below to handle creating/updating Jilt orders.
	 *
	 * @since 1.0.0
	 */
	public function handle_persistent_cart() {

		// bail for guest users, when the cart is empty, or when doing a WP cron request
		if ( ! is_user_logged_in() || WC()->cart->is_empty() || defined( 'DOING_CRON' ) ) {
			return;
		}

		$user_id       = get_current_user_id();
		$cart_token    = get_user_meta( $user_id, '_wc_jilt_cart_token', true );
		$jilt_order_id = get_user_meta( $user_id, '_wc_jilt_order_id', true );

		if ( $cart_token && ! $this->get_cart_token() ) {

			// for a logged in user with a persistent cart, set the cart token/Jilt order ID to the session
			$this->set_jilt_order_data( $cart_token, $jilt_order_id );

		} elseif ( ! $cart_token && $this->get_cart_token() ) {

			// when a guest user with an existing cart logs in, save the cart token/Jilt order ID to user meta
			update_user_meta( $user_id, '_wc_jilt_cart_token', $this->get_cart_token() );
			update_user_meta( $user_id, '_wc_jilt_order_id', $this->get_jilt_order_id() );
		}

		// persist order notes into the session during a pending recovery
		if ( $this->is_pending_recovery() && $order_note = get_user_meta( $user_id, '_wc_jilt_order_note', true ) ) {
			WC()->session->set( 'wc_jilt_order_note', $order_note );
			delete_user_meta( $user_id, '_wc_jilt_order_note' );
		}
	}


	/** Event handlers ******************************************************/



	/**
	 * Create or update a Jilt order when cart is updated
	 *
	 * This is called at the bottom of WC_Checkout::set_session(), after the
	 * session and optional persistent cart are set.
	 *
	 * This will be called:
	 *
	 * + When a user signs into their account from the Checkout page (2x, once for /checkout/ and again for /wc-ajax/update_order_review/)
	 * + When a product is added to cart via ajax (1x)
	 * + When a product is added to cart via form submit (1x)
	 * + When a product is removed from the cart via 'x' link in widget (1x)
	 * + When a product is removed from the cart via 'x' link in cart (2x, once for /cart/?remove_item=6974ce5ac660610b44d9b9fed0ff9548 and then /cart/?removed_item=1)
	 * + When the cart page is loaded (1x)
	 * + When the checkout page is loaded (1x)
	 * + When the checkout form is submitted (I think, /wp-admin/admin-ajax.php?action=woocommerce_checkout)
	 * + When the address form on the checkout page is refreshed (via /wc-ajax/update_order_review/)
	 * + When a user logs into WordPress, either from the /wp-login.php page or on the WooCommerce my account page
	 *
	 * This will not be called:
	 *
	 * + When a customer creates an account from the checkout page
	 * + On the pay page
	 *
	 * @since 1.0.0
	 */
	public function cart_updated() {

		if ( wc_jilt()->get_integration()->is_disabled() ) {
			return;
		}

		if ( WC()->cart->is_empty() ) {
			return $this->cart_emptied();
		}

		// prevent duplicate updates when changing item quantities in the cart
		if ( isset( $_POST['cart'] ) ) {
			return;
		}

		$jilt_order_id = $this->get_jilt_order_id();

		if ( $jilt_order_id ) {

			try {

				// update the existing Jilt order
				$this->get_api()->update_order( $jilt_order_id, $this->get_cart_data() );

			} catch ( SV_WC_API_Exception $exception ) {

				// clear session so a new Jilt order can be created
				if ( 404 == $exception->getCode() ) {
					$this->unset_jilt_order_data();
					// try to create the order below
					$jilt_order_id = null;
				}

				wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
			}

		}

		if ( ! $jilt_order_id ) {

			try {

				// create a new Jilt order
				$jilt_order = $this->get_api()->create_order( $this->get_cart_data() );

				$this->set_jilt_order_data( $jilt_order->cart_token, $jilt_order->id );

				// update the order with the usable checkout recovery URL
				$this->get_api()->update_order( $jilt_order->id, array( 'checkout_url' => $this->get_checkout_recovery_url() ) );

			} catch ( SV_WC_API_Exception $exception ) {

				wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
			}
		}
	}


	/**
	 * When a user intentionally empties their cart, delete the associated Jilt
	 * order
	 *
	 * @since 1.0.0
	 */
	public function cart_emptied() {

		if ( wc_jilt()->get_integration()->is_disabled() ) {
			return;
		}

		$jilt_order_id = $this->get_jilt_order_id();

		if ( ! $jilt_order_id ) {
			return;
		}

		$this->unset_jilt_order_data();

		try {

			// TODO: need to make sure an order isn't deleted after being placed
			$this->get_api()->delete_order( $jilt_order_id );

		} catch ( SV_WC_API_Exception $exception ) {

			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/**
	 * Get the cart data for updating/creating a Jilt order via the API
	 *
	 * @since 1.0.0
	 * @return array
	 */
	protected function get_cart_data() {

		if ( WC()->cart->is_empty() ) {
			return array();
		}

		$params = array(
			'total_price'       => $this->amount_to_int( $this->get_cart_total() ),
			'subtotal_price'    => $this->amount_to_int( WC()->cart->subtotal_ex_tax ),
			'total_tax'         => $this->amount_to_int( WC()->cart->tax_total + WC()->cart->shipping_tax_total ),
			'total_discounts'   => $this->amount_to_int( WC()->cart->discount_cart ),
			'total_shipping'    => $this->amount_to_int( WC()->cart->shipping_total ),
			'requires_shipping' => WC()->cart->needs_shipping(),
			'currency'          => get_woocommerce_currency(),
			'checkout_url'      => $this->get_checkout_recovery_url(),
			'line_items'        => $this->get_cart_product_line_items(),
			'fee_items'         => $this->get_cart_fee_line_items(),
			'client_details'    => array(),
			'client_session'    => $this->get_client_session(),
		);

		if ( $browser_ip = WC_Geolocation::get_ip_address() ) {
			$params['client_details']['browser_ip'] = $browser_ip;
		}
		if ( ! empty( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) {
			$params['client_details']['accept_language'] = $_SERVER['HTTP_ACCEPT_LANGUAGE'];
		}
		if ( ! empty( $_SERVER['HTTP_USER_AGENT'] ) ) {
			$params['client_details']['user_agent'] = $_SERVER['HTTP_USER_AGENT'];
		}

		// a cart token will be generated by Jilt if not provided
		if ( $cart_token = $this->get_cart_token() ) {
			$params['cart_token'] = $cart_token;
		}

		$params = array_merge( $params, $this->get_customer_data() );

		/**
		 * Filter the cart data used for creating or updating a Jilt order
		 * via the API
		 *
		 * @since 1.0.0
		 * @param array $params
		 * @param int $order_id optional
		 */
		return apply_filters( 'wc_jilt_order_cart_params', (array) $params, $this );
	}


	/**
	 * Get the customer data (email/ID, billing / shipping address) used when
	 * creating/updating an order in Jilt
	 *
	 * @since 1.0.6
	 * @return array
	 */
	protected function get_customer_data() {

		$params = array(
			'billing_address'  => array(),
			'shipping_address' => array(),
		);

		// set the billing/shipping fields from the WC_Customer object
		$params = $this->set_address_fields_from_customer( $params );

		// set customer data from the logged in user, if available. note that this
		// info can be different than the billing/shipping info.
		if ( is_user_logged_in() ) {

			$user = get_user_by( 'id', get_current_user_id() );

			$params['customer'] = array(
				'email'      => $user->user_email,
				'first_name' => $user->first_name,
				'last_name'  => $user->last_name,
				'id'         => $user->ID,
				'admin_url'  => esc_url_raw( add_query_arg( array( 'user_id' => $user->ID ), self_admin_url( 'user-edit.php' ) ) ),
			);

		} elseif ( $this->has_customer_data_from_js_api() ) {

			// set from WC_Customer, if available. currently this should only occur
			// if custom code is using the public JS API to set this data.
			$params['customer'] = array(
				'email'      => SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? WC()->customer->get_billing_email() : WC()->customer->email,
				'first_name' => SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? WC()->customer->get_billing_first_name() : WC()->customer->first_name,
				'last_name'  => SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? WC()->customer->get_billing_last_name() : WC()->customer->last_name,
			);
		}

		return $params;
	}


	/**
	 * Set address fields (billing/shipping) from the customer object in the
	 * session.
	 *
	 * TODO: This is a compatibility method that can be refactored once WC 3.0+ is required. {MR 2017-03-27}
	 *
	 * @since 1.1.0
	 * @param array $params
	 * @return array
	 */
	private function set_address_fields_from_customer( $params ) {

		foreach ( WC_Jilt_Order::get_jilt_order_address_mapping() as $wc_field => $jilt_field ) {

			if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

				$billing_method  = "get_billing_{$wc_field}";
				$shipping_method = "get_shipping_{$wc_field}";

				$billing_value  = WC()->customer->$billing_method();
				$shipping_value = is_callable( array( WC()->customer, $shipping_method ) ) ? WC()->customer->$shipping_method() : null;

			} else {

				$billing_property  = $wc_field;
				$shipping_property = "shipping_{$wc_field}";

				$billing_value  = WC()->customer->$billing_property;
				$shipping_value = WC()->customer->$shipping_property;
			}

			$params['billing_address'][ $jilt_field ] = ( $billing_value !== '' )  ? $billing_value : null;
			$params['shipping_address'][ $jilt_field] = ( $shipping_value !== '' ) ? $shipping_value : null;
		}

		return $params;
	}


	/**
	 * Return true if customer data was set from the JS API.
	 *
	 * TODO: This is a compatibility method that can be refactored once WC 3.0+ is required. {MR 2017-03-27}
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	private function has_customer_data_from_js_api() {

		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {
			return WC()->customer->get_first_name() || WC()->customer->get_last_name() || WC()->customer->get_billing_email();
		} else {
			WC()->customer->first_name || WC()->customer->last_name || WC()->customer->email;
		}
	}


	/**
	 * Map WC cart items to Jilt line items
	 *
	 * @since 1.0.0
	 * @return array Mapped line items
	 */
	private function get_cart_product_line_items() {

		$line_items = array();

		// products
		foreach ( WC()->cart->get_cart() as $item_key => $item ) {

			$product = $item['data'];
			if ( ! $product instanceof WC_Product ) {
				continue;
			}

			// prepare main line item params
			$line_item = array(
				'title'      => $product->get_title(),
				'product_id' => $item['product_id'],
				'quantity'   => $item['quantity'],
				'sku'        => $product->get_sku(),
				'url'        => get_the_permalink( $item['product_id'] ),
				'image_url'  => $this->get_product_image_url( $product ),
				'key'        => $item_key,
				'price'      => $this->amount_to_int( $item['line_subtotal'] / $item['quantity'] ),
			);

			// add variation data
			if ( ! empty( $item['variation_id'] ) ) {

				$line_item['variant_id'] = $item['variation_id'];
				$line_item['variation']  = $this->get_variation_data( $item );
			}

			/**
			 * Filter cart item params used for creating/updating a Jilt order
			 * via the API
			 *
			 * @since 1.0.0
			 * @param array $line_item Jilt line item data
			 * @param array $item WC line item data
			 * @param string $item_key WC cart key for item
			 */
			$line_items[] = apply_filters( 'wc_jilt_order_cart_item_params', $line_item, $item, $item_key );
		}

		return $line_items;
	}


	/**
	 * Map WC cart fee line items to Jilt fee items
	 *
	 * @since 1.1.0
	 * @return array Mapped fee items
	 */
	private function get_cart_fee_line_items() {

		$fee_items = array();

		// fees
		if ( $fees = WC()->cart->get_fees() ) {
			foreach ( $fees as $fee ) {

				$fee_item = array(
					'title'  => $fee->name,
					'key'    => $fee->id,
					'amount' => $this->amount_to_int( $fee->amount ),
				);

				/**
				 * Filter cart fee item params used for creating/updating a Jilt order
				 * via the API
				 *
				 * @since 1.1.0
				 * @param array $fee_item Jilt fee item data
				 * @param \stdClass $fee WC fee object
				 */
				$fee_items[] = apply_filters( 'wc_jilt_order_cart_fee_params', $fee_item, $fee );
			}
		}

		return $fee_items;
	}


	/**
	 * Return the cart total. WC does not set the cart total unless on the
	 * cart or checkout pages - see WC_Cart:calculate_totals() so on other
	 * pages it's calculated manually.
	 *
	 * @since 1.0.6
	 * @return double
	 */
	protected function get_cart_total() {

		if ( is_checkout() || is_cart() || defined( 'WOOCOMMERCE_CHECKOUT' ) || defined( 'WOOCOMMERCE_CART' ) ) {

			// on cart/checkout, total is calculated
			return WC()->cart->total;

		} else {

			// likely on product page, etc. total is not calculated but tax/shipping may be available
			return WC()->cart->subtotal_ex_tax + WC()->cart->tax_total + WC()->cart->shipping_tax_total + WC()->cart->shipping_total;
		}
	}


}
