<?php
/**
 * Runs one tool call.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\MasksAuditArgs;
use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Execution\Confirmable;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Execution\Snapshots;
use Kuduy\Mcp\Execution\Snapshottable;

/**
 * Availability → validation → capability → confirmation (high-risk tools, D45) → snapshot (destructive tools) →
 * execute → redact → audit.
 */
final class Executor {

	/**
	 * Run a tool and build a CallToolResult. Every call is audited.
	 *
	 * @param Tool  $tool Tool.
	 * @param mixed $args Raw `arguments` from the request.
	 * @return array<string, mixed>
	 */
	public static function call( Tool $tool, mixed $args ): array {
		$start                      = hrtime( true );
		$snapshot                   = null;
		[ $result, $status, $code ] = self::run( $tool, $args, $snapshot );
		if ( is_array( $args ) && array_key_exists( Confirmation::ARG, $args ) ) {
			// The token itself is never stored; a reference that cannot be used as a token is.
			$token = $args[ Confirmation::ARG ];
			unset( $args[ Confirmation::ARG ] );
			$args['_confirmation'] = is_string( $token ) ? Confirmation::reference( $token ) : '';
		}
		AuditLog::record( $tool->name(), self::audit_args( $tool, $args ), $status, $code, (int) ( ( hrtime( true ) - $start ) / 1000000 ), $snapshot );
		return $result;
	}

	/**
	 * The call itself.
	 *
	 * @param Tool     $tool     Tool.
	 * @param mixed    $args     Raw arguments.
	 * @param int|null $snapshot Set to the snapshot id when one was taken.
	 * @return array{0:array<string, mixed>, 1:string, 2:?string} Result, audit status, error code.
	 */
	private static function run( Tool $tool, mixed $args, ?int &$snapshot ): array {
		$available = $tool->is_available();
		if ( true !== $available ) {
			$category = false !== stripos( (string) $available, 'not active' ) ? ToolError::PLUGIN_NOT_ACTIVE : ToolError::FEATURE_NOT_SUPPORTED;
			/* translators: %s: why the tool cannot run, e.g. "Elementor is not active". */
			return self::error( sprintf( __( 'This tool is not available: %s', 'kuduy-mcp' ), (string) $available ), 'error', 'unavailable', $category );
		}

		$args   = null === $args ? array() : $args;
		$schema = $tool->input_schema();
		$valid  = rest_validate_value_from_schema( $args, $schema, 'arguments' );
		if ( is_wp_error( $valid ) ) {
			return self::error( $valid->get_error_message(), 'error', 'invalid_arguments', ToolError::INVALID_ARGUMENT );
		}
		$input = (array) rest_sanitize_value_from_schema( $args, $schema, 'arguments' );

		if ( ! self::allowed( $tool, $input ) ) {
			return self::error( __( 'Your WordPress account is not allowed to use this tool.', 'kuduy-mcp' ), 'denied', 'forbidden', ToolError::PERMISSION_DENIED );
		}

		try {
			if ( $tool instanceof Confirmable ) {
				$token = (string) ( $input[ Confirmation::ARG ] ?? '' );
				unset( $input[ Confirmation::ARG ] );
				$preview = Redactor::redact_array( $tool->preview( $input ) );
				$version = (string) ( $preview['version'] ?? '' );
				unset( $preview['version'] );
				if ( '' === $token ) {
					return array(
						self::success(
							array(
								'confirmation_required' => true,
								'preview'               => $preview ? $preview : new \stdClass(),
								'confirm_token'         => Confirmation::issue( $tool->name(), $input, $version ),
								'expires_in'            => Confirmation::TTL,
								'next'                  => 'Nothing was changed. Show the preview to the user and ask. If they agree, call this tool again with the same arguments and this confirm_token.',
							),
							array()
						),
						'ok',
						'confirmation_required',
					);
				}
				if ( ! Confirmation::consume( $token, $tool->name(), $input, $version ) ) {
					return self::error( __( 'This confirmation is not valid: it expired, was already used, was made for other arguments or another account, or the item changed since the preview. Nothing was changed. Call again without confirm_token for a new preview.', 'kuduy-mcp' ), 'error', 'confirmation_invalid', ToolError::CONFLICT );
				}
			}//end if
			if ( $tool instanceof Snapshottable && ( $tool->annotations()['destructiveHint'] ?? false ) ) {
				$snap = $tool->snapshot( $input );
				if ( null !== $snap ) {
					AuditLog::target( $snap['object_type'], $snap['object_id'] );
					$snapshot = Snapshots::store( $tool->name(), $snap, (array) self::audit_args( $tool, $input ) );
				}
			}
			$result = Redactor::redact_array( $tool->execute( $input ) );
		} catch ( ToolError $e ) {
			return self::error( $e->getMessage(), 'error', 'tool_error', $e->category() );
		} catch ( \Throwable $e ) {
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- operator diagnostics; arguments are never logged.
			error_log( sprintf( 'KuDiagnostics: tool %s failed with %s in %s:%d', $tool->name(), get_class( $e ), $e->getFile(), $e->getLine() ) );
			return self::error( __( 'The tool failed unexpectedly. Details are in the site error log.', 'kuduy-mcp' ), 'error', 'exception', ToolError::INTERNAL_ERROR );
		}//end try

		$extra = self::extra_content( $result['_content'] ?? array() );
		unset( $result['_content'] );
		if ( null !== $snapshot ) {
			$result['_snapshot_id'] = $snapshot;
		}
		return array( self::success( $result, $extra ), 'ok', null );
	}

	/**
	 * Successful CallToolResult.
	 *
	 * @param array<string, mixed>                                   $result Structured result.
	 * @param list<array{type:string, data:string, mimeType:string}> $extra  Extra content blocks.
	 * @return array<string, mixed>
	 */
	private static function success( array $result, array $extra ): array {
		return array(
			'content'           => array_merge(
				array(
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( $result, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
					),
				),
				$extra
			),
			'structuredContent' => $result ? $result : new \stdClass(),
			'isError'           => false,
		);
	}

	/**
	 * Arguments as they may be stored (audit row, snapshot input): tools carrying personal data mask it (D12).
	 *
	 * @param Tool  $tool Tool.
	 * @param mixed $args Arguments.
	 */
	private static function audit_args( Tool $tool, mixed $args ): mixed {
		return $tool instanceof MasksAuditArgs && is_array( $args ) ? $tool->audit_args( $args ) : $args;
	}

	/**
	 * Whether the current user has the tool's capability for this input (object checks like [ 'edit_post', 42 ]).
	 *
	 * @param Tool                 $tool  Tool.
	 * @param array<string, mixed> $input Validated input; empty for listing.
	 */
	public static function allowed( Tool $tool, array $input ): bool {
		$cap = $tool->capability( $input );
		$cap = is_array( $cap ) ? array_values( $cap ) : array( $cap );
		return is_string( $cap[0] ?? null ) && current_user_can( ...$cap );
	}

	/**
	 * Extra MCP content blocks a tool returns under `_content` (e.g. an image for Claude to look at). Only image
	 * blocks with base64 data and a raster image MIME type pass; anything else is dropped.
	 *
	 * @param mixed $blocks Blocks from the tool.
	 * @return list<array{type:string, data:string, mimeType:string}>
	 */
	private static function extra_content( mixed $blocks ): array {
		$out = array();
		foreach ( is_array( $blocks ) ? $blocks : array() as $block ) {
			if ( is_array( $block ) && 'image' === ( $block['type'] ?? '' )
				&& in_array( $block['mimeType'] ?? '', array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp' ), true )
				&& is_string( $block['data'] ?? null ) && '' !== $block['data'] && false !== base64_decode( $block['data'], true ) ) { // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- validates image data, nothing is executed.
				$out[] = array(
					'type'     => 'image',
					'data'     => $block['data'],
					'mimeType' => $block['mimeType'],
				);
			}
		}
		return $out;
	}

	/**
	 * Tool error result (the model sees it; not a protocol error). The first text block is the message; the second
	 * names the failure category (decision D52). No structuredContent: it would not fit the tool's output schema.
	 *
	 * @param string $message  Message.
	 * @param string $status   Audit status.
	 * @param string $code     Audit error code.
	 * @param string $category ToolError category.
	 * @return array{0:array<string, mixed>, 1:string, 2:string}
	 */
	private static function error( string $message, string $status, string $code, string $category ): array {
		$error = array(
			'code'      => $category,
			'retryable' => ToolError::retryable( $category ),
		);
		return array(
			array(
				'content' => array(
					array(
						'type' => 'text',
						'text' => $message,
					),
					array(
						'type' => 'text',
						'text' => (string) wp_json_encode( array( 'error' => $error ) ),
					),
				),
				'isError' => true,
				'_meta'   => array( 'kuduy/error' => $error ),
			),
			$status,
			$code,
		);
	}
}
