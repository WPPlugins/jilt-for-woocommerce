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
 * Integration admin class. The WC_Jilt_Integration class delegates as much
 * admin related functionality as possible to this class. This strange setup is
 * the result of the tight coupling between integration functionality code and
 * admin settings within WooCommerce.
 *
 * @since 1.1.0
 */
class WC_Jilt_Integration_Admin {


	/** @var WC_Jilt_Integration instance */
	private $integration;


	/**
	 * Initialize the class
	 *
	 * @since 1.0.0
	 * @see WC_Jilt_Integration::instance()
	 */
	public function __construct( $integration ) {

		$this->integration = $integration;

		$this->integration->id                 = 'jilt';
		$this->integration->method_title       = __( 'Jilt', 'jilt-for-woocommerce' );
		$this->integration->method_description = __( 'Automatically send reminder emails to customers who have abandoned their cart, and recover lost sales', 'jilt-for-woocommerce' );

		// Load admin form
		$this->init_form_fields();

		if ( $this->integration->is_linked() ) {

			// update the shop info in Jilt once per day
			if ( ! wp_next_scheduled( 'wc_jilt_shop_update' ) ) {
				wp_schedule_event( time(), 'daily' , 'wc_jilt_shop_update' );
			}
			add_action( 'wc_jilt_shop_update', array( $this->integration, 'update_shop') );
		}

		// as an integration it's up to us to save our admin options
		if ( is_admin() ) {
			add_filter( 'woocommerce_settings_api_sanitized_fields_jilt', array( $this, 'sanitize_fields' ) );

			add_action( 'woocommerce_update_options_integration_jilt', array( $this, 'process_admin_options' ) );

			// report connection errors
			add_action( 'admin_notices', array( $this, 'show_connection_notices' ) );

			// whenever WC settings are changed (including Jilt's own settings), update data in Jilt app
			add_action( 'woocommerce_settings_saved', array( $this->integration, 'update_shop' ) );
		}
	}


	/**
	 * Initializes form fields in the format required by WC_Integration
	 *
	 * @since 1.0.0
	 */
	public function init_form_fields() {

		$this->integration->form_fields = array(

			'secret_key' => array(
				'title'   => __( 'Secret Key', 'jilt-for-woocommerce' ),
				'type'    => 'password',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description' => sprintf( __( 'Get this from your %1$sJilt account%2$s', 'jilt-for-woocommerce' ), '<a href="' . esc_url( 'https://' . wc_jilt()->get_app_hostname() . '/shops/new/woocommerce' ) . '" target="_blank">', '</a>' ),
			),

			'recover_held_orders' => array(
				'title'       => __( 'Recover Held Orders', 'jilt-for-woocommerce' ),
				'label'       => __( 'Send recovery emails for orders with status "on-hold"', 'jilt-for-woocommerce' ),
				'type'        => 'checkbox',
				'description' => __( 'When enabled, recovery emails will be sent for orders with the "on-hold" status', 'jilt-for-woocommerce' ),
				'default'     => 'no',
			),

			'log_level' => array(
				'title'   => __( 'Logging', 'jilt-for-woocommerce' ),
				'type'    => 'select',
				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
				'description'    => sprintf( __( 'Save detailed error messages and API requests/responses to the %1$sdebug log%2$s', 'jilt-for-woocommerce' ), '<a href="' . esc_url( SV_WC_Helper::get_wc_log_file_url( wc_jilt()->get_id() ) ) . '">', '</a>' ),
				'default' => WC_Jilt_Integration::LOG_LEVEL_OFF,
				'options' => array(
					WC_Jilt_Integration::LOG_LEVEL_OFF   => _x( 'Off',   'Logging disabled', 'jilt-for-woocommerce' ),
					WC_Jilt_Integration::LOG_LEVEL_DEBUG => _x( 'Debug', 'Log level debug',  'jilt-for-woocommerce' ),
					WC_Jilt_Integration::LOG_LEVEL_INFO  => __( 'Info',  'Log level info',   'jilt-for-woocommerce' ),
					WC_Jilt_Integration::LOG_LEVEL_WARN  => __( 'Warn',  'Log level warn',   'jilt-for-woocommerce' ),
					WC_Jilt_Integration::LOG_LEVEL_ERROR => __( 'Error', 'Log level error',  'jilt-for-woocommerce' ),
					WC_Jilt_Integration::LOG_LEVEL_FATAL => __( 'Fatal', 'Log level fatal',  'jilt-for-woocommerce' ),
				),
			),

			'links' => array(
				'title'       => '',
				'type'        => 'title',
				'description' => $this->get_links_form_field_description(),
			),
		);
	}


	/**
	 * Update Jilt public key and shop ID when updating secret key
	 *
	 * @since 1.0.0
	 * @see WC_Settings_API::process_admin_options
	 * @return bool
	 */
	public function process_admin_options() {

		$old    = $this->integration->settings;
		$result = $this->integration->process_admin_options();
		$new    = $this->integration->settings;

		// secret key has been changed or removed, so unlink remote shop
		if ( $new['secret_key'] != $old['secret_key'] && $this->integration->is_linked() ) {
			$this->integration->unlink_shop( array( 'secret_key' => $old['secret_key'] ) );
		}

		if ( $new['secret_key'] && ( $new['secret_key'] != $old['secret_key'] || ! $this->integration->has_connected() || ! $this->integration->is_linked() ) ) {
			$this->connect_to_jilt( $new['secret_key'] );

			// avoid an extra useless REST API request
			remove_action( 'woocommerce_settings_saved', array( $this->integration, 'update_shop' ) );
		}

		// disconnecting from Jilt :'(
		if ( ! $new['secret_key'] && $old['secret_key'] ) {
			$this->integration->clear_connection_data();

			wc_jilt()->get_admin_notice_handler()->add_admin_notice( __( 'Shop is now unlinked from Jilt', 'jilt-for-woocommerce' ), 'unlink-notice' );
		}

		// the links that we added to the settings block could be incorrect now
		if ( $new['secret_key'] != $old['secret_key'] ) {
			add_action( 'admin_footer',  array( $this, 'update_jilt_links_js' ) );
		}

		return $result;
	}


	/**
	 * Sanitize Jilt settings. Removes faux settings, such as `links`.
	 *
	 * @since 1.0.0
	 * @param array $sanitized_fields
	 * @return array
	 */
	public function sanitize_fields( $sanitized_fields ) {

		if ( isset( $sanitized_fields['links'] ) ) {
			unset( $sanitized_fields['links'] );
		}

		return $sanitized_fields;
	}


	/**
	 * Returns an HTML fragment containing the Jilt external campaigns/dashboard
	 * links for the plugin settings page
	 *
	 * @since 1.0.3
	 * @return string HTML fragment
	 */
	private function get_links_form_field_description() {

		/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag*/
		$links = sprintf( __( '%1$sGet Support!%2$s', 'jilt-for-woocommerce' ),
			'<a target="_blank" href="' . esc_url( wc_jilt()->get_support_url() ) . '">', '</a>'
		);

		if ( $this->integration->is_linked() ) {
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
			$links = sprintf( __( '%1$sConfigure my Campaigns%2$s | %3$sView Statistics%4$s', 'jilt-for-woocommerce' ),
				'<a target="_blank" href="' . esc_url( $this->integration->get_jilt_app_url( 'campaigns' ) ) . '">', '</a>',
				'<a target="_blank" href="' . esc_url( $this->integration->get_jilt_app_url( 'dashboard' ) ) . '">', '</a>'
			) . ' | ' . $links;
		}

		return $links;
	}


	/**
	 * Render javascript to update the Jilt campaign/statistics external links
	 * shown on the Jilt plugin settings page, via JavaScript. This is done
	 * when the shop's connection to Jilt may have changed, since the links are
	 * first written out before any Jilt connection/disconnection is handled.
	 *
	 * @since 1.0.3
	 */
	public function update_jilt_links_js() {
		?>
		<script>
			jQuery('#woocommerce_jilt_links + p').html('<?php echo $this->get_links_form_field_description(); ?>');
		</script>
		<?php
	}


	/**
	 * We already show connection error notices when the plugin settings save
	 * post is happening; this method makes those notices more persistent by
	 * showing a connection notice on a regular page load if there's an issue
	 * with the Jilt connection.
	 *
	 * @since 1.0.3
	 */
	public function show_connection_notices() {

		if ( $this->integration->is_duplicate_site() ) {
			if ( ! ( wc_jilt()->is_plugin_settings() && isset( $_POST['save'] ) ) ) {
				// don't render the message if we're saving on the plugin settings page

				/* translators: Placeholders: %1$s - <strong> tag, %2$s - </strong> tag, %3$s - <a> tag, %4$s </a> tag */
				$message = sprintf( __( 'It looks like this site has moved or is a duplicate site. %1$sJilt for WooCommerce%2$s has been disabled to prevent sending recovery emails from a staging or test environment. For more information please %3$sget in touch%4$s.', 'jilt-for-woocommerce' ),
					'<strong>', '</strong>',
					'<a target="_blank" href="' . wc_jilt()->get_support_url() . '">', '</a>'
				);
				wc_jilt()->get_admin_notice_handler()->add_admin_notice( $message, 'duplicate-site-unlink-notice', array( 'notice_class' => 'error' ) );
			}

			return;
		}

		// if we're on the Jilt settings page and we're not currently saving the settings (e.g. regular page load), and the plugin is configured
		if ( ! wc_jilt()->is_plugin_settings() || isset( $_POST['save'] ) || ! $this->integration->is_configured() ) {
			return;
		}

		$message = null;

		// call to action based on error state
		if ( ! $this->integration->has_connected() ) {

			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
			$solution_message = sprintf( __( 'Please try re-entering your %1$sJilt API Secret Key%2$s or %3$sget in touch with Jilt Support%4$s to help resolve this issue.', 'jilt-for-woocommerce' ),
				'<a target="_blank" href="https://' . wc_jilt()->get_app_hostname() . '/shops/new/woocommerce">',
				'</a>',
				'<a target="_blank" href="' . esc_url( wc_jilt()->get_support_url() ) . '">',
				'</a>'
			);
			$this->add_api_error_notice( array( 'solution_message' => $solution_message  ));

		} elseif ( ! $this->integration->is_linked() ) {
			$this->add_api_error_notice( array( 'support_message' => "I'm having an issue linking my shop to Jilt" ) );
		}
	}


	/**
	 * If a $secret_key is provided, attempt to connect to the Jilt API to
	 * retrieve the corresponding Public Key, and link the shop to Jilt
	 *
	 * @since 1.0.0
	 * @param string $secret_key the secret key to use, or empty string
	 * @return true if this shop is successfully connected to Jilt, false otherwise
	 */
	private function connect_to_jilt( $secret_key ) {

		try {

			// remove the previous public key and linked shop id, if any, when the secret key is changed
			$this->integration->clear_connection_data();

			if ( $secret_key ) {
				$this->integration->refresh_public_key();

				if ( is_int( $this->integration->link_shop() ) ) {
					// dismiss the "welcome" message now that we've successfully linked
					wc_jilt()->get_admin_notice_handler()->dismiss_notice( 'get-started-notice' );
					wc_jilt()->get_admin_notice_handler()->add_admin_notice(
						__( 'Shop is now linked to Jilt!', 'jilt-for-woocommerce' ),
						'shop-linked'
					);
					return true;
				} else {
					$this->add_api_error_notice( array( 'error_message' => 'Unable to link shop' ) );
				}
			}

			return false;

		} catch ( SV_WC_API_Exception $exception ) {

			$solution_message = null;

			// call to action based on error message
			if ( SV_WC_Helper::str_exists( $exception->getMessage(), 'Invalid API Key provided' ) ) {

				/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag, %3$s - <a> tag, %4$s - </a> tag */
				$solution_message = sprintf( __( 'Please try re-entering your %1$sJilt API Secret Key%2$s or %3$sget in touch with Jilt Support%4$s to resolve this issue.', 'jilt-for-woocommerce' ),
					'<a target="_blank" href="https://' . wc_jilt()->get_app_hostname() . '/shops/new/woocommerce">',
					'</a>',
					'<a target="_blank" href="' . esc_url( wc_jilt()->get_support_url( array( 'message' => $exception->getMessage() ) ) ) . '">',
					'</a>'
				);
			}

			$this->add_api_error_notice( array( 'error_message' => $exception->getMessage(), 'solution_message' => $solution_message ) );

			wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_ERROR, "Error communicating with Jilt: {$exception->getMessage()}" );

			return false;
		}
	}


	/**
	 * Report an API error message in an admin notice with a link to the Jilt
	 * support page. Optionally log error.
	 *
	 * @since 1.1.0
	 * @param array $params Associative array of params:
	 *   'error_message': optional error message
	 *   'solution_message': optional solution message (defaults to "get in touch with support")
	 *   'support_message': optional message to include in a support request
	 *     (defaults to error_message)
	 *
	 */
	private function add_api_error_notice( $params ) {

		if ( ! isset( $params['error_message'] ) ) {
			$params['error_message'] = null;
		}

		// this will be pre-populated in any support request form. Defaults to
		// the error message, if not set
		if ( empty( $params['support_message'] ) ) {
			$params['support_message'] = $params['error_message'];
		}

		if ( empty( $params['solution_message'] ) ) {
			// generic solution message: get in touch with support
			/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
			$params['solution_message'] = sprintf(__( 'Please %1$sget in touch with Jilt Support%2$s to resolve this issue.', 'jilt-for-woocommerce' ),
				'<a target="_blank" href="' . esc_url( wc_jilt()->get_support_url( array( 'message' => $params['support_message'] ) ) ) . '">',
				'</a>'
			);
		}

		if ( ! empty( $params['error_message'] ) ) {
			// add a full stop
			$params['error_message'] .= '.';
		}

		/* translators: Placeholders: %1$s - <strong> tag, %2$s - </strong> tag, %3$s - error message, %4$s - solution message */
		$notice = sprintf( __( '%1$sError communicating with Jilt%2$s %3$s %4$s', 'jilt-for-woocommerce' ),
			'<strong>',
			'</strong>',
			$params['error_message'],
			$params['solution_message']
		);

		wc_jilt()->get_admin_notice_handler()->add_admin_notice(
			$notice,
			'api-error',
			array( 'notice_class' => 'error' )
		);
	}


}
