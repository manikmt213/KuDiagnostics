<?php
/**
 * Tools: kuduy-plugin-upload, kuduy-plugin-delete, kuduy-plugin-inspect.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Update\GitHubUpdater;

/**
 * Plugin administration beyond install/activate/update (phase O, D74; D46, D47, D49).
 *
 * Upload: a .zip the user already put in the media library (attachment id — never a URL), installed with WordPress'
 * own Plugin_Upgrader::install(), which validates the package and refuses an existing folder; the plugin is not
 * activated. Delete: WordPress' delete_plugins(), which refuses active plugins and runs the plugin's uninstall routine
 * before removing its files (core has no "uninstall without deleting"); it cannot be undone. Both run in two steps
 * with a confirmation token (D45) and never touch KuDiagnostics itself. Inspect reads what a plugin brings to the
 * site — header and requirements, plugins it needs and that need it, REST routes, abilities and WP-Cron hooks whose
 * callbacks live in its folder, the registered settings it owns (names only) and its update history from the audit
 * log.
 */
class PluginAdmin implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode upload | delete | inspect.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-plugin-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'upload' => __( 'Install a plugin from a ZIP in the media library', 'kuduy-mcp' ),
			'delete' => __( 'Delete a plugin', 'kuduy-mcp' ),
			default  => __( 'Inspect a plugin', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$two = ' Two steps: the first call changes nothing and returns a preview with a confirm_token; show it to the user and only if they agree call again with the same arguments and the token.';
		return match ( $this->mode ) {
			'upload' => 'Installs a plugin from a .zip file that is already in the media library (attachment_id; upload it in Media first — URLs are not accepted), with WordPress\' own installer, which checks the package. Only new plugins: an existing plugin folder is refused (update it with kuduy-plugin-update). The plugin is not activated (kuduy-plugin-activate). Needs the admin access level and the right to install plugins.' . $two,
			'delete' => 'Deletes an inactive plugin the way the Delete link on the Plugins screen does: WordPress runs the plugin\'s uninstall routine (which may remove its settings and data) and removes its files. This cannot be undone. The preview says whether it has an uninstall routine and which active plugins need it. KuDiagnostics cannot delete itself. Needs the admin access level and the right to delete plugins.' . $two,
			default  => 'Shows what a plugin brings to the site: name, version, author, required WordPress/PHP and plugins (with whether those are active), the plugins that need it, its REST routes, abilities and WP-Cron hooks, the registered settings it owns (names only, no values) and when KuDiagnostics last installed, activated, updated or deactivated it. Reads only.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$props = 'upload' === $this->mode
			? array(
				'attachment_id'   => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				Confirmation::ARG => Confirmation::token_input(),
			)
			: array(
				'file' => array(
					'type'        => 'string',
					'pattern'     => '^[A-Za-z0-9._-]+/[A-Za-z0-9._-]+\.php$',
					'description' => 'Plugin file from kuduy-plugin-list, e.g. "akismet/akismet.php".',
				),
			);
		if ( 'delete' === $this->mode ) {
			$props[ Confirmation::ARG ] = Confirmation::token_input();
		}
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => array( 'upload' === $this->mode ? 'attachment_id' : 'file' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => Confirmation::output_properties() + array(
				'file'    => array( 'type' => 'string' ),
				'name'    => array( 'type' => 'string' ),
				'version' => array( 'type' => 'string' ),
				'deleted' => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'inspect' === $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'delete' === $this->mode,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'inspect' === $this->mode ? 'kuduy:read' : 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return match ( $this->mode ) {
			'upload' => 'install_plugins',
			'delete' => 'delete_plugins',
			default  => 'activate_plugins',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return true;
	}

	/**
	 * A plugin file that is installed.
	 *
	 * @param string $file Plugin file.
	 * @return array<string, string>
	 * @throws ToolError When it is not installed.
	 */
	private static function installed( string $file ): array {
		Packages::load_admin();
		$all = get_plugins();
		if ( ! isset( $all[ $file ] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Plugin not installed: use the file from kuduy-plugin-list, e.g. "akismet/akismet.php".' );
		}
		return array_map( 'strval', (array) $all[ $file ] );
	}

	/**
	 * The ZIP of an upload and the folder it would create.
	 *
	 * @param int $id Attachment id.
	 * @return array{0: string, 1: string} Path, top folder ('' when it cannot be read before installing).
	 * @throws ToolError When the attachment is not a ZIP in the media library.
	 */
	private static function package( int $id ): array {
		$post = get_post( $id );
		$path = $post instanceof \WP_Post && 'attachment' === $post->post_type ? (string) get_attached_file( $id ) : '';
		if ( '' === $path || ! is_file( $path ) || 'zip' !== strtolower( pathinfo( $path, PATHINFO_EXTENSION ) ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Attachment ' . $id . ' is not a .zip file in the media library. Upload the plugin ZIP in Media first, then give its id.' );
		}
		$uploads = wp_normalize_path( (string) wp_get_upload_dir()['basedir'] ) . '/';
		if ( ! str_starts_with( wp_normalize_path( (string) realpath( $path ) ), $uploads ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'The file of attachment ' . $id . ' is not inside the uploads folder.' );
		}
		$names = array();
		if ( class_exists( '\ZipArchive' ) ) {
			$zip = new \ZipArchive();
			if ( true === $zip->open( $path ) ) {
				for ( $i = 0; $i < $zip->numFiles; $i++ ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- ZipArchive's property.
					$names[] = (string) $zip->getNameIndex( $i );
				}
				$zip->close();
			}
		} else {
			// WordPress' own fallback when the zip extension is missing (as unzip_file() does).
			require_once ABSPATH . 'wp-admin/includes/class-pclzip.php';
			$list = ( new \PclZip( $path ) )->listContent();
			foreach ( is_array( $list ) ? $list : array() as $entry ) {
				$names[] = (string) $entry['filename'];
			}
		}
		if ( ! $names ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The ZIP could not be read or is empty. Nothing was installed.' );
		}
		$tops = array();
		foreach ( $names as $name ) {
			$name = str_replace( '\\', '/', $name );
			if ( in_array( '..', explode( '/', $name ), true ) || str_starts_with( $name, '/' ) || str_contains( $name, ':' ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'The ZIP contains a path that leaves its folder (' . $name . '). Nothing was installed.' );
			}
			$tops[ (string) strtok( $name, '/' ) ] = true;
		}
		$folder = 1 === count( $tops ) ? (string) array_key_first( $tops ) : '';
		return array( $path, $folder );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the package or plugin cannot be used.
	 */
	public function preview( array $input ): array {
		Packages::load_admin();
		$own = dirname( GitHubUpdater::basename() );
		if ( 'upload' === $this->mode ) {
			[ $path, $folder ] = self::package( (int) $input['attachment_id'] );
			if ( $own === $folder ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, 'This ZIP is KuDiagnostics itself; update it from Dashboard > Updates instead. Nothing was installed.' );
			}
			if ( '' !== $folder && is_dir( WP_PLUGIN_DIR . '/' . $folder ) ) {
				throw ToolError::of( ToolError::DUPLICATE_RESOURCE, 'A plugin folder "' . $folder . '" already exists. Update it with kuduy-plugin-update, or delete it first. Nothing was installed.' );
			}
			return array(
				'action'  => 'install',
				'zip'     => wp_basename( $path ),
				'size'    => (int) filesize( $path ),
				'folder'  => '' !== $folder ? $folder : 'unknown until unpacked',
				'note'    => 'The plugin is installed but not activated. Only install plugins from sources you trust: plugin code runs with full access once activated.',
				'version' => md5( $path . '|' . (string) filemtime( $path ) . '|' . (string) filesize( $path ) ),
			);
		}
		$file = (string) $input['file'];
		$data = self::installed( $file );
		if ( GitHubUpdater::basename() === $file ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'KuDiagnostics cannot delete itself through MCP. Use the Plugins screen.' );
		}
		if ( is_plugin_active( $file ) || is_plugin_active_for_network( $file ) ) {
			throw ToolError::of( ToolError::CONFLICT, $data['Name'] . ' is active. Deactivate it first (kuduy-plugin-deactivate), then delete it. Nothing was deleted.' );
		}
		return array(
			'action'         => 'delete',
			'file'           => $file,
			'name'           => $data['Name'],
			'plugin_version' => $data['Version'],
			'uninstall'      => is_uninstallable_plugin( $file ) ? 'This plugin has an uninstall routine: WordPress runs it first, which may remove its settings and data from the database.' : 'This plugin has no uninstall routine: only its files are removed; settings it stored stay in the database.',
			'needed_by'      => self::needed_by( $file ),
			'undo'           => 'Not possible. To get it back, install it again (its data may be gone).',
			'version'        => md5( $file . '|' . $data['Version'] . '|' . (string) filemtime( WP_PLUGIN_DIR . '/' . $file ) ),
		);
	}

	/**
	 * Active plugins whose Requires Plugins names this plugin's folder.
	 *
	 * @param string $file Plugin file.
	 * @return list<string>
	 */
	private static function needed_by( string $file ): array {
		$slug = dirname( $file );
		$out  = array();
		foreach ( get_plugins() as $other => $data ) {
			$needs = array_map( 'trim', explode( ',', (string) ( $data['RequiresPlugins'] ?? '' ) ) );
			if ( in_array( $slug, $needs, true ) ) {
				$out[] = (string) ( $data['Name'] ?? $other ) . ( is_plugin_active( (string) $other ) ? ' (active)' : '' );
			}
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WordPress refuses.
	 */
	public function execute( array $input ): array {
		if ( 'inspect' === $this->mode ) {
			return self::inspect( (string) $input['file'] );
		}
		$this->preview( $input );
		if ( 'upload' === $this->mode ) {
			[ $path ] = self::package( (int) $input['attachment_id'] );
			$upgrader = null;
			Packages::run(
				static function ( \Automatic_Upgrader_Skin $skin ) use ( $path, &$upgrader ) {
					$upgrader = new \Plugin_Upgrader( $skin );
					return $upgrader->install( $path );
				}
			);
			wp_clean_plugins_cache();
			$file = $upgrader instanceof \Plugin_Upgrader ? (string) $upgrader->plugin_info() : '';
			if ( '' === $file ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'The ZIP was unpacked but WordPress found no plugin in it.' );
			}
			AuditLog::target( 'plugin', $file );
			$data = get_plugins()[ $file ] ?? array();
			return array(
				'file'    => $file,
				'name'    => (string) ( $data['Name'] ?? $file ),
				'version' => (string) ( $data['Version'] ?? '' ),
				'active'  => false,
			);
		}//end if
		$file = (string) $input['file'];
		$data = self::installed( $file );
		AuditLog::target( 'plugin', $file );
		Packages::require_direct_filesystem();
		$result = delete_plugins( array( $file ) );
		wp_clean_plugins_cache();
		if ( true !== $result ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not delete the plugin' . ( is_wp_error( $result ) ? ': ' . wp_strip_all_tags( $result->get_error_message() ) : '.' ) );
		}
		return array(
			'file'    => $file,
			'name'    => $data['Name'],
			'deleted' => true,
		);
	}

	/**
	 * What a plugin brings to the site.
	 *
	 * @param string $file Plugin file.
	 * @return array<string, mixed>
	 */
	private static function inspect( string $file ): array {
		$data   = self::installed( $file );
		$folder = dirname( $file );
		$mine   = static fn( mixed $callback ): bool => Diagnostics::describe( $callback )['source'] === 'plugin: ' . $folder; // phpcs:ignore WordPress.PHP.YodaConditions.NotYoda -- both sides are expressions.
		AuditLog::target( 'plugin', $file );

		$requires = array();
		foreach ( array_filter( array_map( 'trim', explode( ',', $data['RequiresPlugins'] ?? '' ) ) ) as $slug ) {
			$found = '';
			foreach ( array_keys( get_plugins() ) as $candidate ) {
				if ( str_starts_with( (string) $candidate, $slug . '/' ) ) {
					$found = (string) $candidate;
				}
			}
			$requires[] = array(
				'slug'   => $slug,
				'status' => '' === $found ? 'not installed' : ( is_plugin_active( $found ) ? 'active' : 'inactive' ),
			);
		}

		$routes = array();
		foreach ( rest_get_server()->get_routes() as $route => $handlers ) {
			foreach ( (array) $handlers as $handler ) {
				if ( is_array( $handler ) && isset( $handler['callback'] ) && $mine( $handler['callback'] ) ) {
					$routes[ (string) $route ] = true;
					break;
				}
			}
		}
		$abilities = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( str_starts_with( $ability->get_name(), $folder . '/' ) ) {
					$abilities[] = $ability->get_name();
				}
			}
		}
		$cron = array();
		foreach ( (array) _get_cron_array() as $events ) {
			foreach ( (array) $events as $hook => $instances ) {
				foreach ( (array) ( $GLOBALS['wp_filter'][ $hook ]->callbacks ?? array() ) as $callbacks ) {
					foreach ( (array) $callbacks as $entry ) {
						if ( $mine( $entry['function'] ) ) {
							$cron[ (string) $hook ] = true;
						}
					}
				}
			}
		}
		$prefix   = strtolower( str_replace( '-', '_', $folder ) );
		$settings = array();
		foreach ( array_keys( get_registered_settings() ) as $option ) {
			if ( str_starts_with( strtolower( (string) $option ), $prefix ) || str_starts_with( strtolower( (string) $option ), strtolower( $folder ) ) ) {
				$settings[] = (string) $option;
			}
		}

		global $wpdb;
		$history = $wpdb->get_results( $wpdb->prepare( 'SELECT ts, tool, result FROM %i WHERE object_type = %s AND object_id = %s ORDER BY id DESC LIMIT 20', $wpdb->prefix . 'kuduy_audit_log', 'plugin', $file ), ARRAY_A ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- the plugin's own audit table.

		return array(
			'file'         => $file,
			'name'         => $data['Name'],
			'version'      => $data['Version'],
			'author'       => wp_strip_all_tags( $data['Author'] ?? '' ),
			'active'       => is_plugin_active( $file ),
			'requires_wp'  => $data['RequiresWP'] ?? '',
			'requires_php' => $data['RequiresPHP'] ?? '',
			'requires'     => $requires,
			'needed_by'    => self::needed_by( $file ),
			'uninstall'    => is_uninstallable_plugin( $file ),
			'rest_routes'  => array_keys( $routes ),
			'abilities'    => $abilities,
			'cron_hooks'   => array_keys( $cron ),
			'settings'     => $settings,
			'history'      => is_array( $history ) ? $history : array(),
		);
	}
}
