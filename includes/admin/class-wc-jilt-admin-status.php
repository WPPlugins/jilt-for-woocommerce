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
 * Admin class
 *
 * @since 1.0.0
 */
class WC_Jilt_Admin_Status {


	/**
	 * Constructor
	 *
	 * @since 1.0.0
	 */
	public function __construct() {

		add_action( 'woocommerce_system_status_report', array( $this, 'add_jilt_status' ) );

	}


	/**
	 * Add Jilt status box to the WC Status page
	 *
	 * @since 1.0.0
	 */
	public function add_jilt_status() {
		?>
		<table class="wc_status_table widefat" cellspacing="0" id="jilt-status">
			<thead>
				<tr>
					<th colspan="3" data-export-label="Jilt"><?php esc_html_e( 'Jilt Abandoned Cart Recovery', 'jilt-for-woocommerce' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<tr>
					<td data-export-label="Plugin Version"><?php esc_html_e( 'Plugin Version', 'jilt-for-woocommerce' ); ?>:</td>
					<td class="help"><?php echo '<a href="#" class="help_tip" data-tip="' . esc_attr__( 'The version of the Jilt plugin installed on your site.', 'jilt-for-woocommerce' ) . '">[?]</a>'; ?></td>
					<td>
						<?php echo esc_html( wc_jilt()->get_version() ); ?>
						<?php if ( wc_jilt()->is_plugin_update_available() ) : ?>
							&ndash;
							<strong style="color:red;">
								<?php echo esc_html( sprintf( _x( '%s is available', 'Version info', 'jilt-for-woocommerce' ), wc_jilt()->get_latest_plugin_version() ) ) ?>
							</strong>
						<?php endif; ?>
					</td>
				</tr>
				<tr>
					<td data-export-label="API Version"><?php esc_html_e( 'API Version', 'jilt-for-woocommerce' ); ?>:</td>
					<td class="help"><?php echo '<a href="#" class="help_tip" data-tip="' . esc_attr__( 'The version of the Jilt REST API supported by this plugin.', 'jilt-for-woocommerce' ) . '">[?]</a>'; ?></td>
					<td><?php echo esc_html( wc_jilt()->get_integration()->get_api()->get_api_version() ); ?></td>
				</tr>
				<tr>
					<td data-export-label="API Connected"><?php esc_html_e( 'API Connected', 'jilt-for-woocommerce' ); ?>:</td>
					<td class="help"><?php echo '<a href="#" class="help_tip" data-tip="' . esc_attr__( 'Indicates whether the plugin has been successfully configured and connected to the Jilt API.', 'jilt-for-woocommerce' ) . '">[?]</a>'; ?></td>
					<td><?php
						if ( wc_jilt()->get_integration()->has_connected() ) :
							echo '<mark class="yes">&#10004;</mark>';
						else:
							/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
							echo '<mark class="error">' . '&#10005; ' . sprintf( esc_html__( 'Please ensure the plugin is properly %1$sconfigured%2$s with your Jilt secret key.', 'jilt-for-woocommerce' ), '<a href="' . esc_url( wc_jilt()->get_settings_url() ) . '">', '</a>' ) . '</mark>';
						endif;
					?></td>
				</tr>
				<tr>
					<td data-export-label="Linked to Jilt"><?php esc_html_e( 'Linked to Jilt', 'jilt-for-woocommerce' ); ?>:</td>
					<td class="help"><?php echo '<a href="#" class="help_tip" data-tip="' . esc_attr__( 'Indicates whether the plugin has successfully linked your shop to your Jilt account.', 'jilt-for-woocommerce' ) . '">[?]</a>'; ?></td>
					<td><?php
						if ( wc_jilt()->get_integration()->is_linked() ) :
							echo '<mark class="yes">&#10004;</mark>';
						else:
							/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
							echo '<mark class="error">' . '&#10005; ' . sprintf( esc_html__( 'Please ensure the plugin is properly %1$sconfigured%2$s with your Jilt secret key.', 'jilt-for-woocommerce' ), '<a href="' . esc_url( wc_jilt()->get_settings_url() ) . '">', '</a>' ) . '</mark>';
						endif;
					?></td>
				</tr>
				<tr>
					<td data-export-label="Enabled"><?php esc_html_e( 'Enabled', 'jilt-for-woocommerce' ); ?>:</td>
					<td class="help"><?php echo '<a href="#" class="help_tip" data-tip="' . esc_attr__( 'Indicates whether the plugin is enabled and sending Order data to Jilt.', 'jilt-for-woocommerce' ) . '">[?]</a>'; ?></td>
					<td><?php
						if ( ! wc_jilt()->get_integration()->has_connected() || ! wc_jilt()->get_integration()->is_linked() || wc_jilt()->get_integration()->is_disabled() || wc_jilt()->get_integration()->is_duplicate_site() ) :
							echo '<mark class="error">' . '&#10005; ';
							if ( wc_jilt()->get_integration()->is_duplicate_site() ) {
								/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
								$message = sprintf( __( 'It looks like this site has moved or is a duplicate site. For more information please %1$sget in touch%2$s', 'jilt-for-woocommerce' ),
									'<strong>', '</strong>',
									'<a target="_blank" href="' . wc_jilt()->get_support_url() . '">', '</a>'
								);
								echo $message;
							} elseif ( wc_jilt()->get_integration()->is_disabled() ) {
								/* translators: Placeholders: %1$s - <a> tag, %2$s - </a> tag */
								echo sprintf( esc_html__( 'Plugin has been disabled within your %1$sJilt admin%2$s.', 'jilt-for-woocommerce' ), '<a href="' . esc_url( 'https://' . wc_jilt()->get_app_hostname() . '/account' ) . '">', '</a>' );
							}
							echo '</mark>';
						else:
							echo '<mark class="yes">&#10004;</mark>';
						endif;
					?></td>
				</tr>
			</tbody>
		</table>
		<?php
	}


}
