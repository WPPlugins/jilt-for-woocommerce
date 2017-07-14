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
 * @package   WC-Jilt/Order
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Jilt Order Class
 *
 * Extends the WooCommerce Order class to add additional information and
 * functionality specific to Jilt
 *
 * Note: this class does not represent an order stored in the Jilt app
 *
 * @since 1.0.0
 * @extends \WC_Order
 */
class WC_Jilt_Order extends WC_Order {


	/**
	 * Get the Jilt cart token for an order.
	 *
	 * @since 1.1.0
	 * @return string
	 */
	public function get_jilt_cart_token() {

		return get_post_meta( SV_WC_Order_Compatibility::get_prop( $this, 'id' ), '_wc_jilt_cart_token', true );
	}


	/**
	 * Get the Jilt order ID for an order.
	 *
	 * @since 1.1.0
	 * @return int|string
	 */
	public function get_jilt_order_id() {

		return get_post_meta( SV_WC_Order_Compatibility::get_prop( $this, 'id' ), '_wc_jilt_order_id', true );
	}


	/**
	 * Get the financial status for the order
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_financial_status() {

		$order_status     = $this->get_status();
		$financial_status = $order_status; // by default, match order & financial status

		if ( $this->is_paid() ) {
			$financial_status = 'paid';
		}

		elseif ( 'failed' === $order_status ) {
			$financial_status = 'voided';
		}

		elseif ( $this->get_total_refunded() && $this->get_total_refunded() !== $this->get_total() ) {
			$financial_status = 'partially_refunded';
		}

		/**
		 * Filter order financial status for Jilt
		 *
		 * @since 1.0.0
		 * @param string $financial_status
		 * @param int $order_id
		 */
		return apply_filters( 'wc_jilt_order_financial_status', $financial_status, $this->id );
	}


	/**
	 * Get the admin edit url for the order
	 *
	 * @since 1.0.0
	 * @return string|null
	 */
	public function get_order_edit_link() {

		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {
			$id   = $this->get_id();
			$post = get_post( $id );
		} else {
			$id   = $this->id;
			$post = $this->post;
		}

		if ( 'revision' === $post->post_type ) {
			$action = '';
		} else {
			$action = '&action=edit';
		}

		$post_type_object = get_post_type_object( $post->post_type );

		if ( ! $post_type_object ) {
			return null;
		}

		return esc_url_raw( admin_url( sprintf( $post_type_object->_edit_link . $action, $id ) ) );
	}


	/**
	 * Determine if the order needs shipping or not
	 *
	 * @since 1.0.0
	 * @return bool
	 */
	public function needs_shipping() {

		$needs_shipping = false;

		foreach ( $this->get_items() as $item ) {

			$_product = $this->get_product_from_item( $item );

			if ( $_product->needs_shipping() ) {

				$needs_shipping = true;
				break;
			}
		}

		return $needs_shipping;
	}


	/**
	 * Map a WooCommerce address to Jilt address
	 *
	 * @since 1.0.0
	 * @param string $address_type either `billing` or `shipping`, defaults to `billing`
	 * @return array associative array suitable for Jilt API consumption
	 */
	public function map_address_to_jilt( $address_type = 'billing' ) {

		$address = $this->get_address( $address_type );
		$mapped_address = array();

		foreach ( self::get_jilt_order_address_mapping() as $wc_param => $jilt_param ) {

			if ( ! isset( $address[ $wc_param ] ) ) {
				continue;
			}

			$mapped_address[ $jilt_param ] = $address[ $wc_param ];
		}

		return $mapped_address;
	}


	/**
	 * Get WooCommerce order address -> Jilt order address mapping
	 *
	 * @since 1.0.0
	 * @return array $mapping
	 */
	public static function get_jilt_order_address_mapping() {

		/**
		 * Filter which WooCommerce address fields are mapped to which Jilt address fields
		 *
		 * @since 1.0.0
		 * @param array $mapping Associative array 'wc_param' => 'jilt_param'
		 */
		return apply_filters( 'wc_jilt_address_mapping', array(
			'email'      => 'email',
			'first_name' => 'first_name',
			'last_name'  => 'last_name',
			'address_1'  => 'address1',
			'address_2'  => 'address2',
			'company'    => 'company',
			'city'       => 'city',
			'state'      => 'state_code',
			'postcode'   => 'postal_code',
			'country'    => 'country_code',
			'phone'      => 'phone',
		) );
	}


}
