<?php
/**
 * Calls an SEO plugin's own REST routes inside the current request.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo\Redirects;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Internal REST dispatch (rest_do_request): the route's own permission callback and handler run as the current user,
 * exactly as when the plugin's screen calls them.
 */
final class RestRedirects {

	/**
	 * Dispatch a route and return its data.
	 *
	 * @param string               $method HTTP method.
	 * @param string               $route  Route, e.g. /seopress/v1/redirections.
	 * @param array<string, mixed> $body   JSON body (POST, PUT) or query (GET).
	 * @param string               $plugin Plugin name for messages.
	 * @return array<string, mixed>
	 * @throws ToolError When the route refuses or fails.
	 */
	public static function call( string $method, string $route, array $body, string $plugin ): array {
		$request = new \WP_REST_Request( $method, $route );
		if ( 'GET' === $method ) {
			$request->set_query_params( $body );
		} else {
			$request->set_header( 'Content-Type', 'application/json' );
			$request->set_body( (string) wp_json_encode( $body ) );
		}
		$response = rest_do_request( $request );
		$data     = $response->get_data();
		if ( $response->is_error() || $response->get_status() >= 400 ) {
			$error   = $response->as_error();
			$message = is_wp_error( $error ) ? $error->get_error_message() : '';
			if ( '' === $message && is_array( $data ) ) {
				$message = (string) ( $data['message'] ?? ( isset( $data['failed'] ) ? 'already used: ' . implode( ', ', (array) $data['failed'] ) : '' ) );
			}
			$category = in_array( $response->get_status(), array( 401, 403 ), true ) ? ToolError::PERMISSION_DENIED : ( 404 === $response->get_status() ? ToolError::RESOURCE_NOT_FOUND : ToolError::VALIDATION_FAILED );
			throw ToolError::of( $category, $plugin . ' refused the request (HTTP ' . $response->get_status() . ( '' !== $message ? ': ' . $message : '' ) . '). Nothing was changed.' );
		}
		return is_array( $data ) ? $data : array();
	}
}
