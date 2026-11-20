<?php
/**
 * Tools: kuduy-wpcode-snippets, -snippet-get, -snippet-save, -snippet-php-save, -snippet-deactivate,
 * -headers-footers, -headers-footers-save.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Code;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Audit\Redactor;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Dev\DevText;

/**
 * WPCode (phase R, D62; source of WPCode Lite 2.4.2, `insert-headers-and-footers`). WPCode registers abilities under
 * "wpcode/…" and marks them public for MCP. Reads (list-snippets, detect-snippet-errors) are always registered; the
 * write abilities (create-snippet, update-snippet, set-snippet-status, get/update-headers-footers) only when the site
 * owner switches on WPCode's "AI & Automation Access" setting, which KuDiagnostics never changes. Writes run through
 * those abilities as the connected user, so WPCode's own checks apply: capabilities (wpcode_edit_snippets,
 * wpcode_activate_snippets, the per-type PHP capability), locations valid for the code type, kses for users without
 * unfiltered_html, its testing mode, and a test run of PHP and universal snippets when they are switched on.
 *
 * Reading one snippet uses WPCode's own WPCode_Snippet class (get-snippet is a write-gated ability), and the
 * site-wide header, body and footer code is read as WPCode's ability reads it (options ihaf_insert_*, stored
 * slashed). Credentials in code are masked (rule 3). PHP and universal snippets run on the server, so creating,
 * changing or switching them on is a separate tool that exists only under D4. WPCode Library installs are not
 * offered: they fetch code from WPCode's cloud.
 */
class WpCode implements Tool, Snapshottable {

	/**
	 * Code types WPCode 2.4.2 knows. PHP and universal (PHP mixed with HTML) run on the server.
	 */
	public const TYPES = array( 'php', 'universal', 'html', 'css', 'js', 'text', 'blocks', 'scss' );

	/**
	 * Types that run on the server.
	 */
	private const SERVER = array( 'php', 'universal' );

	/**
	 * Types saved with kuduy-wpcode-snippet-save (scss is compiled in WPCode's editor, WPCode refuses it here).
	 */
	private const CLIENT = array( 'html', 'css', 'js', 'text', 'blocks' );

	/**
	 * Fields create-snippet / update-snippet take, besides code_type.
	 */
	private const FIELDS = array( 'title', 'code', 'auto_insert', 'location', 'insert_number', 'location_extra', 'priority', 'tags', 'note', 'active' );

	/**
	 * Sections of the site-wide code.
	 */
	private const SECTIONS = array( 'header', 'body', 'footer' );

	/**
	 * Mode.
	 *
	 * @param string $mode snippets | snippet-get | snippet-save | snippet-php-save | snippet-deactivate | headers-footers | headers-footers-save.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-wpcode-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'snippets'             => __( 'List WPCode snippets', 'kuduy-mcp' ),
			'snippet-get'          => __( 'Read a WPCode snippet', 'kuduy-mcp' ),
			'snippet-save'         => __( 'Save a WPCode snippet (HTML, CSS, JS, text)', 'kuduy-mcp' ),
			'snippet-php-save'     => __( 'Save a WPCode PHP snippet', 'kuduy-mcp' ),
			'snippet-deactivate'   => __( 'Switch a WPCode snippet off', 'kuduy-mcp' ),
			'headers-footers'      => __( 'Read WPCode header and footer code', 'kuduy-mcp' ),
			default                => __( 'Change WPCode header and footer code', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$two    = ' Two steps: the first call changes nothing and returns a preview (what changes, a syntax check, risky calls found) with a confirm_token; show it to the user and only if they agree call again with the same arguments and the token. A clean preview does not make code safe.';
		$switch = ' Needs WPCode\'s "AI & Automation Access" setting (WPCode > Settings), which only the site owner switches on.';
		return match ( $this->mode ) {
			'snippets'             => 'Lists WPCode snippets through WPCode\'s own list-snippets ability: id, title, code type (php, universal, html, css, js, text, blocks, scss), location, active, tags, whether WPCode flagged an error, last change. Filter by status (all, active, inactive), code_type or tag. Code is not included (kuduy-wpcode-snippet-get).',
			'snippet-get'          => 'Returns one WPCode snippet: title, code, code type, active, auto insert and location, priority, tags, note, WPCode\'s last recorded error, plus a syntax check (PHP: PHP\'s own parser, the code is not run) and the risky calls it contains. Values that look like credentials are shown as [redacted]: keep the real value out of new code or ask the user to enter it in WPCode.',
			'snippet-save'         => 'Creates (no id) or changes (id) an HTML, CSS, JavaScript, text or blocks snippet through WPCode\'s own create-snippet / update-snippet abilities: title, code, code_type, active, auto_insert, location (must suit the code type, e.g. site_wide_header, site_wide_footer, before_content), insert_number, location_extra, priority, tags, note. Fields not given stay. WPCode checks the location and its permissions. PHP and universal snippets are refused here (kuduy-wpcode-snippet-php-save). Needs the admin access level; undo with kuduy-snapshot-restore.' . $switch . $two,
			'snippet-php-save'     => 'Creates (no id), changes or switches on (id) a PHP or universal snippet of WPCode through its own abilities (same fields as kuduy-wpcode-snippet-save, code without the opening <?php). PHP runs on the server with full access: WPCode runs an active snippet once when it is switched on, to test it, and refuses it if it fails. Code with a syntax error is not saved active. Only exists when the site allows code execution (KUDUY_MCP_ALLOW_CODE_EXEC) and needs the code access level; use on development or staging sites. Undo with kuduy-snapshot-restore.' . $switch . $two,
			'snippet-deactivate'   => 'Switches a WPCode snippet off (any type) through WPCode\'s set-snippet-status ability; its code stays. Undo with kuduy-snapshot-restore switches it on again.' . $switch,
			'headers-footers'      => 'Returns WPCode\'s site-wide header, body and footer code (scripts added to every page: analytics, pixels, verification tags), with credentials masked, and whether the theme supports the body section. Needs the admin access level and the right to post unfiltered HTML.',
			default                => 'Adds to (mode append, the default) or replaces (mode replace) WPCode\'s site-wide header, body and/or footer code through WPCode\'s update-headers-footers ability. This code runs on every page for every visitor. Needs the admin access level and the right to post unfiltered HTML; undo with kuduy-snapshot-restore puts the previous code back.' . $switch . $two,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		if ( in_array( $this->mode, array( 'snippet-save', 'snippet-php-save' ), true ) ) {
			$properties = array(
				'id'              => $id,
				'title'           => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'code'            => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
				'code_type'       => array(
					'type' => 'string',
					'enum' => 'snippet-php-save' === $this->mode ? self::SERVER : self::CLIENT,
				),
				'active'          => array( 'type' => 'boolean' ),
				'auto_insert'     => array( 'type' => 'boolean' ),
				'location'        => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
				'insert_number'   => array(
					'type'    => 'integer',
					'minimum' => 0,
				),
				'location_extra'  => array(
					'type'      => 'string',
					'maxLength' => 500,
				),
				'priority'        => array(
					'type'    => 'integer',
					'minimum' => -1000,
					'maximum' => 1000,
				),
				'tags'            => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 50,
					),
				),
				'note'            => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				Confirmation::ARG => Confirmation::token_input(),
			);
			return array(
				'type'                 => 'object',
				'properties'           => $properties,
				'additionalProperties' => false,
			);
		}//end if
		$properties = match ( $this->mode ) {
			'snippets'             => array(
				'status'    => array(
					'type' => 'string',
					'enum' => array( 'all', 'active', 'inactive' ),
				),
				'code_type' => array(
					'type' => 'string',
					'enum' => self::TYPES,
				),
				'tag'       => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'headers-footers'      => array(),
			'headers-footers-save' => array(
				'header'          => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'body'            => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'footer'          => array(
					'type'      => 'string',
					'maxLength' => 100000,
				),
				'mode'            => array(
					'type' => 'string',
					'enum' => array( 'append', 'replace' ),
				),
				Confirmation::ARG => Confirmation::token_input(),
			),
			default                => array( 'id' => $id ),
		};//end match
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( in_array( $this->mode, array( 'snippet-get', 'snippet-deactivate' ), true ) ) {
			$schema['required'] = array( 'id' );
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => Confirmation::output_properties() + array(
				'snippets'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'id'             => array( 'type' => 'integer' ),
				'code_type'      => array( 'type' => 'string' ),
				'active'         => array( 'type' => 'boolean' ),
				'code'           => array( 'type' => 'string' ),
				'check'          => array( 'type' => 'object' ),
				'header'         => array( 'type' => 'string' ),
				'body'           => array( 'type' => 'string' ),
				'footer'         => array( 'type' => 'string' ),
				'body_supported' => array( 'type' => 'boolean' ),
				'_snapshot_id'   => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'snippets', 'snippet-get', 'headers-footers' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'snippet-deactivate' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'snippets', 'snippet-get' => 'kuduy:read',
			'snippet-php-save'        => 'kuduy:code',
			default                   => 'kuduy:admin',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return match ( $this->mode ) {
			'headers-footers', 'headers-footers-save' => 'unfiltered_html',
			'snippet-deactivate'                      => 'wpcode_activate_snippets',
			default                                   => 'wpcode_edit_snippets',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! defined( 'WPCODE_VERSION' ) || ! class_exists( 'WPCode_Snippet' ) ) {
			return 'WPCode is not active on this site.';
		}
		if ( 'snippet-php-save' === $this->mode && ! ( defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) ) ) {
			return 'PHP snippets run code on the server; this site does not allow code execution (KUDUY_MCP_ALLOW_CODE_EXEC).';
		}
		return true;
	}

	/**
	 * A registered WPCode ability.
	 *
	 * @param string $name Ability name.
	 * @throws ToolError When WPCode has not registered it (its write setting is off, or an older WPCode).
	 */
	private static function ability( string $name ): \WP_Ability {
		$ability = function_exists( 'wp_get_ability' ) ? wp_get_ability( $name ) : null;
		if ( ! $ability instanceof \WP_Ability ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'WPCode has not registered ' . $name . ' on this site. Its write abilities exist only with "AI & Automation Access" switched on in WPCode > Settings (WPCode 2.4 or later); KuDiagnostics does not change that setting. Nothing was changed.' );
		}
		return $ability;
	}

	/**
	 * Run a WPCode ability as the connected user.
	 *
	 * @param string               $name  Ability name.
	 * @param array<string, mixed> $input Ability input.
	 * @return mixed
	 * @throws ToolError When WPCode refuses.
	 */
	private static function run( string $name, array $input ): mixed {
		$result = self::ability( $name )->execute( $input );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WPCode refused: ' . wp_strip_all_tags( $result->get_error_message() ) );
		}
		return $result;
	}

	/**
	 * A WPCode snippet that exists.
	 *
	 * @param int $id Snippet id.
	 * @throws ToolError When there is none or the user may not edit it.
	 */
	private static function snippet( int $id ): \WPCode_Snippet {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || 'wpcode' !== $post->post_type || 'trash' === $post->post_status ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No WPCode snippet with id ' . $id . ' (see kuduy-wpcode-snippets).' );
		}
		if ( ! current_user_can( 'edit_post', $id ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'You may not edit WPCode snippet ' . $id . '.' );
		}
		return new \WPCode_Snippet( $post );
	}

	/**
	 * Code type of a snippet, as WPCode stores it.
	 *
	 * @param \WPCode_Snippet $snippet Snippet.
	 */
	private static function type( \WPCode_Snippet $snippet ): string {
		return (string) $snippet->get_code_type();
	}

	/**
	 * One snippet with its code (masked) and a check.
	 *
	 * @param \WPCode_Snippet $snippet Snippet.
	 * @return array<string, mixed>
	 */
	private static function detail( \WPCode_Snippet $snippet ): array {
		$type  = self::type( $snippet );
		$code  = (string) $snippet->get_code();
		$error = $snippet->get_last_error();
		$out   = array(
			'id'             => (int) $snippet->get_id(),
			'title'          => (string) $snippet->get_title(),
			'code_type'      => $type,
			'active'         => (bool) $snippet->is_active(),
			'auto_insert'    => 1 === (int) $snippet->get_auto_insert(),
			'location'       => (string) $snippet->get_location(),
			'insert_number'  => (int) $snippet->get_auto_insert_number(),
			'location_extra' => (string) $snippet->get_location_extra(),
			'priority'       => (int) $snippet->get_priority(),
			'tags'           => array_values( array_map( 'strval', (array) $snippet->get_tags() ) ),
			'note'           => wp_strip_all_tags( (string) $snippet->get_note() ),
			// Hard-coded credentials in the code are masked (rule 3); the check still reads the stored code.
			'code'           => DevText::mask( $code ),
			'check'          => CodeSnippets::check( $code, self::check_type( $type ) ),
		);
		if ( is_array( $error ) && ! empty( $error['message'] ) ) {
			$out['last_error'] = array(
				'message' => wp_strip_all_tags( (string) $error['message'] ),
				'line'    => (int) ( $error['error_line'] ?? 0 ),
			);
		}
		return $out;
	}

	/**
	 * The check kind for a WPCode type (universal is PHP mixed with HTML: checked as PHP after a closing tag).
	 *
	 * @param string $type WPCode code type.
	 */
	private static function check_type( string $type ): string {
		return match ( $type ) {
			'php', 'universal' => 'php',
			'js'               => 'js',
			'css', 'scss'      => 'css',
			default            => 'html',
		};
	}

	/**
	 * What a snippet save would do (the preview of WpCodeSave, which adds the confirmation step).
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the snippet is unknown, of the wrong kind, or the code does not parse.
	 */
	public function preview( array $input ): array {
		if ( 'headers-footers-save' === $this->mode ) {
			return $this->preview_sections( $input );
		}
		$old  = isset( $input['id'] ) ? self::snippet( (int) $input['id'] ) : null;
		$type = (string) ( $input['code_type'] ?? ( null === $old ? '' : self::type( $old ) ) );
		if ( '' === $type ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new snippet needs a code_type.' );
		}
		$server   = in_array( $type, self::SERVER, true );
		$php_tool = 'snippet-php-save' === $this->mode;
		if ( $php_tool !== $server ) {
			throw ToolError::of(
				$server ? ToolError::PERMISSION_DENIED : ToolError::INVALID_ARGUMENT,
				$server ? 'This is a ' . $type . ' snippet: it runs on the server and is saved only with kuduy-wpcode-snippet-php-save, which needs code execution to be allowed on the site.' : 'This snippet is ' . $type . ', not PHP: use kuduy-wpcode-snippet-save.'
			);
		}
		if ( ! $server && ! in_array( $type, self::CLIENT, true ) ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'WPCode compiles ' . $type . ' snippets in its own editor; change this one in WPCode.' );
		}
		if ( null === $old && ! isset( $input['code'] ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new snippet needs code.' );
		}
		$code   = (string) ( $input['code'] ?? ( null === $old ? '' : $old->get_code() ) );
		$active = (bool) ( $input['active'] ?? ( null === $old ? false : $old->is_active() ) );
		if ( isset( $input['code'] ) && str_contains( $code, Redactor::MASK ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The code contains ' . Redactor::MASK . ', the placeholder for a masked credential: saving it would replace the real value. Leave that line as it is in WPCode, or ask the user to enter the value there. Nothing was changed.' );
		}
		$check = CodeSnippets::check( 'universal' === $type ? '?>' . $code : $code, self::check_type( $type ) );
		if ( $server && $active && 'ok' !== $check['syntax'] ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The PHP code does not parse (' . $check['syntax'] . '). It is not saved active; fix it first. Nothing was changed.' );
		}
		self::ability( null === $old ? 'wpcode/create-snippet' : 'wpcode/update-snippet' );
		$changes = array();
		foreach ( array_merge( self::FIELDS, array( 'code_type' ) ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$changes[] = $field;
			}
		}
		return array(
			'id'         => null === $old ? null : (int) $old->get_id(),
			'action'     => null === $old ? 'create' : 'change',
			'code_type'  => $type,
			'active'     => $active,
			'changes'    => $changes,
			'code_lines' => substr_count( $code, "\n" ) + 1,
			'was_active' => null === $old ? false : (bool) $old->is_active(),
			'check'      => $check,
			'runs'       => match ( $type ) {
				'php', 'universal' => 'on the server' . ( $active ? ', from the next page load (WPCode also runs it once now to test it)' : ' once switched on' ),
				'js'               => 'in visitors\' browsers' . ( $active ? '' : ' once switched on' ),
				'css'              => 'as styles' . ( $active ? '' : ' once switched on' ),
				default            => 'in page output' . ( $active ? '' : ' once switched on' ),
			},
			'version'    => null === $old ? 'new' : md5( (string) get_post_field( 'post_modified_gmt', $old->get_id() ) . '|' . (string) $old->get_code() . '|' . (int) $old->is_active() ),
		);
	}

	/**
	 * What a header/footer change would do.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When no section is given or WPCode's ability is missing.
	 */
	private function preview_sections( array $input ): array {
		$given = array_values( array_filter( self::SECTIONS, static fn( string $s ): bool => isset( $input[ $s ] ) ) );
		if ( ! $given ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give at least one of header, body or footer.' );
		}
		foreach ( $given as $section ) {
			if ( str_contains( (string) $input[ $section ], Redactor::MASK ) ) {
				throw ToolError::of( ToolError::VALIDATION_FAILED, 'The ' . $section . ' code contains ' . Redactor::MASK . ', the placeholder for a masked credential: saving it would replace the real value. Nothing was changed.' );
			}
		}
		self::ability( 'wpcode/update-headers-footers' );
		$now   = self::sections();
		$check = array();
		foreach ( $given as $section ) {
			$check[ $section ] = CodeSnippets::check( (string) $input[ $section ], 'html' );
		}
		return array(
			'action'   => ( $input['mode'] ?? 'append' ) === 'replace' ? 'replace' : 'append',
			'sections' => $given,
			'check'    => $check,
			'runs'     => 'on every page, for every visitor',
			'version'  => md5( (string) wp_json_encode( $now ) ),
		);
	}

	/**
	 * The site-wide code as WPCode's get-headers-footers ability reads it (options stored slashed).
	 *
	 * @return array<string, string>
	 */
	private static function sections(): array {
		$out = array();
		foreach ( self::SECTIONS as $section ) {
			$out[ $section ] = (string) wp_unslash( (string) get_option( 'ihaf_insert_' . $section, '' ) );
		}
		return $out;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'headers-footers-save' === $this->mode ) {
			return array(
				'object_type' => 'wpcode_headers_footers',
				'object_id'   => 'site',
				'payload'     => array( 'sections' => self::sections() ),
			);
		}
		if ( ! isset( $input['id'] ) || ! in_array( $this->mode, array( 'snippet-save', 'snippet-php-save', 'snippet-deactivate' ), true ) ) {
			return null;
		}
		$post = get_post( (int) $input['id'] );
		if ( ! $post instanceof \WP_Post || 'wpcode' !== $post->post_type ) {
			return null;
		}
		$meta = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( str_starts_with( (string) $key, '_wpcode_' ) ) {
				$meta[ (string) $key ] = maybe_unserialize( $values[0] ?? '' );
			}
		}
		$terms = array();
		foreach ( array( 'wpcode_type', 'wpcode_location', 'wpcode_tags' ) as $taxonomy ) {
			$slugs              = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'slugs' ) );
			$terms[ $taxonomy ] = is_array( $slugs ) ? array_map( 'strval', $slugs ) : array();
		}
		return array(
			'object_type' => 'wpcode_snippet',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'      => $post->ID,
				'title'   => $post->post_title,
				'content' => $post->post_content,
				'status'  => $post->post_status,
				'meta'    => $meta,
				'terms'   => $terms,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * The snippet is put back as it was stored (post, WPCode's meta, its taxonomies). Nothing is test-run: the restored
	 * state is the one the site ran before.
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the snippet is gone or WPCode refuses the old code.
	 */
	public function restore( array $payload ): void {
		if ( isset( $payload['sections'] ) ) {
			$sections = (array) $payload['sections'];
			self::run( 'wpcode/update-headers-footers', array_intersect_key( $sections, array_flip( self::SECTIONS ) ) + array( 'mode' => 'replace' ) );
			return;
		}
		$id   = (int) ( $payload['id'] ?? 0 );
		$post = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || 'wpcode' !== $post->post_type ) {
			throw new ToolError( 'The WPCode snippet of this snapshot no longer exists.' );
		}
		$meta = (array) ( $payload['meta'] ?? array() );
		foreach ( array_keys( (array) get_post_meta( $id ) ) as $key ) {
			if ( str_starts_with( (string) $key, '_wpcode_' ) && ! array_key_exists( (string) $key, $meta ) ) {
				delete_post_meta( $id, (string) $key );
			}
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, (string) $key, wp_slash( $value ) );
		}
		foreach ( (array) ( $payload['terms'] ?? array() ) as $taxonomy => $slugs ) {
			wp_set_object_terms( $id, array_map( 'strval', (array) $slugs ), (string) $taxonomy );
		}
		$done = wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_title'   => (string) ( $payload['title'] ?? $post->post_title ),
					'post_content' => (string) ( $payload['content'] ?? $post->post_content ),
					'post_status'  => (string) ( $payload['status'] ?? $post->post_status ),
				)
			),
			true
		);
		if ( is_wp_error( $done ) ) {
			throw new ToolError( 'WordPress did not take the snippet back: ' . wp_strip_all_tags( $done->get_error_message() ) );
		}
		// WPCode serves active snippets from its own cache; rebuild it for the restored state.
		if ( function_exists( 'wpcode' ) ) {
			wpcode()->cache->cache_all_loaded_snippets();
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the snippet is unknown or WPCode refuses.
	 */
	public function execute( array $input ): array {
		switch ( $this->mode ) {
			case 'snippets':
				return array( 'snippets' => self::listing( $input ) );
			case 'snippet-get':
				AuditLog::target( 'wpcode_snippet', (string) $input['id'] );
				return self::detail( self::snippet( (int) $input['id'] ) );
			case 'snippet-deactivate':
				self::snippet( (int) $input['id'] );
				AuditLog::target( 'wpcode_snippet', (string) $input['id'] );
				self::run(
					'wpcode/set-snippet-status',
					array(
						'id'     => (int) $input['id'],
						'active' => false,
					)
				);
				return self::detail( self::snippet( (int) $input['id'] ) );
			case 'headers-footers':
				$out = array();
				foreach ( self::sections() as $section => $code ) {
					$out[ $section ] = DevText::mask( $code );
				}
				return $out + array( 'body_supported' => function_exists( 'wp_body_open' ) );
			case 'headers-footers-save':
				AuditLog::target( 'wpcode_headers_footers', 'site' );
				$args = array( 'mode' => ( $input['mode'] ?? 'append' ) === 'replace' ? 'replace' : 'append' );
				foreach ( self::SECTIONS as $section ) {
					if ( isset( $input[ $section ] ) ) {
						$args[ $section ] = (string) $input[ $section ];
					}
				}
				self::run( 'wpcode/update-headers-footers', $args );
				$out = array();
				foreach ( self::sections() as $section => $code ) {
					$out[ $section ] = DevText::mask( $code );
				}
				return $out;
			default:
				return $this->save( $input );
		}//end switch
	}

	/**
	 * Snippets through WPCode's list-snippets ability.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return list<array<string, mixed>>
	 */
	private static function listing( array $input ): array {
		$args = array( 'status' => (string) ( $input['status'] ?? 'all' ) );
		foreach ( array( 'code_type', 'tag' ) as $key ) {
			if ( isset( $input[ $key ] ) ) {
				$args[ $key ] = (string) $input[ $key ];
			}
		}
		$rows = self::run( 'wpcode/list-snippets', $args );
		$out  = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row   = (array) $row;
			$out[] = array(
				'id'          => (int) ( $row['id'] ?? 0 ),
				'title'       => (string) ( $row['title'] ?? '' ),
				'code_type'   => (string) ( $row['code_type'] ?? '' ),
				'location'    => (string) ( $row['location'] ?? '' ),
				'active'      => (bool) ( $row['active'] ?? false ),
				'tags'        => array_values( array_map( 'strval', (array) ( $row['tags'] ?? array() ) ) ),
				'has_error'   => (bool) ( $row['has_error'] ?? false ),
				'last_update' => (string) ( $row['last_update'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * Create or change a snippet through WPCode's abilities.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When WPCode refuses.
	 */
	private function save( array $input ): array {
		$args = array();
		foreach ( array_merge( self::FIELDS, array( 'code_type' ) ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$args[ $field ] = $input[ $field ];
			}
		}
		if ( isset( $input['id'] ) ) {
			$old        = self::snippet( (int) $input['id'] );
			$args['id'] = (int) $old->get_id();
			$result     = self::run( 'wpcode/update-snippet', $args );
		} else {
			$result = self::run( 'wpcode/create-snippet', $args );
		}
		$id = (int) ( ( (array) $result )['id'] ?? 0 );
		if ( $id < 1 ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WPCode did not return the saved snippet.' );
		}
		AuditLog::target( 'wpcode_snippet', (string) $id );
		return self::detail( self::snippet( $id ) );
	}
}
