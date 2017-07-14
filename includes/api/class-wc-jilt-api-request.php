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
 * @category  Admin
 * @copyright Copyright (c) 2015-2017, SkyVerge, Inc.
 * @license   http://www.gnu.org/licenses/gpl-3.0.html GNU General Public License v3.0
 */

defined( 'ABSPATH' ) or exit;


/**
 * Jilt API Request class
 *
 * @since 1.0.0
 */
class WC_Jilt_API_Request extends SV_WC_API_JSON_Request {


	/**
	 * While not strictly a JSON request, it's close enough to use this base class
	 *
	 * @since 1.0.0
	 * @param string $method request method
	 * @param string $path request path
	 * @param array $params associative array of request parameters
	 */
	public function __construct( $method, $path = '', $params = array() ) {
		$this->method = $method;
		$this->path   = $path;
		$this->params = $params;
	}


	/**
	 * Returns the string representation of this request
	 *
	 * @since 4.0.0
	 * @see SV_WC_API_Request::to_string()
	 * @return string request
	 */
	public function to_string() {

		// URL encode params
		return http_build_query( $this->get_params(), '', '&' );
	}


}
