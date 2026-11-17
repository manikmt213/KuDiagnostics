<?php
/**
 * Tools: kuduy-code-snippets-list, -get, -save, -php-save, -deactivate, -trash.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Code;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Code Snippets (phase G3) through the plugin's own functions (Code Snippets 4.1.0, namespace Code_Snippets):
 * get_snippets(), get_snippet(), save_snippet(), deactivate_snippet(), trash_snippet() / restore_snippet(), and its
 * own capability (code_snippets()->get_cap(), manage_options by default).
 *
 * Security (spec Part 11, rule 6, D4, D45): snippets are code that runs on the site. Reading is kuduy:read. Saving
 * HTML, CSS and JavaScript snippets needs kuduy:admin and a confirmation token. PHP snippets run on the server —
 * Code Snippets even runs an active PHP snippet once when it is saved, to test it — so creating, changing or
 * activating them is a separate tool that exists only with KUDUY_MCP_ALLOW_CODE_EXEC and needs kuduy:code plus a
 * confirmation token. Every preview carries a syntax check by PHP's own parser (the code is never run for it) and a
 * list of risky calls; neither proves the code safe, and the preview says so. Switching a snippet off and trashing
 * it are kuduy:admin with undo.
 */
class CodeSnippets implements Tool, Snapshottable {

	/**
	 * Scopes Code Snippets 4.1.0 knows, by type (Model\Snippet::get_all_scopes(), get_type_from_scope()).
	 */
	private const SCOPES = array(
		'php'  => array( 'global', 'admin', 'front-end', 'single-use' ),
		'html' => array( 'content', 'head-content', 'body-content', 'footer-content' ),
		'css'  => array( 'admin-css', 'site-css' ),
		'js'   => array( 'site-head-js', 'site-footer-js' ),
	);

	/**
	 * Calls worth a second look in PHP code, with why.
	 */
	private const RISKY_PHP = array(
		'eval'                => 'runs text as code',
		'assert'              => 'can run text as code',
		'create_function'     => 'runs text as code',
		'exec'                => 'runs a shell command',
		'shell_exec'          => 'runs a shell command',
		'system'              => 'runs a shell command',
		'passthru'            => 'runs a shell command',
		'proc_open'           => 'runs a shell command',
		'popen'               => 'runs a shell command',
		'base64_decode'       => 'often hides code',
		'gzinflate'           => 'often hides code',
		'str_rot13'           => 'often hides code',
		'file_put_contents'   => 'writes files',
		'fwrite'              => 'writes files',
		'unlink'              => 'deletes files',
		'move_uploaded_file'  => 'stores uploaded files',
		'wp_remote_post'      => 'sends data to another site',
		'curl_exec'           => 'sends data to another site',
		'mail'                => 'sends email',
		'wp_mail'             => 'sends email',
		'wp_set_current_user' => 'switches the acting user',
		'wp_set_auth_cookie'  => 'logs someone in',
		'wp_insert_user'      => 'creates users',
		'add_role'            => 'changes roles',
		'update_option'       => 'changes site settings',
		'query'               => 'runs a raw database query',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode list | get | save | php-save | deactivate | trash.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-code-snippets-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'list'       => __( 'List code snippets', 'kuduy-mcp' ),
			'get'        => __( 'Read a code snippet', 'kuduy-mcp' ),
			'save'       => __( 'Save an HTML, CSS or JavaScript snippet', 'kuduy-mcp' ),
			'php-save'   => __( 'Save a PHP snippet', 'kuduy-mcp' ),
			'deactivate' => __( 'Switch a code snippet off', 'kuduy-mcp' ),
			default      => __( 'Trash a code snippet', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$two = ' Two steps: the first call changes nothing and returns a preview (what changes, a syntax check, risky calls found) with a confirm_token; show it to the user and only if they agree call again with the same arguments and the token. A clean preview does not make code safe.';
		return match ( $this->mode ) {
			'list'       => 'Lists the snippets of the Code Snippets plugin: id, name, type (php, html, css, js), scope (where it runs: global, admin, front-end, single-use, content, head-content, body-content, footer-content, site-css, admin-css, site-head-js, site-footer-js), active, priority, tags, description, last change. Filter by type, active or search. Code is not included (kuduy-code-snippets-get).',
			'get'        => 'Returns one Code Snippets snippet with its code, scope, type, active state, priority, tags and description, plus a syntax check (PHP: PHP\'s own parser, the code is not run) and the risky calls it contains. Values that look like credentials are masked.',
			'save'       => 'Creates (no id) or changes (id) an HTML, CSS or JavaScript snippet of Code Snippets: name, code, scope (content, head-content, body-content, footer-content, site-css, admin-css, site-head-js, site-footer-js), active, priority, description, tags. Fields not given stay. These run in visitors\' browsers. PHP snippets are refused here (kuduy-code-snippets-php-save). Needs the admin access level.' . $two,
			'php-save'   => 'Creates (no id), changes or activates/deactivates (id) a PHP snippet of Code Snippets: name, code (without the opening <?php), scope (global, admin, front-end, single-use), active, priority, description, tags. PHP runs on the server with full access: Code Snippets runs an active snippet once when it is saved, to test it, and switches it off if it fails. Code with a syntax error is not saved active. Only exists when the site allows code execution (KUDUY_MCP_ALLOW_CODE_EXEC) and needs the code access level; use on development or staging sites.' . $two,
			'deactivate' => 'Switches a Code Snippets snippet off (any type) through the plugin; its code stays. Undo with kuduy-snapshot-restore switches it on again.',
			default      => 'Moves a Code Snippets snippet to its trash (it stops running and can be restored in Code Snippets). Undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = match ( $this->mode ) {
			'list'               => array(
				'type'   => array(
					'type' => 'string',
					'enum' => array_keys( self::SCOPES ),
				),
				'active' => array( 'type' => 'boolean' ),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'save', 'php-save'   => array(
				'id'              => $id,
				'name'            => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'code'            => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
				'scope'           => array(
					'type' => 'string',
					'enum' => 'php-save' === $this->mode ? self::SCOPES['php'] : array_merge( self::SCOPES['html'], self::SCOPES['css'], self::SCOPES['js'] ),
				),
				'active'          => array( 'type' => 'boolean' ),
				'priority'        => array(
					'type'    => 'integer',
					'minimum' => -1000,
					'maximum' => 1000,
				),
				'description'     => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'tags'            => array(
					'type'     => 'array',
					'maxItems' => 20,
					'items'    => array(
						'type'      => 'string',
						'maxLength' => 50,
					),
				),
				Confirmation::ARG => Confirmation::token_input(),
			),
			default              => array( 'id' => $id ),
		};//end match
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( in_array( $this->mode, array( 'get', 'deactivate', 'trash' ), true ) ) {
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
				'snippets'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'id'           => array( 'type' => 'integer' ),
				'type'         => array( 'type' => 'string' ),
				'active'       => array( 'type' => 'boolean' ),
				'code'         => array( 'type' => 'string' ),
				'check'        => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'list', 'get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => in_array( $this->mode, array( 'deactivate', 'trash' ), true ),
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return match ( $this->mode ) {
			'list', 'get' => 'kuduy:read',
			'php-save'    => 'kuduy:code',
			default       => 'kuduy:admin',
		};
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return function_exists( 'code_snippets' ) ? (string) code_snippets()->get_cap() : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! defined( 'CODE_SNIPPETS_VERSION' ) || ! function_exists( '\Code_Snippets\get_snippet' ) ) {
			return 'Code Snippets is not active on this site.';
		}
		if ( 'php-save' === $this->mode && ! ( defined( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) && true === constant( 'KUDUY_MCP_ALLOW_CODE_EXEC' ) ) ) {
			return 'PHP snippets run code on the server; this site does not allow code execution (KUDUY_MCP_ALLOW_CODE_EXEC).';
		}
		return true;
	}

	/**
	 * A snippet that exists (not trashed unless allowed).
	 *
	 * @param int $id Snippet id.
	 * @return object Code_Snippets\Model\Snippet.
	 * @throws ToolError When there is none.
	 */
	private static function snippet( int $id ): object {
		$snippet = \Code_Snippets\get_snippet( $id );
		if ( null === $snippet || (int) $snippet->id < 1 ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No code snippet with id ' . $id . ' (see kuduy-code-snippets-list).' );
		}
		return $snippet;
	}

	/**
	 * Summary of a snippet.
	 *
	 * @param object $snippet Snippet.
	 * @param bool   $code    Include the code.
	 * @return array<string, mixed>
	 */
	private static function summary( object $snippet, bool $code ): array {
		$out = array(
			'id'          => (int) $snippet->id,
			'name'        => (string) $snippet->name,
			'type'        => (string) $snippet->type,
			'scope'       => (string) $snippet->scope,
			'active'      => (bool) $snippet->active,
			'trashed'     => (bool) $snippet->trashed,
			'priority'    => (int) $snippet->priority,
			'tags'        => array_values( array_map( 'strval', (array) $snippet->tags ) ),
			'description' => wp_strip_all_tags( (string) $snippet->desc ),
			'modified'    => (string) $snippet->modified,
		);
		if ( $code ) {
			$out['code']  = (string) $snippet->code;
			$out['check'] = self::check( (string) $snippet->code, (string) $snippet->type );
		}
		return $out;
	}

	/**
	 * Syntax check (PHP's parser, nothing is run) and risky calls.
	 *
	 * @param string $code Code.
	 * @param string $type php | html | css | js.
	 * @return array<string, mixed>
	 */
	public static function check( string $code, string $type ): array {
		$out = array(
			'syntax' => 'not checked for ' . $type,
			'risky'  => array(),
			'note'   => 'A clean check does not make code safe: read it before switching it on.',
		);
		if ( 'php' === $type ) {
			try {
				$tokens        = token_get_all( '<?php ' . $code, TOKEN_PARSE );
				$out['syntax'] = 'ok';
			} catch ( \ParseError $e ) {
				return array(
					'syntax' => 'error on line ' . $e->getLine() . ': ' . $e->getMessage(),
					'risky'  => array(),
					'note'   => $out['note'],
				);
			}
			$found = array();
			foreach ( $tokens as $i => $token ) {
				if ( is_array( $token ) && T_STRING === $token[0] && isset( self::RISKY_PHP[ strtolower( $token[1] ) ] ) ) {
					$next = $tokens[ $i + 1 ] ?? null;
					$next = is_array( $next ) && T_WHITESPACE === $next[0] ? ( $tokens[ $i + 2 ] ?? null ) : $next;
					if ( '(' === $next ) {
						$found[ strtolower( $token[1] ) ] = strtolower( $token[1] ) . '(): ' . self::RISKY_PHP[ strtolower( $token[1] ) ];
					}
				}
				if ( is_array( $token ) && T_EVAL === $token[0] ) {
					$found['eval'] = 'eval(): ' . self::RISKY_PHP['eval'];
				}
				if ( is_array( $token ) && in_array( $token[0], array( T_VARIABLE ), true ) && in_array( $token[1], array( '$_GET', '$_POST', '$_REQUEST', '$_COOKIE', '$_FILES' ), true ) ) {
					$found[ $token[1] ] = $token[1] . ': reads visitor input directly';
				}
			}
			$out['risky'] = array_values( $found );
			return $out;
		}//end if
		if ( in_array( $type, array( 'js', 'html' ), true ) ) {
			foreach ( array(
				'/\beval\s*\(/i'                   => 'eval(): runs text as code',
				'/document\.cookie/i'              => 'document.cookie: reads visitors\' cookies',
				'/<script[^>]+src=["\']?https?:/i' => 'loads a script from another site',
				'/fetch\s*\(|XMLHttpRequest/i'     => 'sends requests from visitors\' browsers',
				'/atob\s*\(/i'                     => 'atob(): often hides code',
			) as $pattern => $why ) {
				if ( preg_match( $pattern, $code ) ) {
					$out['risky'][] = $why;
				}
			}
		}
		return $out;
	}

	/**
	 * What a save would do (the preview of CodeSnippetSave, which adds the confirmation step).
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the snippet is unknown, of the wrong kind, or the code does not parse.
	 */
	public function preview( array $input ): array {
		$old   = isset( $input['id'] ) ? self::snippet( (int) $input['id'] ) : null;
		$scope = (string) ( $input['scope'] ?? ( null === $old ? ( 'php-save' === $this->mode ? 'global' : '' ) : $old->scope ) );
		if ( '' === $scope ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new snippet needs a scope (where it runs).' );
		}
		$type = self::type_of( $scope );
		if ( ( 'php' === $type ) !== ( 'php-save' === $this->mode ) ) {
			throw ToolError::of(
				'php' === $type ? ToolError::PERMISSION_DENIED : ToolError::INVALID_ARGUMENT,
				'php' === $type ? 'This is a PHP snippet: it runs on the server and is saved only with kuduy-code-snippets-php-save, which needs code execution to be allowed on the site.' : 'This snippet is ' . $type . ', not PHP: use kuduy-code-snippets-save.'
			);
		}
		if ( null === $old && ( ! isset( $input['name'] ) || ! isset( $input['code'] ) ) ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'A new snippet needs a name and code.' );
		}
		$code   = (string) ( $input['code'] ?? ( null === $old ? '' : $old->code ) );
		$active = (bool) ( $input['active'] ?? ( null === $old ? false : $old->active ) );
		$check  = self::check( $code, $type );
		if ( 'php' === $type && $active && 'ok' !== $check['syntax'] ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The PHP code does not parse (' . $check['syntax'] . '). It is not saved active; fix it first. Nothing was changed.' );
		}
		$changes = array();
		foreach ( array( 'name', 'code', 'scope', 'active', 'priority', 'description', 'tags' ) as $field ) {
			if ( array_key_exists( $field, $input ) ) {
				$changes[] = $field;
			}
		}
		return array(
			'id'         => null === $old ? null : (int) $old->id,
			'action'     => null === $old ? 'create' : 'change',
			'type'       => $type,
			'scope'      => $scope,
			'active'     => $active,
			'changes'    => $changes,
			'code_lines' => substr_count( $code, "\n" ) + 1,
			'was_active' => null === $old ? false : (bool) $old->active,
			'check'      => $check,
			'runs'       => match ( $type ) {
				'php'  => 'on the server' . ( $active ? ', from the next page load (Code Snippets also runs it once now to test it)' : ' once switched on' ),
				'js'   => 'in visitors\' browsers' . ( $active ? '' : ' once switched on' ),
				'css'  => 'as styles' . ( $active ? '' : ' once switched on' ),
				default => 'in page output' . ( $active ? '' : ' once switched on' ),
			},
			'version'    => null === $old ? 'new' : md5( (string) $old->modified . '|' . (string) $old->code . '|' . (int) $old->active ),
		);
	}

	/**
	 * Type of a scope.
	 *
	 * @param string $scope Scope.
	 */
	private static function type_of( string $scope ): string {
		foreach ( self::SCOPES as $type => $scopes ) {
			if ( in_array( $scope, $scopes, true ) ) {
				return $type;
			}
		}
		return 'php';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( ! isset( $input['id'] ) || in_array( $this->mode, array( 'list', 'get' ), true ) ) {
			return null;
		}
		$snippet = \Code_Snippets\get_snippet( (int) $input['id'] );
		if ( null === $snippet || (int) $snippet->id < 1 ) {
			return null;
		}
		return array(
			'object_type' => 'code_snippet',
			'object_id'   => (string) $snippet->id,
			'payload'     => array(
				'fields'  => array(
					'id'       => (int) $snippet->id,
					'name'     => (string) $snippet->name,
					'desc'     => (string) $snippet->desc,
					'code'     => (string) $snippet->code,
					'tags'     => (array) $snippet->tags,
					'scope'    => (string) $snippet->scope,
					'priority' => (int) $snippet->priority,
					'active'   => (bool) $snippet->active,
				),
				'trashed' => (bool) $snippet->trashed,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When Code Snippets does not take the state back.
	 */
	public function restore( array $payload ): void {
		$fields = (array) $payload['fields'];
		if ( 'trash' === $this->mode ) {
			\Code_Snippets\restore_snippet( (int) $fields['id'] );
		}
		$saved = \Code_Snippets\save_snippet( new \Code_Snippets\Model\Snippet( $fields ) );
		if ( null === $saved ) {
			throw new ToolError( 'Code Snippets did not take the previous state back.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the snippet is unknown or Code Snippets refuses.
	 */
	public function execute( array $input ): array {
		switch ( $this->mode ) {
			case 'list':
				return array( 'snippets' => self::listing( $input ) );
			case 'get':
				AuditLog::target( 'code_snippet', (string) $input['id'] );
				return self::summary( self::snippet( (int) $input['id'] ), true );
			case 'deactivate':
				self::snippet( (int) $input['id'] );
				AuditLog::target( 'code_snippet', (string) $input['id'] );
				\Code_Snippets\deactivate_snippet( (int) $input['id'] );
				return self::summary( self::snippet( (int) $input['id'] ), false );
			case 'trash':
				self::snippet( (int) $input['id'] );
				AuditLog::target( 'code_snippet', (string) $input['id'] );
				if ( ! \Code_Snippets\trash_snippet( (int) $input['id'] ) ) {
					throw new ToolError( 'Code Snippets did not move this snippet to its trash.' );
				}
				return array(
					'id'      => (int) $input['id'],
					'trashed' => true,
				);
			default:
				return $this->save( $input );
		}//end switch
	}

	/**
	 * Snippets, filtered.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return list<array<string, mixed>>
	 */
	private static function listing( array $input ): array {
		$out    = array();
		$search = strtolower( (string) ( $input['search'] ?? '' ) );
		foreach ( \Code_Snippets\get_snippets() as $snippet ) {
			if ( (bool) $snippet->trashed ) {
				continue;
			}
			$row = self::summary( $snippet, false );
			if ( ( isset( $input['type'] ) && $input['type'] !== $row['type'] ) || ( isset( $input['active'] ) && (bool) $input['active'] !== $row['active'] ) ) {
				continue;
			}
			if ( '' !== $search && ! str_contains( strtolower( $row['name'] . ' ' . $row['description'] . ' ' . implode( ' ', $row['tags'] ) ), $search ) ) {
				continue;
			}
			$out[] = $row;
		}
		return $out;
	}

	/**
	 * Create or change a snippet through Code Snippets.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When Code Snippets refuses.
	 */
	private function save( array $input ): array {
		$fields = array();
		if ( isset( $input['id'] ) ) {
			$old    = self::snippet( (int) $input['id'] );
			$fields = array(
				'id'       => (int) $old->id,
				'name'     => (string) $old->name,
				'desc'     => (string) $old->desc,
				'code'     => (string) $old->code,
				'tags'     => (array) $old->tags,
				'scope'    => (string) $old->scope,
				'priority' => (int) $old->priority,
				'active'   => (bool) $old->active,
			);
		}
		foreach ( array(
			'name'        => 'name',
			'code'        => 'code',
			'scope'       => 'scope',
			'active'      => 'active',
			'priority'    => 'priority',
			'description' => 'desc',
			'tags'        => 'tags',
		) as $key => $field ) {
			if ( array_key_exists( $key, $input ) ) {
				$fields[ $field ] = $input[ $key ];
			}
		}
		if ( ! isset( $fields['scope'] ) ) {
			$fields['scope'] = 'global';
		}
		$saved = \Code_Snippets\save_snippet( new \Code_Snippets\Model\Snippet( $fields ) );
		if ( null === $saved ) {
			throw new ToolError( 'Code Snippets did not save the snippet.' );
		}
		AuditLog::target( 'code_snippet', (string) $saved->id );
		$out = self::summary( $saved, false );
		if ( ! empty( $fields['active'] ) && ! $saved->active ) {
			$error             = (array) $saved->code_error;
			$out['code_error'] = 'Code Snippets switched the snippet off because its test run failed' . ( $error ? ': ' . (string) ( $error[0] ?? '' ) . ( isset( $error[1] ) ? ' (line ' . (int) $error[1] . ')' : '' ) : '.' );
		}
		return $out;
	}
}
