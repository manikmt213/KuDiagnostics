<?php
/**
 * Tools: kuduy-dev-file-move, kuduy-dev-file-delete (decision D4, D79).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Dev;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Packages;

/**
 * Moving (renaming) and deleting a file, with the same gate as kuduy-dev-file-write (phase O, D79): only when
 * KUDUY_MCP_ALLOW_CODE_EXEC is on, kuduy:code scope, edit_plugins and manage_options; both paths must pass
 * DevPaths::writable() (plugin and theme folders and the sandbox, never KuDiagnostics itself, must-use plugins, core
 * or secret files); a file of an active plugin or theme is confirm-first; files over 1 MB are refused so the
 * snapshot always holds the whole file. Undo moves the file back or writes the deleted file again.
 */
final class DevFileOps implements Tool, Snapshottable {

	private const MAX_FILE = 1048576;

	/**
	 * Mode.
	 *
	 * @param string $mode move | delete.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-dev-file-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'move' === $this->mode ? __( 'Move or rename a file', 'kuduy-mcp' ) : __( 'Delete a file', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'move' === $this->mode
			? 'Moves or renames a text file inside the same folders kuduy-dev-file-write may change (plugin folders, theme folders, wp-content/kuduy-sandbox; root "content", e.g. path "plugins/my-plugin/old.php", to "plugins/my-plugin/new.php"). The target must not exist. Files of an active plugin or theme are reported first (done: false): ask the user, then call again with confirm: true. Undo with kuduy-snapshot-restore moves it back.'
			: 'Deletes a text file (up to 1 MB) in a plugin folder, a theme folder or wp-content/kuduy-sandbox (root "content"). Files of an active plugin or theme are reported first (done: false): ask the user, then call again with confirm: true. The whole file is saved first; undo with kuduy-snapshot-restore writes it again.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$path       = array(
			'type'      => 'string',
			'minLength' => 1,
			'maxLength' => 1000,
		);
		$properties = array(
			'root'    => DevPaths::root_input(),
			'path'    => $path,
			'confirm' => Packages::confirm_input(),
		);
		if ( 'move' === $this->mode ) {
			$properties['to'] = $path;
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'move' === $this->mode ? array( 'path', 'to' ) : array( 'path' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'path'         => array( 'type' => 'string' ),
				'to'           => array( 'type' => 'string' ),
				'done'         => array( 'type' => 'boolean' ),
				'impact'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'path', 'done', 'impact' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:code';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'edit_plugins';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return DevCode::enabled() ? true : 'Code tools are off. A site administrator can turn them on with define( \'KUDUY_MCP_ALLOW_CODE_EXEC\', true ); in wp-config.php';
	}

	/**
	 * Checked source and target paths, and what they put at risk.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: string, 1: string|null, 2: list<string>}
	 * @throws ToolError When a path is refused.
	 */
	private function paths( array $input ): array {
		if ( ! current_user_can( 'manage_options' ) ) {
			throw new ToolError( 'Code tools need a site administrator (manage_options).' );
		}
		$root = (string) ( $input['root'] ?? 'content' );
		$from = DevPaths::writable( $root, (string) $input['path'] );
		if ( ! is_file( $from ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'There is no file "' . DevPaths::relative( $from ) . '".' );
		}
		if ( (int) filesize( $from ) > self::MAX_FILE ) {
			throw new ToolError( 'The file is larger than 1 MB; KuDiagnostics moves or deletes only files it can save whole for undo.' );
		}
		$to = null;
		if ( 'move' === $this->mode ) {
			$to = DevPaths::writable( $root, (string) $input['to'] );
			if ( file_exists( $to ) ) {
				throw ToolError::of( ToolError::CONFLICT, 'The target "' . DevPaths::relative( $to ) . '" already exists; nothing was moved.' );
			}
			// A file that becomes .php must parse, as kuduy-dev-file-write requires.
			if ( 'php' === strtolower( pathinfo( $to, PATHINFO_EXTENSION ) ) && 'php' !== strtolower( pathinfo( $from, PATHINFO_EXTENSION ) ) ) {
				try {
					token_get_all( (string) file_get_contents( $from ), TOKEN_PARSE ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local file, size-capped.
				} catch ( \ParseError $e ) {
					throw new ToolError( 'The file would become PHP but does not parse (line ' . (int) $e->getLine() . '). Nothing was moved.' );
				}
			}
		}
		$impact = array();
		foreach ( array_filter( array( $from, $to ) ) as $path ) {
			$owner = DevPaths::active_owner( $path );
			if ( null !== $owner ) {
				[ $kind, $dir ] = explode( ':', $owner, 2 );
				$impact[]       = 'This changes a file of the active ' . $kind . ' "' . $dir . '": the ' . $kind . ' may stop working or break the site if it loads this file.';
			}
		}
		return array( $from, $to, array_values( array_unique( $impact ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a path is refused.
	 */
	public function snapshot( array $input ): ?array {
		[ $from, $to, $impact ] = $this->paths( $input );
		if ( $impact && empty( $input['confirm'] ) ) {
			return null;
		}
		return array(
			'object_type' => 'file',
			'object_id'   => DevPaths::relative( $from ),
			'payload'     => 'move' === $this->mode
				? array(
					'moved' => true,
					'from'  => $from,
					'to'    => (string) $to,
				)
				: array(
					'path'    => $from,
					'existed' => true,
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- binary-safe snapshot of the file before it is deleted (local, size-capped).
					'content' => base64_encode( (string) file_get_contents( $from ) ),
				),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the file cannot be put back.
	 */
	public function restore( array $payload ): void {
		$inside = static fn( string $p ): bool => '' !== $p && DevPaths::inside( $p, DevPaths::roots()['content'] );
		if ( empty( $payload['moved'] ) ) {
			$path = (string) ( $payload['path'] ?? '' );
			if ( ! $inside( $path ) ) {
				throw new ToolError( 'The saved path is outside wp-content.' );
			}
			if ( ! is_dir( dirname( $path ) ) && ! wp_mkdir_p( dirname( $path ) ) ) {
				throw new ToolError( 'The folder could not be created again.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents, WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- writing back the file KuDiagnostics deleted.
			if ( false === file_put_contents( $path, (string) base64_decode( (string) $payload['content'], true ) ) ) {
				throw new ToolError( 'The deleted file could not be written back.' );
			}
			return;
		}
		$from = (string) ( $payload['from'] ?? '' );
		$to   = (string) ( $payload['to'] ?? '' );
		if ( ! $inside( $from ) || ! $inside( $to ) ) {
			throw new ToolError( 'The saved paths are outside wp-content.' );
		}
		if ( file_exists( $from ) || ! is_file( $to ) ) {
			throw ToolError::of( ToolError::CONFLICT, 'The file cannot be moved back: the old name is taken again or the moved file is gone.' );
		}
		if ( ! is_dir( dirname( $from ) ) && ! wp_mkdir_p( dirname( $from ) ) ) {
			throw new ToolError( 'The folder could not be created again.' );
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- moving back a file KuDiagnostics moved (D79).
		if ( ! rename( $to, $from ) ) {
			throw new ToolError( 'The file could not be moved back.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When a path is refused or the file system fails.
	 */
	public function execute( array $input ): array {
		[ $from, $to, $impact ] = $this->paths( $input );
		AuditLog::target( 'file', DevPaths::relative( $from ) );
		$out = array(
			'path'   => DevPaths::relative( $from ),
			'done'   => false,
			'impact' => $impact,
		);
		if ( null !== $to ) {
			$out['to'] = DevPaths::relative( $to );
		}
		if ( $impact && empty( $input['confirm'] ) ) {
			return $out;
		}
		if ( null !== $to ) {
			if ( ! is_dir( dirname( $to ) ) && ! wp_mkdir_p( dirname( $to ) ) ) {
				throw new ToolError( 'The target folder could not be created.' );
			}
			// phpcs:ignore WordPress.WP.AlternativeFunctions.rename_rename -- approved folder, D4/D79.
			if ( ! rename( $from, $to ) ) {
				throw new ToolError( 'WordPress could not move the file (check folder permissions).' );
			}
		} else {
			wp_delete_file( $from );
			if ( file_exists( $from ) ) {
				throw new ToolError( 'WordPress could not delete the file (check folder permissions).' );
			}
		}
		foreach ( array_filter( array( $from, $to ) ) as $path ) {
			if ( function_exists( 'opcache_invalidate' ) ) {
				opcache_invalidate( $path, true );
			}
		}
		$out['done'] = true;
		return $out;
	}
}
