<?php
/**
 * Tools: kuduy-tutor-courses, -course-get, -students, -quiz-attempts, -enroll, -enrollment-status, -curriculum-add.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Lms;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Tutor LMS (phase R, D64; source of Tutor LMS 4.2.1). Tutor registers no abilities and its REST API (tutor/v1) is
 * read-only behind its own token sign-in, so these tools use its PHP models, as its own screens do:
 * tutor_utils()->get_topics() and get_course_contents_by_topic() for the curriculum, get_course_completed_percent()
 * for progress, Tutor\Models\EnrollmentModel (do_enroll(), update_enrollments(); enrolments are posts
 * of type tutor_enrolled under the course) and Tutor\Models\QuizModel::get_quiz_attempts_by_course_ids().
 *
 * Topics and lessons are created exactly as Tutor's course builder creates them (Course::tutor_save_topic(),
 * Lesson::ajax_save_lesson()): wp_insert_post with post type topics under the course or the lesson post type under a
 * topic, Tutor's own next-order helpers, and the tutor/lesson/created action. Who may do what is Tutor's own rule:
 * tutor_utils()->can_user_manage( 'course', $id ) (administrators and the course's instructors).
 */
final class TutorTools implements Tool, Snapshottable {

	/**
	 * Enrollment statuses Tutor 4.2.1 knows (EnrollmentModel::STATUS_*).
	 */
	private const STATUSES = array( 'completed', 'pending', 'cancel' );

	/**
	 * Mode.
	 *
	 * @param string $mode courses | course-get | students | quiz-attempts | enroll | enrollment-status | curriculum-add.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-tutor-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'courses'           => __( 'List Tutor LMS courses', 'kuduy-mcp' ),
			'course-get'        => __( 'Read a Tutor LMS course and its curriculum', 'kuduy-mcp' ),
			'students'          => __( 'List students of a Tutor LMS course', 'kuduy-mcp' ),
			'quiz-attempts'     => __( 'List Tutor LMS quiz attempts', 'kuduy-mcp' ),
			'enroll'            => __( 'Enroll a user in a Tutor LMS course', 'kuduy-mcp' ),
			'enrollment-status' => __( 'Change a Tutor LMS enrollment', 'kuduy-mcp' ),
			default             => __( 'Add a topic or lesson to a Tutor LMS course', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'courses'           => 'Lists Tutor LMS courses: id, title, status, author, price type, number of topics and enrolled students. Filter by status or search; paged.',
			'course-get'        => 'Returns one Tutor LMS course with its curriculum as Tutor orders it: topics, and in each topic its lessons, quizzes and assignments (id, type, title, status). Content of the items is not included (kuduy-post-get).',
			'students'          => 'Lists the enrollments of a Tutor LMS course: enrollment id, user (id, name, login), status (completed = active, pending = awaiting payment, cancel), enrolled date and the student\'s completion percent as Tutor computes it. Paged.',
			'quiz-attempts'     => 'Lists quiz attempts of a Tutor LMS course: attempt id, quiz, user id, status, marks earned of total, start and end. Answers are not included.',
			'enroll'            => 'Administrators only: enrolls a user in a Tutor LMS course through Tutor\'s own EnrollmentModel::do_enroll() (Tutor\'s hooks run; a paid course gets a pending enrollment, as Tutor does for unpaid orders). An existing enrollment is returned as it is. To take it back, cancel it with kuduy-tutor-enrollment-status.',
			'enrollment-status' => 'Administrators only: sets the status of Tutor LMS enrollments through EnrollmentModel::update_enrollments(): completed (active), pending or cancel. Tutor\'s hooks run (e.g. emails Tutor sends on enrollment). Undo with kuduy-snapshot-restore puts the previous statuses back.',
			default             => 'Adds a topic to a Tutor LMS course (type topic: course_id, title, summary) or a lesson to a topic (type lesson: topic_id, title, content), as Tutor\'s course builder does: Tutor\'s order helpers place it last. To take it back, trash it with kuduy-post-trash.',
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
		$page       = array(
			'page'     => array(
				'type'    => 'integer',
				'minimum' => 1,
			),
			'per_page' => array(
				'type'    => 'integer',
				'minimum' => 1,
				'maximum' => 100,
			),
		);
		$properties = match ( $this->mode ) {
			'courses'           => array(
				'status' => array(
					'type' => 'string',
					'enum' => array( 'any', 'publish', 'draft', 'pending', 'private', 'future' ),
				),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			) + $page,
			'course-get'        => array( 'course_id' => $id ),
			'students'          => array(
				'course_id' => $id,
				'status'    => array(
					'type' => 'string',
					'enum' => array_merge( array( 'any' ), self::STATUSES ),
				),
			) + $page,
			'quiz-attempts'     => array( 'course_id' => $id ) + $page,
			'enroll'            => array(
				'course_id' => $id,
				'user_id'   => $id,
			),
			'enrollment-status' => array(
				'course_id'      => $id,
				'enrollment_ids' => array(
					'type'     => 'array',
					'minItems' => 1,
					'maxItems' => 100,
					'items'    => $id,
				),
				'status'         => array(
					'type' => 'string',
					'enum' => self::STATUSES,
				),
			),
			default             => array(
				'type'      => array(
					'type' => 'string',
					'enum' => array( 'topic', 'lesson' ),
				),
				'course_id' => $id,
				'topic_id'  => $id,
				'title'     => array(
					'type'      => 'string',
					'minLength' => 1,
					'maxLength' => 200,
				),
				'summary'   => array(
					'type'      => 'string',
					'maxLength' => 5000,
				),
				'content'   => array(
					'type'      => 'string',
					'maxLength' => 200000,
				),
			),
		};//end match
		$required = match ( $this->mode ) {
			'courses'           => array(),
			'enroll'            => array( 'course_id', 'user_id' ),
			'enrollment-status' => array( 'course_id', 'enrollment_ids', 'status' ),
			'curriculum-add'    => array( 'type', 'title' ),
			default             => array( 'course_id' ),
		};
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( $required ) {
			$schema['required'] = $required;
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'courses'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'course'       => array( 'type' => 'object' ),
				'topics'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'students'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'attempts'     => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'total'        => array( 'type' => 'integer' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'courses', 'course-get', 'students', 'quiz-attempts' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'enrollment-status' === $this->mode,
			'idempotentHint'  => 'curriculum-add' !== $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'courses', 'course-get', 'students', 'quiz-attempts' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * The course-level check (Tutor's can_user_manage) runs in execute(); here the WordPress side. Enrolling someone
	 * and changing enrollments are site administration in Tutor (its manual enrollment screen is for administrators),
	 * so an instructor cannot put arbitrary users into a course or cancel them from here.
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return match ( $this->mode ) {
			'courses'                     => 'read',
			'enroll', 'enrollment-status' => 'manage_options',
			default                       => 'edit_posts',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return defined( 'TUTOR_VERSION' ) && function_exists( 'tutor_utils' ) && class_exists( '\Tutor\Models\EnrollmentModel' ) ? true : 'Tutor LMS is not active on this site.';
	}

	/**
	 * Tutor's course post type ("courses" unless filtered).
	 */
	private static function course_type(): string {
		return (string) tutor()->course_post_type;
	}

	/**
	 * A course the user may manage in Tutor.
	 *
	 * @param int $id Course id.
	 * @throws ToolError When there is none or Tutor says no.
	 */
	private static function course( int $id ): \WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || self::course_type() !== $post->post_type ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No Tutor LMS course with id ' . $id . ' (see kuduy-tutor-courses).' );
		}
		if ( ! tutor_utils()->can_user_manage( 'course', $id ) ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'Tutor LMS lets only administrators and the course\'s instructors work on course ' . $id . '.' );
		}
		return $post;
	}

	/**
	 * Paging from the input.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array{0: int, 1: int}
	 */
	private static function paging( array $input ): array {
		return array( max( 1, (int) ( $input['page'] ?? 1 ) ), min( 100, max( 1, (int) ( $input['per_page'] ?? 20 ) ) ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'enrollment-status' !== $this->mode ) {
			return null;
		}
		$before = array();
		foreach ( (array) ( $input['enrollment_ids'] ?? array() ) as $id ) {
			$post = get_post( (int) $id );
			if ( $post instanceof \WP_Post && \Tutor\Models\EnrollmentModel::POST_TYPE === $post->post_type ) {
				$before[ (string) $post->ID ] = $post->post_status;
			}
		}
		return $before ? array(
			'object_type' => 'tutor_enrollment',
			'object_id'   => (string) array_key_first( $before ),
			'payload'     => array( 'statuses' => $before ),
		) : null;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$groups = array();
		foreach ( (array) ( $payload['statuses'] ?? array() ) as $id => $status ) {
			$groups[ (string) $status ][] = (int) $id;
		}
		foreach ( $groups as $status => $ids ) {
			\Tutor\Models\EnrollmentModel::update_enrollments( (string) $status, $ids );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When an item is unknown or Tutor refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'courses'           => self::courses( $input ),
			'course-get'        => self::course_get( (int) $input['course_id'] ),
			'students'          => self::students( $input ),
			'quiz-attempts'     => self::attempts( $input ),
			'enroll'            => self::enroll( (int) $input['course_id'], (int) $input['user_id'] ),
			'enrollment-status' => self::set_status( $input ),
			default             => self::add( $input ),
		};
	}

	/**
	 * Courses.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function courses( array $input ): array {
		[ $page, $per ] = self::paging( $input );
		$query          = new \WP_Query(
			array(
				'post_type'      => self::course_type(),
				'post_status'    => (string) ( $input['status'] ?? 'any' ),
				's'              => (string) ( $input['search'] ?? '' ),
				'posts_per_page' => $per,
				'paged'          => $page,
				'orderby'        => 'date',
				'order'          => 'DESC',
				// Drafts of others only for those who may edit them (WordPress' own rule for listing posts).
				'perm'           => 'readable',
			)
		);
		$out            = array();
		foreach ( $query->posts as $post ) {
			if ( ! $post instanceof \WP_Post ) {
				continue;
			}
			$out[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'author'     => (int) $post->post_author,
				'price_type' => (string) tutor_utils()->price_type( $post->ID ),
				'topics'     => (int) tutor_utils()->get_topics( $post->ID )->found_posts,
				'students'   => (int) tutor_utils()->count_enrolled_users_by_course( $post->ID ),
			);
		}
		return array(
			'courses' => $out,
			'total'   => (int) $query->found_posts,
		);
	}

	/**
	 * One course with its curriculum.
	 *
	 * @param int $id Course id.
	 * @return array<string, mixed>
	 */
	private static function course_get( int $id ): array {
		$post = self::course( $id );
		AuditLog::target( 'post', (string) $id );
		$topics = array();
		foreach ( tutor_utils()->get_topics( $id )->posts as $topic ) {
			$items = array();
			foreach ( tutor_utils()->get_course_contents_by_topic( $topic->ID, -1 )->posts as $item ) {
				$items[] = array(
					'id'     => $item->ID,
					'type'   => $item->post_type,
					'title'  => $item->post_title,
					'status' => $item->post_status,
				);
			}
			$topics[] = array(
				'id'      => $topic->ID,
				'title'   => $topic->post_title,
				'summary' => wp_strip_all_tags( $topic->post_content ),
				'items'   => $items,
			);
		}
		return array(
			'course' => array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'status'     => $post->post_status,
				'author'     => (int) $post->post_author,
				'price_type' => (string) tutor_utils()->price_type( $post->ID ),
				'link'       => (string) get_permalink( $post ),
			),
			'topics' => $topics,
		);
	}

	/**
	 * Enrollments of a course.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function students( array $input ): array {
		$course           = self::course( (int) $input['course_id'] );
		[ $page, $per ]   = self::paging( $input );
		$status           = (string) ( $input['status'] ?? 'any' );
		[ $rows, $total ] = self::enrollments( $course->ID, 0, 'any' === $status ? self::STATUSES : array( $status ), $per, ( $page - 1 ) * $per );
		$out              = array();
		foreach ( $rows as $enrollment ) {
			$user  = get_userdata( (int) $enrollment->post_author );
			$out[] = array(
				'enrollment_id' => (int) $enrollment->ID,
				'user_id'       => (int) $enrollment->post_author,
				'name'          => $user ? $user->display_name : '',
				'login'         => $user ? $user->user_login : '',
				'status'        => $enrollment->post_status,
				'enrolled'      => (string) $enrollment->post_date_gmt,
				'progress'      => (int) tutor_utils()->get_course_completed_percent( $course->ID, (int) $enrollment->post_author ),
			);
		}
		return array(
			'students' => $out,
			'total'    => $total,
		);
	}

	/**
	 * Enrollment posts of a course (optionally one student), newest first. Tutor registers no post statuses for
	 * completed / pending / cancel, so WP_Query cannot filter on them; this is the read Tutor's own
	 * EnrollmentModel::is_enrolled() makes, prepared, without its per-request cache.
	 *
	 * @param int          $course_id Course id.
	 * @param int          $user_id   Student id, or 0 for all.
	 * @param list<string> $statuses  Statuses.
	 * @param int          $limit     Rows.
	 * @param int          $offset    Offset.
	 * @return array{0: list<object{ID: string, post_author: string, post_status: string, post_date_gmt: string}>, 1: int}
	 */
	private static function enrollments( int $course_id, int $user_id, array $statuses, int $limit, int $offset ): array {
		global $wpdb;
		$statuses = array_values( array_intersect( $statuses, self::STATUSES ) );
		if ( ! $statuses ) {
			return array( array(), 0 );
		}
		$in    = implode( ', ', array_fill( 0, count( $statuses ), '%s' ) );
		$args  = array_merge( array( \Tutor\Models\EnrollmentModel::POST_TYPE, $course_id, $user_id, $user_id ), $statuses );
		$where = "post_type = %s AND post_parent = %d AND post_author > 0 AND ( 0 = %d OR post_author = %d ) AND post_status IN ($in)";
		// phpcs:disable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber -- read only; $where holds only placeholders ($in: one %s per status), all values go through prepare().
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE {$where}", $args ) );
		$rows  = $wpdb->get_results( $wpdb->prepare( "SELECT ID, post_author, post_status, post_date_gmt FROM {$wpdb->posts} WHERE {$where} ORDER BY post_date_gmt DESC, ID DESC LIMIT %d OFFSET %d", array_merge( $args, array( $limit, $offset ) ) ) );
		// phpcs:enable WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare, WordPress.DB.PreparedSQLPlaceholders.ReplacementsWrongNumber
		return array( is_array( $rows ) ? array_values( $rows ) : array(), $total );
	}

	/**
	 * Quiz attempts of a course.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function attempts( array $input ): array {
		$course         = self::course( (int) $input['course_id'] );
		[ $page, $per ] = self::paging( $input );
		$rows           = \Tutor\Models\QuizModel::get_quiz_attempts_by_course_ids( ( $page - 1 ) * $per, $per, array( $course->ID ) );
		$out            = array();
		foreach ( is_array( $rows ) ? $rows : array() as $row ) {
			$row   = (array) $row;
			$out[] = array(
				'attempt_id' => (int) ( $row['attempt_id'] ?? 0 ),
				'quiz_id'    => (int) ( $row['quiz_id'] ?? 0 ),
				'quiz'       => (string) ( $row['post_title'] ?? '' ),
				'user_id'    => (int) ( $row['user_id'] ?? 0 ),
				'status'     => (string) ( $row['attempt_status'] ?? '' ),
				'earned'     => (float) ( $row['earned_marks'] ?? 0 ),
				'total'      => (float) ( $row['total_marks'] ?? 0 ),
				'started'    => (string) ( $row['attempt_started_at'] ?? '' ),
				'ended'      => (string) ( $row['attempt_ended_at'] ?? '' ),
			);
		}
		return array(
			'attempts' => $out,
			'total'    => (int) \Tutor\Models\QuizModel::get_quiz_attempts_by_course_ids( 0, 0, array( $course->ID ), '', '', '', '', null, true ),
		);
	}

	/**
	 * Enroll a user.
	 *
	 * @param int $course_id Course id.
	 * @param int $user_id   User id.
	 * @return array<string, mixed>
	 * @throws ToolError When the user is unknown or Tutor refuses.
	 */
	private static function enroll( int $course_id, int $user_id ): array {
		$course = self::course( $course_id );
		if ( ! get_userdata( $user_id ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No user with id ' . $user_id . '.' );
		}
		// Not EnrollmentModel::is_enrolled(): it caches a "not enrolled" answer for the request (stale right after
		// enrolling) and does not select the status. Enrollments are posts of its type under the course, by the student.
		$existing = self::enrollments( $course->ID, $user_id, self::STATUSES, 1, 0 )[0][0] ?? null;
		if ( null !== $existing ) {
			return array(
				'enrollment_id' => (int) $existing->ID,
				'status'        => (string) $existing->post_status,
				'created'       => false,
			);
		}
		AuditLog::target( 'post', (string) $course->ID );
		$id = (int) \Tutor\Models\EnrollmentModel::do_enroll( $course->ID, 0, $user_id );
		if ( $id < 1 ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Tutor LMS did not enroll the user (the course must be published or private, and Tutor\'s filters may refuse). Nothing was changed.' );
		}
		return array(
			'enrollment_id' => $id,
			'status'        => (string) get_post_status( $id ),
			'created'       => true,
		);
	}

	/**
	 * Set enrollment statuses.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When an enrollment does not belong to the course or Tutor refuses.
	 */
	private static function set_status( array $input ): array {
		$course = self::course( (int) $input['course_id'] );
		$ids    = array_values( array_unique( array_map( 'intval', (array) $input['enrollment_ids'] ) ) );
		foreach ( $ids as $id ) {
			$post = get_post( $id );
			if ( ! $post instanceof \WP_Post || \Tutor\Models\EnrollmentModel::POST_TYPE !== $post->post_type || $post->post_parent !== $course->ID ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'Enrollment ' . $id . ' is not an enrollment of course ' . $course->ID . ' (see kuduy-tutor-students). Nothing was changed.' );
			}
		}
		AuditLog::target( 'post', (string) $course->ID );
		if ( ! \Tutor\Models\EnrollmentModel::update_enrollments( (string) $input['status'], $ids ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Tutor LMS did not change the enrollments.' );
		}
		$out = array();
		foreach ( $ids as $id ) {
			clean_post_cache( $id );
			$out[] = array(
				'enrollment_id' => $id,
				'status'        => (string) get_post_status( $id ),
			);
		}
		return array( 'students' => $out );
	}

	/**
	 * Add a topic to a course or a lesson to a topic, as Tutor's course builder does.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the parent is unknown or Tutor says no.
	 */
	private static function add( array $input ): array {
		$title = sanitize_text_field( (string) $input['title'] );
		if ( 'topic' === $input['type'] ) {
			$course = self::course( (int) ( $input['course_id'] ?? 0 ) );
			$id     = wp_insert_post(
				array(
					'post_type'    => 'topics',
					'post_title'   => $title,
					'post_content' => wp_kses_post( (string) ( $input['summary'] ?? '' ) ),
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
					'post_parent'  => $course->ID,
					'menu_order'   => (int) tutor_utils()->get_next_topic_order_id( $course->ID ),
				),
				true
			);
		} else {
			$topic = get_post( (int) ( $input['topic_id'] ?? 0 ) );
			if ( ! $topic instanceof \WP_Post || 'topics' !== $topic->post_type ) {
				throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'A lesson needs topic_id, the id of a topic (see kuduy-tutor-course-get).' );
			}
			if ( ! tutor_utils()->can_user_manage( 'topic', $topic->ID ) ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, 'Tutor LMS lets only administrators and the course\'s instructors add lessons to topic ' . $topic->ID . '.' );
			}
			$id = wp_insert_post(
				array(
					'post_type'    => (string) tutor()->lesson_post_type,
					'post_title'   => $title,
					'post_content' => wp_kses_post( (string) ( $input['content'] ?? '' ) ),
					'post_status'  => 'publish',
					'post_author'  => get_current_user_id(),
					'post_parent'  => $topic->ID,
					'menu_order'   => (int) tutor_utils()->get_next_course_content_order_id( $topic->ID ),
				),
				true
			);
			if ( ! is_wp_error( $id ) ) {
				// phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound, WordPress.NamingConventions.ValidHookName.UseUnderscores -- Tutor's own action, fired as its course builder fires it.
				do_action( 'tutor/lesson/created', $id );
			}
		}//end if
		if ( is_wp_error( $id ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not save the ' . (string) $input['type'] . ': ' . wp_strip_all_tags( $id->get_error_message() ) );
		}
		AuditLog::target( 'post', (string) $id );
		return array(
			'id'     => (int) $id,
			'type'   => (string) $input['type'],
			'parent' => (int) get_post_field( 'post_parent', (int) $id ),
			'order'  => (int) get_post_field( 'menu_order', (int) $id ),
		);
	}
}
