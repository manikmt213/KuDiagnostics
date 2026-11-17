<?php
/**
 * Tools: kuduy-{yoast,rank-math,seopress}-term-seo, -term-seo-update, -settings.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Blocks\CoreRest;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * SEO of categories, tags and other taxonomy terms, and each plugin's title templates and archive settings (phase
 * G2, decision D43: one tool set per SEO plugin). Through each plugin's own API, read from its source:
 *
 * - Yoast SEO 28.8: WPSEO_Taxonomy_Meta::get_term_meta() / set_values() (its own validation), then its term
 *   indexable is rebuilt (Indexable_Term_Watcher::build_indexable()). Settings: WPSEO_Options.
 * - Rank Math 1.0.281: its editor's REST route `rankmath/v1/updateMeta` with objectType "term" (its sanitizer and
 *   permission check; empty values delete). Settings: the `rank-math-options-titles` option.
 * - SEOPress 10.4: its abilities seopress/get-|update-term-title-description, -robots-settings, -social-settings.
 *   Settings: the `seopress_titles_option_name` option.
 *
 * All in One SEO keeps term SEO only in its Pro edition, so it has no term tools here.
 */
final class SeoTerms implements Tool, Snapshottable {

	/**
	 * Plugins: slug => display name.
	 */
	public const PLUGINS = array(
		'yoast'     => 'Yoast SEO',
		'rank-math' => 'Rank Math',
		'seopress'  => 'SEOPress',
	);

	/**
	 * Yoast term meta keys per field.
	 */
	private const YOAST = array(
		'title'               => 'wpseo_title',
		'description'         => 'wpseo_desc',
		'focus_keyword'       => 'wpseo_focuskw',
		'canonical'           => 'wpseo_canonical',
		'og.title'            => 'wpseo_opengraph-title',
		'og.description'      => 'wpseo_opengraph-description',
		'twitter.title'       => 'wpseo_twitter-title',
		'twitter.description' => 'wpseo_twitter-description',
	);

	/**
	 * Rank Math term meta keys per field (the same keys as for posts).
	 */
	private const RANK_MATH = array(
		'title'               => 'rank_math_title',
		'description'         => 'rank_math_description',
		'focus_keyword'       => 'rank_math_focus_keyword',
		'canonical'           => 'rank_math_canonical_url',
		'og.title'            => 'rank_math_facebook_title',
		'og.description'      => 'rank_math_facebook_description',
		'twitter.title'       => 'rank_math_twitter_title',
		'twitter.description' => 'rank_math_twitter_description',
	);

	/**
	 * SEOPress term keys per field, by ability group.
	 */
	private const SEOPRESS_SOCIAL = array(
		'og.title'            => '_seopress_social_fb_title',
		'og.description'      => '_seopress_social_fb_desc',
		'twitter.title'       => '_seopress_social_twitter_title',
		'twitter.description' => '_seopress_social_twitter_desc',
	);

	/**
	 * Plugin and mode.
	 *
	 * @param string $plugin yoast | rank-math | seopress.
	 * @param string $mode   term-seo | term-seo-update | settings.
	 */
	public function __construct( private string $plugin, private string $mode ) {}

	/**
	 * Display name.
	 */
	private function label(): string {
		return self::PLUGINS[ $this->plugin ];
	}

	/**
	 * Fields this plugin keeps per term.
	 *
	 * @return list<string>
	 */
	private function fields(): array {
		return match ( $this->plugin ) {
			'yoast'     => array( 'title', 'description', 'focus_keyword', 'canonical', 'robots.noindex', 'og.title', 'og.description', 'twitter.title', 'twitter.description' ),
			'rank-math' => array( 'title', 'description', 'focus_keyword', 'canonical', 'robots.noindex', 'robots.nofollow', 'og.title', 'og.description', 'twitter.title', 'twitter.description' ),
			default     => array( 'title', 'description', 'canonical', 'robots.noindex', 'robots.nofollow', 'og.title', 'og.description', 'twitter.title', 'twitter.description' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->plugin . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			/* translators: %s: SEO plugin name. */
			'term-seo'        => sprintf( __( 'Read %s data of a category or tag', 'kuduy-mcp' ), $this->label() ),
			/* translators: %s: SEO plugin name. */
			'term-seo-update' => sprintf( __( 'Change %s data of a category or tag', 'kuduy-mcp' ), $this->label() ),
			/* translators: %s: SEO plugin name. */
			default           => sprintf( __( '%s title templates and archive settings', 'kuduy-mcp' ), $this->label() ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$b      = $this->label();
		$fields = implode( ', ', $this->fields() );
		return match ( $this->mode ) {
			'term-seo'        => 'Returns the ' . $b . ' data stored for one category, tag or other taxonomy term (term_id from kuduy-term-list): ' . $fields . '. null means not set (the template from kuduy-' . $this->plugin . '-settings applies). robots.noindex: true = hidden from search engines, ' . ( 'seopress' === $this->plugin ? 'null = the taxonomy default (SEOPress has no "force visible" per term).' : 'false = forced visible, null = the taxonomy default.' ),
			'term-seo-update' => 'Changes ' . $b . ' data of one category, tag or other taxonomy term through ' . $b . '\'s own save path; only the fields you send change: ' . $fields . '. null clears a field (the template applies again). robots.noindex true/false/null as in kuduy-' . $this->plugin . '-term-seo. Snapshot first; undo with kuduy-snapshot-restore.',
			default           => 'Returns ' . $b . '\'s site-wide title and description templates (per post type, taxonomy and archive), the title separator, and which archives are hidden from search engines, as stored by ' . $b . '. Read only here: change them in ' . $b . '\'s settings. Per-post and per-term values: kuduy-seo-get and kuduy-' . $this->plugin . '-term-seo.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'settings' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			);
		}
		$properties = array(
			'term_id'  => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'taxonomy' => array(
				'type'      => 'string',
				'maxLength' => 32,
			),
		);
		if ( 'term-seo-update' === $this->mode ) {
			$fields = array();
			foreach ( $this->fields() as $field ) {
				$fields[ $field ] = str_starts_with( $field, 'robots.' )
					? array( 'type' => array( 'boolean', 'null' ) )
					: array(
						'type'      => array( 'string', 'null' ),
						'maxLength' => 'canonical' === $field ? 2000 : 1000,
					);
			}
			$properties['seo'] = array(
				'type'                 => 'object',
				'properties'           => $fields,
				'minProperties'        => 1,
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => 'term-seo-update' === $this->mode ? array( 'term_id', 'seo' ) : array( 'term_id' ),
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
				'plugin'       => array( 'type' => 'string' ),
				'term_id'      => array( 'type' => 'integer' ),
				'taxonomy'     => array( 'type' => 'string' ),
				'name'         => array( 'type' => 'string' ),
				'seo'          => array( 'type' => 'object' ),
				'settings'     => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'plugin' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return 'term-seo-update' === $this->mode ? array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => true,
			'openWorldHint'   => false,
		) : Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'term-seo-update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		if ( 'settings' === $this->mode ) {
			return 'edit_posts';
		}
		$id = (int) ( $input['term_id'] ?? 0 );
		return $id > 0 && get_term( $id ) instanceof \WP_Term ? array( 'edit_term', $id ) : 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$available = Seo::available();
		if ( true !== $available ) {
			return $available;
		}
		return Seo::adapter()->slug() === $this->plugin ? true : $this->label() . ' is not the active SEO plugin on this site.';
	}

	/**
	 * The term, checked against the given taxonomy.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When there is no such term.
	 */
	private static function term( array $input ): \WP_Term {
		$term = get_term( (int) $input['term_id'] );
		if ( ! $term instanceof \WP_Term || ( isset( $input['taxonomy'] ) && $input['taxonomy'] !== $term->taxonomy ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No term ' . (int) $input['term_id'] . ( isset( $input['taxonomy'] ) ? ' in ' . $input['taxonomy'] : '' ) . ' (see kuduy-term-list).' );
		}
		return $term;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'term-seo-update' !== $this->mode ) {
			return null;
		}
		$term = get_term( (int) $input['term_id'] );
		if ( ! $term instanceof \WP_Term ) {
			return null;
		}
		return array(
			'object_type' => 'term',
			'object_id'   => (string) $term->term_id,
			'payload'     => array(
				'term_id'  => $term->term_id,
				'taxonomy' => $term->taxonomy,
				'raw'      => $this->raw( $term ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the term is gone.
	 */
	public function restore( array $payload ): void {
		$term = get_term( (int) $payload['term_id'] );
		if ( ! $term instanceof \WP_Term ) {
			throw new ToolError( 'The term of this snapshot no longer exists.' );
		}
		$raw = (array) $payload['raw'];
		switch ( $this->plugin ) {
			case 'yoast':
				\WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $raw );
				self::yoast_rebuild( $term->term_id );
				break;
			case 'rank-math':
				foreach ( array_keys( (array) get_term_meta( $term->term_id ) ) as $key ) {
					if ( str_starts_with( (string) $key, 'rank_math_' ) && ! array_key_exists( (string) $key, $raw ) ) {
						delete_term_meta( $term->term_id, (string) $key );
					}
				}
				foreach ( $raw as $key => $value ) {
					update_term_meta( $term->term_id, (string) $key, $value );
				}
				break;
			default:
				$this->seopress_write( $term, self::from_seopress( $raw ), true );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the term is unknown or the plugin refuses.
	 */
	public function execute( array $input ): array {
		if ( 'settings' === $this->mode ) {
			return array(
				'plugin'   => $this->plugin,
				'settings' => (object) $this->settings(),
			);
		}
		$term = self::term( $input );
		AuditLog::target( 'term', (string) $term->term_id );
		if ( 'term-seo-update' === $this->mode ) {
			$this->write( $term, (array) $input['seo'] );
		}
		return array(
			'plugin'   => $this->plugin,
			'term_id'  => $term->term_id,
			'taxonomy' => $term->taxonomy,
			'name'     => $term->name,
			'seo'      => (object) $this->read( $term ),
		);
	}

	/**
	 * Raw stored values (for undo).
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	private function raw( \WP_Term $term ): array {
		return match ( $this->plugin ) {
			'yoast'     => (array) \WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy ),
			'rank-math' => array_filter(
				array_map( static fn( $values ) => maybe_unserialize( is_array( $values ) ? ( $values[0] ?? '' ) : $values ), (array) get_term_meta( $term->term_id ) ),
				static fn( $key ) => str_starts_with( (string) $key, 'rank_math_' ),
				ARRAY_FILTER_USE_KEY
			),
			default     => $this->seopress_raw( $term ),
		};
	}

	/**
	 * Values in the tool's field shape.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, mixed>
	 */
	private function read( \WP_Term $term ): array {
		$raw = $this->raw( $term );
		$out = array();
		if ( 'yoast' === $this->plugin ) {
			foreach ( self::YOAST as $field => $key ) {
				$out[ $field ] = Seo::text( $raw[ $key ] ?? '' );
			}
			$noindex               = (string) ( $raw['wpseo_noindex'] ?? 'default' );
			$out['robots.noindex'] = 'noindex' === $noindex ? true : ( 'index' === $noindex ? false : null );
			return $out;
		}
		if ( 'rank-math' === $this->plugin ) {
			foreach ( self::RANK_MATH as $field => $key ) {
				$out[ $field ] = Seo::text( is_scalar( $raw[ $key ] ?? null ) ? $raw[ $key ] : '' );
			}
			$robots                 = array_map( 'strval', (array) ( $raw['rank_math_robots'] ?? array() ) );
			$out['robots.noindex']  = in_array( 'noindex', $robots, true ) ? true : ( in_array( 'index', $robots, true ) ? false : null );
			$out['robots.nofollow'] = in_array( 'nofollow', $robots, true );
			return $out;
		}
		return self::from_seopress( $raw );
	}

	/**
	 * Write a patch through the plugin.
	 *
	 * @param \WP_Term             $term  Term.
	 * @param array<string, mixed> $patch Fields.
	 * @throws ToolError When the plugin refuses.
	 */
	private function write( \WP_Term $term, array $patch ): void {
		if ( 'yoast' === $this->plugin ) {
			$values = array();
			foreach ( self::YOAST as $field => $key ) {
				if ( array_key_exists( $field, $patch ) ) {
					$values[ $key ] = (string) Seo::text( $patch[ $field ] );
				}
			}
			if ( array_key_exists( 'robots.noindex', $patch ) ) {
				$values['wpseo_noindex'] = null === $patch['robots.noindex'] ? 'default' : ( $patch['robots.noindex'] ? 'noindex' : 'index' );
			}
			\WPSEO_Taxonomy_Meta::set_values( $term->term_id, $term->taxonomy, $values + (array) \WPSEO_Taxonomy_Meta::get_term_meta( $term->term_id, $term->taxonomy ) );
			self::yoast_rebuild( $term->term_id );
			return;
		}
		if ( 'rank-math' === $this->plugin ) {
			$meta = array();
			foreach ( self::RANK_MATH as $field => $key ) {
				if ( array_key_exists( $field, $patch ) ) {
					$meta[ $key ] = (string) Seo::text( $patch[ $field ] );
				}
			}
			if ( array_key_exists( 'twitter.title', $patch ) || array_key_exists( 'twitter.description', $patch ) ) {
				// Rank Math copies the Facebook values to X unless "use Facebook data" is off.
				$meta['rank_math_twitter_use_facebook'] = 'off';
			}
			if ( array_key_exists( 'robots.noindex', $patch ) || array_key_exists( 'robots.nofollow', $patch ) ) {
				$robots = array_map( 'strval', (array) get_term_meta( $term->term_id, 'rank_math_robots', true ) );
				if ( array_key_exists( 'robots.noindex', $patch ) ) {
					$robots = array_diff( $robots, array( 'index', 'noindex' ) );
					if ( null !== $patch['robots.noindex'] ) {
						$robots[] = $patch['robots.noindex'] ? 'noindex' : 'index';
					}
				}
				if ( array_key_exists( 'robots.nofollow', $patch ) ) {
					$robots = array_diff( $robots, array( 'nofollow' ) );
					if ( $patch['robots.nofollow'] ) {
						$robots[] = 'nofollow';
					}
				}
				$meta['rank_math_robots'] = array_values( array_unique( array_filter( $robots ) ) );
			}
			CoreRest::call(
				'POST',
				'/rankmath/v1/updateMeta',
				array(
					'objectType' => 'term',
					'objectID'   => $term->term_id,
					'meta'       => $meta,
				)
			);
			return;
		}//end if
		$this->seopress_write( $term, $patch, false );
	}

	/**
	 * SEOPress values of a term as key => value.
	 *
	 * @param \WP_Term $term Term.
	 * @return array<string, string>
	 */
	private function seopress_raw( \WP_Term $term ): array {
		$input  = array( 'term_id' => $term->term_id );
		$basics = Seo::ability( 'seopress/get-term-title-description', $input );
		$out    = array(
			'_seopress_titles_title' => (string) ( $basics['title'] ?? '' ),
			'_seopress_titles_desc'  => (string) ( $basics['description'] ?? '' ),
		);
		foreach ( array( 'seopress/get-term-robots-settings', 'seopress/get-term-social-settings' ) as $ability ) {
			// SEOPress returns the term values as { settings: [ { key, value, … } ], term_id, … }.
			foreach ( (array) ( Seo::ability( $ability, $input )['settings'] ?? array() ) as $row ) {
				if ( is_array( $row ) && isset( $row['key'] ) && is_scalar( $row['value'] ?? null ) ) {
					$out[ (string) $row['key'] ] = (string) $row['value'];
				}
			}
		}
		return $out;
	}

	/**
	 * SEOPress raw values in the tool's field shape.
	 *
	 * @param array<string, mixed> $raw Key => value.
	 * @return array<string, mixed>
	 */
	private static function from_seopress( array $raw ): array {
		$out = array(
			'title'           => Seo::text( $raw['_seopress_titles_title'] ?? '' ),
			'description'     => Seo::text( $raw['_seopress_titles_desc'] ?? '' ),
			'canonical'       => Seo::text( $raw['_seopress_robots_canonical'] ?? '' ),
			'robots.noindex'  => 'yes' === ( $raw['_seopress_robots_index'] ?? '' ) ? true : null,
			'robots.nofollow' => 'yes' === ( $raw['_seopress_robots_follow'] ?? '' ),
		);
		foreach ( self::SEOPRESS_SOCIAL as $field => $key ) {
			$out[ $field ] = Seo::text( $raw[ $key ] ?? '' );
		}
		return $out;
	}

	/**
	 * Write through SEOPress' term abilities.
	 *
	 * @param \WP_Term             $term Term.
	 * @param array<string, mixed> $patch Fields.
	 * @param bool                 $all   Write every field (undo).
	 * @throws ToolError When SEOPress refuses.
	 */
	private function seopress_write( \WP_Term $term, array $patch, bool $all ): void {
		// SEOPress only stores "hidden from search engines" per term; false and null both clear it (taxonomy default).
		$basics = array( 'term_id' => $term->term_id );
		foreach ( array( 'title', 'description' ) as $field ) {
			if ( $all || array_key_exists( $field, $patch ) ) {
				$basics[ $field ] = (string) Seo::text( $patch[ $field ] ?? '' );
			}
		}
		if ( count( $basics ) > 1 ) {
			Seo::ability( 'seopress/update-term-title-description', $basics );
		}
		$robots = array();
		foreach ( array(
			'robots.noindex'  => '_seopress_robots_index',
			'robots.nofollow' => '_seopress_robots_follow',
		) as $field => $key ) {
			if ( $all || array_key_exists( $field, $patch ) ) {
				$robots[ $key ] = ! empty( $patch[ $field ] ) ? 'yes' : '';
			}
		}
		if ( $all || array_key_exists( 'canonical', $patch ) ) {
			$robots['_seopress_robots_canonical'] = (string) Seo::text( $patch['canonical'] ?? '' );
		}
		if ( $robots ) {
			Seo::ability(
				'seopress/update-term-robots-settings',
				array(
					'term_id'  => $term->term_id,
					'settings' => $robots,
				)
			);
		}
		$social = array();
		foreach ( self::SEOPRESS_SOCIAL as $field => $key ) {
			if ( $all || array_key_exists( $field, $patch ) ) {
				$social[ $key ] = (string) Seo::text( $patch[ $field ] ?? '' );
			}
		}
		if ( $social ) {
			Seo::ability(
				'seopress/update-term-social-settings',
				array(
					'term_id'  => $term->term_id,
					'settings' => $social,
				)
			);
		}
	}

	/**
	 * Rebuild Yoast's term indexable now (its watcher does it on edited_term).
	 *
	 * @param int $term_id Term id.
	 */
	private static function yoast_rebuild( int $term_id ): void {
		$watcher = YoastSEO()->classes->get( \Yoast\WP\SEO\Integrations\Watchers\Indexable_Term_Watcher::class );
		if ( is_object( $watcher ) && method_exists( $watcher, 'build_indexable' ) ) {
			$watcher->build_indexable( $term_id );
		}
	}

	/**
	 * Title templates and archive settings as the plugin stores them.
	 *
	 * @return array<string, mixed>
	 */
	private function settings(): array {
		if ( 'yoast' === $this->plugin ) {
			$out = array();
			foreach ( (array) \WPSEO_Options::get_all() as $key => $value ) {
				if ( preg_match( '/^(title-|metadesc-|noindex-|separator$|website_name$|alternate_website_name$|company_name$|company_or_person$|breadcrumbs-enable$|stripcategorybase$|disable-(author|date|post_format|attachment)$)/', (string) $key ) ) {
					$out[ (string) $key ] = $value;
				}
			}
			ksort( $out );
			return $out;
		}
		$option = 'rank-math' === $this->plugin ? 'rank-math-options-titles' : 'seopress_titles_option_name';
		$value  = get_option( $option, array() );
		return is_array( $value ) ? $value : array();
	}
}
