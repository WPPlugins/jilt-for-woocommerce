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
 * @package   WC-Jilt/API
 * @author    Jilt
 * @category  Frontend
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Integration API: responds to API requests from the Jilt App
 *
 * @since 1.1.0
 * @see WC_Jilt_Integration_API_Base
 */
class WC_Jilt_Integration_API extends WC_Jilt_Integration_API_Base {


	/**
	 * Disable the Jilt integration
	 *
	 * Routed from DELETE /wc-api/jilt?resource=integration
	 *
	 * @since 1.1.0
	 */
	protected function delete_integration() {
		wc_jilt()->get_integration()->disable();
	}


	/**
	 * Enable the Jilt integration
	 *
	 * Routed from POST /wc-api/jilt?resource=integration
	 *
	 * @since 1.1.0
	 */
	protected function post_integration() {
		wc_jilt()->get_integration()->enable();
	}


	/**
	 * Get the integration settings
	 *
	 * Routed from GET /wc-api/jilt?resource=integration
	 *
	 * @since 1.1.0
	 */
	protected function get_integration() {
		$settings = wc_jilt()->get_integration()->get_settings();
		return $this->get_safe_settings( $settings );
	}


	/**
	 * Update the integration/wc settings
	 *
	 * Routed from PUT /wc-api/jilt?resource=integration
	 *
	 * @param array $data associative array of integration settings
	 * @param array associative array of updated integration settings
	 */
	protected function put_integration( $data ) {
		$settings = wc_jilt()->get_integration()->get_settings();

		// strip out sensitive settings
		$safe_settings = $this->get_safe_settings( $settings );

		// only update known settings
		$safe_data = array_intersect_key( $data, $safe_settings );
		$updated_settings = array_merge( $settings, $safe_data );

		wc_jilt()->get_integration()->update_settings( $updated_settings );

		// allow coupons to be enabled/disabled remotely
		if ( ! empty( $data['woocommerce_enable_coupons'] ) && in_array( $data['woocommerce_enable_coupons'], array( 'yes', 'no' ) ) ) {
			update_option( 'woocommerce_enable_coupons', $data['woocommerce_enable_coupons'] );
			$updated_settings['woocommerce_enable_coupons'] = $data['woocommerce_enable_coupons'];
		}

		return $this->get_safe_settings( $updated_settings );
	}


	/**
	 * Handle a remote get shop API request by returning the shop data
	 *
	 * Routed from GET /wc-api/jilt?resource=shop
	 *
	 * @since 1.1.0
	 * @return array associative array of shop data
	 */
	protected function get_shop() {
		return wc_jilt()->get_integration()->get_shop_data();
	}


	/**
	 * Get a coupon
	 *
	 * Routed from GET /wc-api/jilt?resource=coupons
	 *
	 * @param array $query coupon data including id, code, usage_count, and used_by
	 * @throws SV_WC_Plugin_Exception if the request
	 * @return array
	 */
	protected function get_coupon( $query ) {
		if ( ! isset( $query['id'] ) && ! isset( $query['code'] ) ) {
			throw new SV_WC_Plugin_Exception( 'Need either an id or code to get a coupon', 422 );
		}

		$identifier = isset( $query['id'] ) ? $query['id'] : $query['code'];

		if ( isset( $query['id'] ) ) {
			// this is for WC < 2.7 support
			$coupon_post = get_post( $identifier );
			if ( null === $coupon_post || 'shop_coupon' !== $coupon_post->post_type ) {
				throw new SV_WC_Plugin_Exception( "No such coupon '{$identifier}'", 404 );
			}
			$coupon = new WC_Coupon( $coupon_post->post_name );
		} else {
			$coupon = new WC_Coupon( $identifier );
		}

		if ( ! $coupon->id ) {
			throw new SV_WC_Plugin_Exception( "No such coupon '{$identifier}'", 404 );
		}

		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

			$coupon_data = array(
				'id'          => $coupon->get_id(),
				'code'        => $coupon->get_code(),
				'usage_count' => $coupon->get_usage_count(),
				'used_by'     => $coupon->get_used_by(),
			);

		} else {

			$coupon_data = array(
				'id'          => $coupon->id,
				'code'        => $coupon->code,
				'usage_count' => $coupon->usage_count,
				'used_by'     => $coupon->get_used_by(),
			);
		}

		return $coupon_data;
	}


	/**
	 * Create a coupon
	 *
	 * Routed from POST /wc-api/jilt?resource=coupons
	 *
	 * @param array $coupon_data associative array of coupon data
	 * @throws SV_WC_Plugin_Exception
	 * @return array
	 */
	protected function post_coupons( $coupon_data ) {

		$this->validate_post_coupons( $coupon_data );

		$defaults = array(
			'discount_type'                => 'fixed_cart',
			'coupon_amount'                => 0,
			'individual_use'               => 'no',
			'product_ids'                  => array(),
			'exclude_product_ids'          => array(),
			'usage_limit'                  => '',
			'usage_limit_per_user'         => '',
			'limit_usage_to_x_items'       => '',
			'usage_count'                  => '',
			'expiry_date'                  => '',
			'free_shipping'                => 'no',
			'product_category_ids'         => array(),
			'exclude_product_category_ids' => array(),
			'exclude_sale_items'           => 'no',
			'minimum_amount'               => '',
			'maximum_amount'               => '',
			'customer_email'               => '',
			'description'                  => '',
		);

		$coupon_data = wp_parse_args( $coupon_data, $defaults );

		$new_coupon = array(
			'post_title'   => $coupon_data['code'],
			'post_content' => '',
			'post_status'  => 'publish',
			'post_author'  => 0,
			'post_type'    => 'shop_coupon',
			'post_excerpt' => $coupon_data['description'],
 		);

		$id = wp_insert_post( $new_coupon, true );

		if ( is_wp_error( $id ) ) {
			throw new SV_WC_Plugin_Exception( $id->get_error_message(), 422 );
		}

		// identify the coupon as having been created by jilt by setting the remote discount id
		update_post_meta( $id, 'jilt_discount_id', $coupon_data['discount_id'] );

		// Set coupon meta
		update_post_meta( $id, 'discount_type',              $coupon_data['discount_type'] ); // one of fixed_cart, percent, fixed_product, percent_product
		update_post_meta( $id, 'coupon_amount',              wc_format_decimal( $coupon_data['coupon_amount'] ) ); // e.g. '1.00'
		update_post_meta( $id, 'individual_use',             $coupon_data['individual_use'] ); // yes/no
		update_post_meta( $id, 'product_ids',                implode( ',', array_filter( array_map( 'intval', $coupon_data['product_ids'] ) ) ) );
		update_post_meta( $id, 'exclude_product_ids',        implode( ',', array_filter( array_map( 'intval', $coupon_data['exclude_product_ids'] ) ) ) );
		update_post_meta( $id, 'usage_limit',                absint( $coupon_data['usage_limit'] ) );
		update_post_meta( $id, 'usage_limit_per_user',       absint( $coupon_data['usage_limit_per_user'] ) );
		update_post_meta( $id, 'limit_usage_to_x_items',     absint( $coupon_data['limit_usage_to_x_items'] ) );
		update_post_meta( $id, 'usage_count',                absint( $coupon_data['usage_count'] ) );
		update_post_meta( $id, 'expiry_date',                $coupon_data['expiry_date'] ); // YYYY-MM-DD
		update_post_meta( $id, 'free_shipping',              $coupon_data['free_shipping'] ); // yes/no
		update_post_meta( $id, 'product_categories',         array_filter( array_map( 'intval', $coupon_data['product_category_ids'] ) ) );
		update_post_meta( $id, 'exclude_product_categories', array_filter( array_map( 'intval', $coupon_data['exclude_product_category_ids'] ) ) );
		update_post_meta( $id, 'exclude_sale_items',         $coupon_data['exclude_sale_items'] ); // yes/no
		update_post_meta( $id, 'minimum_amount',             wc_format_decimal( $coupon_data['minimum_amount'] ) );
		update_post_meta( $id, 'maximum_amount',             wc_format_decimal( $coupon_data['maximum_amount'] ) );
		update_post_meta( $id, 'customer_email',             array_filter( array( sanitize_email( $coupon_data['customer_email'] ) ) ) );

		// support expiry dates in WC 3.0+
		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

			if ( empty( $coupon_data['expiry_date'] ) ) {

				$timestamp = null;

			} else {

				try {

					$datetime = new WC_DateTime( $coupon_data['expiry_date'], new DateTimeZone( wc_timezone_string() ) );
					$datetime->setTimezone( new DateTimeZone( wc_timezone_string() ) );
					$timestamp = $datetime->getTimestamp();

				} catch ( Exception $e ) { $timestamp = null; }
			}

			update_post_meta( $id, 'date_expires', $timestamp );
		}

		$response = array(
			'id'   => $id,
			'code' => $coupon_data['code'],
		);

		return $response;
	}


	/** Integration API Helpers ******************************************************/


	/**
	 * Validate the post coupons request data
	 *
	 * @since 1.1.0
	 * @param array $coupon_data associative array of coupon data
	 * @throws SV_WC_Plugin_Exception
	 */
	private function validate_post_coupons( $coupon_data ) {
		global $wpdb;

		// validate required params
		$required_params = array( 'code', 'discount_id', 'discount_type' );
		if ( ! isset( $coupon_data['free_shipping'] ) || 'yes' != $coupon_data['free_shipping'] ) {
			$required_params[] = 'coupon_amount';
		}
		$missing_params = array();

		foreach ( $required_params as $required_param ) {
			if ( empty( $coupon_data[ $required_param ] ) ) {
				$missing_params[] = $required_param;
			}
		}

		if ( $missing_params ) {
			throw new SV_WC_Plugin_Exception( 'Missing required params: ' . join( ', ', $missing_params ), 422 );
		}

		// Validate coupon types
		if ( ! in_array( wc_clean( $coupon_data['discount_type'] ), array_keys( wc_get_coupon_types() ) ) ) {
			throw new SV_WC_Plugin_Exception( sprintf( 'Invalid discount type - the type must be any of these: %s', implode( ', ', array_keys( wc_get_coupon_types() ) ) ), 422 );
		}

		// Check for duplicate coupon codes
		$coupon_found = $wpdb->get_var( $wpdb->prepare( "
			SELECT $wpdb->posts.ID
			FROM $wpdb->posts
			WHERE $wpdb->posts.post_type = 'shop_coupon'
			AND $wpdb->posts.post_status = 'publish'
			AND $wpdb->posts.post_title = '%s'
		", $coupon_data['code'] ) );

		if ( $coupon_found ) {
			throw new SV_WC_Plugin_Exception( "Discount code '{$coupon_data['code']}' already exists", 422 );
		}
	}


	/**
	 * Returns $settings with any unsafe members removed
	 *
	 * @since 1.1.0
	 * @param $settings array associative array of settings
	 * @return array associative array of safe settings
	 */
	private function get_safe_settings( $settings ) {
		// strip out sensitive settings
		unset( $settings['secret_key'] );

		return $settings;
	}


}
