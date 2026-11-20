<?php
/**
 * Phase R (test matrix TU1–TU4): Tutor LMS tools against the real Tutor LMS 4.2.1 (job "+ Tutor LMS").
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class TutorTest extends TestCase {

	use CallsTools;

	private static int $admin;
	private static int $student;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin   = self::make_user( 'administrator' );
		self::$student = self::make_user( 'subscriber' );
	}

	protected function setUp(): void {
		if ( ! defined( 'TUTOR_VERSION' ) ) {
			$this->markTestSkipped( 'Tutor LMS is not active in this run.' );
		}
		wp_set_current_user( self::$admin );
	}

	/**
	 * A published free course.
	 */
	private static function course(): int {
		return (int) wp_insert_post(
			array(
				'post_type'   => tutor()->course_post_type,
				'post_title'  => 'Kuduy Tutor course',
				'post_status' => 'publish',
				'post_author' => self::$admin,
			)
		);
	}

	/**
	 * TU1: topics and lessons are added as Tutor's builder adds them and come back in the curriculum.
	 */
	public function test_curriculum(): void {
		$course = self::course();
		$topic  = $this->ok(
			'kuduy-tutor-curriculum-add',
			array(
				'type'      => 'topic',
				'course_id' => $course,
				'title'     => 'Kuduy topic one',
				'summary'   => 'First steps',
			)
		);
		$this->assertSame( $course, $topic['parent'] );
		$second = $this->ok(
			'kuduy-tutor-curriculum-add',
			array(
				'type'      => 'topic',
				'course_id' => $course,
				'title'     => 'Kuduy topic two',
			)
		);
		$this->assertGreaterThan( $topic['order'], $second['order'], 'Tutor\'s order helper places the new topic last.' );
		$lesson = $this->ok(
			'kuduy-tutor-curriculum-add',
			array(
				'type'     => 'lesson',
				'topic_id' => $topic['id'],
				'title'    => 'Kuduy lesson',
				'content'  => '<p>Hello</p>',
			)
		);
		$this->assertSame( tutor()->lesson_post_type, get_post_type( $lesson['id'] ) );

		$got = $this->ok( 'kuduy-tutor-course-get', array( 'course_id' => $course ) );
		$this->assertSame( array( 'Kuduy topic one', 'Kuduy topic two' ), array_column( $got['topics'], 'title' ) );
		$this->assertSame( 'Kuduy lesson', $got['topics'][0]['items'][0]['title'] ?? null );

		$this->fails(
			'kuduy-tutor-curriculum-add',
			array(
				'type'     => 'lesson',
				'topic_id' => $course,
				'title'    => 'Lesson under a course',
			),
			'a lesson needs a topic'
		);
	}

	/**
	 * TU2: enrolling through Tutor, listing students, changing status and undoing it.
	 */
	public function test_enrollment(): void {
		$course = self::course();
		$first  = $this->ok(
			'kuduy-tutor-enroll',
			array(
				'course_id' => $course,
				'user_id'   => self::$student,
			)
		);
		$this->assertTrue( $first['created'] );
		$this->assertSame( 'completed', $first['status'], 'A free course enrolls directly.' );
		$again = $this->ok(
			'kuduy-tutor-enroll',
			array(
				'course_id' => $course,
				'user_id'   => self::$student,
			)
		);
		$this->assertFalse( $again['created'] );
		$this->assertSame( $first['enrollment_id'], $again['enrollment_id'] );

		$students = $this->ok( 'kuduy-tutor-students', array( 'course_id' => $course ) );
		$this->assertSame( 1, $students['total'] );
		$this->assertSame( self::$student, $students['students'][0]['user_id'] );

		$cancel = $this->ok(
			'kuduy-tutor-enrollment-status',
			array(
				'course_id'      => $course,
				'enrollment_ids' => array( $first['enrollment_id'] ),
				'status'         => 'cancel',
			)
		);
		$this->assertSame( 'cancel', $cancel['students'][0]['status'] );
		$this->ok( 'kuduy-snapshot-restore', array( 'snapshot_id' => $cancel['_snapshot_id'] ) );
		clean_post_cache( $first['enrollment_id'] );
		$this->assertSame( 'completed', get_post_status( $first['enrollment_id'] ), 'Undo puts the previous status back.' );

		$other = self::course();
		$this->fails(
			'kuduy-tutor-enrollment-status',
			array(
				'course_id'      => $other,
				'enrollment_ids' => array( $first['enrollment_id'] ),
				'status'         => 'cancel',
			),
			'an enrollment of another course is refused'
		);
		$this->assertSame( 'completed', get_post_status( $first['enrollment_id'] ) );
	}

	/**
	 * TU3: course list and quiz attempts read.
	 */
	public function test_reads(): void {
		$course = self::course();
		$rows   = array_column( $this->ok( 'kuduy-tutor-courses', array( 'search' => 'Kuduy Tutor course' ) )['courses'], null, 'id' );
		$this->assertArrayHasKey( $course, $rows );
		$this->assertSame( 0, $this->ok( 'kuduy-tutor-quiz-attempts', array( 'course_id' => $course ) )['total'] );
	}

	/**
	 * TU4: Tutor's own rule decides who works on a course.
	 */
	public function test_only_managers(): void {
		$course = self::course();
		wp_set_current_user( self::make_user( 'editor' ) );
		$this->fails( 'kuduy-tutor-course-get', array( 'course_id' => $course ), 'not an instructor of this course' );
		$this->fails(
			'kuduy-tutor-enroll',
			array(
				'course_id' => $course,
				'user_id'   => self::$student,
			),
			'not an instructor of this course'
		);
	}
}
