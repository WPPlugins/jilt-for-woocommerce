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
 * @package   WC-Jilt/Checkout
 * @author    Jilt
 * @category  Frontend
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Checkout Class
 *
 * Handles checkout page and orders that have been placed, but not yet paid for
 *
 * @since 1.0.0
 */
class WC_Jilt_Checkout_Handler extends WC_Jilt_Handler {


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

		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

			add_filter( 'woocommerce_checkout_fields', array( $this, 'move_checkout_email_field' ), 1 );

		} else {

			// load customer data from session when a cart is recreated
			add_action( 'woocommerce_before_checkout_form', array( $this, 'load_customer_data_from_session' ) );
		}

		// maybe apply coupon code provided in the recovery URL
		add_action( 'woocommerce_cart_loaded_from_session', array( $this, 'maybe_apply_cart_recovery_coupon' ), 11 );

		// set order note content available and if pending recovery
		add_action( 'woocommerce_checkout_get_value', array( $this, 'maybe_set_order_note' ), 1, 2 );

		// handle placed orders
		add_action( 'woocommerce_checkout_order_processed', array( $this, 'checkout_order_processed' ) );

		// handle updating Jilt order data after a successful payment, for certain gateways
		add_filter( 'woocommerce_payment_successful_result', array( $this, 'maybe_update_jilt_order_on_successful_payment' ), 10, 2 );

		// handle marking an order as recovered when completing it from the pay page
		add_action( 'woocommerce_thankyou', array( $this, 'handle_pay_page_order_completion' ) );
	}


	/**
	 * Maybe apply the recovery coupon provided in the recovery URL.
	 *
	 * @since 1.1.0
	 */
	public function maybe_apply_cart_recovery_coupon() {

		if ( $this->is_pending_recovery() && ! empty( $_REQUEST['coupon'] ) ) {

			$coupon_code = wc_clean( rawurldecode( $_REQUEST['coupon'] ) );

			if ( ! WC()->cart->has_discount( $coupon_code ) ) {
				WC()->cart->add_discount( $coupon_code );
			}
		}
	}


	/**
	 * Move the email field to the top of the checkout billing form.
	 *
	 * WC 3.0+ moved the email field to the bottom of the checkout form,
	 * which is less than ideal for capturing it. This method moves it
	 * to the top and makes it full-width.
	 *
	 * @since 1.1.0
	 * @param array $fields
	 * @return array
	 */
	public function move_checkout_email_field( $fields ) {

		if ( isset( $fields['billing'], $fields['billing']['billing_email'], $fields['billing']['billing_email']['priority'] ) ) {

			$email_field = $fields['billing']['billing_email'];
			unset( $fields['billing']['billing_email'] );

			$email_field['priority'] = 5;
			$email_field['class']    = array( 'form-row-wide' );

			$fields['billing'] = array_merge( array( 'billing_email' => $email_field ), $fields['billing'] );

			if ( isset( $fields['billing']['billing_postcode'], $fields['billing']['billing_phone'] ) ) {
				$fields['billing']['billing_postcode']['class'] = array( 'form-row-first');
				$fields['billing']['billing_phone']['class']    = array( 'form-row-last' );
			}
		}

		return $fields;
	}


	/**
	 * When a customer visits the checkout recovery URL, load their data from
	 * the session and pre-fill the checkout form
	 *
	 * Note that WC 3.0+ handles this automatically when using setters in
	 * the WC_Customer class.
	 *
	 * TODO: Remove this (and default_checkout_value()) when WC 3.0+ is
	 * required. {MR 2017-03-28}
	 *
	 * @since 1.0.0
	 */
	public function load_customer_data_from_session() {

		if ( ! $this->is_pending_recovery() ) {
			return;
		}

		// add default value filter hooks for checkout fields
		$address_fields = array_merge( WC()->countries->get_address_fields(), WC()->countries->get_address_fields( '', 'shipping_' ) );

		foreach ( $address_fields as $field => $data ) {
			add_filter( 'default_checkout_' . $field, array( $this, 'default_checkout_value' ), 10, 2 );
		}
	}


	/**
	 * Get default checkout value from session
	 *
	 * @since 1.0.0
	 * @param mixed $value
	 * @param string $input
	 * @return mixed
	 */
	public function default_checkout_value( $value, $input ) {
		$input  = str_replace( 'billing_', '', $input );
		$method = "get_$input";
		if ( ! $value ) {
			// if there's a getter method available on WC_Customer for this field, use it
			if ( is_callable( array( WC()->customer, $method ) ) ) {
				$value = WC()->customer->{$method}();
			}
			// otherwise, fall back to session
			else {
				$customer = WC()->session->get('customer');
				if ( $customer && isset( $customer[ $input ] ) ) {
					$value = $customer[ $input ];
				}
			}
		}
		return $value;
	}


	/**
	 * Maybe set the order note when there is a pending recovery
	 * with a previous value for the order note from the Jilt order.
	 *
	 * Note that unlike customer data (email, etc). this will not persist
	 * when/if the customer navigates away from the checkout page. We want to avoid
	 * a situation where the customer feels like they can't change the value of
	 * the order note field after it's been populated for them.
	 *
	 * @since 1.1.0
	 * @param string $value null
	 * @param string $input input field name
	 * @return null|string
	 */
	public function maybe_set_order_note( $value, $input ) {

		// target order comments input when a pending recovery order has an order note present
		if ( 'order_comments' !== $input || ! $this->is_pending_recovery() || ! $order_note = WC()->session->get( 'wc_jilt_order_note' ) ) {
			return $value;
		}

		unset( WC()->session->wc_jilt_order_note );

		return $order_note;
	}


	/**
	 * This is called once the checkout has been processed and an order has been created.
	 * Does not necessarily mean that the order has been paid for.
	 *
	 * @since 1.0.0
	 * @param int $order_id order ID
	 */
	public function checkout_order_processed( $order_id ) {

		if ( wc_jilt()->get_integration()->is_disabled() ) {
			return;
		}

		$cart_token    = $this->get_cart_token();
		$jilt_order_id = $this->get_jilt_order_id();

		// bail out if this cart is not associated with a Jilt order
		if ( ! $jilt_order_id ) {
			return;
		}

		// save Jilt order ID and cart token to order meta
		update_post_meta( $order_id, '_wc_jilt_cart_token', $cart_token );
		update_post_meta( $order_id, '_wc_jilt_order_id', $jilt_order_id );

		// mark as recovered
		if ( $this->is_pending_recovery() ) {

			$this->mark_order_as_recovered( $order_id );
		}

		// update Jilt order details
		try {

			$this->get_api()->update_order( $jilt_order_id, $this->get_order_data( $order_id ) );

		} catch ( SV_WC_API_Exception $exception ) {

			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
		}

		// remove Jilt order ID from session and user meta
		$this->unset_jilt_order_data();
	}


	/**
	 * Handle updating the Jilt order when order details aren't available until
	 * *after* payment is received. Gateways like Amazon Payments Advanced and
	 * other on-site/iframed gateways act this way and otherwise result in a lot
	 * of placed orders with empty order data in Jilt.
	 *
	 * Important: this is a non-standard use of a filter used in an action context,
	 * but I preferred to use this over the woocommerce_thankyou action since that
	 * requires it to be present on the template. {MR 2016-12-06}
	 *
	 * @since 1.0.7
	 * @param array $result payment successful result
	 * @param int $order_id
	 * @return array
	 */
	public function maybe_update_jilt_order_on_successful_payment( $result, $order_id ) {

		if ( wc_jilt()->get_integration()->is_disabled() ) {
			return $result;
		}

		if ( ! $jilt_order_id = get_post_meta( $order_id, '_wc_jilt_order_id', true ) ) {
			return $result;
		}

		try {

			$this->get_api()->update_order( $jilt_order_id, $this->get_order_data( $order_id ) );

		} catch ( SV_WC_API_Exception $exception ) {

			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
		}

		return $result;
	}


	/**
	 * When a customer completes a pending recovery from the pay page (e.g an order
	 * originally placed with an off-site gateway then later completed via an
	 * on-site gateway), mark it as recovered.
	 *
	 * @since 1.0.7
	 * @param $order_id
	 */
	public function handle_pay_page_order_completion( $order_id ) {

		if ( $this->is_pending_recovery() ) {

			$this->mark_order_as_recovered( $order_id );

			$this->unset_jilt_order_data();
		}
	}


	/**
	 * Get the order data for updating a Jilt order via the API
	 *
	 * @since 1.0.0
	 * @param int $order_id
	 * @return array
	 */
	protected function get_order_data( $order_id ) {

		$order = new WC_Jilt_Order( $order_id );

		$params = array(
			'name'              => $order->get_order_number(),
			'order_id'          => SV_WC_Order_Compatibility::get_prop( $order, 'id' ),
			'admin_url'         => $order->get_order_edit_link(),
			'status'            => $order->get_status(),
			'financial_status'  => $order->get_financial_status(),
			'total_price'       => $this->amount_to_int( $order->get_total() ),
			'subtotal_price'    => $this->amount_to_int( $order->get_subtotal() ),
			'total_tax'         => $this->amount_to_int( $order->get_total_tax() ),
			'total_discounts'   => $this->amount_to_int( $order->get_total_discount() ),
			'total_shipping'    => $this->amount_to_int( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $order->get_shipping_total() : $order->get_total_shipping() ),
			'requires_shipping' => $order->needs_shipping(),
			'currency'          => SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $order->get_currency() : $order->get_order_currency(),
			'checkout_url'      => $this->get_checkout_recovery_url( $order->get_jilt_cart_token(), $order->get_jilt_order_id() ),
			'line_items'        => $this->get_product_line_items_for_order( $order ),
			'fee_items'         => $this->get_fee_line_items_for_order( $order ),
			'cart_token'        => $order->get_jilt_cart_token(),
			'client_details'    => array(
				'browser_ip' => SV_WC_Order_Compatibility::get_prop( $order, 'customer_ip_address' ),
				'user_agent' => SV_WC_Order_Compatibility::get_prop( $order, 'customer_user_agent' ),
			),
			'client_session'    => $this->get_client_session(),
			'customer'          => array(
				'email'      => SV_WC_Order_Compatibility::get_prop( $order, 'billing_email' ),
				'first_name' => SV_WC_Order_Compatibility::get_prop( $order, 'billing_first_name' ),
				'last_name'  => SV_WC_Order_Compatibility::get_prop( $order, 'billing_last_name' ),
			),
			'billing_address'   => $order->map_address_to_jilt( 'billing' ),
			'shipping_address'  => $order->map_address_to_jilt( 'shipping' ),
		);

		if ( $user_id = $order->get_user_id() ) {
			$params['customer']['id'] = $user_id;
			$params['customer']['admin_url'] = esc_url_raw( add_query_arg( array( 'user_id' => $user_id ), self_admin_url( 'user-edit.php' ) ) );
		}

		if ( $customer_note = SV_WC_Order_Compatibility::get_prop( $order, 'customer_note' ) ) {
			$params['note'] = $customer_note;
		}

		/**
		 * Filter the order data used for updating a Jilt order
		 * via the API
		 *
		 * @since 1.0.0
		 * @param array $params
		 * @param \WC_Jilt_Order $order instance
		 * @param \WC_Jilt_Checkout_Handler $this instance
		 */
		return apply_filters( 'wc_jilt_order_params', $params, $order, $this );
	}


	/**
	 * Return the product line items for the given Order in the format required by Jilt
	 *
	 * @since 1.0.0
	 * @param \WC_Jilt_Order $order instance
	 * @return array
	 */
	private function get_product_line_items_for_order( WC_Jilt_Order $order ) {

		$line_items = array();

		foreach ( SV_WC_Helper::get_order_line_items( $order ) as $item ) {

			if ( ! $item->product instanceof WC_Product ) {
				continue;
			}

			// prepare main line item params
			$line_item = array(
				'title'      => $item->name,
				'product_id' => $item->product->get_id(),
				'quantity'   => $item->quantity,
				'sku'        => $item->product->get_sku(),
				'url'        => get_the_permalink( $item->product->get_id() ),
				'image_url'  => $this->get_product_image_url(  $item->product ),
				'key'        => $item->id,
				'price'      => $this->amount_to_int( $order->get_line_subtotal( $item->item ) ),
			);

			// add variation data
			if ( $item->product->is_type( 'variation' ) ) {

				$line_item['variant_id'] = $item->product->get_id();
				$line_item['product_id'] = SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $item->product->get_parent_id() : $item->product->get_parent();
				$line_item['variation']  = $this->get_variation_data( $item->item );
			}

			// add line item meta
			if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

				$item_meta = array();

				foreach( $item->item->get_formatted_meta_data() as $id => $meta ) {

					$item_meta[] = array(
						'label' => $meta->key,
						'value' => $meta->value,
					);
				}

			} else {

				$item_meta = new WC_Order_Item_Meta( $item->item );
				$item_meta = $item_meta->get_formatted();
			}

			if ( ! empty( $item_meta ) ) {
				foreach ( $item_meta as $property ) {

					// skip normal product attributes - these are already handled as variation data
					if ( isset( $property['key'] ) && SV_WC_Helper::str_starts_with( $property['key'], 'pa_' ) ) {
						continue;
					}

					if ( ! isset( $line_item['properties'] ) ) {
						$line_item['properties'] = array();
					}

					$line_item['properties'][ $property['label'] ] = $property['value'];
				}
			}

			/**
			 * Filter order item params used for updating a Jilt order
			 * via the API
			 *
			 * @since 1.0.0
			 * @param array $line_item Jilt line item data
			 * @param stdClass $item WC line item data in format provided by SV_WC_Helper::get_order_line_items()
			 * @param \WC_Jilt_Order $order instance
			 */
			$line_items[] = apply_filters( 'wc_jilt_order_line_item_params', $line_item, $item, $order );
		}

		return $line_items;
	}

	/**
	 * Return the fee line items for the given Order in the format required by Jilt
	 *
	 * @since 1.1.0
	 * @param \WC_Jilt_Order $order instance
	 * @return array order fee items in Jilt format
	 */
	private function get_fee_line_items_for_order( WC_Jilt_Order $order ) {

		$fee_items = array();

		foreach ( $order->get_fees() as $fee ) {

			$name = SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? $fee->get_name() : $fee['name'];
			$id   = sanitize_title( $name );

			$fee_item = array(
				'title'  => $name,
				'key'    => $id,
				'amount' => $this->amount_to_int( $order->get_item_total( $fee ) ),
			);

			/**
			 * Filter order fee params used for updating a Jilt order
			 * via the API
			 *
			 * @since 1.1.0
			 * @param array $fee_item Jilt fee item data
			 * @param array|\WC_Order_Item_Fee $fee provided by WC_Order::get_fees()
			 * @param \WC_Jilt_Order $order instance
			 */
			$fee_items[] = apply_filters( 'wc_jilt_order_fee_item_params', $fee_item, $fee, $order );
		}

		return $fee_items;
	}


	/**
	 * Mark an order as recovered by Jilt
	 *
	 * @since 1.0.1
	 * @param int|string $order_id order ID
	 */
	protected function mark_order_as_recovered( $order_id ) {

		$order = wc_get_order( $order_id );

		if ( ! $order instanceof WC_Order ) {
			return;
		}

		update_post_meta( SV_WC_Order_Compatibility::get_prop( $order, 'id' ), '_wc_jilt_recovered', true );

		$order->add_order_note( __( 'Order recovered by Jilt.', 'jilt-for-woocommerce' ) );
	}


}
