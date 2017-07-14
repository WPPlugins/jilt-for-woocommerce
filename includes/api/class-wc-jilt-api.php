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
 * Jilt API class
 *
 * @since 1.0.0
 */
class WC_Jilt_API extends SV_WC_API_Base {


	/** Jilt REST API version */
	const API_VERSION = 1;

	/** @var string linked Shop ID */
	protected $shop_id;

	/** @var string Jilt secret API key */
	protected $api_key;


	/**
	 * Constructor - setup API client
	 *
	 * @since 1.0.0
	 * @param string $shop_id linked Shop ID
	 * @param string $api_key Jilt secret API key
	 */
	public function __construct( $shop_id, $api_key ) {

		$this->shop_id = $shop_id;

		// set auth creds
		$this->api_key = $api_key;

		// set up the request/response defaults
		$this->request_uri = $this->get_api_endpoint();
		$this->set_request_content_type_header( 'application/x-www-form-urlencoded' );
		$this->set_request_accept_header( 'application/json' );
		$this->set_request_header( 'Authorization', 'Token ' . $this->api_key );
		$this->set_request_header( 'x-jilt-shop-domain', wc_jilt()->get_shop_domain() );
		$this->set_response_handler( 'WC_Jilt_API_Response' );
	}


	/** API methods ****************************************************/


	/**
	 * Gets the current user public key
	 *
	 * @since 1.0.0
	 * @return string public key for the current API user
	 * @throws SV_WC_API_Exception on API error
	 */
	public function get_public_key() {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'GET', 'path' => '/user' ) ) );

		return $response->public_key;
	}


	/**
	 * Find a shop by domain
	 *
	 * @since 1.0.0
	 *
	 * @param array $args associative array of search parameters. Supports: 'domain'
	 * @return stdClass the shop record returned by the API, or null if none was found
	 * @throws SV_WC_API_Exception on API error
	 */
	public function find_shop( $args ) {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'GET', 'path' => '/shops', 'params' => $args ) ) );

		if ( 0 === count( $response->response_data ) ) {
			return null;
		} else {
			// return the first found shop
			return $response->response_data[0];
		}
	}


	/**
	 * Create a shop
	 *
	 * @since 1.0.0
	 * @param array $args associative array of shop parameters.
	 *        Required: 'profile_type', 'domain'
	 * @return stdClass the shop record returned by the API
	 * @throws SV_WC_API_Exception on API error
	 */
	public function create_shop( $args ) {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'POST', 'path' => '/shops', 'params' => $args ) ) );

		return $response->response_data;
	}


	/**
	 * Update a shop
	 *
	 * @since 1.0.0
	 * @param array $args associative array of shop parameters
	 * @param int $shop_id optional shop ID to update
	 * @return stdClass the shop record returned by the API
	 * @throws SV_WC_API_Exception on API error
	 */
	public function update_shop( $args, $shop_id = null ) {

		$shop_id = is_null( $shop_id ) ? $this->shop_id : $shop_id;

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'PUT', 'path' => "/shops/{$shop_id}", 'params' => $args ) ) );

		return $response->response_data;
	}


	/**
	 * Deletes the shop
	 *
	 * @since 1.1.0
	 * @return stdClass the shop record returned by the API
	 * @throws SV_WC_API_Exception on API error
	 */
	public function delete_shop() {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'DELETE', 'path' => "/shops/{$this->shop_id}" ) ) );

		return $response->response_data;
	}


	/**
	 * Get an order
	 *
	 * @since 1.0.0
	 * @param int $id order ID
	 * @return stdClass the order record returned by the API
	 * @throws SV_WC_API_Exception on API error
	 */
	public function get_order( $id ) {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'GET', 'path' => "/orders/$id" ) ) );

		return $response->response_data;
	}


	/**
	 * Create an order
	 *
	 * @since 1.0.0
	 * @param array $args associative array of order parameters
	 * @return int the order id returned by the API
	 * @throws SV_WC_API_Exception on API error
	 */
	public function create_order( $args ) {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'POST', 'path' => "/shops/{$this->shop_id}/orders", 'params' => $args ) ) );

		return $response->response_data;
	}


	/**
	 * Update an order
	 *
	 * @since 1.0.0
	 * @param int $id order ID.
	 * @param array $args associative array of order parameters
	 * @return int the order id returned by the API
	 * @throws SV_WC_API_Exception on API error
	 */
	public function update_order( $id, $args ) {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'PUT', 'path' => "/orders/$id", 'params' => $args ) ) );

		return $response->response_data;
	}


	/**
	 * Delete an order
	 *
	 * @since 1.0.0
	 * @param int $id order ID.
	 * @throws SV_WC_API_Exception on API error
	 */
	public function delete_order( $id ) {

		$response = $this->perform_request( $this->get_new_request( array( 'method' => 'DELETE', 'path' => "/orders/$id" ) ) );

		return $response->response_data;
	}


	/** Validation methods ****************************************************/


	/**
	 * Check if the response has any status code errors
	 *
	 * @since 1.0.0
	 * @see \SV_WC_API_Base::do_pre_parse_response_validation()
	 * @throws \SV_WC_API_Exception non HTTP 200 status
	 */
	protected function do_pre_parse_response_validation() {

		// nothing to do for HTTP 200 responses
		if ( 200 === $this->get_response_code() ) {
			return;
		}

		switch ( $this->get_response_code() ) {

			// jilt account has been cancelled
			// TODO: this code has not yet been implemented see https://github.com/skyverge/jilt-app/issues/90
			case 410:
				$this->get_plugin()->handle_account_cancellation();
			break;

			default:
				// default message to response code/message (e.g. HTTP Code 422 - Unprocessable Entity)
				$message = sprintf( 'HTTP code %s - %s', $this->get_response_code(), $this->get_response_message() );

				// if there's a more helpful Jilt API error message, use that instead
				if ( $this->get_raw_response_body() ) {
					$response = $this->get_parsed_response( $this->raw_response_body );
					if ( $response->response_data ) {
						$message = $response->error->message;
					}
				}

				throw new SV_WC_API_Exception( $message, $this->get_response_code() );
		}
	}


	/** Helper methods ********************************************************/


	/**
	 * Get the request arguments and override the timeout to 5 seconds
	 *
	 * @since 1.0.0
	 * @see SV_WC_API_Base::get_request_args()
	 * @return array
	 */
	protected function get_request_args() {

		return array_merge( parent::get_request_args(), array( 'timeout' => 5 ) );
	}


	/**
	 * Perform a custom sanitization of the Authorization header, with a partial
	 * masking rather than the full mask of the base API class
	 *
	 * @since 1.0.0
	 * @see SV_WC_API_Base::get_sanitized_request_headers()
	 * @return array of sanitized request headers
	 */
	protected function get_sanitized_request_headers() {

		$sanitized_headers = parent::get_sanitized_request_headers();

		$headers = $this->get_request_headers();

		if ( ! empty( $headers['Authorization'] ) ) {
			list( $_, $credential ) = explode( ' ', $headers['Authorization'] );
			if ( strlen( $credential ) > 7 ) {
				$sanitized_headers['Authorization'] = 'Token ' . substr( $credential, 0, 2 ) . str_repeat( '*', strlen( $credential ) - 7 ) . substr( $credential, -4 );
			} else {
				// invalid key, no masking required
				$sanitized_headers['Authorization'] = $headers['Authorization'];
			}
		}

		return $sanitized_headers;
	}


	/**
	 * Builds and returns a new API request object
	 *
	 * @since 1.0.0
	 * @see \SV_WC_API_Base::get_new_request()
	 * @param array $args Associative array of request arguments. 'method' and
	 *   'path' are required, 'params' is an optional associative array of query
	 *   parameters
	 * @return \WC_Jilt_API_Request API request object
	 */
	protected function get_new_request( $args = array() ) {

		return new WC_Jilt_API_Request(
			$args['method'],
			$args['path'],
			isset( $args['params'] ) ? $args['params'] : array()
		);
	}


	/**
	 * Returns the main plugin class
	 *
	 * @since 1.0.0
	 * @see \SV_WC_API_Base::get_plugin()
	 * @return \WC_Jilt
	 */
	protected function get_plugin() {
		return wc_jilt();
	}


	/**
	 * Get the API endpoint URI
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_api_endpoint() {

		return sprintf( 'https://api.%s/%s', wc_jilt()->get_hostname(), $this->get_api_version() );
	}


	/**
	 * Return a friendly representation of the API version in use
	 *
	 * @since 1.0.0
	 * @return string
	 */
	public function get_api_version() {

		return 'v' . self::API_VERSION;
	}


}
