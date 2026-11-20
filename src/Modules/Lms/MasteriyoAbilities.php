<?php
/**
 * Tools: kuduy-masteriyo-abilities, kuduy-masteriyo-ability-get, kuduy-masteriyo-ability-run.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Lms;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * Masteriyo LMS through the abilities it registers itself (phase R, D63; source of Masteriyo 3.5.2,
 * `learning-management-system`, includes/Abilities). Masteriyo registers about eighty abilities under "masteriyo/…"
 * (courses, sections, lessons, quizzes, questions, categories, tags, difficulties, enrollments, progress, Q&A,
 * reviews, orders, users, settings, course and quiz builder). Each proxies a Masteriyo REST controller and is checked
 * by that controller's own *_permissions_check(), so the user's Masteriyo role decides. Payment, email and advanced
 * settings updates are not registered unless a filter opts in.
 *
 * Reads run freely. Writes run only from an allowlist: creating course content, terms and enrollments, cloning
 * lessons and quizzes, changing course content (a snapshot of the post and its meta, or of the term, is taken first),
 * trashing and restoring courses and lessons. Orders, users, settings, builder saves (they rewrite many posts at once),
 * reviews and Q&A (posted in someone's name), progress and permanent deletes are left to Masteriyo itself.
 */
final class MasteriyoAbilities implements Tool, Snapshottable {

	/**
	 * Post type behind each post-backed domain (Masteriyo 3.5.2 PostType enums).
	 */
	private const POSTS = array(
		'course'   => 'mto-course',
		'section'  => 'mto-section',
		'lesson'   => 'mto-lesson',
		'quiz'     => 'mto-quiz',
		'question' => 'mto-question',
	);

	/**
	 * Taxonomy behind each term domain.
	 */
	private const TERMS = array(
		'course-category'   => 'course_cat',
		'course-tag'        => 'course_tag',
		'course-difficulty' => 'course_difficulty',
	);

	/**
	 * Write abilities run here (short names, without "masteriyo/").
	 */
	private const ALLOW = array(
		'course-create',
		'section-create',
		'lesson-create',
		'quiz-create',
		'question-create',
		'course-category-create',
		'course-tag-create',
		'course-difficulty-create',
		'enrollment-create',
		'lesson-clone',
		'quiz-clone',
		'course-update',
		'section-update',
		'lesson-update',
		'quiz-update',
		'question-update',
		'course-category-update',
		'course-tag-update',
		'course-difficulty-update',
		'enrollment-update',
		'course-delete',
		'lesson-delete',
		'course-restore',
		'lesson-restore',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode abilities | ability-get | ability-run.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-masteriyo-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'abilities'   => __( 'List Masteriyo abilities', 'kuduy-mcp' ),
			'ability-get' => __( 'Read with Masteriyo', 'kuduy-mcp' ),
			default       => __( 'Change Masteriyo course content', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'abilities'   => 'Lists what Masteriyo LMS lets an assistant do through its own abilities (courses, sections, lessons, quizzes, questions, categories, tags, difficulties, enrollments, progress, Q&A, reviews, orders, users, settings): name, label, description, read or write, and whether it can be run here (why not, when not). With name it returns that ability\'s input schema. Masteriyo 3.5 or later.',
			'ability-get' => 'Runs one of Masteriyo\'s read abilities and returns its data, e.g. masteriyo/course-list {"per_page": 10}, masteriyo/course-get {"id": 12}, masteriyo/course-children-get {"id": 12} (sections, lessons and quizzes in order), masteriyo/enrollment-list {"course_id": 12}, masteriyo/progress-get. Masteriyo checks the user\'s permissions. Names and inputs: kuduy-masteriyo-abilities.',
			default       => 'Runs one of Masteriyo\'s write abilities with the input it declares: create courses, sections, lessons, quizzes, questions, categories, tags, difficulties and enrollments; clone lessons and quizzes; change them (id); trash and restore courses and lessons (never force). Masteriyo validates the input and checks its own permissions. Changes to an existing course, section, lesson, quiz, question or term are saved first; undo with kuduy-snapshot-restore. Orders, users, settings, builder saves, reviews, Q&A, progress and permanent deletes are not run here.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$name  = array(
			'type'        => 'string',
			'pattern'     => '^masteriyo/[a-z0-9-]+$',
			'description' => 'Ability name from kuduy-masteriyo-abilities.',
		);
		$props = 'abilities' === $this->mode
			? array( 'name' => $name )
			: array(
				'name'  => $name,
				'input' => array(
					'type'        => 'object',
					'description' => 'Input the ability declares (see kuduy-masteriyo-abilities with name).',
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
	 * Masteriyo's controllers decide per item (instructors manage their own courses); here only that the user can
	 * work in the dashboard at all.
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return defined( 'MASTERIYO_VERSION' ) ? true : 'Masteriyo LMS is not active on this site.';
	}

	/**
	 * Masteriyo's registered abilities by name.
	 *
	 * @return array<string, \WP_Ability>
	 */
	private static function abilities(): array {
		$out = array();
		if ( function_exists( 'wp_get_abilities' ) ) {
			foreach ( wp_get_abilities() as $ability ) {
				if ( str_starts_with( $ability->get_name(), 'masteriyo/' ) ) {
					$out[ $ability->get_name() ] = $ability;
				}
			}
		}
		ksort( $out );
		return $out;
	}

	/**
	 * Short name of an ability ("course-update").
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private static function short( \WP_Ability $ability ): string {
		return substr( $ability->get_name(), strlen( 'masteriyo/' ) );
	}

	/**
	 * Whether an ability only reads (Masteriyo sets readOnlyHint on every ability).
	 *
	 * @param \WP_Ability $ability Ability.
	 */
	private static function reads( \WP_Ability $ability ): bool {
		$annotations = $ability->get_meta_item( 'annotations', array() );
		if ( is_array( $annotations ) ) {
			foreach ( array( 'readOnlyHint', 'readonly' ) as $key ) {
				if ( isset( $annotations[ $key ] ) ) {
					return (bool) $annotations[ $key ];
				}
			}
		}
		return 1 === preg_match( '/-(get|list)$/', self::short( $ability ) );
	}

	/**
	 * Why a write ability is not run here, or '' when it is.
	 *
	 * @param \WP_Ability          $ability Ability.
	 * @param array<string, mixed> $args    Ability input.
	 */
	private static function blocked( \WP_Ability $ability, array $args ): string {
		$short = self::short( $ability );
		if ( ! in_array( $short, self::ALLOW, true ) ) {
			return match ( true ) {
				str_starts_with( $short, 'order-' )       => 'Orders carry payments; change them in Masteriyo itself.',
				str_starts_with( $short, 'user-' )        => 'Users are changed with the KuDiagnostics user tools (kuduy-user-*), which guard roles and administrators.',
				str_starts_with( $short, 'settings-' )    => 'Masteriyo settings (including payment and email settings) are changed in Masteriyo itself.',
				str_contains( $short, 'builder-save' )    => 'Builder saves rewrite many sections, lessons and quizzes at once, which cannot be undone from here. Change the items one by one (…-update), or use Masteriyo\'s course builder.',
				str_starts_with( $short, 'course-review' ) => 'Reviews are posted in someone\'s name; KuDiagnostics does not write them.',
				str_starts_with( $short, 'course-qa' )    => 'Questions and answers are posted in someone\'s name; KuDiagnostics does not write them.',
				str_starts_with( $short, 'progress-' )    => 'A student\'s progress is theirs; it changes as they learn.',
				str_ends_with( $short, '-delete' )        => 'This delete cannot be undone from here (only courses and lessons go to a trash). Do it in Masteriyo itself.',
				default                                  => 'This ability is not on the list of Masteriyo changes run here.',
			};
		}
		if ( str_ends_with( $short, '-delete' ) && ! empty( $args['force'] ) ) {
			return 'Permanent deletes are not run here. Without force the item goes to Masteriyo\'s trash and can be restored.';
		}
		if ( ( str_ends_with( $short, '-update' ) || str_ends_with( $short, '-delete' ) || str_ends_with( $short, '-restore' ) ) && (int) ( $args['id'] ?? 0 ) < 1 ) {
			return 'Name the item by its numeric id so it can be saved first and the change undone.';
		}
		return '';
	}

	/**
	 * Domain of an ability ("course", "course-category", "enrollment").
	 *
	 * @param string $short Short name.
	 */
	private static function domain( string $short ): string {
		return (string) preg_replace( '/-(create|update|delete|restore|clone|get|list)$/', '', $short );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'ability-run' !== $this->mode ) {
			return null;
		}
		$short = substr( (string) ( $input['name'] ?? '' ), strlen( 'masteriyo/' ) );
		$args  = (array) ( $input['input'] ?? array() );
		$id    = (int) ( $args['id'] ?? 0 );
		if ( $id < 1 || ! preg_match( '/-(update|delete|restore)$/', $short ) ) {
			return null;
		}
		$domain = self::domain( $short );
		if ( isset( self::POSTS[ $domain ] ) ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || self::POSTS[ $domain ] !== $post->post_type ) {
				return null;
			}
			$meta = array();
			foreach ( (array) get_post_meta( $post->ID ) as $key => $values ) {
				$meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
			}
			$terms = array();
			foreach ( get_object_taxonomies( $post->post_type ) as $taxonomy ) {
				$ids                = wp_get_object_terms( $post->ID, $taxonomy, array( 'fields' => 'ids' ) );
				$terms[ $taxonomy ] = is_array( $ids ) ? array_map( 'intval', $ids ) : array();
			}
			return array(
				'object_type' => 'post',
				'object_id'   => (string) $post->ID,
				'payload'     => array(
					'kind'  => 'post',
					'post'  => array(
						'ID'           => $post->ID,
						'post_title'   => $post->post_title,
						'post_content' => $post->post_content,
						'post_excerpt' => $post->post_excerpt,
						'post_status'  => $post->post_status,
						'post_name'    => $post->post_name,
						'post_parent'  => $post->post_parent,
						'menu_order'   => $post->menu_order,
					),
					'meta'  => $meta,
					'terms' => $terms,
				),
			);
		}//end if
		if ( isset( self::TERMS[ $domain ] ) ) {
			$term = get_term( $id, self::TERMS[ $domain ] );
			if ( ! $term instanceof \WP_Term ) {
				return null;
			}
			$meta = array();
			foreach ( (array) get_term_meta( $term->term_id ) as $key => $values ) {
				$meta[ (string) $key ] = array_map( 'maybe_unserialize', (array) $values );
			}
			return array(
				'object_type' => 'term',
				'object_id'   => (string) $term->term_id,
				'payload'     => array(
					'kind'     => 'term',
					'taxonomy' => $term->taxonomy,
					'term'     => array(
						'term_id'     => $term->term_id,
						'name'        => $term->name,
						'slug'        => $term->slug,
						'description' => $term->description,
						'parent'      => $term->parent,
					),
					'meta'     => $meta,
				),
			);
		}//end if
		return null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the item is gone.
	 */
	public function restore( array $payload ): void {
		if ( 'term' === ( $payload['kind'] ?? '' ) ) {
			$term     = (array) $payload['term'];
			$taxonomy = (string) $payload['taxonomy'];
			$id       = (int) $term['term_id'];
			if ( ! get_term( $id, $taxonomy ) instanceof \WP_Term ) {
				throw new ToolError( 'The term of this snapshot no longer exists.' );
			}
			wp_update_term(
				$id,
				$taxonomy,
				array(
					'name'        => (string) $term['name'],
					'slug'        => (string) $term['slug'],
					'description' => (string) $term['description'],
					'parent'      => (int) $term['parent'],
				)
			);
			self::put_meta( 'term', $id, (array) ( $payload['meta'] ?? array() ) );
			return;
		}
		$post = (array) ( $payload['post'] ?? array() );
		$id   = (int) ( $post['ID'] ?? 0 );
		if ( ! get_post( $id ) instanceof \WP_Post ) {
			throw new ToolError( 'The Masteriyo item of this snapshot no longer exists.' );
		}
		$done = wp_update_post( wp_slash( $post ), true );
		if ( is_wp_error( $done ) ) {
			throw new ToolError( 'WordPress did not take the item back: ' . wp_strip_all_tags( $done->get_error_message() ) );
		}
		self::put_meta( 'post', $id, (array) ( $payload['meta'] ?? array() ) );
		foreach ( (array) ( $payload['terms'] ?? array() ) as $taxonomy => $ids ) {
			wp_set_object_terms( $id, array_map( 'intval', (array) $ids ), (string) $taxonomy );
		}
		clean_post_cache( $id );
	}

	/**
	 * Put an object's meta back exactly (keys added since are removed, multi-value keys kept).
	 *
	 * @param string                           $type  post | term.
	 * @param int                              $id    Object id.
	 * @param array<string, array<int, mixed>> $saved Saved meta.
	 */
	private static function put_meta( string $type, int $id, array $saved ): void {
		foreach ( array_keys( (array) get_metadata( $type, $id ) ) as $key ) {
			if ( ! array_key_exists( (string) $key, $saved ) ) {
				delete_metadata( $type, $id, (string) $key );
			}
		}
		foreach ( $saved as $key => $values ) {
			delete_metadata( $type, $id, (string) $key );
			foreach ( (array) $values as $value ) {
				add_metadata( $type, $id, (string) $key, wp_slash( $value ) );
			}
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the ability is unknown, not run here, or Masteriyo refuses.
	 */
	public function execute( array $input ): array {
		$all = self::abilities();
		if ( 'abilities' === $this->mode ) {
			return $this->listing( $all, (string) ( $input['name'] ?? '' ) );
		}
		if ( ! $all ) {
			throw ToolError::of( ToolError::FEATURE_NOT_SUPPORTED, 'Masteriyo has not registered its abilities on this site (Masteriyo 3.5 or later on WordPress 6.9 or later).' );
		}
		$name    = (string) $input['name'];
		$ability = $all[ $name ] ?? null;
		if ( null === $ability ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Masteriyo has no ability "' . $name . '" on this site. See kuduy-masteriyo-abilities.' );
		}
		$write = 'ability-run' === $this->mode;
		if ( self::reads( $ability ) === $write ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, $write ? 'This is a read ability: use kuduy-masteriyo-ability-get.' : 'This ability changes things: use kuduy-masteriyo-ability-run.' );
		}
		$args = (array) ( $input['input'] ?? array() );
		if ( $write ) {
			$why = self::blocked( $ability, $args );
			if ( '' !== $why ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, $why . ' Nothing was changed.' );
			}
			$domain = self::domain( self::short( $ability ) );
			AuditLog::target( 'masteriyo_' . str_replace( '-', '_', $domain ), isset( $args['id'] ) ? (string) (int) $args['id'] : 'new' );
		}
		$result = $ability->execute( $args );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Masteriyo refused: ' . wp_strip_all_tags( $result->get_error_message() ) . ( $write ? ' Nothing was changed.' : '' ) );
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
				'note'      => 'Masteriyo has not registered its abilities (Masteriyo 3.5 or later on WordPress 6.9 or later).',
			);
		}
		if ( '' !== $name ) {
			$ability = $all[ $name ] ?? null;
			if ( null === $ability ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Masteriyo has no ability "' . $name . '" on this site.' );
			}
			return array(
				'enabled' => true,
				'ability' => self::row( $ability ) + array( 'input_schema' => $ability->get_input_schema() ),
			);
		}
		return array(
			'enabled'   => true,
			'abilities' => array_values( array_map( array( self::class, 'row' ), $all ) ),
		);
	}

	/**
	 * Summary of one ability.
	 *
	 * @param \WP_Ability $ability Ability.
	 * @return array<string, mixed>
	 */
	private static function row( \WP_Ability $ability ): array {
		$reads = self::reads( $ability );
		$why   = $reads ? '' : self::blocked( $ability, array( 'id' => 1 ) );
		$row   = array(
			'name'        => $ability->get_name(),
			'label'       => wp_strip_all_tags( $ability->get_label() ),
			'description' => wp_strip_all_tags( $ability->get_description() ),
			'kind'        => $reads ? 'read' : 'write',
			'run_with'    => '' !== $why ? 'not run here' : ( $reads ? 'kuduy-masteriyo-ability-get' : 'kuduy-masteriyo-ability-run' ),
		);
		if ( '' !== $why ) {
			$row['why_not'] = $why;
		}
		return $row;
	}
}
