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
 * Customer Handler class
 *
 * Handles populating and updating additional WC_Customer session data that's not
 * handled by WC core. See the $fields array for the additional data.
 *
 * @since 1.0.6
 */
class WC_Jilt_Customer_Handler {


	/** @var array $fields form/user meta key => WC_Customer class property */
	protected $fields = array(
		'billing_first_name'  => 'first_name',
		'billing_last_name'   => 'last_name',
		'billing_company'     => 'company',
		'billing_email'       => 'email',
		'billing_phone'       => 'phone',
		'shipping_first_name' => 'shipping_first_name',
		'shipping_last_name'  => 'shipping_last_name',
		'shipping_company'    => 'shipping_company',
	);


	/**
	 * Bootstrap class.
	 *
	 * @since 1.0.6
	 */
	public function __construct() {
		$this->init();
	}


	/**
	 * Add required actions.
	 *
	 * @since 1.0.6
	 */
	protected function init() {

		// WC 3.0+ handles getting/setting the additional $fields above
		if ( SV_WC_Plugin_Compatibility::is_wc_version_lt_3_0() ) {

			add_action( 'woocommerce_init', array( $this, 'set_customer_data_from_user' ) );

			add_action( 'woocommerce_checkout_update_order_review', array( $this, 'update_from_order_review_ajax' ) );

			add_action( 'woocommerce_checkout_process', array( $this, 'update_data' ) );

			add_filter( 'woocommerce_checkout_get_value', array( $this, 'maybe_set_checkout_field_value' ), 1, 2 );
		}

		add_action( 'wc_ajax_jilt_set_customer', array( $this, 'ajax_set_customer' ) );

		// set customer info upon login
		add_action( 'wp_login', array( $this, 'customer_login' ), 1, 2 );
	}


	/**
	 * Set the additional data (f not already set) from user meta (when the customer
	 * is logged in) immediately after the WC_Customer object is instantiated.
	 *
	 * @since 1.0.6
	 */
	public function set_customer_data_from_user() {

		// nothing to do for non-logged in users
		if ( ! is_user_logged_in() ) {
			return;
		}

		$user_id = get_current_user_id();

		// set from user meta, if not already set
		foreach ( $this->fields as $user_meta_key => $customer_property ) {

			if ( isset( WC()->customer->$customer_property ) ) {
				continue;
			}

			if ( $value = get_user_meta( $user_id, $user_meta_key, true ) ) {
				WC()->customer->$customer_property = $value;
			}
		}

		// handle when only user data is set (no billing_*/shipping_* user meta)
		if ( ! metadata_exists( 'user', $user_id, 'billing_email' ) ) {

			$user = get_user_by( 'id', $user_id );

			WC()->customer->email      = $user->user_email;
			WC()->customer->first_name = $user->first_name;
			WC()->customer->last_name  = $user->last_name;
		}
	}


	/**
	 * Update the additional data at checkout when the Ajax order review is triggered.
	 *
	 * @since 1.0.6
	 * @param string $post_data jQuery.serialize()'d form data
	 */
	public function update_from_order_review_ajax( $post_data ) {

		$posted = array();

		parse_str( $post_data, $posted );

		$this->update_data( $posted );
	}


	/**
	 * Update the additional data during checkout processing.
	 *
	 * @since 1.0.6
	 * @param array $data checkout form data
	 */
	public function update_data( $data = array() ) {

		if ( empty( $data ) ) {
			$data = $_POST;
		}

		foreach ( $this->fields as $field_key => $customer_property ) {

			if ( ! empty( $data[ $field_key ] ) ) {

				WC()->customer->$customer_property = wc_clean( $data[ $field_key ] );
			}
		}
	}


	/**
	 * Ajax handler for setting customer data.
	 *
	 * @since 1.0.6
	 */
	public function ajax_set_customer() {

		// security check
		check_ajax_referer( 'jilt-for-wc', 'security' );

		// prevent overriding the logged in user's email address
		if ( is_user_logged_in() ) {
			wp_send_json_error( array(
				'message' => __( 'You cannot set customer data for logged-in user.', 'jilt-for-woocommerce' ),
			) );
		}

		// start the session if not yet started
		if ( ! WC()->session->has_session() ) {
			WC()->session->set_customer_session_cookie( true );
		}

		$first_name = ! empty( $_POST['first_name'] ) ? sanitize_user( $_POST['first_name'] ) : null;
		$last_name  = ! empty( $_POST['last_name'] ) ? sanitize_user( $_POST['last_name'] ) : null;
		$email      = ! empty( $_POST['email'] ) ? filter_var( $_POST['email'], FILTER_VALIDATE_EMAIL ) : null;

		$this->set_customer_info( $first_name, $last_name, $email );

		wp_send_json_success( array(
			'message' => 'Successfully set customer data.'
		) );
	}


	/**
	 * Handle setting first/last name and email when a customer logs in.
	 *
	 * @since 1.1.0
	 * @param string $username, unused
	 * @param \WP_User $user
	 */
	public function customer_login( $username, $user ) {

		$this->set_customer_info( $user->first_name, $user->last_name, $user->user_email );
	}


	/**
	 * Set the first name, last name, and email address for Customer session
	 * object.
	 *
	 * TODO: This is a compatibility method and can be removed when WC 3.0+
	 * is required. {MR 2017-03-29}
	 *
	 * @since 1.1.0
	 * @param string $first_name
	 * @param string $last_name
	 * @param string $email
	 */
	private function set_customer_info( $first_name, $last_name, $email ) {

		if ( ! WC()->customer instanceof WC_Customer ) {
			return;
		}

		if ( SV_WC_Plugin_Compatibility::is_wc_version_gte_3_0() ) {

			WC()->customer->set_billing_first_name( $first_name );
			WC()->customer->set_billing_last_name( $last_name );
			WC()->customer->set_billing_email( $email );

		} else {

			WC()->customer->first_name = $first_name;
			WC()->customer->last_name  = $last_name;
			WC()->customer->email      = $email;
		}
	}


	/**
	 * Pre-fill checkout fields with additional WC_Customer data if set
	 *
	 * @since 1.0.6
	 * @param string|null $value default checkout field value
	 * @param string $checkout_field_name checkout field name
	 * @return string
	 */
	public function maybe_set_checkout_field_value( $value, $checkout_field_name ) {

		if ( isset( $this->fields[ $checkout_field_name ] ) ) {

			// map checkout field name to that used by the WC_Customer class
			$customer_field_name = $this->fields[ $checkout_field_name ];
		} else {

			// return the default value if the checkout field isn't one managed by WC_Customer
			return $value;
		}

		return ! empty( WC()->customer->$customer_field_name ) ? WC()->customer->$customer_field_name : $value;
	}


}
