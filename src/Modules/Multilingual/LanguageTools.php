<?php
/**
 * Tools: kuduy-lang-status, kuduy-lang-get, kuduy-lang-set, kuduy-lang-link, kuduy-lang-translate, kuduy-lang-missing,
 * kuduy-lang-strings, kuduy-lang-string-save.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Multilingual;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Polylang's own sanitizing filter, applied as its Strings screen does.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Multilingual content with Polylang or WPML (D20 A, D60): languages, an object's language and translation group,
 * setting a language, linking translations, creating a translation as a draft with text Claude provides, and the
 * content still missing a translation. Polylang's string translations (strings registered with pll_register_string,
 * widget titles) are read and changed through its own PLL_MO store, as its Strings screen saves them. Every change has
 * an undo except creating a translation (undo with kuduy-post-trash, as for other creates).
 */
final class LanguageTools implements Tool, Snapshottable {

	/**
	 * Mode.
	 *
	 * @param string $mode status | get | set | link | translate | missing | strings | string-save.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-lang-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'status'    => __( 'Languages', 'kuduy-mcp' ),
			'get'       => __( 'Language and translations', 'kuduy-mcp' ),
			'set'       => __( 'Set a language', 'kuduy-mcp' ),
			'link'        => __( 'Link translations', 'kuduy-mcp' ),
			'missing'     => __( 'Missing translations', 'kuduy-mcp' ),
			'strings'     => __( 'String translations', 'kuduy-mcp' ),
			'string-save' => __( 'Save a string translation', 'kuduy-mcp' ),
			default       => __( 'Create a translation', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'status'    => 'The multilingual plugin (Polylang or WPML) and its version, the site\'s languages (slug, name, locale, default; Polylang also the number of posts) and which post types and taxonomies are translated.',
			'get'       => 'Returns the language of a post or term and its translations (language → id, title, status).',
			'set'       => 'Sets the language of a post or term (Polylang or WPML). Snapshot first; undo with kuduy-snapshot-restore.',
			'link'      => 'Links posts (or terms) as translations of each other: translations maps language → id, e.g. {"en": 12, "de": 34}. Each object must already have that language and you must be able to edit it. Snapshot first; undo restores the previous groups.',
			'missing'     => 'Lists content of a post type (default post) that has no translation yet in one or every other language: id, title, language, missing languages. Looks at the newest 500 items; limit up to 100 results.',
			'strings'     => 'Polylang only: lists the translatable strings registered on the site (theme and plugin strings registered with pll_register_string, widget titles) with name, group, original text and the translation per language. Strings that a theme or plugin registers only on admin screens may be missing here. Filter by group or search.',
			'string-save' => 'Polylang only: sets the translation of one registered string in one language (string = the original text exactly as kuduy-lang-strings shows it; an empty translation removes it). Polylang\'s own sanitizing filter runs as on its Strings screen. Needs a site manager. Snapshot first; undo with kuduy-snapshot-restore.',
			default     => 'Creates a translation of a post as a draft in another language, with the title, content and excerpt you give (write the translated text yourself), and links it to the original. Undo with kuduy-post-trash on the new draft.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$object     = array(
			'object' => array(
				'type'    => 'string',
				'enum'    => Languages::OBJECTS,
				'default' => 'post',
			),
			'id'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
		);
		$lang       = array(
			'type'      => 'string',
			'minLength' => 2,
			'maxLength' => 20,
		);
		$properties = array();
		$required   = array();
		switch ( $this->mode ) {
			case 'get':
				$properties = $object;
				$required   = array( 'id' );
				break;
			case 'set':
				$properties = $object + array( 'language' => $lang );
				$required   = array( 'id', 'language' );
				break;
			case 'link':
				$properties = array(
					'object'       => $object['object'],
					'translations' => array(
						'type'                 => 'object',
						'minProperties'        => 2,
						'maxProperties'        => 50,
						'additionalProperties' => array(
							'type'    => 'integer',
							'minimum' => 1,
						),
					),
				);
				$required   = array( 'translations' );
				break;
			case 'missing':
				$properties = array(
					'post_type' => array(
						'type'      => 'string',
						'maxLength' => 40,
						'default'   => 'post',
					),
					'language'  => $lang,
					'limit'     => array(
						'type'    => 'integer',
						'minimum' => 1,
						'maximum' => 100,
						'default' => 50,
					),
				);
				break;
			case 'strings':
				$properties = array(
					'group'  => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
					'search' => array(
						'type'      => 'string',
						'maxLength' => 200,
					),
				);
				break;
			case 'string-save':
				$properties = array(
					'string'      => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 10000,
					),
					'language'    => $lang,
					'translation' => array(
						'type'      => 'string',
						'maxLength' => 10000,
					),
				);
				$required   = array( 'string', 'language', 'translation' );
				break;
			case 'translate':
				$properties = array(
					'id'       => $object['id'],
					'language' => $lang,
					'title'    => array(
						'type'      => 'string',
						'minLength' => 1,
						'maxLength' => 500,
					),
					'content'  => array(
						'type'      => 'string',
						'maxLength' => 1000000,
					),
					'excerpt'  => array(
						'type'      => 'string',
						'maxLength' => 10000,
					),
				);
				$required   = array( 'id', 'language', 'title' );
				break;
		}//end switch
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		if ( 'status' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'plugin'     => array( 'type' => 'string' ),
					'version'    => array( 'type' => 'string' ),
					'languages'  => array(
						'type'  => 'array',
						'items' => array( 'type' => 'object' ),
					),
					'post_types' => array( 'type' => 'array' ),
					'taxonomies' => array( 'type' => 'array' ),
				),
				'required'   => array( 'plugin', 'version', 'languages' ),
			);
		}
		if ( in_array( $this->mode, array( 'missing', 'strings', 'string-save' ), true ) ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'items'        => array( 'type' => 'array' ),
					'scanned'      => array( 'type' => 'integer' ),
					'languages'    => array( 'type' => 'array' ),
					'strings'      => array( 'type' => 'array' ),
					'string'       => array( 'type' => 'object' ),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
			);
		}
		if ( 'link' === $this->mode ) {
			return array(
				'type'       => 'object',
				'properties' => array(
					'object'       => array( 'type' => 'string' ),
					'translations' => array( 'type' => 'object' ),
					'_snapshot_id' => array( 'type' => 'integer' ),
				),
				'required'   => array( 'object', 'translations' ),
			);
		}
		return array(
			'type'       => 'object',
			'properties' => array(
				'object'       => array( 'type' => 'string' ),
				'id'           => array( 'type' => 'integer' ),
				'language'     => array( 'type' => array( 'string', 'null' ) ),
				'translations' => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'object', 'id', 'language', 'translations' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'status', 'get', 'missing', 'strings' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'translate' !== $this->mode,
			'idempotentHint'  => 'translate' !== $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'status', 'get', 'missing', 'strings' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$kind = (string) ( $input['object'] ?? 'post' );
		$id   = (int) ( $input['id'] ?? 0 );
		if ( in_array( $this->mode, array( 'get', 'set' ), true ) && $id > 0 && in_array( $kind, Languages::OBJECTS, true ) ) {
			return Languages::capability( $kind, $id, 'set' === $this->mode );
		}
		if ( 'translate' === $this->mode && $id > 0 ) {
			// Plus the post type's create capability, checked in execute().
			return array( 'edit_post', $id );
		}
		if ( 'string-save' === $this->mode || 'strings' === $this->mode ) {
			// Polylang's Strings screen is for site managers.
			return 'manage_options';
		}
		// Link: edit permission on every object is checked in execute(); missing lists only readable items.
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( in_array( $this->mode, array( 'strings', 'string-save' ), true ) ) {
			return 'polylang' === Languages::plugin() && class_exists( 'PLL_MO' ) && class_exists( 'PLL_Admin_Strings' ) ? true : 'Polylang is not active (string translation here is Polylang\'s)';
		}
		return Languages::available();
	}

	/**
	 * Objects of a link request, checked.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: string, 1: array<string, int>}
	 * @throws ToolError When an object, its language or the permission does not fit.
	 */
	private static function link_request( array $input ): array {
		$kind = (string) ( $input['object'] ?? 'post' );
		$map  = array();
		foreach ( (array) $input['translations'] as $lang => $id ) {
			$lang = (string) $lang;
			$id   = (int) $id;
			Languages::assert_language( $lang );
			Languages::assert_object( $kind, $id );
			if ( ! current_user_can( ...Languages::capability( $kind, $id, true ) ) ) {
				throw new ToolError( 'Your account cannot edit ' . esc_html( $kind ) . ' ' . (int) $id . '. Nothing was linked.' );
			}
			$has = Languages::language( $kind, $id );
			if ( $has !== $lang ) {
				throw new ToolError( esc_html( ucfirst( $kind ) ) . ' ' . (int) $id . ' is in ' . esc_html( $has ?? 'no language' ) . ', not ' . esc_html( $lang ) . ' (set it with kuduy-lang-set first). Nothing was linked.' );
			}
			$map[ $lang ] = $id;
		}
		if ( count( array_unique( $map ) ) !== count( $map ) ) {
			throw new ToolError( 'The same object is listed for two languages. Nothing was linked.' );
		}
		return array( $kind, $map );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request does not fit.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'set' === $this->mode ) {
			$kind = (string) ( $input['object'] ?? 'post' );
			$id   = (int) $input['id'];
			Languages::assert_object( $kind, $id );
			Languages::assert_language( (string) $input['language'] );
			return array(
				'object_type' => $kind,
				'object_id'   => (string) $id,
				'payload'     => array(
					'object' => $kind,
					'groups' => array( $id => Languages::translations( $kind, $id ) ),
					'langs'  => array( $id => Languages::language( $kind, $id ) ),
				),
			);
		}
		if ( 'string-save' === $this->mode ) {
			$row = self::find_string( (string) $input['string'] );
			Languages::assert_language( (string) $input['language'] );
			return array(
				'object_type' => 'pll_string',
				'object_id'   => (string) $input['language'],
				'payload'     => array(
					'string'      => $row['string'],
					'language'    => (string) $input['language'],
					'translation' => (string) ( $row['translations'][ (string) $input['language'] ] ?? '' ),
				),
			);
		}
		if ( 'link' === $this->mode ) {
			[ $kind, $map ] = self::link_request( $input );
			$groups         = array();
			$langs          = array();
			foreach ( $map as $id ) {
				$groups[ $id ] = Languages::translations( $kind, $id );
				$langs[ $id ]  = Languages::language( $kind, $id );
			}
			return array(
				'object_type' => $kind,
				'object_id'   => (string) reset( $map ),
				'payload'     => array(
					'object' => $kind,
					'groups' => $groups,
					'langs'  => $langs,
				),
			);
		}
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		if ( isset( $payload['string'] ) ) {
			self::store_string( (string) $payload['string'], (string) $payload['language'], (string) $payload['translation'] );
			return;
		}
		$kind  = (string) ( $payload['object'] ?? 'post' );
		$langs = (array) ( $payload['langs'] ?? array() );
		foreach ( $langs as $id => $lang ) {
			if ( ! is_string( $lang ) || '' === $lang ) {
				// The object had no language before: take it out of any group and remove its language
				// (found on staging 2026-11-10: undo used to leave the new language in place).
				Languages::clear_language( $kind, (int) $id );
			} elseif ( Languages::language( $kind, (int) $id ) !== $lang ) {
				Languages::set_language( $kind, (int) $id, $lang );
			}
		}
		foreach ( (array) ( $payload['groups'] ?? array() ) as $id => $group ) {
			$lang = $langs[ $id ] ?? null;
			if ( ! is_string( $lang ) || '' === $lang ) {
				continue;
			}
			$group = array_map( 'intval', (array) $group );
			// A group of one means the object was not linked: save it alone, which unlinks it.
			Languages::save_group( $kind, count( $group ) > 1 ? $group : array( $lang => (int) $id ) );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the request does not fit or WordPress refuses.
	 */
	public function execute( array $input ): array {
		if ( 'status' === $this->mode ) {
			$plugin = (string) Languages::plugin();
			$const  = 'wpml' === $plugin ? 'ICL_SITEPRESS_VERSION' : 'POLYLANG_VERSION';
			return array(
				'plugin'     => $plugin,
				'version'    => defined( $const ) ? (string) constant( $const ) : '',
				'languages'  => Languages::details(),
				'post_types' => array_values( array_filter( array_keys( Content::post_types() ), array( Languages::class, 'translates_post_type' ) ) ),
				'taxonomies' => array_values( array_filter( get_taxonomies( array( 'show_ui' => true ) ), array( Languages::class, 'translates_taxonomy' ) ) ),
			);
		}
		if ( 'missing' === $this->mode ) {
			return self::missing( $input );
		}
		if ( 'strings' === $this->mode ) {
			$rows = self::strings();
			if ( isset( $input['group'] ) ) {
				$rows = array_values( array_filter( $rows, static fn( $r ) => $r['group'] === (string) $input['group'] ) );
			}
			if ( isset( $input['search'] ) && '' !== (string) $input['search'] ) {
				$rows = array_values( array_filter( $rows, static fn( $r ) => false !== stripos( $r['name'] . ' ' . $r['string'], (string) $input['search'] ) ) );
			}
			return array(
				'languages' => Languages::languages(),
				'strings'   => array_slice( $rows, 0, 200 ),
			);
		}
		if ( 'string-save' === $this->mode ) {
			$row  = self::find_string( (string) $input['string'] );
			$lang = (string) $input['language'];
			Languages::assert_language( $lang );
			AuditLog::target( 'pll_string', $lang );
			$text = (string) apply_filters( 'pll_sanitize_string_translation', trim( (string) $input['translation'] ), $row['name'], $row['group'], $row['string'], (string) ( $row['translations'][ $lang ] ?? '' ) );
			self::store_string( $row['string'], $lang, $text );
			return array( 'string' => self::find_string( $row['string'] ) );
		}
		if ( 'link' === $this->mode ) {
			[ $kind, $map ] = self::link_request( $input );
			AuditLog::target( $kind, (string) reset( $map ) );
			Languages::save_group( $kind, $map );
			$first = (int) reset( $map );
			return array(
				'object'       => $kind,
				'translations' => Languages::describe( $kind, $first )['translations'],
			);
		}
		$kind = 'translate' === $this->mode ? 'post' : (string) ( $input['object'] ?? 'post' );
		$id   = (int) $input['id'];
		Languages::assert_object( $kind, $id );
		AuditLog::target( $kind, (string) $id );
		if ( 'get' === $this->mode ) {
			return Languages::describe( $kind, $id );
		}
		$lang = (string) $input['language'];
		Languages::assert_language( $lang );
		if ( 'set' === $this->mode ) {
			Languages::set_language( $kind, $id, $lang );
			return Languages::describe( $kind, $id );
		}
		return self::translate( $id, $lang, $input );
	}

	/**
	 * Create a translation draft and link it.
	 *
	 * @param int                  $source Original post.
	 * @param string               $lang   Target language.
	 * @param array<string, mixed> $input  Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the translation exists, the user cannot create posts, or WordPress refuses.
	 */
	private static function translate( int $source, string $lang, array $input ): array {
		$post  = get_post( $source );
		$group = Languages::translations( 'post', $source );
		if ( null === Languages::language( 'post', $source ) ) {
			throw new ToolError( 'The original has no language yet; set it with kuduy-lang-set first.' );
		}
		if ( isset( $group[ $lang ] ) ) {
			throw new ToolError( 'There is already a ' . esc_html( $lang ) . ' translation: post ' . (int) $group[ $lang ] . '. Change it with kuduy-post-update.' );
		}
		$type = Content::post_type( (string) $post->post_type );
		if ( ! current_user_can( $type->cap->create_posts ) ) {
			throw new ToolError( 'Your account cannot create ' . esc_html( $type->labels->name ) . '.' );
		}
		Content::assert_writable( $type->name );
		$new = Languages::insert_post(
			array(
				'post_type'    => $type->name,
				'post_status'  => 'draft',
				'post_title'   => wp_slash( (string) $input['title'] ),
				'post_content' => wp_slash( (string) ( $input['content'] ?? '' ) ),
				'post_excerpt' => wp_slash( (string) ( $input['excerpt'] ?? '' ) ),
				'post_parent'  => (int) $post->post_parent,
			),
			$lang
		);
		if ( is_wp_error( $new ) || ! $new ) {
			throw new ToolError( 'WordPress did not create the translation' . ( is_wp_error( $new ) ? ': ' . esc_html( $new->get_error_message() ) : '.' ) );
		}
		$group[ $lang ] = (int) $new;
		Languages::save_group( 'post', $group );
		return Languages::describe( 'post', (int) $new );
	}

	/**
	 * Content without a translation in one or every other language.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the post type is not translated.
	 */
	private static function missing( array $input ): array {
		$type = (string) ( $input['post_type'] ?? 'post' );
		if ( ! post_type_exists( $type ) || ! Languages::translates_post_type( $type ) ) {
			throw new ToolError( esc_html( Languages::name() ) . ' does not translate the post type "' . esc_html( $type ) . '".' );
		}
		$all    = Languages::languages();
		$wanted = isset( $input['language'] ) ? array( (string) $input['language'] ) : $all;
		foreach ( $wanted as $lang ) {
			Languages::assert_language( $lang );
		}
		$limit = (int) ( $input['limit'] ?? 50 );
		$ids   = get_posts(
			array(
				'post_type'        => $type,
				'post_status'      => array( 'publish', 'draft', 'pending', 'private', 'future' ),
				'posts_per_page'   => 500, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- ids only, newest 500.
				'orderby'          => 'date',
				'order'            => 'DESC',
				'fields'           => 'ids',
				'suppress_filters' => true,
				'lang'             => '',
			)
		);
		$items = array();
		$seen  = array();
		foreach ( $ids as $id ) {
			$id = (int) $id;
			if ( isset( $seen[ $id ] ) || ! current_user_can( 'read_post', $id ) ) {
				continue;
			}
			$group = Languages::translations( 'post', $id );
			foreach ( $group as $member ) {
				$seen[ $member ] = true;
			}
			$lang    = Languages::language( 'post', $id );
			$missing = array_values( array_diff( $wanted, array_keys( $group ), null === $lang ? array() : array( $lang ) ) );
			if ( $missing ) {
				$items[] = array(
					'id'       => $id,
					'title'    => get_the_title( $id ),
					'status'   => (string) get_post_status( $id ),
					'language' => $lang,
					'missing'  => $missing,
				);
			}
			if ( count( $items ) >= $limit ) {
				break;
			}
		}//end foreach
		return array(
			'items'     => $items,
			'scanned'   => count( $ids ),
			'languages' => $all,
		);
	}

	/**
	 * Polylang's registered strings with their translations.
	 *
	 * @return list<array{name: string, group: string, string: string, multiline: bool, translations: array<string, string>}>
	 */
	private static function strings(): array {
		$mos = array();
		foreach ( Languages::languages() as $slug ) {
			$language = PLL()->model->get_language( $slug );
			if ( ! $language ) {
				continue;
			}
			$mo = new \PLL_MO();
			$mo->import_from_db( $language );
			$mos[ $slug ] = $mo;
		}
		$out = array();
		foreach ( (array) \PLL_Admin_Strings::get_strings() as $row ) {
			$original     = (string) ( $row['string'] ?? '' );
			$translations = array();
			foreach ( $mos as $slug => $mo ) {
				$text = (string) $mo->translate_if_any( $original );
				if ( '' !== $text && $text !== $original ) {
					$translations[ $slug ] = $text;
				}
			}
			$out[] = array(
				'name'         => (string) ( $row['name'] ?? '' ),
				'group'        => (string) ( $row['context'] ?? '' ),
				'string'       => $original,
				'multiline'    => ! empty( $row['multiline'] ),
				'translations' => $translations,
			);
		}
		return $out;
	}

	/**
	 * One registered string by its original text.
	 *
	 * @param string $original Original text.
	 * @return array{name: string, group: string, string: string, multiline: bool, translations: array<string, string>}
	 * @throws ToolError When no string has that text.
	 */
	private static function find_string( string $original ): array {
		foreach ( self::strings() as $row ) {
			if ( $row['string'] === $original ) {
				return $row;
			}
		}
		throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Polylang has no registered string with that exact text (see kuduy-lang-strings).' );
	}

	/**
	 * Store one translation in Polylang's PLL_MO for a language ('' removes it).
	 *
	 * @param string $original    Original text.
	 * @param string $lang        Language slug.
	 * @param string $translation Translation.
	 * @throws ToolError When the language is unknown.
	 */
	private static function store_string( string $original, string $lang, string $translation ): void {
		$language = PLL()->model->get_language( $lang );
		if ( ! $language ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Polylang has no language "' . $lang . '".' );
		}
		$mo = new \PLL_MO();
		$mo->import_from_db( $language );
		if ( '' === $translation ) {
			$mo->delete_entry( $original );
		} else {
			$mo->add_entry( $mo->make_entry( $original, $translation ) );
		}
		$mo->export_to_db( $language );
	}
}
