<?php
/**
 * Languages and translation groups through Polylang's or WPML's public API (decisions D20 A, D60).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Multilingual;

// phpcs:disable WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own documented hooks.
// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\ToolError;

/**
 * Languages and translation groups of posts and terms, with Polylang's documented pll_* functions (RESEARCH.md §10e)
 * or WPML's documented hooks (read on WPML 5.3.0, sitepress.class.php): wpml_active_languages, wpml_default_language,
 * wpml_is_translated_post_type / _taxonomy, wpml_element_trid, wpml_get_element_translations and the action
 * wpml_set_element_language_details, with WPML element types "post_{type}" and "tax_{taxonomy}" (terms by
 * term_taxonomy_id). Polylang is used when both are active (the owner never runs both). Access follows the object:
 * edit_post / edit_term to change a language or a translation group, read_post / edit_term to read one. The language
 * list itself stays in the plugin's settings.
 */
final class Languages {

	public const OBJECTS = array( 'post', 'term' );

	/**
	 * The active plugin: polylang, wpml or null.
	 */
	public static function plugin(): ?string {
		if ( function_exists( 'pll_languages_list' ) && function_exists( 'pll_save_post_translations' ) ) {
			return 'polylang';
		}
		if ( defined( 'ICL_SITEPRESS_VERSION' ) && has_filter( 'wpml_element_trid' ) && has_action( 'wpml_set_element_language_details' ) ) {
			return 'wpml';
		}
		return null;
	}

	/**
	 * Availability.
	 */
	public static function available(): bool|string {
		return null !== self::plugin() ? true : 'Neither Polylang nor WPML is active';
	}

	/**
	 * Plugin name for messages.
	 */
	public static function name(): string {
		return 'wpml' === self::plugin() ? 'WPML' : 'Polylang';
	}

	/**
	 * Language slugs (Polylang) or codes (WPML).
	 *
	 * @return list<string>
	 */
	public static function languages(): array {
		if ( 'wpml' === self::plugin() ) {
			return array_map( 'strval', array_keys( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) ) );
		}
		return array_values( array_map( 'strval', (array) pll_languages_list( array( 'fields' => 'slug' ) ) ) );
	}

	/**
	 * Default language.
	 */
	public static function default_language(): string {
		return 'wpml' === self::plugin() ? (string) apply_filters( 'wpml_default_language', null ) : (string) pll_default_language();
	}

	/**
	 * Languages with their details, for status.
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function details(): array {
		$default = self::default_language();
		$out     = array();
		if ( 'wpml' === self::plugin() ) {
			foreach ( (array) apply_filters( 'wpml_active_languages', null, array( 'skip_missing' => 0 ) ) as $code => $row ) {
				$row   = (array) $row;
				$out[] = array(
					'slug'    => (string) $code,
					'name'    => (string) ( $row['translated_name'] ?? $row['native_name'] ?? $code ),
					'locale'  => (string) ( $row['default_locale'] ?? '' ),
					'default' => $default === (string) $code,
				);
			}
			return $out;
		}
		foreach ( (array) pll_languages_list( array( 'fields' => '' ) ) as $language ) {
			$out[] = array(
				'slug'    => (string) $language->slug,
				'name'    => (string) $language->name,
				'locale'  => (string) $language->locale,
				'default' => $default === (string) $language->slug,
				'posts'   => (int) pll_count_posts( (string) $language->slug ),
			);
		}
		return $out;
	}

	/**
	 * Whether the plugin translates a post type.
	 *
	 * @param string $type Post type.
	 */
	public static function translates_post_type( string $type ): bool {
		return 'wpml' === self::plugin() ? (bool) apply_filters( 'wpml_is_translated_post_type', null, $type ) : (bool) pll_is_translated_post_type( $type );
	}

	/**
	 * Whether the plugin translates a taxonomy.
	 *
	 * @param string $taxonomy Taxonomy.
	 */
	public static function translates_taxonomy( string $taxonomy ): bool {
		return 'wpml' === self::plugin() ? (bool) apply_filters( 'wpml_is_translated_taxonomy', null, $taxonomy ) : (bool) pll_is_translated_taxonomy( $taxonomy );
	}

	/**
	 * Refuse an unknown language.
	 *
	 * @param string $lang Language slug.
	 * @throws ToolError When the plugin has no such language.
	 */
	public static function assert_language( string $lang ): void {
		if ( ! in_array( $lang, self::languages(), true ) ) {
			throw new ToolError( self::name() . ' has no language "' . esc_html( $lang ) . '". Languages: ' . esc_html( implode( ', ', self::languages() ) ) . '.' );
		}
	}

	/**
	 * Whether the object exists and the plugin translates its type.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @throws ToolError When it does not exist or is not translated.
	 */
	public static function assert_object( string $kind, int $id ): void {
		if ( 'post' === $kind ) {
			$post = get_post( $id );
			if ( ! $post ) {
				throw new ToolError( 'No post with id ' . (int) $id . '.' );
			}
			if ( ! self::translates_post_type( $post->post_type ) ) {
				throw new ToolError( esc_html( self::name() ) . ' does not translate the post type "' . esc_html( $post->post_type ) . '" (see its settings).' );
			}
			return;
		}
		$term = get_term( $id );
		if ( ! $term instanceof \WP_Term ) {
			throw new ToolError( 'No term with id ' . (int) $id . '.' );
		}
		if ( ! self::translates_taxonomy( $term->taxonomy ) ) {
			throw new ToolError( esc_html( self::name() ) . ' does not translate the taxonomy "' . esc_html( $term->taxonomy ) . '".' );
		}
	}

	/**
	 * Capability to read or change an object.
	 *
	 * @param string $kind  post | term.
	 * @param int    $id    Id.
	 * @param bool   $write Change.
	 * @return array{0: string, 1: int}
	 */
	public static function capability( string $kind, int $id, bool $write ): array {
		if ( 'term' === $kind ) {
			return array( 'edit_term', $id );
		}
		return array( $write ? 'edit_post' : 'read_post', $id );
	}

	/**
	 * WPML element type and element id of an object (terms by term_taxonomy_id).
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @return array{0: string, 1: int}
	 */
	private static function element( string $kind, int $id ): array {
		if ( 'post' === $kind ) {
			return array( 'post_' . (string) get_post_type( $id ), $id );
		}
		$term = get_term( $id );
		return $term instanceof \WP_Term ? array( 'tax_' . $term->taxonomy, (int) $term->term_taxonomy_id ) : array( 'tax_', 0 );
	}

	/**
	 * WPML translation group id of an object, or null.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 */
	private static function trid( string $kind, int $id ): ?int {
		[ $type, $element ] = self::element( $kind, $id );
		$trid               = apply_filters( 'wpml_element_trid', null, $element, $type );
		return is_numeric( $trid ) && (int) $trid > 0 ? (int) $trid : null;
	}

	/**
	 * Object id from a WPML element id.
	 *
	 * @param string $kind    post | term.
	 * @param int    $element Element id.
	 */
	private static function object_id( string $kind, int $element ): int {
		if ( 'post' === $kind ) {
			return $element;
		}
		$term = get_term_by( 'term_taxonomy_id', $element );
		return $term instanceof \WP_Term ? (int) $term->term_id : 0;
	}

	/**
	 * Language of an object, or null.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 */
	public static function language( string $kind, int $id ): ?string {
		if ( 'wpml' === self::plugin() ) {
			$found = array_search( $id, self::translations( $kind, $id ), true );
			return false === $found ? null : (string) $found;
		}
		$lang = 'post' === $kind ? pll_get_post_language( $id ) : pll_get_term_language( $id );
		return is_string( $lang ) && '' !== $lang ? $lang : null;
	}

	/**
	 * Translation group of an object: language => id (includes the object itself).
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @return array<string, int>
	 */
	public static function translations( string $kind, int $id ): array {
		if ( 'wpml' === self::plugin() ) {
			$trid = self::trid( $kind, $id );
			if ( null === $trid ) {
				return array();
			}
			$out = array();
			foreach ( (array) apply_filters( 'wpml_get_element_translations', null, $trid, self::element( $kind, $id )[0] ) as $lang => $row ) {
				$element = (int) ( is_object( $row ) ? ( $row->element_id ?? 0 ) : ( $row['element_id'] ?? 0 ) );
				if ( $element > 0 ) {
					$out[ (string) $lang ] = self::object_id( $kind, $element );
				}
			}
			return array_filter( $out );
		}
		$map = 'post' === $kind ? pll_get_post_translations( $id ) : pll_get_term_translations( $id );
		return array_map( 'intval', (array) $map );
	}

	/**
	 * WPML: put an element into a group in a language.
	 *
	 * @param string      $kind   post | term.
	 * @param int         $id     Id.
	 * @param int|null    $trid   Group (null = a group of its own).
	 * @param string      $lang   Language.
	 * @param string|null $source Source language (null for an original).
	 */
	private static function wpml_set( string $kind, int $id, ?int $trid, string $lang, ?string $source ): void {
		[ $type, $element ] = self::element( $kind, $id );
		do_action(
			'wpml_set_element_language_details',
			array(
				'element_id'           => $element,
				'element_type'         => $type,
				'trid'                 => $trid,
				'language_code'        => $lang,
				'source_language_code' => $source,
			)
		);
	}

	/**
	 * Set an object's language.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @param string $lang Language slug.
	 */
	public static function set_language( string $kind, int $id, string $lang ): void {
		if ( 'wpml' === self::plugin() ) {
			$group = self::translations( $kind, $id );
			$trid  = self::trid( $kind, $id );
			// Another object of the group already has that language: the object leaves the group, as with Polylang.
			if ( isset( $group[ $lang ] ) && $group[ $lang ] !== $id ) {
				$trid = null;
			}
			self::wpml_set( $kind, $id, $trid, $lang, null );
			return;
		}
		if ( 'post' === $kind ) {
			pll_set_post_language( $id, $lang );
		} else {
			pll_set_term_language( $id, $lang );
		}
	}

	/**
	 * Take an object out of its translation group and remove its language, so it is back to "no language" (used by
	 * undo when the object had none): Polylang's own model methods, WPML's SitePress::delete_element_translation().
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 */
	public static function clear_language( string $kind, int $id ): void {
		if ( 'wpml' === self::plugin() ) {
			global $sitepress;
			$lang = self::language( $kind, $id );
			$trid = self::trid( $kind, $id );
			if ( null !== $lang && null !== $trid && is_object( $sitepress ) && method_exists( $sitepress, 'delete_element_translation' ) ) {
				$sitepress->delete_element_translation( (string) $trid, self::element( $kind, $id )[0], $lang );
			}
			return;
		}
		$model = 'post' === $kind ? PLL()->model->post : PLL()->model->term;
		$model->delete_translation( $id );
		$model->delete_language( $id );
	}

	/**
	 * Save a translation group: exactly these objects, each in its language.
	 *
	 * @param string             $kind post | term.
	 * @param array<string, int> $map  Language => id.
	 */
	public static function save_group( string $kind, array $map ): void {
		if ( 'wpml' === self::plugin() ) {
			$first_lang = (string) array_key_first( $map );
			$first      = (int) $map[ $first_lang ];
			$old        = self::translations( $kind, $first );
			$trid       = self::trid( $kind, $first );
			// Objects of the first one's group that are not in the map leave it, as Polylang's save does.
			foreach ( $old as $lang => $other ) {
				if ( ! in_array( $other, $map, true ) ) {
					self::wpml_set( $kind, $other, null, (string) $lang, null );
				}
			}
			if ( null === $trid ) {
				self::wpml_set( $kind, $first, null, $first_lang, null );
				$trid = self::trid( $kind, $first );
			}
			foreach ( $map as $lang => $id ) {
				if ( (int) $id !== $first ) {
					self::wpml_set( $kind, (int) $id, $trid, (string) $lang, $first_lang );
				}
			}
			if ( 1 === count( $map ) ) {
				self::wpml_set( $kind, $first, null, $first_lang, null );
			}
			return;
		}//end if
		if ( 'post' === $kind ) {
			pll_save_post_translations( $map );
		} else {
			pll_save_term_translations( $map );
		}
	}

	/**
	 * Insert a post in a language.
	 *
	 * @param array<string, mixed> $postarr Post data (slashed).
	 * @param string               $lang    Language.
	 * @return int|\WP_Error
	 */
	public static function insert_post( array $postarr, string $lang ): int|\WP_Error {
		if ( 'wpml' === self::plugin() ) {
			$id = wp_insert_post( $postarr, true );
			if ( ! is_wp_error( $id ) ) {
				self::wpml_set( 'post', (int) $id, null, $lang, null );
			}
			return $id;
		}
		return pll_insert_post( $postarr, $lang );
	}

	/**
	 * One object with its language and translations, for results.
	 *
	 * @param string $kind post | term.
	 * @param int    $id   Id.
	 * @return array<string, mixed>
	 */
	public static function describe( string $kind, int $id ): array {
		$translations = array();
		foreach ( self::translations( $kind, $id ) as $lang => $tid ) {
			if ( 'post' === $kind ) {
				$readable = current_user_can( 'read_post', $tid );
				$title    = $readable ? get_the_title( $tid ) : '';
				$status   = $readable ? (string) get_post_status( $tid ) : '';
			} else {
				$term     = get_term( $tid );
				$readable = $term instanceof \WP_Term;
				$title    = $readable ? $term->name : '';
				$status   = '';
			}
			$translations[ $lang ] = array_filter(
				array(
					'id'     => $tid,
					'title'  => $readable ? $title : null,
					'status' => '' !== $status ? $status : null,
				),
				static fn( $v ) => null !== $v
			);
		}
		return array(
			'object'       => $kind,
			'id'           => $id,
			'language'     => self::language( $kind, $id ),
			'translations' => (object) $translations,
		);
	}
}
