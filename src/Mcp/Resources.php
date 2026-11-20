<?php
/**
 * MCP resources (phase N, D72): read-only views of the site, each backed by a read tool.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\OAuth\Scopes;
use Kuduy\Mcp\OAuth\TokenAuth;

/**
 * Five resources under the kuduy:// scheme (RFC 3986). Each is the JSON result of an existing read tool, run through
 * the Executor, so the tool's scope, capability, redaction and audit row apply exactly as for tools/call. A resource is
 * listed only when its tool is available and allowed for this connection and user; anything else reads as not found.
 */
final class Resources {

	/**
	 * URI => [tool, name, title, description].
	 */
	private const MAP = array(
		'kuduy://site/overview'      => array( 'kuduy-site-info', 'site-overview', 'Site overview', 'WordPress and PHP versions, active theme and plugins, the connected user and what KuDiagnostics can do here.' ),
		'kuduy://site/integrations'  => array( 'kuduy-integrations', 'integrations', 'Integrations', 'Builders, themes, plugins and add-ons KuDiagnostics knows, whether each is installed and active here, and the tools for it.' ),
		'kuduy://site/content-model' => array( 'kuduy-content-model', 'content-model', 'Content model', 'Post types, taxonomies and custom fields across the field plugins on this site.' ),
		'kuduy://memory/context'     => array( 'kuduy-memory-context', 'memory-context', 'Memory for this session', 'Current memories about this site (yours and shared), with warnings where an integration they mention is no longer active.' ),
		'kuduy://design/tokens'      => array( 'kuduy-design-scan', 'design-tokens', 'Design tokens', 'Colors, fonts, font sizes, spacing and widths from the theme, global styles, Elementor Kit and Customizer, each with its source.' ),
	);

	/**
	 * The tool behind a URI when this connection and user may use it, else null.
	 *
	 * @param string $uri Resource URI.
	 */
	private static function tool( string $uri ): ?Tool {
		$name = self::MAP[ $uri ][0] ?? null;
		$tool = null === $name ? null : ToolRegistry::create()->get( $name );
		if ( null === $tool || true !== $tool->is_available() ) {
			return null;
		}
		$granted = TokenAuth::scopes();
		if ( null !== $granted && ! Scopes::allows( $granted, $tool->scope() ) ) {
			return null;
		}
		return Executor::allowed( $tool, array() ) ? $tool : null;
	}

	/**
	 * Resources this connection and user may read.
	 *
	 * @return list<array<string, string>>
	 */
	public static function list(): array {
		$out = array();
		foreach ( self::MAP as $uri => [ , $name, $title, $description ] ) {
			if ( null !== self::tool( $uri ) ) {
				$out[] = array(
					'uri'         => $uri,
					'name'        => $name,
					'title'       => $title,
					'description' => $description,
					'mimeType'    => 'application/json',
				);
			}
		}
		return $out;
	}

	/**
	 * Read one resource.
	 *
	 * @param string $uri Resource URI.
	 * @return array{contents: list<array<string, string>>}|null Null when unknown or not allowed.
	 * @throws ToolError When the tool behind it fails (its message, as the tool would report it).
	 */
	public static function read( string $uri ): ?array {
		$tool = self::tool( $uri );
		if ( null === $tool ) {
			return null;
		}
		$result = Executor::call( $tool, array() );
		if ( ! empty( $result['isError'] ) ) {
			throw new ToolError( (string) ( $result['content'][0]['text'] ?? 'The resource could not be read.' ) );
		}
		return array(
			'contents' => array(
				array(
					'uri'      => $uri,
					'mimeType' => 'application/json',
					'text'     => (string) wp_json_encode( $result['structuredContent'] ?? new \stdClass(), JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				),
			),
		);
	}
}
