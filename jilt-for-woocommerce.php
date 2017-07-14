<?php
/**
 * Plugin Name: Jilt for WooCommerce
 * Plugin URI: https://wordpress.org/plugins/jilt-for-woocommerce/
 * Description: Start recovering lost revenue from abandoned carts in minutes
 * Author: Jilt
 * Author URI: https://jilt.com
 * Version: 1.1.0
 * Text Domain: jilt-for-woocommerce
 * Domain Path: /i18n/languages/
 *
 * Copyright: (c) 2015-2017 SkyVerge, Inc. (info@skyverge.com)
 *
 * License: GNU General Public License v3.0
 * License URI: http://www.gnu.org/licenses/gpl-3.0.html
 *
 * @package   Jilt
 * @author    Jilt
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;

// Required library class
if ( ! class_exists( 'SV_WC_Framework_Bootstrap' ) ) {
	require_once( plugin_dir_path( __FILE__ ) . 'lib/skyverge/woocommerce/class-sv-wc-framework-bootstrap.php' );
}

// WC active check
if ( ! SV_WC_Framework_Bootstrap::is_woocommerce_active() ) {
	return;
}

SV_WC_Framework_Bootstrap::instance()->register_plugin( '4.6.0', __( 'Jilt for WooCommerce', 'jilt-for-woocommerce' ), __FILE__, 'init_woocommerce_jilt', array(
	'minimum_wc_version'   => '2.6.0',
	'minimum_wp_version'   => '4.4',
	'backwards_compatible' => '4.4.0',
) );

function init_woocommerce_jilt() {


/**
 * WooCommerce Jilt Main Plugin Class
 *
 * @since 1.0.0
 */
class WC_Jilt extends SV_WC_Plugin {


	/** plugin version number */
	const VERSION = '1.1.0';

	/** @var WC_Jilt single instance of this plugin */
	protected static $instance;

	/** plugin id */
	const PLUGIN_ID = 'jilt';

	/** the app hostname */
	const HOSTNAME = 'jilt.com';

	/** @var \WC_Jilt_Admin_Status instance */
	protected $admin_status;

	/** @var \WC_Jilt_Customer_Handler instance */
	protected $customer_handler;

	/** @var \WC_Jilt_Cart_Handler instance */
	protected $cart_handler;

	/** @var \WC_Jilt_Checkout_Handler instance */
	protected $checkout_handler;

	/** @var  \WC_Jilt_WC_API_Handler instance */
	protected $wc_api_handler;

	/** @var array data from last request, if any. see SV_WC_API_Base::broadcast_request() for format */
	private $last_api_request;

	/** @var array data from last API response, if any */
	private $last_api_response;


	/**
	 * Initializes the plugin
	 *
	 * @since 1.0.0
	 * @return \WC_Jilt
	 */
	public function __construct() {

		parent::__construct(
			self::PLUGIN_ID,
			self::VERSION,
			array( 'text_domain' => 'jilt-for-woocommerce' )
		);

		// Include required files
		add_action( 'sv_wc_framework_plugins_loaded', array( $this, 'includes' ) );
	}


	/**
	 * Include required files
	 *
	 * @since 1.0.0
	 */
	public function includes() {

		// base integration & related classes
		require_once( $this->get_plugin_path() . '/includes/class-wc-jilt-order.php' );
		require_once( $this->get_plugin_path() . '/includes/class-wc-jilt-integration.php' );

		// load Jilt API classes
		require_once( $this->get_plugin_path() . '/includes/api/class-wc-jilt-api.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-wc-jilt-api-request.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-wc-jilt-api-response.php' );

		require_once( $this->get_plugin_path() . '/includes/admin/class-wc-jilt-integration-admin.php' );

		add_filter( 'woocommerce_integrations', array( $this, 'load_integration' ) );

		// frontend includes
		if ( ! is_admin() ) {

			// customer handler must be loaded earlier than others to use woocommerce_init hook
			$this->customer_handler = $this->load_class( '/includes/handlers/class-wc-jilt-customer-handler.php', 'WC_Jilt_Customer_Handler' );

			add_action( 'init', array( $this, 'frontend_includes' ) );
		}

		// admin includes
		if ( is_admin() && ! is_ajax() ) {
			add_action( 'admin_init', array( $this, 'admin_includes' ) );
		}
	}


	/**
	 * Include required frontend files
	 *
	 * @since 1.0.0
	 */
	public function frontend_includes() {

		if ( $this->get_integration()->is_linked() ) {

			// abstract handler
			require_once( $this->get_plugin_path() . '/includes/handlers/abstract-wc-jilt-handler.php' );

			// cart/checkout handlers
			$this->cart_handler     = $this->load_class( '/includes/handlers/class-wc-jilt-cart-handler.php', 'WC_Jilt_Cart_Handler' );
			$this->checkout_handler = $this->load_class( '/includes/handlers/class-wc-jilt-checkout-handler.php', 'WC_Jilt_Checkout_Handler' );
		}

		// WC API: do our best to handle requests even when the plugin is not linked
		require_once( $this->get_plugin_path() . '/includes/api/abstract-wc-jilt-integration-api-base.php' );
		require_once( $this->get_plugin_path() . '/includes/api/class-wc-jilt-integration-api.php' );
		$this->wc_api_handler = $this->load_class( '/includes/handlers/class-wc-jilt-wc-api-handler.php', 'WC_Jilt_WC_API_Handler' );
	}


	/**
	 * Include required admin files
	 *
	 * @since 1.0.0
	 */
	public function admin_includes() {

		$this->admin_status = $this->load_class( '/includes/admin/class-wc-jilt-admin-status.php', 'WC_Jilt_Admin_Status' );
	}


	/**
	 * Add Jilt WC integration
	 *
	 * @since 1.0.0
	 * @param array $integrations
	 * @return array
	 */
	public function load_integration( $integrations ) {

		$integrations[] = 'WC_Jilt_Integration';

		return $integrations;
	}


	/** Admin methods ******************************************************/


	/**
	 * Render a notice for the user to read the docs before adding add-ons
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::add_delayed_admin_notices()
	 */
	public function add_delayed_admin_notices() {

		// show any dependency notices
		parent::add_delayed_admin_notices();

		$screen = get_current_screen();

		// no messages to display if the plugin is already configured
		if ( $this->get_integration()->is_configured() ) {
			return;
		}

		// plugins page, link to settings
		if ( 'plugins' === $screen->id ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
			$message = sprintf( __( 'Thanks for installing Jilt! To get started, %1$sget your Jilt API key%2$s and %3$sconfigure the plugin%4$s :)', 'jilt-for-woocommerce' ),
				'<a href="https://' . $this->get_app_hostname() . '/account" target="_blank">', '</a>',
				'<a href="' . esc_url( $this->get_settings_url() ) . '">', '</a>' );

		} elseif ( $this->is_plugin_settings() ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
			$message = sprintf( __( 'Thanks for installing Jilt! To get started, %1$sget your Jilt API key%2$s and enter it below :)', 'jilt-for-woocommerce' ), '<a href="https://' . $this->get_app_hostname() . '/account" target="_blank">', '</a>' );
		}

		// only render on plugins or settings screen
		if ( ! empty( $message ) ) {

			$this->get_admin_notice_handler()->add_admin_notice(
				$message,
				'get-started-notice',
				array( 'always_show_on_settings' => false )
			);
		}
	}


	/** Helper methods ******************************************************/


	/**
	 * When the Jilt API indicates a customer's Jilt account has been cancelled,
	 * deactivate the plugin.
	 *
	 * @since 1.0.0
	 */
	public function handle_account_cancellation() {

		require_once( ABSPATH . 'wp-admin/includes/plugin.php' );
		deactivate_plugins( $this->get_file() );
	}


	/**
	 * Log a statement at log level INFO
	 *
	 * @since 1.0.0
	 * @see self::log_with_level()
	 * @param string $message error or message to save to log
	 * @param string $log_id optional log id to segment the files by, defaults to plugin id
	 */
	public function log( $message, $log_id = null ) {

		// consider this method to be log level INFO
		$this->log_with_level( WC_Jilt_Integration::LOG_LEVEL_INFO, $message, $log_id );

	}


	/**
	 * Saves errors or messages to WC log when logging is enabled.
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::log()
	 * @param int $level one of LOG_LEVEL_OFF, LOG_LEVEL_DEBUG, LOG_LEVEL_INFO,
	 *   LOG_LEVEL_WARN, LOG_LEVEL_ERROR, LOG_LEVEL_FATAL
	 * @param string $message error or message to save to log
	 * @param string $log_id optional log id to segment the files by, defaults to plugin id
	 */
	public function log_with_level( $level, $message, $log_id = null ) {

		// allow logging?
		if ( $this->get_integration()->logging_enabled( $level ) ) {

			$level_name = $this->get_integration()->get_log_level_name( $level );

			// if we're logging an error or fatal, and there is an unlogged API
			// request, log it as well
			if ( $this->last_api_request && $level >= WC_Jilt_Integration::LOG_LEVEL_ERROR ) {
				$this->log_api_request_helper( $level_name, $this->last_api_request, $this->last_api_response, $log_id );

				$this->last_api_request = null;
				$this->last_api_response = null;
			}

			parent::log( "{$level_name} : {$message}", $log_id );
		}

	}


	/**
	 * Log API requests/responses
	 *
	 * @since 1.1.0
	 * @see SV_WC_Plugin::log_api_request
	 * @param array $request request data, see SV_WC_API_Base::broadcast_request() for format
	 * @param array $response response data
	 * @param string|null $log_id log to write data to
	 */
	public function log_api_request( $request, $response, $log_id = null ) {

		// defaults to DEBUG level
		if ( $this->get_integration()->logging_enabled( WC_Jilt_Integration::LOG_LEVEL_DEBUG ) ) {
			$this->log_api_request_helper( 'DEBUG', $request, $response, $log_id );

			$this->last_api_request = null;
			$this->last_api_response = null;
		} else {
			// save the request/response data in case our log level is higher than
			// DEBUG but there was an error
			$this->last_api_request  = $request;
			$this->last_api_response = $response;
		}

	}


	/**
	 * Log API requests/responses with a given log level
	 *
	 * @since 1.1.0
	 * @see self::log_api_request()
	 * @param string $level_name one of 'OFF', 'DEBUG', 'INFO', 'WARN', 'ERROR', 'FATAL'
	 * @param array $request request data, see SV_WC_API_Base::broadcast_request() for format
	 * @param array $response response data
	 * @param string|null $log_id log to write data to
	 */
	private function log_api_request_helper( $level_name, $request, $response, $log_id = null ) {

		parent::log( "{$level_name} : Request\n" . $this->get_api_log_message( $request ), $log_id );

		if ( ! empty( $response ) ) {
			parent::log( "{$level_name} : Response\n" . $this->get_api_log_message( $response ), $log_id );
		}

	}


	/**
	 * Returns the instance of WC_Jilt_Integration, the integration class
	 *
	 * @since 1.0.0
	 * @return WC_Jilt_Integration The integration class instance
	 */
	public function get_integration() {

		$integrations = WC()->integrations->get_integrations();

		return $integrations['jilt'];
	}


	/**
	 * Main Jilt Plugin instance, ensures only one instance is/can be loaded
	 *
	 * @since 1.0.0
	 * @see wc_jilt()
	 * @return WC_Jilt
	 */
	public static function instance() {
		if ( is_null( self::$instance ) ) {
			self::$instance = new self();
		}
		return self::$instance;
	}


	/**
	 * Returns the plugin name, localized
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::get_plugin_name()
	 * @return string the plugin name
	 */
	public function get_plugin_name() {
		return __( 'Jilt for WooCommerce', 'jilt-for-woocommerce' );
	}


	/**
	 * Returns __FILE__
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::get_file()
	 * @return string the full path and filename of the plugin file
	 */
	protected function get_file() {
		return __FILE__;
	}


	/**
	 * Returns true if on the plugin settings page
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::is_plugin_settings()
	 * @return boolean true if on the settings page
	 */
	public function is_plugin_settings() {

		return isset( $_GET['page'] ) && 'wc-settings' === $_GET['page'] &&
		       isset( $_GET['tab'] ) && 'integration' === $_GET['tab'] &&
		       ( ! isset( $_GET['section'] ) || $this->get_id() === $_GET['section'] );
	}


	/**
	 * Gets the plugin configuration URL
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::get_settings_link()
	 * @param string $plugin_id optional plugin identifier.
	 * @return string plugin settings URL
	 */
	public function get_settings_url( $plugin_id = null ) {
		return admin_url( 'admin.php?page=wc-settings&tab=integration&section=jilt' );
	}


	/**
	 * Gets the wordpress.org plugin page URL
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::get_product_page_url()
	 * @return string wordpress.org product page url
	 */
	public function get_product_page_url() {

		return 'https://wordpress.org/plugins/jilt-for-woocommerce/';
	}


	/**
	 * No review link for Jilt
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::get_review_url()
	 * @return string review url
	 */
	public function get_review_url() {
		return null;
	}


	/**
	 * Gets the plugin documentation url
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::get_documentation_url()
	 * @return string documentation URL
	 */
	public function get_documentation_url() {

		return 'http://help.jilt.com/collection/176-jilt-for-woocommerce';
	}


	/**
	 * Get the Jilt hostname.
	 *
	 * @sine 1.1.0
	 * @return string
	 */
	public function get_hostname() {

		/**
		 * Filter the Jilt hostname, used in development for changing to
		 * dev/staging instances
		 *
		 * @since 1.1.0
		 * @param string $hostname
		 * @param \WC_Jilt $this instance
		 */
		return apply_filters( 'wc_jilt_hostname', self::HOSTNAME, $this );
	}


	/**
	 * Get the app hostname
	 *
	 * @since 1.0.3
	 * @return string app hostname, defaults to app.jilt.com
	 */
	public function get_app_hostname() {

		return sprintf( 'app.%s', $this->get_hostname() );
	}


	/**
	 * Get the current shop domain
	 *
	 * @since 1.1.0
	 * @return string the current shop domain
	 */
	public function get_shop_domain() {
		return parse_url( get_home_url(), PHP_URL_HOST );
	}


	/**
	 * Get the best available timestamp for when WooCommerce was installed in
	 * this site. For this we use the create date of the special shop page,
	 * if it exists
	 *
	 * @since 1.1.0
	 * @return string|null The timestamp at which WooCommerce was installed in
	 *   this shop, in iso8601 format
	 */
	public function get_wc_created_at() {

		$shop_page = get_post( wc_get_page_id( 'shop' ) );

		if ( $shop_page ) {
			return date( 'Y-m-d\TH:i:s\Z', strtotime( $shop_page->post_date_gmt ) );
		}
	}


	/**
	 * Gets the Jilt support URL, with optional parameters given by $args
	 *
	 * @since 1.0.0
	 * @param array $args Optional array of method arguments:
	 *        'domain' defaults to server domain
	 *        'form_type' defaults to 'support'
	 *        'platform' defaults to 'WooCommerce'
	 *        'message' defaults to false, if given this will be pre-populated in the support form message field
	 *        'first_name' defaults to current user first name
	 *        'last_name' defaults to current user last name
	 *        'email' defaults to current user email
	 *        Any parameter can be excluded from the returned URL by setting to false.
	 *        If $args itself is null, then no parameters will be added to the support URL
	 * @return string support URL
	 */
	public function get_support_url( $args = array() ) {

		if ( is_array( $args ) ) {

			$current_user       = wp_get_current_user();

			$args = array_merge(
				array(
					'domain'     => $this->get_shop_domain(),
					'form_type'  => 'support',
					'platform'   => 'woocommerce',
					'first_name' => $current_user->user_firstname,
					'last_name'  => $current_user->user_lastname,
					'email'      => $current_user->user_email,
				),
				$args
			);

			// strip out empty params, and urlencode the others
			foreach ( $args as $key => $value ) {
				if ( false === $value ) {
					unset( $args[ $key ] );
				} else {
					$args[ $key ] = urlencode( $value );
				}
			}
		}

		return "https://jilt.com/contact/" . ( ! is_null( $args ) && count( $args ) > 0 ? '?' . build_query( $args ) : '' );
	}


	/**
	 * Get the currently released version of the plugin available on wordpress.org
	 *
	 * @since 1.1.0
	 * @return string the version, e.g. '1.0.0'
	 */
	public function get_latest_plugin_version() {

		if ( false === ( $version_data = get_transient( md5( $this->get_id() ) . '_version_data' ) ) ) {
			$changelog = wp_safe_remote_get( 'https://plugins.svn.wordpress.org/jilt-for-woocommerce/trunk/readme.txt' );
			$cl_lines  = explode( '\n', wp_remote_retrieve_body( $changelog ) );

			if ( ! empty( $cl_lines ) ) {
				foreach ( $cl_lines as $line_num => $cl_line ) {
					if ( preg_match( '/= ([\d\-]{10}) - version ([\d.]+) =/', $cl_line, $matches ) ) {
						$version_data = array( 'date' => $matches[1] , 'version' => $matches[2] );
						set_transient( md5( $this->get_id() ) . '_version_data', $version_data, DAY_IN_SECONDS );
						break;
					}
				}
			}
		}

		if ( isset( $version_data['version'] ) ) {
			return $version_data['version'];
		}
	}


	/**
	 * Is there a plugin update available on wordpress.org?
	 *
	 * @since 1.1.0
	 * @return boolean true if there's an update avaialble
	 */
	public function is_plugin_update_available() {

		$current_plugin_version = $this->get_latest_plugin_version();

		if ( ! $current_plugin_version ) {
			return false;
		}

		return version_compare( $current_plugin_version, $this->get_version(), '>' );
	}


	/** Lifecycle methods *****************************************************/


	/**
	 * Called when the plugin is activated. Note this is *not* triggered during
	 * auto-updates from WordPress.org, but the upgrade() method below handles that.
	 *
	 * @since 1.0.6
	 * @see SV_WC_Plugin::activate()
	 */
	public function activate() {

		// update shop data in Jilt (especially plugin version), note this will
		// will be triggered when the plugin is downgraded to an older version
		$this->get_integration()->update_shop();
	}


	/**
	 * Clear the shop update cron task when the plugin is deactivated and
	 * unlink the remote Jilt shop
	 *
	 * @since 1.0.0
	 * @see SV_WC_Plugin::deactivate()
	 */
	public function deactivate() {
		wp_clear_scheduled_hook( 'wc_jilt_shop_update' );

		if ( $this->get_integration()->is_linked() ) {
			$this->get_integration()->unlink_shop();
		}
	}


	/**
	 * Handle upgrading the plugin to the current version.
	 *
	 * @since 1.0.6
	 * @see SV_WC_Plugin::upgrade()
	 * @param string $installed_version currently installed version
	 */
	protected function upgrade( $installed_version ) {

		// update plugin settings:
		// - debug_mode => log_level, log => INFO, off => OFF
		// - set wc_jilt_shop_domain wp option, if linked shop
		// - current secret key is stashed into a wp option, if linked shop
		if ( version_compare( $installed_version, '1.1.0', '<' ) ) {

			// get existing settings
			$settings = $this->get_integration()->get_settings();

			if ( ! isset( $settings['log_level'] ) ) {
				$settings['log_level'] = $settings['debug_mode'] == 'log' ? WC_Jilt_Integration::LOG_LEVEL_INFO : WC_Jilt_Integration::LOG_LEVEL_OFF;
				unset( $settings['debug_mode'] );

				// update to new settings
				$this->get_integration()->update_settings( $settings );
			}

			if ( $this->get_integration()->is_linked() ) {
				$this->get_integration()->set_shop_domain();
				$this->get_integration()->stash_secret_key();

			}
		}

		// update shop data in Jilt (especially plugin version)
		$this->get_integration()->update_shop();
	}


} // end WC_Jilt class


/**
 * Returns the One True Instance of Jilt
 *
 * @since 1.0.0
 * @return \WC_Jilt
 */
function wc_jilt() {
	return WC_Jilt::instance();
}

// fire it up!
wc_jilt();

} // init_woocommerce_jilt()
