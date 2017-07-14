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
 * Abstract Handler class
 *
 * @since 1.0.0
 */
abstract class WC_Jilt_Handler {


	/**
	 * Set Jilt order data to the session and user meta, if customer is logged in
	 *
	 * @since 1.0.0
	 * @param $cart_token
	 * @param $jilt_order_id
	 */
	protected function set_jilt_order_data( $cart_token, $jilt_order_id ) {

		WC()->session->set( 'wc_jilt_cart_token', $cart_token );
		WC()->session->set( 'wc_jilt_order_id', $jilt_order_id );

		if ( $user_id = get_current_user_id() ) {

			update_user_meta( $user_id, '_wc_jilt_cart_token', $cart_token );
			update_user_meta( $user_id, '_wc_jilt_order_id', $jilt_order_id );
		}
	}


	/**
	 * Unset Jilt order id from session and user meta
	 *
	 * @since 1.0.0
	 */
	protected function unset_jilt_order_data() {

		unset( WC()->session->wc_jilt_cart_token );
		unset( WC()->session->wc_jilt_order_id );
		unset( WC()->session->wc_jilt_pending_recovery );

		if ( $user_id = get_current_user_id() ) {
			delete_user_meta( $user_id, '_wc_jilt_cart_token' );
			delete_user_meta( $user_id, '_wc_jilt_order_id' );
			delete_user_meta( $user_id, '_wc_jilt_pending_recovery' );
		}
	}


	/**
	 * Convert a price/total to the lowest currency unit (e.g. cents)
	 *
	 * @since 1.0.6
	 * @param string|float $number
	 * @return int
	 */
	protected function amount_to_int( $number ) {

		if ( is_string( $number ) ) {
			$number = (float) $number
;		}

		return round( $number * 100, 0 );
	}


	/** Getter methods ******************************************************/


	/**
	 * Helper method to improve the readability of methods calling the API
	 *
	 * @since 1.0.0
	 * @return \WC_Jilt_API instance
	 */
	protected function get_api() {
		return wc_jilt()->get_integration()->get_api();
	}


	/**
	 * Gets the cart checkout URL for Jilt
	 *
	 * Visiting this URL will load the associated cart from session/persistent cart
	 *
	 * @since 1.0.0
	 * @param string $jilt_cart_token, optional Jilt cart token, defaults to session/persistent cart value if not provided
	 * @param string $jilt_order_id optional Jilt order ID, defaults to session/persistent cart value if not provided
	 * @return string
	 */
	public function get_checkout_recovery_url( $jilt_cart_token = '', $jilt_order_id = '' ) {

		$data = array(
			'order_id'      => $jilt_order_id   ? $jilt_order_id : $this->get_jilt_order_id(),
			'cart_token'    => $jilt_cart_token ? $jilt_cart_token : $this->get_cart_token(),
		);

		// encode
		$data = base64_encode( wp_json_encode( $data ) );

		// add hash for easier verification that the checkout URL hasn't been tampered with
		$hash = hash_hmac( 'sha256', $data, wc_jilt()->get_integration()->get_secret_key() );

		$url = $this->get_jilt_wc_api_url();

		// returns URL like:
		// pretty permalinks enabled - https://domain.tld/wc-api/jilt?token=abc123&hash=xyz
		// pretty permalinks disabled - https://domain.tld?wc-api=jilt&token=abc123&hash=xyz
		return esc_url_raw( add_query_arg( array( 'token' => rawurlencode( $data ), 'hash' => $hash ), $url ) );
	}


	/**
	 * Return the WC API URL for handling Jilt recovery links by accounting
	 * for whether pretty permalinks are enabled or not.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	private function get_jilt_wc_api_url() {

		$scheme = wc_site_is_https() ? 'https' : 'http';

		return get_option( 'permalink_structure' )
			? get_home_url( null, 'wc-api/jilt', $scheme )
			: add_query_arg( 'wc-api', 'jilt', get_home_url( null, null, $scheme ) );
	}


	/**
	 * Return the cart token from the session
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_cart_token() {

		return WC()->session->get( 'wc_jilt_cart_token' );
	}


	/**
	 * Return the Jilt order ID from the session
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_jilt_order_id() {

		return WC()->session->get( 'wc_jilt_order_id' );
	}


	/**
	 * Returns true if the current checkout was created by a customer visiting
	 * a Jilt provided recovery URL
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	protected function is_pending_recovery() {

		return (bool) ( is_user_logged_in() ? get_user_meta( get_current_user_id(), '_wc_jilt_pending_recovery', true ) : WC()->session->wc_jilt_pending_recovery );
	}


	/**
	 * Get item variation data
	 *
	 * @since 1.0.0
	 * @param array $item
	 * @return array
	 */
	protected function get_variation_data( $item ) {

		$variation_data = array();

		if ( ! empty( $item['variation_id'] ) && $attributes = wc_get_product_variation_attributes( $item['variation_id'] ) ) {

			foreach ( $attributes as $name => $value ) {

				if ( '' === $value ) {
					continue;
				}

				$taxonomy = wc_attribute_taxonomy_name( str_replace( 'attribute_pa_', '', urldecode( $name ) ) );

				// If this is a term slug, get the term's nice name
				if ( taxonomy_exists( $taxonomy ) ) {

					$term = get_term_by( 'slug', $value, $taxonomy );

					if ( ! is_wp_error( $term ) && $term && $term->name ) {
						$value = $term->name;
					}

					$label = wc_attribute_label( $taxonomy );

					// If this is a custom option slug, get the options name
				} else {

					$value = apply_filters( 'woocommerce_variation_option_name', $value );

					// can occur after checkout, but generally not before
					if ( empty( $item['data'] ) || ! $item['data'] instanceof WC_Product ) {
						$item['data'] = wc_get_product( $item['variation_id'] );
					}

					$product_attributes = $item['data']->get_attributes();

					if ( isset( $product_attributes[ str_replace( 'attribute_', '', $name ) ] ) ) {

						$attribute_name = SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ? str_replace( 'attribute_', '', $name ) : $product_attributes[ str_replace( 'attribute_', '', $name ) ]['name'];

						$label = wc_attribute_label( $attribute_name, $item['data'] );

					} else {

						$label = $name;
					}
				}

				$variation_data[ $label ] = $value;
			}
		}

		return $variation_data;
	}


	/**
	 * Return the image URL for a product
	 *
	 * @since 1.0.0
	 * @param \WC_Product $product
	 * @return string
	 */
	protected function get_product_image_url( WC_Product $product ) {

		$src = wc_placeholder_img_src();

		if ( $image_id  = $product->get_image_id() ) {

			list( $src, $_, $_, $_ ) = wp_get_attachment_image_src( $image_id, 'full' );
		}

		return $src;
	}


	/**
	 * Return the client session data that should be stored in Jilt. This is used
	 * to recreate the cart for guest customers who do not have an active session.
	 *
	 * Note that we're explicitly *not* saving the entire session, as it could
	 * contain confidential information that we don't want stored in Jilt. For
	 * future integrations with other extensions, the filter can be used to include
	 * their data.
	 *
	 * @since 1.0.0
	 * @return string
	 */
	protected function get_client_session() {

		$session = array(
			'token'                   => WC()->session->get_customer_id(),
			'cart'                    => WC()->session->get( 'cart' ),
			'customer'                => $this->get_customer_session_data(),
			'applied_coupons'         => WC()->session->get( 'applied_coupons' ),
			'chosen_shipping_methods' => WC()->session->get( 'chosen_shipping_methods' ),
			'shipping_method_counts'  => WC()->session->get( 'shipping_method_counts' ),
			'chosen_payment_method'   => WC()->session->get( 'chosen_payment_method' ),
		);

		/**
		 * Allow actors to filter the client session data sent to Jilt. This is
		 * potentially useful for adding support for other extensions.
		 *
		 * @since 1.0.0
		 * @param array $session session data
		 * @param \WC_Jilt_Handler $this Jilt handler instance
		 */
		return wp_json_encode( apply_filters( 'wc_jilt_get_client_session', $session, $this ) );
	}


	/**
	 * The WC_Customer class does not persist data changed during an execution cycle
	 * until the `shutdown` hook. Because of this, the get_client_session() method
	 * above can't get the customer data directly as it can possibly contain
	 * stale data. This method builds the customer session data directly from
	 * the WC_Customer class which will return the most recent data.
	 *
	 * @since 1.0.6
	 * @return array
	 */
	protected function get_customer_session_data() {

		$data = array();

		$properties = array(
			'first_name', 'last_name', 'company', 'email', 'phone', 'address_1',
			'address_2', 'city', 'state', 'postcode', 'country', 'shipping_first_name',
			'shipping_last_name', 'shipping_company', 'shipping_address_1',
			'shipping_address_2', 'shipping_city', 'shipping_state', 'shipping_postcode',
			'shipping_country'
		);

		foreach ( $properties as $property ) {

			if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

				$method = SV_WC_Helper::str_starts_with( $property, 'shipping_' ) ? "get_{$property}" : "get_billing_{$property}";

				$data[ $property ] = WC()->customer->$method();

			} else {

				$data[ $property ] = WC()->customer->$property;
			}

		}

		$data['is_vat_exempt']       = WC()->customer->is_vat_exempt();
		$data['calculated_shipping'] = WC()->customer->has_calculated_shipping();

		return $data;
	}


}
