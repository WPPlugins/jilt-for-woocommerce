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
 * @package   WC-Jilt/Admin
 * @author    Jilt
 * @category  Admin
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

/**
 * Main integration class
 *
 * @since 1.0.0
 */
class WC_Jilt_Integration extends WC_Integration {


	/** Information interesting for Developers, when trying to debug a problem */
	const LOG_LEVEL_DEBUG = 0;

	/** Information interesting for Support staff trying to figure out the context of a given error */
	const LOG_LEVEL_INFO = 1;

	/** Indicates potentially harmful events or states in the program */
	const LOG_LEVEL_WARN = 2;

	/** Indicates non-fatal errors in the application */
	const LOG_LEVEL_ERROR = 3;

	/** Indicates the most severe of error conditions */
	const LOG_LEVEL_FATAL = 4;

	/** Logging disabled */
	const LOG_LEVEL_OFF = 5;

	/** @var WC_Jilt_API instance */
	private $api;

	/** @var WC_Jilt_Admin instance */
	private $admin;


	/**
	 * Initialize the class
	 *
	 * @since 1.0.0
	 * @see WC_Jilt_Integration::instance()
	 */
	public function __construct() {

		// delegate admin-related setup
		$this->admin = new WC_Jilt_Integration_Admin( $this );

		// Load settings
		$this->init_settings();

		if ( $this->is_linked() ) {

			// handle placed orders and keeping the financial status in sync with Jilt
			add_action( 'woocommerce_order_status_changed', array( $this, 'order_status_changed' ), 10, 3 );
		}
	}


	/**
	 * Return the URL to the specified page within the Jilt web app, useful
	 * for direct linking to internal pages, like campaigns
	 *
	 * @since 1.0.0
	 * @param string $page page URL partial, e.g. 'dashboard'
	 * @return string
	 */
	public function get_jilt_app_url( $page = '' ) {
		return sprintf( 'https://' . wc_jilt()->get_app_hostname() . '/shops/%1$d/%2$s', (int) $this->get_linked_shop_id(), rawurlencode( $page ) );
	}


	/**
	 * Gets the plugin settings
	 *
	 * @seee WC_Settings_API::settings
	 * @since 1.1.0
	 * @return array associative array of plugin settings
	 */
	public function get_settings() {
		return $this->settings;
	}


	/**
	 * Update the plugin settings
	 *
	 * @since 1.1.0
	 * @param array $data associative array of settings
	 */
	public function update_settings( $data ) {
		update_option( $this->get_option_key(), $data );
	}


	/**
	 * Clear out the the connection data, including: public key, shop id,
	 * current shop domain, is disabled.
	 *
	 * @since 1.1.0
	 */
	public function clear_connection_data() {
		update_option( 'wc_jilt_public_key',  '' );
		update_option( 'wc_jilt_shop_id',     '' );
		update_option( 'wc_jilt_shop_domain', '' );
		update_option( 'wc_jilt_disabled',    '' );
		$this->api = null;
	}


	/**
	 * Returns the Jilt API instance
	 *
	 * @since 1.0.0
	 * @param array $params optional params including 'secret_key'
	 * @return WC_Jilt_API the API instance
	 */
	public function get_api( $params = array() ) {

		if ( is_null( $this->api ) ) {
			$this->set_api(
				new WC_Jilt_API(
					$this->get_linked_shop_id(),
					isset( $params['secret_key'] ) ? $params['secret_key'] : $this->get_secret_key()
				)
			);
		}

		return $this->api;
	}


	/**
	 * Checks the site URL to determine whether this is likely a duplicate site.
	 * The typical case is when a production site is copied to a staging server
	 * in which case all of the Jilt keys will be copied as well, and staging
	 * will happily make production API requests.
	 *
	 * The one false positive that can happen here is if the site legitimately
	 * changes domains. Not sure yet how you would handle this, might require
	 * some administrator intervention
	 *
	 * @since 1.1.0
	 * @return boolean true if this is likely a duplicate site
	 */
	public function is_duplicate_site() {
		$shop_domain = get_option( 'wc_jilt_shop_domain' );

		return $shop_domain && $shop_domain != wc_jilt()->get_shop_domain();
	}


	/**
	 * Gets the configured secret key
	 *
	 * @since 1.0.0
	 * @return string the secret key, if set, null otherwise
	 */
	public function get_secret_key() {
		return $this->get_option( 'secret_key' );
	}


	/**
	 * Is the plugin configured?
	 *
	 * @since 1.0.0
	 * @return boolean true if the plugin is configured, false otherwise
	 */
	public function is_configured() {
		return (bool) $this->get_secret_key();
	}


	/**
	 * Has the plugin connected to the Jilt REST API with the current secret key?
	 *
	 * @since 1.0.0
	 * @return boolean true if the plugin has connected to the Jilt REST API
	 *         with the current secret key, false otherwise
	 */
	public function has_connected() {

		// since the public key is returned by the REST API it serves as a
		//  reasonable proxy for whether we've connected
		// note that we get the option directly
		return (bool) get_option( 'wc_jilt_public_key' );
	}


	/**
	 * Returns true if this shop has linked itself to a Jilt user account over
	 * the REST API
	 *
	 * @since 1.0.0
	 * @return boolean true if this shop is linked
	 */
	public function is_linked() {
		return (bool) $this->get_linked_shop_id();
	}


	/**
	 * Get the linked Jilt Shop identifier for this site, if any
	 *
	 * @since 1.0.0
	 * @return int Jilt shop identifier, or null
	 */
	public function get_linked_shop_id() {
		return get_option( 'wc_jilt_shop_id', null );
	}


	/**
	 * Persists the given linked Shop identifier
	 *
	 * @since 1.0.0
	 * @param int $id the linked Shop identifier
	 * @return int the provided $id
	 */
	public function set_linked_shop_id( $id ) {
		update_option( 'wc_jilt_shop_id', $id );

		$this->stash_secret_key();

		// clear the API object so that the new shop id can be used for subsequent requests
		$this->api = null;

		return $id;
	}


	/**
	 * Put the integration into disable mode: it will still respond to remote
	 * API requests, but it won't send requests over the REST API any longer
	 *
	 * @since 1.1.0
	 */
	public function disable() {
		update_option( 'wc_jilt_disabled', 'yes' );
	}


	/**
	 * Re-enable the integration
	 *
	 * @since 1.1.0
	 */
	public function enable() {
		update_option( 'wc_jilt_disabled', 'no' );
	}


	/**
	 * Is the integration disabled? This indicates that although the plugin is
	 * installed, activated, and configured, it should not send asynchronous
	 * Order notifications over the Jilt REST API.
	 *
	 * This also can indicate that the site is detected to be duplicated (e.g.
	 * a production site that was migrated to staging)
	 *
	 * @since 1.1.0
	 * @return bool
	 */
	public function is_disabled() {
		return get_option( 'wc_jilt_disabled' ) === 'yes' || $this->is_duplicate_site();
	}


	/**
	 * Stash the current secret key into the db
	 *
	 * @since 1.1.0
	 */
	public function stash_secret_key() {
		// What is the purpose of all this you might ask? Well it provides us a
		// future means of validating/handling recovery URLs that were generated
		// with a prior secret key
		$stash = get_option( 'wc_jilt_secret_key_stash', array() );

		if ( ! in_array( $this->get_secret_key(), $stash ) ) {
			$stash[] = $this->get_secret_key();
		}

		update_option( 'wc_jilt_secret_key_stash', $stash );
	}


	/**
	 * Persists the given linked Shop identifier
	 *
	 * @since 1.1.0
	 * @return String the shop domain taht was set
	 */
	public function set_shop_domain() {
		$shop_domain = wc_jilt()->get_shop_domain();
		update_option( 'wc_jilt_shop_domain', $shop_domain );
		return $shop_domain;
	}


	/**
	 * Should held orders be considered as placed?
	 *
	 * @since 1.1.0
	 * @return boolean true if "on-hold" orders should not be considered as
	 *   placed, false otherwise if "on-hold" should be considered recoverable
	 */
	public function recover_held_orders() {

		// when updating settings, make sure we have the new value
		if ( isset( $_POST['woocommerce_jilt_recover_held_orders'] ) ) {
			return $_POST['woocommerce_jilt_recover_held_orders'];
		}

		return 'yes' == $this->get_option( 'recover_held_orders' );
	}


	/**
	 * Get base data for creating/updating a linked shop in Jilt
	 *
	 * @since 1.0.0
	 * @return array
	 */
	public function get_shop_data() {

		$theme         = wp_get_theme();
		$base_location = wc_get_base_location();

		return array(
			'domain'                  => wc_jilt()->get_shop_domain(),
			'admin_url'               => admin_url(),
			'profile_type'            => 'woocommerce',
			'woocommerce_version'     => WC()->version,
			'wordpress_version'       => get_bloginfo( 'version' ),
			'integration_version'     => wc_jilt()->get_version(),
			'php_version'             => phpversion(),
			'name'                    => html_entity_decode( get_bloginfo( 'name' ) ),
			'main_theme'              => $theme->name,
			'currency'                => get_woocommerce_currency(),
			'province_code'           => $base_location['state'],
			'country_code'            => $base_location['country'],
			'timezone'                => wc_timezone_string(),
			'created_at'              => wc_jilt()->get_wc_created_at(),
			'coupons_enabled'         => wc_coupons_enabled(),
			'free_shipping_available' => $this->is_free_shipping_available(),
			'integration_enabled'     => $this->is_linked() && ! $this->is_disabled(),
			'supports_ssl'            => wc_site_is_https(),
		);
	}


	/**
	 * Returns the current log level
	 *
	 * @since 1.0.0
	 * @return int one of LOG_LEVEL_OFF, LOG_LEVEL_DEBUG, LOG_LEVEL_INFO,
	 *   LOG_LEVEL_WARN, LOG_LEVEL_ERROR, LOG_LEVEL_FATAL
	 */
	public function get_log_level() {

		// when updating settings, make sure we have the new value
		if ( isset( $_POST['woocommerce_jilt_log_level'] ) ) {
			return (int) $_POST['woocommerce_jilt_log_level'];
		}

		return (int) $this->get_option( 'log_level' );
	}


	/**
	 * Returns the current log level as a string name
	 *
	 * @since 1.1.0
	 * @param int $level optional level one of LOG_LEVEL_OFF, LOG_LEVEL_DEBUG, LOG_LEVEL_INFO,
	 *   LOG_LEVEL_WARN, LOG_LEVEL_ERROR, LOG_LEVEL_FATAL
	 * @return string one of 'OFF', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'
	 */
	public function get_log_level_name( $level = null ) {

		if ( null === $level ) {
			$level = $this->get_log_level();
		}

		switch ( $level ) {
			case self::LOG_LEVEL_DEBUG: return 'DEBUG';
			case self::LOG_LEVEL_INFO:  return 'INFO';
			case self::LOG_LEVEL_WARN:  return 'WARN';
			case self::LOG_LEVEL_ERROR: return 'ERROR';
			case self::LOG_LEVEL_FATAL: return 'FATAL';
			case self::LOG_LEVEL_OFF:   return 'OFF';
		}
	}

	/**
	 * Is logging enabled for the given level?
	 *
	 * @since 1.1.0
	 * @param int $level one of LOG_LEVEL_OFF, LOG_LEVEL_DEBUG, LOG_LEVEL_INFO,
	 *   LOG_LEVEL_WARN, LOG_LEVEL_ERROR, LOG_LEVEL_FATAL
	 * @return boolean true if logging is enabled for the given $level
	 */
	public function logging_enabled( $level ) {
		return $level >= $this->get_log_level();
	}


	/** API methods ******************************************************/


	/**
	 * Link this shop to Jilt. The basic algorithm is to first attempt to
	 * create the shop over the Jilt API. If this request fails with a
	 * "Domain has already been taken" error, we try to find it over the Jilt
	 * API by domain, and update with the latest shop data.
	 *
	 * @since 1.0.0
	 * @return int the Jilt linked shop id, or false if the linking failed
	 * @throws SV_WC_API_Exception network exception or API error
	 */
	public function link_shop() {

		if ( $this->is_configured() && ! $this->is_duplicate_site() ) {

			$args = $this->get_shop_data();

			// set shop owner/email
			$current_user       = wp_get_current_user();
			$args['shop_owner'] = $current_user->user_firstname . ' ' . $current_user->user_lastname;
			$args['email']      = $current_user->user_email;

			try {

				$shop = $this->get_api()->create_shop( $args );
				$this->set_shop_domain();

				return $this->set_linked_shop_id( $shop->id );

			} catch ( SV_WC_API_Exception $exception ) {

				if ( SV_WC_Helper::str_exists( $exception->getMessage(), 'Domain has already been taken' ) ) {

					// log the exception and continue attempting to recover
					wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );

				} else {

					// for any error other than "Domain has already been taken" rethrow so the calling code can handle
					throw $exception;
				}
			}

			// if we're down here, it means that our attempt to create the
			// shop failed with "domain has already been taken". Lets try to
			// recover gracefully by finding the shop over the API
			$shop = $this->get_api()->find_shop( array( 'domain' => $args['domain'] ) );

			// no shop found? it might even exist, but the current API user might not have access to it
			if ( ! $shop ) {
				return false;
			}

			// we successfully found our shop. attempt to update it and save the ID
			try {

				// update the linked shop record with the latest settings
				$this->get_api()->update_shop( $args, $shop->id );

			} catch ( SV_WC_API_Exception $exception ) {

				// otherwise, log the exception
				wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
			}

			$this->set_shop_domain();

			return $this->set_linked_shop_id( $shop->id );
		}
	}


	/**
	 * Unlink shop from Jilt
	 *
	 * @since 1.1.0
	 * @param array $params Optional associative array of params, including 'secret_key'
	 */
	public function unlink_shop( $params = array() ) {

		// there is no remote Jilt shop for a duplicate site
		if ( $this->is_duplicate_site() ) {
			return;
		}

		try {
			$this->get_api( $params )->delete_shop();
		} catch ( SV_WC_API_Exception $exception ) {
			// quietly log any exception
			wc_jilt()->log( "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/**
	 * Update the shop info in Jilt once per day, useful for keeping track
	 * of which WP/WC versions are in use
	 *
	 * @since 1.0.0
	 */
	public function update_shop() {

		if ( ! $this->is_linked() || $this->is_duplicate_site() ) {
			return;
		}

		try {

			// update the linked shop record with the latest settings
			$this->get_api()->update_shop( $this->get_shop_data() );

		} catch ( SV_WC_API_Exception $exception ) {

			// otherwise, log the exception
			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/**
	 * Get and persist the public key for the current API user from the Jilt REST
	 * API
	 *
	 * @since 1.0.0
	 * @return string the public key
	 * @throws SV_WC_API_Exception on network exception or API error
	 */
	public function refresh_public_key() {
		return $this->get_public_key( true );
	}


	/**
	 * Gets the configured public key, optionally refreshing from the Jilt REST
	 * API if $refresh is true
	 *
	 * @since 1.0.0
	 * @param boolean $refresh true if the current API user public key should
	 *        be fetched from the Jilt API
	 * @return string the public key, if set
	 * @throws SV_WC_API_Exception on network exception or API error
	 */
	public function get_public_key( $refresh = false ) {

		$public_key = get_option( 'wc_jilt_public_key', null );

		if ( ( $refresh || ! $public_key ) && $this->is_configured() ) {
			$public_key = $this->get_api()->get_public_key();
			update_option( 'wc_jilt_public_key', $public_key );
		}

		return $public_key;
	}


	/** Other methods ******************************************************/


	/**
	 * Update related Jilt order when order status changes
	 *
	 * @since 1.0.0
	 * @param int $order_id order ID
	 * @param string $old_status
	 * @param string $new_status
	 */
	public function order_status_changed( $order_id, $old_status, $new_status ) {

		if ( $this->is_disabled() ) {
			return;
		}

		$jilt_order_id = get_post_meta( $order_id, '_wc_jilt_order_id', true );

		// bail out if this order is not associated with a Jilt order
		if ( ! $jilt_order_id ) {
			return;
		}

		$order = new WC_Jilt_Order( $order_id );

		$jilt_placed_at    = get_post_meta( $order_id, '_wc_jilt_placed_at', true );
		$jilt_cancelled_at = get_post_meta( $order_id, '_wc_jilt_cancelled_at', true );

		// handle mySQL formatted dates stored prior to 1.1.0
		// TODO: this can be removed in the next minor release (1.2) {MR 2017-03-27}
		if ( $jilt_placed_at && ! is_numeric( $jilt_placed_at ) ) {
			$jilt_placed_at = strtotime( $jilt_placed_at );
		}

		// when a non-placed order transitions to a paid (processing/completed)
		// or on-hold status (unless "Recover Held Orders" is enabled), mark it
		// as placed. see also WC_Abstract_Order::update_status()
		if ( ! $jilt_placed_at && ( $order->is_paid() || ( $new_status == 'on-hold' && ! $this->recover_held_orders() ) ) ) {

			$jilt_placed_at = current_time( 'timestamp', true );
			update_post_meta( $order_id, '_wc_jilt_placed_at', $jilt_placed_at );
		}

		// handle order cancellation: when the status change is from pending ->
		// cancelled, that indicates WooCommerce auto-cancelling an unpaid order
		// from an off-site gateway, and in which case we'd want to consider the
		// order as recoverable. Any other status change to "cancelled" indicates
		// a user or admin cancellation
		if ( ! $jilt_cancelled_at && $old_status != 'pending' && 'cancelled' == $new_status ) {

			$jilt_cancelled_at = current_time( 'timestamp', true );
			update_post_meta( $order_id, '_wc_jilt_cancelled_at', $jilt_cancelled_at );
		}

		$params = array(
			'status'           => $new_status,
			'financial_status' => $order->get_financial_status(),
		);

		if ( $jilt_placed_at ) {
			$params['placed_at'] = $jilt_placed_at;
		}
		if ( $jilt_cancelled_at ) {
			$params['cancelled_at'] = $jilt_cancelled_at;
		}

		// update Jilt order details
		try {

			$this->get_api()->update_order( $jilt_order_id, $params );

		} catch ( SV_WC_API_Exception $exception ) {

			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );
		}
	}


	/** Helper methods ******************************************************/


	/**
	 * Does there seem to be a coupon-enabled WC free shipping method available?
	 *
	 * Supports WC >= 2.6
	 *
	 * @return boolean true if there appears to be a coupon-enabled WC free
	 *   shipping method available.
	 */
	private function is_free_shipping_available() {
		global $wpdb;

		$zone_methods = $wpdb->get_results( "SELECT instance_id FROM {$wpdb->prefix}woocommerce_shipping_zone_methods as methods WHERE methods.method_id = 'free_shipping' AND is_enabled = 1" );
		foreach ( $zone_methods as $zone_method ) {
			$free_shipping_method = new WC_Shipping_Free_Shipping( $zone_method->instance_id );
			if ( in_array( $free_shipping_method->requires, array( 'coupon', 'either', 'both' ) ) ) {
				return true;
			}
		}

		// legacy method enabled?
		// TODO: this can be removed when WC 3.0+ is required
		$legacy_free_shipping = get_option( 'woocommerce_free_shipping_settings' );
		if ( isset( $legacy_free_shipping['enabled'] ) &&
			'yes' === $legacy_free_shipping['enabled'] &&
			in_array( $legacy_free_shipping['requires'], array( 'coupon', 'either', 'both' ) ) ) {
			return true;
		}

		return false;
	}


	/**
	 * Set the API object
	 *
	 * @since 1.1.0
	 * @param WC_Jilt_API $api the Jilt API object
	 */
	private function set_api( $api ) {
		$this->api = $api;
	}


	/** Admin delegator methods ******************************************************/


	/**
	 * Initializes form fields in the format required by WC_Integration
	 *
	 * @see WC_Settings_API::init_form_fields()
	 * @since 1.0.0
	 */
	public function init_form_fields() {
		// delegate to admin instance
		$this->admin->init_form_fields();
	}


}
