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
 * Base integration API: handles security and logging of API requests from the Jilt App
 *
 * @since 1.1.0
 */
abstract class WC_Jilt_Integration_API_Base {


	/**
	 * Handle an api request. This method is responsible for all request
	 * validation, and sending the JSON response.
	 *
	 * This method exits upon completion.
	 *
	 * @since 1.1.0
	 * @param string $request The API request
	 */
	public function handle_api_request( $request ) {

		$this->log_api_request( $request );

		// plugin is configured?
		if ( ! wc_jilt()->get_integration()->get_secret_key() ) {
			$this->send_api_response_error( 'Not linked', 503 );
		}

		// require requests over SSL?
		if ( ! is_ssl() && apply_filters( 'wc_jilt_client_api_requires_ssl', true, $request ) ) {
			$this->send_api_response_error( 'SSL is required', 101 );
		}

		// has required parameters for request validation?
		if ( empty( $request['hash'] ) ) {
			$this->send_api_response_error( 'Missing request signature', 400 );
		}
		if ( empty( $request['timestamp'] ) ) {
			$this->send_api_response_error( 'Missing request timestamp', 400 );
		}
		if ( empty( $request['resource'] ) ) {
			$this->send_api_response_error( 'Missing request resource', 400 );
		}

		$request_hash = $request['hash'];

		// remove unsigned params
		unset( $request['hash'], $request['wc-api'] );

		// include the method name
		$request['method'] = strtolower( $_SERVER['REQUEST_METHOD'] );

		// build the signed params string
		ksort( $request );
		$request_query = http_build_query( $request, '', '&' );

		// when handling arrayed query params, php uses a format that includes
		// the index like data[0]=hello&data[1]=world but the signature requires
		// those indexes to be stripped, so: data[]=hello&data[]=world
		$request_query = preg_replace( '/%5B\d+%5D=/', '%5B%5D=', $request_query );

		// verify hash
		$hash = hash_hmac( 'sha256', $request_query, wc_jilt()->get_integration()->get_secret_key(), true );
		if ( ! hash_equals( base64_decode( $request_hash ), $hash ) ) {
			$this->send_api_response_error( 'Signature verification failed', 401 );
		}

		// guard against replay attacks by rejecting any signed requests that are older than 5 minutes
		$max_age = apply_filters( 'wc_jilt_client_api_request_max_age', 5 * MINUTE_IN_SECONDS, $request );
		if ( ( time() - $request['timestamp'] ) > $max_age ) {
			$this->send_api_response_error( 'Request timestamp exceeds max age', 422 );
		}

		// valid api action?
		$method = "{$request['method']}_{$request['resource']}";
		if ( ! is_callable( array( $this, $method ) ) ) {
			$this->send_api_response_error( "Don't know how to {$_REQUEST['method']} {$_REQUEST['resource']}", 501 );
		}

		// remove all "internal" request params
		unset( $request['method'], $request['resource'], $request['timestamp'] );

		$result = null;

		try {
			// perform the actual api request
			if ( empty( $request ) ) {
				$result = $this->{$method}();
			} else {
				$result = $this->{$method}( $request );
			}
		} catch ( SV_WC_Plugin_Exception $e ) {
			// request failed
			$this->send_api_response_error( $e->getMessage(), $e->getCode() );
		}

		// success!
		$code = $method == 'post' ? 201 : 200;

		// send response and exit
		$this->send_api_response( $result, $code );
	}


	/**
	 * Send and log an API response. The 'x-jilt-version' header is added.
	 *
	 * This method exits upon completion.
	 *
	 * @since 1.1.0
	 * @param array $body associative array of response data
	 * @param int $code optional response code, defaults to 200
	 */
	protected function send_api_response( $body, $code = 200 ) {

		$this->log_api_response( $body, $code );

		// identify the response as coming from the Jilt for WooCommerce plugin
		@header( 'x-jilt-version: ' . wc_jilt()->get_version() );

		// wp_send_json exits after sending
		wp_send_json( $body, $code );
	}


	/**
	 * Reply to an API request with an error response.
	 *
	 * This method exits upon completion.
	 *
	 * @since 1.1.0
	 * @param string $message the error response
	 * @param int $code the error code
	 */
	protected function send_api_response_error( $message, $code = 400 ) {
		$body = array( 'error' => array( 'message' => $message ) );
		$this->send_api_response( $body, $code );
	}


	/**
	 * Log an API response.
	 *
	 * @since 1.1.0
	 * @param array $body associative array of response data
	 * @param int $code optional response code
	 */
	protected function log_api_response( $body, $code ) {

		$response_data = array(
			'code'    => $code,
			'headers' => array(
				'Content-Type'   => 'application/json; charset=' . get_option( 'blog_charset' ),
				'x-jilt-version' =>  wc_jilt()->get_version(),
			),
			'body' => $body,
		);

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_DEBUG, wc_jilt()->get_api_log_message( $response_data ) );
	}


	/**
	 * Log the API request
	 *
	 * @since 1.1.0
	 * @param array $request Associative array of request data
	 */
	protected function log_api_request( $request ) {

		$request_data = array_merge(
			array(
				'method'     => $_SERVER['REQUEST_METHOD'],
				'uri'        => "{$_SERVER['REQUEST_SCHEME']}://{$_SERVER['HTTP_HOST']}{$_SERVER['REQUEST_URI']}",
				'user-agent' => $_SERVER['HTTP_USER_AGENT'],
				'remote-ip'  => $_SERVER['REMOTE_ADDR'],
			),
			$request
		);

		wc_jilt()->log_with_level( WC_Jilt_Integration::LOG_LEVEL_DEBUG, preg_replace( '/^Request/', 'Incoming Request', wc_jilt()->get_api_log_message( $request_data ) ) );
	}


}
