<?php
/**
 * Tools: kuduy-breakdance-abilities, -ability-get, -ability-run and the same three for Oxygen (one class, two
 * builders, three modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Page builders through the abilities they register themselves. Bricks 2.4+ (D41; Bricks Academy "AI Abilities and
 * Skills": abilities under "bricks/…", switched on in Bricks > AI > Configuration, each checked by Bricks against the
 * user's capabilities and builder permissions; post element writes create Bricks revisions; PHP abilities are never
 * run here, rule 6). Breakdance 3 and Oxygen 6 (D40; source of Breakdance 3.0.0-beta.4
 * and Oxygen 6.2.0, which share one codebase: BREAKDANCE_MODE is "breakdance" or "oxygen"). With "Agents & MCP" on
 * in the builder's settings (it is unless switched off) the builder registers about forty abilities under
 * "breakdance/…" or "oxygen/…" with the WordPress Abilities API: get-post-tree, edit-post (atomic element
 * operations), create-post, html-to-page, element schemas, templates, popups, global settings, … Each needs
 * manage_options (the builder's own permission callback) and read abilities are marked annotations.readonly.
 * KuDiagnostics runs them as the connected user through WP_Ability::execute(). Writes are limited to what can be
 * undone here: abilities that work on one post (a snapshot of that post's builder data is taken first) and abilities
 * that create something new. Site-wide writes (global settings, CSS variables, selectors, stylesheets, home page)
 * and the bug report ability, which sends data to the vendor, are not run.
 */
final class BuilderAbilities implements Tool, Snapshottable {

	/**
	 * Builders: BREAKDANCE_MODE value => display name.
	 */
	public const BUILDERS = array(
		'breakdance'     => 'Breakdance',
		'oxygen'         => 'Oxygen',
		'bricks'         => 'Bricks',
		'spectra-blocks' => 'Spectra Blocks',
	);

	/**
	 * Per builder: the version that brings the abilities, where they are switched on, meta key prefix of a post's
	 * builder data (for undo), example abilities, abilities never run here, abilities that take a post but change
	 * the whole site (Breakdance's set-home-page), and - where the builder has many abilities that act on posts in
	 * ways a post snapshot cannot undo (Bricks 2.4.5 registers 167, with delete-post, delete-media, restore-revision,
	 * import-transfer-package) - the write abilities allowed here; null means every write on one post or creating
	 * something new.
	 *
	 * @var array<string, array{since: string, switch: string, prefix: string, get: string, run: string, never: list<string>, site: list<string>, allow: list<string>|null}>
	 */
	private const CONFIG = array(
		'breakdance'     => array(
			'since'  => 'Breakdance 3',
			'switch' => '"Agents & MCP" in the Breakdance settings (on unless switched off)',
			'prefix' => '',
			'get'    => 'breakdance/get-instructions, breakdance/get-post-tree {"post_id": 12}, breakdance/get-element-schemas',
			'run'    => 'breakdance/edit-post (element operations on one page, applied together) or breakdance/create-post',
			'never'  => array( 'report-bug' ),
			'site'   => array( 'set-home-page' ),
			'allow'  => null,
		),
		'oxygen'         => array(
			'since'  => 'Oxygen 6',
			'switch' => '"Agents & MCP" in the Oxygen settings (on unless switched off)',
			'prefix' => '',
			'get'    => 'oxygen/get-instructions, oxygen/get-post-tree {"post_id": 12}, oxygen/get-element-schemas',
			'run'    => 'oxygen/edit-post (element operations on one page, applied together) or oxygen/create-post',
			'never'  => array( 'report-bug' ),
			'site'   => array( 'set-home-page' ),
			'allow'  => null,
		),
		'bricks'         => array(
			'since'  => 'Bricks 2.4',
			'switch' => '"Enable Bricks abilities" in Bricks > AI > Configuration (each ability can also be switched off in Bricks > AI > Abilities)',
			'prefix' => '_bricks_page_',
			'get'    => 'bricks/start-here, bricks/get-page-elements, bricks/get-design-context',
			'run'    => 'bricks/update-element {"postId": 12, "elementId": "abc123", "settings": {…}} or bricks/set-page-elements (all elements of one page)',
			'never'  => array( 'execute-php' ),
			'site'   => array(),
			// Not update-popup-settings: it names the popup by templateId/popupId and writes template settings outside
			// the _bricks_page_ data the snapshot keeps, so it could not be undone (Bricks 2.4.5, checked live).
			'allow'  => array( 'set-page-elements', 'add-element', 'update-element', 'batch-update-elements', 'remove-element', 'set-page-settings', 'update-element-interactions', 'update-element-conditions', 'update-form-fields', 'update-form-actions', 'create-post', 'create-template', 'duplicate-post' ),
		),
		// Spectra Blocks 1.0.15 (phase C2): about fifty abilities under "spectra-blocks/" (create blocks on a page,
		// update block attributes, move/remove/duplicate blocks, animations, display conditions, popups, settings,
		// global styles). Blocks live in post_content, which the snapshot keeps; popup posts also keep their
		// "spectra-blocks-popup-" fields. Abilities that change site-wide settings take no post and are refused.
		'spectra-blocks' => array(
			'since'  => 'Spectra Blocks 1.0',
			'switch' => '"Abilities" in Spectra Blocks > Settings (off unless switched on; editing abilities can be switched off there separately)',
			'prefix' => 'spectra-blocks-popup-',
			'get'    => 'spectra-blocks/get-post-content {"post_id": 12}, spectra-blocks/list-available-blocks, spectra-blocks/get-block-config',
			'run'    => 'spectra-blocks/update-block-attributes or spectra-blocks/create-container on one page (post_id)',
			'never'  => array(),
			'site'   => array(),
			'allow'  => null,
		),
	);

	/**
	 * Builder and mode.
	 *
	 * @param string $builder breakdance | oxygen | bricks | spectra-blocks.
	 * @param string $mode    abilities | ability-get | ability-run.
	 */
	public function __construct( private string $builder, private string $mode ) {}

	/**
	 * Display name of the builder.
	 */
	private function label(): string {
		return self::BUILDERS[ $this->builder ] ?? $this->builder;
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->builder . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			/* translators: %s: page builder name. */
			'abilities'   => sprintf( __( 'List %s abilities', 'kuduy-mcp' ), $this->label() ),
			/* translators: %s: page builder name. */
			'ability-get' => sprintf( __( 'Read with %s', 'kuduy-mcp' ), $this->label() ),
			/* translators: %s: page builder name. */
			default       => sprintf( __( 'Change a %s page', 'kuduy-mcp' ), $this->label() ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$b = $this->label();
		$n = $this->builder;
		$c = self::CONFIG[ $n ];
		return match ( $this->mode ) {
			'abilities'   => 'Lists what ' . $b . ' lets an assistant do through its own abilities (read a page as an element tree, edit elements, create pages, element schemas, …): name, label, description, read or write, and whether it can be run here. With name it returns that ability\'s input schema. They come with ' . $c['since'] . ' and are switched on with ' . $c['switch'] . '; the list says so when they are off.',
			'ability-get' => 'Runs one of ' . $b . '\'s read abilities and returns its data, e.g. ' . $c['get'] . '. Names and inputs: kuduy-' . $n . '-abilities.',
			default       => 'Runs one of ' . $b . '\'s write abilities with the input it declares, e.g. ' . $c['run'] . '. ' . $b . ' validates the input and checks its own permissions. Only abilities that work on one post named by its numeric id (post_id or postId) or create something new are run' . ( null !== $c['allow'] ? ', and only ' . $b . '\'s page-level ones (see kuduy-' . $n . '-abilities)' : '' ) . '; site-wide settings are left to the builder. Snapshot of that post\'s builder data first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name  = array(
			'type'        => 'string',
			'pattern'     => '^' . $this->builder . '/[a-z0-9-]+$',
			'description' => 'Ability name from kuduy-' . $this->builder . '-abilities.',
		);
		$props = 'abilities' === $this->mode
			? array( 'name' => $name )
			: array(
				'name'  => $name,
				'input' => array(
					'type'        => 'object',
					'description' => 'Input the ability declares (see kuduy-' . $this->builder . '-abilities with name).',
				),
			);
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => 'abilities' === $this->mode ? array() : array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array( 'type' => 'object' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		$write = 'ability-run' === $this->mode;
		return array(
			'readOnlyHint'    => ! $write,
			'destructiveHint' => $write,
			'idempotentHint'  => ! $write,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'ability-run' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$post_id = self::post_id( $input );
		return 'ability-run' === $this->mode && $post_id > 0 && get_post( $post_id ) ? array( 'edit_post', $post_id ) : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$active = match ( $this->builder ) {
			'bricks'         => defined( 'BRICKS_DB_PAGE_CONTENT' ),
			'spectra-blocks' => defined( 'SPECTRA_BLOCKS_VER' ),
			default          => defined( 'BREAKDANCE_MODE' ) && constant( 'BREAKDANCE_MODE' ) === $this->builder,
		};
		return $active ? true : self::CONFIG[ $this->builder ]['since'] . ' or later is not active on this site.';
	}

	/**
	 * The post an ability call works on, or 0.
	 *
	 * @param array<string, mixed> $input Tool arguments.
	 */
	private static function post_id( array $input ): int {
		$args = (array) ( $input['input'] ?? array() );
		foreach ( array( 'post_id', 'postId', 'popup_id' ) as $key ) {
			if ( isset( $args[ $key ] ) && is_numeric( $args[ $key ] ) ) {
				return (int) $args[ $key ];
			}
		}
		return 0;
	}

	/**
	 * The builder's registered abilities by name.
	 *
	 * @return array<string, \WP_Ability>
	 */
	private function abilities(): array {
		$out = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( str_starts_with( $ability->get_name(), $this->builder . '/' ) ) {
					$out[ $ability->get_name() ] = $ability;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Whether an ability only reads: the builder's annotations say so (readonly / readOnlyHint, the Abilities API
	 * convention); without annotations, abilities named get-, list- or start-here are taken as reads.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public static function reads( \WP_Ability $ability ): bool {
		$annotations = $ability->get_meta_item( 'annotations', array() );
		if ( is_array( $annotations ) ) {
			foreach ( array( 'readonly', 'readOnlyHint' ) as $key ) {
				// WordPress fills unset annotations with null; only a value the builder set decides.
				if ( isset( $annotations[ $key ] ) ) {
					return (bool) $annotations[ $key ];
				}
			}
		}
		$short = (string) substr( $ability->get_name(), (int) strpos( $ability->get_name(), '/' ) + 1 );
		return 1 === preg_match( '/^(get-|list-|start-here$)/', $short );
	}

	/**
	 * Why an ability is never run here, read or write, or '' when it may be. Breakdance marks report-bug as read-only,
	 * yet it sends the report to Breakdance's makers (found on a live site, 2026-11-15).
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private function refused( \WP_Ability $ability ): string {
		$short = substr( $ability->get_name(), strlen( $this->builder ) + 1 );
		if ( 'report-bug' === $short ) {
			return 'This ability sends a report to ' . $this->label() . '\'s makers; KuDiagnostics does not send site data to outside services.';
		}
		if ( in_array( $short, self::CONFIG[ $this->builder ]['never'], true ) || str_contains( $short, 'php' ) ) {
			return 'This ability runs code on the server. KuDiagnostics only runs code through its own guarded developer tools.';
		}
		return '';
	}

	/**
	 * Why a write ability is not run here, or '' when it is.
	 *
	 * @param \WP_Ability          $ability Ability.
	 * @param array<string, mixed> $input   Tool arguments.
	 * @param bool                 $listing For the abilities list (no input yet).
	 */
	private function blocked( \WP_Ability $ability, array $input, bool $listing = false ): string {
		$refused = $this->refused( $ability );
		if ( '' !== $refused ) {
			return $refused;
		}
		$short  = substr( $ability->get_name(), strlen( $this->builder ) + 1 );
		$config = self::CONFIG[ $this->builder ];
		if ( in_array( $short, $config['site'], true ) ) {
			return 'This ability changes the whole site (not only the page given). ' . $this->site_hint( $ability );
		}
		if ( null !== $config['allow'] && ! in_array( $short, $config['allow'], true ) ) {
			if ( '' === $this->site_why( $ability ) ) {
				return 'This ability changes site-wide ' . $this->label() . ' data. ' . $this->site_hint( $ability );
			}
			return 'Only ' . $this->label() . '\'s page-level changes are run here (' . implode( ', ', $config['allow'] ) . '); this one deletes, restores or changes data shared across the site, which cannot be undone from here. Do it in ' . $this->label() . ' itself.';
		}
		if ( str_starts_with( $short, 'delete-' ) ) {
			return 'This ability deletes for good, which cannot be undone from here. Trash it in ' . $this->label() . ' or with the KuDiagnostics trash tools instead.';
		}
		if ( str_starts_with( $short, 'create-' ) || str_starts_with( $short, 'duplicate-' ) ) {
			return '';
		}
		// Bricks 2.4.5 declares an empty `properties` as an object (so it encodes as {}): read it as an array.
		$props = (array) ( $ability->get_input_schema()['properties'] ?? array() );
		if ( isset( $props['post_id'] ) || isset( $props['postId'] ) || isset( $props['popup_id'] ) ) {
			// The page must be named by its id, so it can be snapshotted first (Bricks also accepts slug, path or title).
			return $listing || self::post_id( $input ) > 0 ? '' : 'Name the page by its numeric id (' . ( isset( $props['postId'] ) ? 'postId' : ( isset( $props['popup_id'] ) ? 'popup_id' : 'post_id' ) ) . ') so it can be saved first and the change undone.';
		}
		return 'This ability changes site-wide ' . $this->label() . ' settings. ' . $this->site_hint( $ability );
	}

	/**
	 * Where a site-wide write ability can be run instead.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private function site_hint( \WP_Ability $ability ): string {
		return '' === $this->site_why( $ability ) && in_array( $this->builder, BuilderSiteAbilities::BUILDERS, true )
			? 'Run it with kuduy-' . $this->builder . '-site-ability-run (two steps with a confirmation; ' . $this->label() . '\'s options are saved first for undo).'
			: 'Change it in ' . $this->label() . ' itself.';
	}

	/**
	 * Why a write ability may not be run as a site-wide change (kuduy-…-site-ability-run), or '' when it may: a
	 * write that is not refused, not a page-level one (those go through ability-run, with a page snapshot), and does
	 * not delete, restore, import, upload files or run tools whose effect an options snapshot cannot undo.
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	public function site_why( \WP_Ability $ability ): string {
		$refused = $this->refused( $ability );
		if ( '' !== $refused ) {
			return $refused;
		}
		if ( self::reads( $ability ) ) {
			return 'This is a read ability: use kuduy-' . $this->builder . '-ability-get.';
		}
		$short  = substr( $ability->get_name(), strlen( $this->builder ) + 1 );
		$config = self::CONFIG[ $this->builder ];
		if ( preg_match( '/^(delete|remove|restore|import|upload|execute|run|send|report|install|activate|deactivate)-/', $short ) ) {
			return 'This ability deletes, restores, imports, uploads or runs something, which a snapshot of ' . $this->label() . '\'s options cannot undo. Do it in ' . $this->label() . ' itself.';
		}
		if ( in_array( $short, $config['site'], true ) ) {
			return '';
		}
		// Found live on Bricks 2.4.5 and Breakdance 3 (2026-11-18): site-level abilities that change roles and builder
		// capabilities, menus, files, fonts, form submissions, WooCommerce options, index tables, remote imports or
		// multi-step page changesets. Their effects lie outside the builder's options, so the snapshot cannot undo them.
		if ( preg_match( '/role|capabilit|menu|agent-file|remote|reindex|changeset|foundation|import|workspace|site-edit|cleanup|woo|font|submission/', $short ) ) {
			return 'This ability changes things outside ' . $this->label() . '\'s options (roles, menus, files, fonts, submissions, WooCommerce settings, indexes or pages), which a snapshot of those options cannot undo. Do it in ' . $this->label() . ' itself.';
		}
		if ( str_starts_with( $short, 'create-' ) || str_starts_with( $short, 'duplicate-' ) || ( null !== $config['allow'] && in_array( $short, $config['allow'], true ) ) ) {
			return 'This ability works on one page or creates something new: use kuduy-' . $this->builder . '-ability-run.';
		}
		$props = (array) ( $ability->get_input_schema()['properties'] ?? array() );
		if ( isset( $props['post_id'] ) || isset( $props['postId'] ) || isset( $props['popup_id'] ) || isset( $props['post_ids'] ) || isset( $props['templateId'] ) ) {
			return 'This ability works on posts or templates, which a snapshot of ' . $this->label() . '\'s options cannot undo. ' . ( null === $config['allow'] ? 'Use kuduy-' . $this->builder . '-ability-run.' : 'Do it in ' . $this->label() . ' itself.' );
		}
		return '';
	}

	/**
	 * A registered ability of this builder by name.
	 *
	 * @param string $name Ability name.
	 * @throws ToolError When the builder's abilities are off or the name is unknown.
	 */
	public function ability( string $name ): \WP_Ability {
		$all = $this->abilities();
		if ( ! $all ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, $this->label() . ' has not registered its abilities on this site. They are switched on with ' . self::CONFIG[ $this->builder ]['switch'] . '; KuDiagnostics does not change that setting.' );
		}
		if ( ! isset( $all[ $name ] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, $this->label() . ' has no ability "' . $name . '" on this site. See kuduy-' . $this->builder . '-abilities.' );
		}
		return $all[ $name ];
	}

	/**
	 * Whether a function of the builder exists (its names change between versions).
	 *
	 * @param string $name Function name.
	 */
	private static function exists( string $name ): bool {
		return function_exists( $name );
	}

	/**
	 * Meta key prefix of the builder's post data ("_breakdance_", "_oxygen_", "_bricks_page_"), or '' when unknown.
	 */
	private function meta_prefix(): string {
		if ( '' !== self::CONFIG[ $this->builder ]['prefix'] ) {
			return self::CONFIG[ $this->builder ]['prefix'];
		}
		$fn = '\Breakdance\BreakdanceOxygen\Strings\__bdox';
		return self::exists( $fn ) ? (string) call_user_func( $fn, '_meta_prefix' ) : '';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		$post_id = self::post_id( $input );
		$post    = 'ability-run' === $this->mode && $post_id > 0 ? get_post( $post_id ) : null;
		$prefix  = $this->meta_prefix();
		if ( ! $post instanceof \WP_Post || '' === $prefix ) {
			return null;
		}
		$meta = array();
		foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
			if ( str_starts_with( (string) $key, $prefix ) ) {
				$meta[ (string) $key ] = maybe_unserialize( $values[0] ?? '' );
			}
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'id'      => $post->ID,
				'prefix'  => $prefix,
				'meta'    => $meta,
				'status'  => $post->post_status,
				'content' => $post->post_content,
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the post is gone.
	 */
	public function restore( array $payload ): void {
		$id     = (int) ( $payload['id'] ?? 0 );
		$prefix = (string) ( $payload['prefix'] ?? '' );
		$post   = $id > 0 ? get_post( $id ) : null;
		if ( ! $post instanceof \WP_Post || '' === $prefix ) {
			throw new ToolError( 'The page of this snapshot no longer exists.' );
		}
		$meta = (array) ( $payload['meta'] ?? array() );
		foreach ( array_keys( (array) get_post_meta( $id ) ) as $key ) {
			if ( str_starts_with( (string) $key, $prefix ) && ! array_key_exists( (string) $key, $meta ) ) {
				delete_post_meta( $id, (string) $key );
			}
		}
		foreach ( $meta as $key => $value ) {
			update_post_meta( $id, (string) $key, wp_slash( $value ) );
		}
		wp_update_post(
			wp_slash(
				array(
					'ID'           => $id,
					'post_status'  => (string) ( $payload['status'] ?? $post->post_status ),
					'post_content' => (string) ( $payload['content'] ?? $post->post_content ),
				)
			)
		);
		// The builder renders a page into cached CSS and HTML when it is saved; rebuild it for the restored data.
		$render = '\Breakdance\Render\generateCacheForPost';
		if ( self::exists( $render ) ) {
			call_user_func( $render, $id );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When abilities are off, the ability is unknown, not run here, or the builder refuses.
	 */
	public function execute( array $input ): array {
		$all = $this->abilities();
		if ( 'abilities' === $this->mode ) {
			return $this->listing( $all, (string) ( $input['name'] ?? '' ) );
		}
		if ( ! $all ) {
			throw new ToolError( esc_html( $this->label() ) . ' has not registered its abilities on this site. They are switched on with ' . esc_html( self::CONFIG[ $this->builder ]['switch'] ) . '; KuDiagnostics does not change that setting.' );
		}
		$name    = (string) $input['name'];
		$ability = $all[ $name ] ?? null;
		if ( null === $ability ) {
			throw new ToolError( esc_html( $this->label() ) . ' has no ability "' . esc_html( $name ) . '" on this site. See kuduy-' . esc_html( $this->builder ) . '-abilities.' );
		}
		$refused = $this->refused( $ability );
		if ( '' !== $refused ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
			throw new ToolError( $refused . ' Nothing was run.' );
		}
		$write = 'ability-run' === $this->mode;
		if ( self::reads( $ability ) === $write ) {
			throw new ToolError( $write ? 'This is a read ability: use kuduy-' . esc_html( $this->builder ) . '-ability-get.' : 'This ability changes things: use kuduy-' . esc_html( $this->builder ) . '-ability-run.' );
		}
		if ( $write ) {
			$why = $this->blocked( $ability, $input );
			if ( '' !== $why ) {
				// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML.
				throw new ToolError( $why . ' Nothing was changed.' );
			}
			$post_id = self::post_id( $input );
			AuditLog::target( $post_id > 0 ? 'post' : 'builder', $post_id > 0 ? (string) $post_id : $this->builder );
		}
		$result = $ability->execute( (array) ( $input['input'] ?? array() ) );
		if ( is_wp_error( $result ) ) {
			// phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- returned as JSON text, never HTML; tags are stripped.
			throw new ToolError( $this->label() . ' refused: ' . wp_strip_all_tags( $result->get_error_message() ) . ( $write ? ' Nothing was changed.' : '' ) );
		}
		return array(
			'ability' => $name,
			'result'  => $result,
		);
	}

	/**
	 * The abilities list, or one ability with its input schema.
	 *
	 * @param array<string, \WP_Ability> $all  Abilities.
	 * @param string                     $name Ability name or ''.
	 * @return array<string, mixed>
	 * @throws ToolError When the named ability is unknown.
	 */
	private function listing( array $all, string $name ): array {
		if ( ! $all ) {
			return array(
				'enabled'   => false,
				'abilities' => array(),
				'note'      => $this->label() . ' has not registered its abilities. They come with ' . self::CONFIG[ $this->builder ]['since'] . ' and are switched on with ' . self::CONFIG[ $this->builder ]['switch'] . '.',
			);
		}
		if ( '' !== $name ) {
			$ability = $all[ $name ] ?? null;
			if ( null === $ability ) {
				throw new ToolError( esc_html( $this->label() ) . ' has no ability "' . esc_html( $name ) . '" on this site.' );
			}
			return array(
				'enabled' => true,
				'ability' => $this->row( $ability ) + array( 'input_schema' => $ability->get_input_schema() ),
			);
		}
		return array(
			'enabled'   => true,
			'abilities' => array_values( array_map( array( $this, 'row' ), $all ) ),
		);
	}

	/**
	 * Summary of one ability.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return array<string, mixed>
	 */
	private function row( \WP_Ability $ability ): array {
		$reads = self::reads( $ability );
		$why   = $reads ? $this->refused( $ability ) : $this->blocked( $ability, array(), true );
		$row   = array(
			'name'        => $ability->get_name(),
			'label'       => wp_strip_all_tags( $ability->get_label() ),
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'kind'        => $reads ? 'read' : 'write',
			'run_with'    => '' !== $why
				? ( ! $reads && '' === $this->site_why( $ability ) && in_array( $this->builder, BuilderSiteAbilities::BUILDERS, true ) ? 'kuduy-' . $this->builder . '-site-ability-run' : 'not run here' )
				: ( $reads ? 'kuduy-' . $this->builder . '-ability-get' : 'kuduy-' . $this->builder . '-ability-run' ),
		);
		if ( '' !== $why ) {
			$row['why_not'] = $why;
		}
		return $row;
	}
}
