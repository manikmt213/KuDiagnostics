<?php
/**
 * Stand-in for Elementor Pro's form submissions store (no Pro copy on the development machine): the public methods of
 * ElementorPro\Modules\Forms\Submissions\Database\Query that KuDiagnostics calls, with the result shapes read on Pro
 * 4.0.4 on a live site (2026-11-18): get_submissions() { data, meta.pagination }, get_submission() { data } with
 * post.id, form.name / form.fields, values (id, key, value), form_actions_log (id, name, label, status, log), and the
 * status / is_read columns. The class is declared unconditionally (PHP hoists it), so the guard checks only the
 * constant. Kept in options instead of Pro's tables. Checks KuDiagnostics' side only. Loaded with
 * KUDUY_FAKES=elementor-forms.
 *
 * @package Kuduy\Mcp
 */

// phpcs:disable

namespace ElementorPro\Modules\Forms\Submissions\Database;

if ( defined( 'ELEMENTOR_PRO_VERSION' ) ) {
	return;
}

define( 'ELEMENTOR_PRO_VERSION', '4.0.4-kuduy-stand-in' );

class Query {

	const STATUS_NEW   = 'new';
	const STATUS_TRASH = 'trash';

	private static $instance;

	public static function get_instance() {
		if ( ! self::$instance ) {
			self::$instance = new Query();
		}
		return self::$instance;
	}

	public function get_table_submissions() {
		global $wpdb;
		return $wpdb->prefix . 'e_submissions';
	}

	/** Rows: id => [post_id, element_id, form_name, fields, values, status, is_read, ip, agent, created_at]. */
	private static function rows() {
		return (array) get_option( 'kuduy_fake_e_submissions', array() );
	}

	private static function save( $rows ) {
		update_option( 'kuduy_fake_e_submissions', $rows );
	}

	public static function add( array $row ) {
		$rows = self::rows();
		$id   = $rows ? max( array_keys( $rows ) ) + 1 : 1;
		$rows[ $id ] = $row + array(
			'status'     => self::STATUS_NEW,
			'is_read'    => 0,
			'created_at' => gmdate( 'Y-m-d H:i:s' ),
		);
		self::save( $rows );
		return $id;
	}

	private function body( $id, $row ) {
		$values = array();
		$n      = 0;
		foreach ( $row['values'] as $key => $value ) {
			$values[] = array(
				'id'    => ++$n,
				'key'   => $key,
				'value' => $value,
			);
		}
		return array(
			'id'         => (int) $id,
			'post'       => array( 'id' => (int) $row['post_id'] ),
			'form'       => array(
				'name'       => $row['form_name'],
				'element_id' => $row['element_id'],
				'fields'     => $row['fields'],
			),
			'element_id' => $row['element_id'],
			'user_ip'    => $row['ip'],
			'user_agent' => $row['agent'],
			'referer'    => 'https://example.com/contact/',
			'created_at' => $row['created_at'],
			'status'     => $row['status'],
			'is_read'    => (bool) $row['is_read'],
			'values'     => $values,
		);
	}

	public function get_submissions( $args = array() ) {
		$filters = $args['filters'] ?? array();
		$out     = array();
		foreach ( array_reverse( self::rows(), true ) as $id => $row ) {
			$status = $filters['status']['value'] ?? 'all';
			$ok     = match ( $status ) {
				'trash'  => self::STATUS_TRASH === $row['status'],
				'unread' => self::STATUS_NEW === $row['status'] && ! $row['is_read'],
				'read'   => self::STATUS_NEW === $row['status'] && $row['is_read'],
				default  => self::STATUS_TRASH !== $row['status'],
			};
			if ( isset( $filters['form']['value'] ) && $filters['form']['value'] !== $row['post_id'] . '_' . $row['element_id'] ) {
				$ok = false;
			}
			if ( isset( $filters['search']['value'] ) && ! str_contains( implode( ' ', $row['values'] ), $filters['search']['value'] ) ) {
				$ok = false;
			}
			if ( $ok ) {
				$out[] = $this->body( $id, $row );
			}
		}
		$per  = (int) ( $args['per_page'] ?? 10 );
		$page = (int) ( $args['page'] ?? 1 );
		return array(
			'data' => array_slice( $out, ( $page - 1 ) * $per, $per ),
			'meta' => array( 'pagination' => array( 'total' => count( $out ) ) ),
		);
	}

	public function get_submission( $id ) {
		$rows = self::rows();
		if ( ! isset( $rows[ $id ] ) ) {
			return null;
		}
		$data                     = $this->body( $id, $rows[ $id ] );
		$data['form_actions_log'] = array(
			array(
				'id'     => 1,
				'name'   => 'email',
				'label'  => 'Email',
				'status' => 'success',
				'log'    => null,
			),
		);
		return array(
			'data' => $data,
			'meta' => array(),
		);
	}

	public function update_submission( $submission_id, array $data, $values = array() ) {
		$rows = self::rows();
		if ( ! isset( $rows[ $submission_id ] ) ) {
			return false;
		}
		$rows[ $submission_id ] = array_merge( $rows[ $submission_id ], $data );
		self::save( $rows );
		return 1;
	}

	public function move_to_trash_submission( $id ) {
		return $this->update_submission( $id, array( 'status' => self::STATUS_TRASH ) );
	}

	public function restore( $id ) {
		return $this->update_submission( $id, array( 'status' => self::STATUS_NEW ) );
	}
}
