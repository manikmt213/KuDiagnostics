<?php
/**
 * Tools: kuduy-elementor-forms, kuduy-elementor-form-submissions, kuduy-elementor-form-submission-get,
 * kuduy-elementor-form-submission-update (one class, four modes).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Forms\Mask;

/**
 * Elementor Pro forms (phase Q5). Form widgets are read from the pages that hold them (widget "form": form_name,
 * form_fields, submit_actions, email settings); they are changed like any widget with kuduy-elementor-edit.
 * Submissions go through Elementor Pro's own store, ElementorPro\Modules\Forms\Submissions\Database\Query (read on Pro
 * 4.0.4 on a live site, 2026-11-18): get_submissions() with filters status (all, unread, read, trash), form
 * ("{post_id}_{element_id}") and search, get_submission(), update_submission() for the read flag,
 * move_to_trash_submission() and restore(). Like Pro's own submissions screen they need manage_options. Visitors'
 * personal data is masked unless include_pii is asked (D17); permanent deletion is not offered.
 */
final class ElementorForms implements Tool, Snapshottable {

	private const QUERY = '\ElementorPro\Modules\Forms\Submissions\Database\Query';

	/**
	 * Elementor form field types => personal data kind (Mask).
	 */
	private const KINDS = array(
		'email'    => 'email',
		'tel'      => 'phone',
		'upload'   => 'file',
		'password' => 'password',
	);

	/**
	 * Mode.
	 *
	 * @param string $mode forms | submissions | submission-get | submission-update.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'forms' === $this->mode ? 'kuduy-elementor-forms' : 'kuduy-elementor-form-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'forms'          => __( 'Elementor Pro forms', 'kuduy-mcp' ),
			'submissions'    => __( 'Elementor Pro form submissions', 'kuduy-mcp' ),
			'submission-get' => __( 'Read an Elementor Pro form submission', 'kuduy-mcp' ),
			default          => __( 'Mark or trash an Elementor Pro form submission', 'kuduy-mcp' ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$pii = ' Visitors\' personal data (email, phone, name, address, file fields, IP address, browser) is masked unless include_pii is true.';
		return match ( $this->mode ) {
			'forms'          => 'Lists the Elementor Pro form widgets on this site: form (key "{post_id}_{element_id}" used by the submission tools), page, form name, fields (id, label, type, required, options), the actions run after sending (email, redirect, save to database, webhook, …), email settings (to, subject, from) and how many submissions are stored. Filter with post_id or search. Change a form like any widget with kuduy-elementor-edit (element_id, settings form_fields / submit_actions / email_to …; see kuduy-elementor-widget-schema "form").',
			'submissions'    => 'Lists form submissions stored by Elementor Pro (its "Collect submissions" action), newest first: id, form, page, date, read, status, field values. Filter by form (key from kuduy-elementor-forms), status (all = not trashed, unread, read, trash) and search; page and per_page (max 100). Needs a site manager, as Pro\'s Submissions screen.' . $pii,
			'submission-get' => 'Returns one Elementor Pro form submission: form, page, date, read, status, every field value with its label, and the log of the actions that ran after it was sent (email, webhook, …: status and message).' . $pii,
			default          => 'Marks an Elementor Pro form submission read or unread (read) and/or moves it to the trash or back (status: trash or active), as Pro\'s Submissions screen does. Permanent deletion is not offered. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$pii   = array(
			'type'        => 'boolean',
			'default'     => false,
			'description' => 'Show visitors\' personal data in full.',
		);
		$props = match ( $this->mode ) {
			'forms'          => array(
				'post_id' => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'search'  => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
			'submissions'    => array(
				'form'        => array(
					'type'    => 'string',
					'pattern' => '^[0-9]+_[A-Za-z0-9]+$',
				),
				'status'      => array(
					'type'    => 'string',
					'enum'    => array( 'all', 'unread', 'read', 'trash' ),
					'default' => 'all',
				),
				'search'      => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'include_pii' => $pii,
			) + Content::paging_input(),
			'submission-get' => array(
				'id'          => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'include_pii' => $pii,
			),
			default          => array(
				'id'     => array(
					'type'    => 'integer',
					'minimum' => 1,
				),
				'read'   => array( 'type' => 'boolean' ),
				'status' => array(
					'type' => 'string',
					'enum' => array( 'active', 'trash' ),
				),
			),
		};//end match
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => in_array( $this->mode, array( 'submission-get', 'submission-update' ), true ) ? array( 'id' ) : array(),
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
		return 'submission-update' === $this->mode
			? array(
				'readOnlyHint'    => false,
				'destructiveHint' => true,
				'idempotentHint'  => true,
				'openWorldHint'   => false,
			)
			: Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'submission-update' === $this->mode ? 'kuduy:write' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'forms' === $this->mode ? 'edit_posts' : 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$base = Elementor::available();
		if ( true !== $base ) {
			return $base;
		}
		if ( ! defined( 'ELEMENTOR_PRO_VERSION' ) ) {
			return 'Elementor Pro is not active on this site (forms are an Elementor Pro widget).';
		}
		return 'forms' === $this->mode || class_exists( ltrim( self::QUERY, '\\' ) )
			? true
			: 'This Elementor Pro version keeps no form submissions on the site.';
	}

	/**
	 * Pro's submissions store.
	 */
	private static function query(): object {
		return call_user_func( array( self::QUERY, 'get_instance' ) );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the submission is missing or Pro refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'forms'          => self::forms( $input ),
			'submissions'    => self::submissions( $input ),
			'submission-get' => self::masked( self::submission( (int) $input['id'] ), ! empty( $input['include_pii'] ) ),
			default          => self::update( $input ),
		};
	}

	/**
	 * Form widgets of the site the user may edit.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function forms( array $input ): array {
		global $wpdb;
		$like = '%' . $wpdb->esc_like( '"widgetType":"form"' ) . '%';
		$sql  = "SELECT p.ID FROM {$wpdb->posts} p JOIN {$wpdb->postmeta} m ON m.post_id = p.ID AND m.meta_key = '_elementor_data' WHERE p.post_type NOT IN ('revision') AND p.post_status NOT IN ('auto-draft', 'trash', 'inherit') AND m.meta_value LIKE %s";
		$args = array( $like );
		if ( ! empty( $input['post_id'] ) ) {
			$sql   .= ' AND p.ID = %d';
			$args[] = (int) $input['post_id'];
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.NotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- read-only and prepared (the placeholders are in $sql); no API finds widgets across documents.
		$ids    = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( $sql . ' ORDER BY p.ID DESC LIMIT 200', $args ) ) );
		$search = strtolower( (string) ( $input['search'] ?? '' ) );
		$out    = array();
		foreach ( $ids as $id ) {
			if ( ! current_user_can( 'edit_post', $id ) ) {
				continue;
			}
			$data = json_decode( (string) get_post_meta( $id, '_elementor_data', true ), true );
			if ( ! is_array( $data ) ) {
				continue;
			}
			Elementor::walk(
				array_values( $data ),
				static function ( array $element ) use ( $id, $search, &$out ): void {
					if ( 'form' !== Elementor::type( $element ) ) {
						return;
					}
					$settings = (array) ( $element['settings'] ?? array() );
					$key      = $id . '_' . (string) ( $element['id'] ?? '' );
					$name     = (string) ( $settings['form_name'] ?? 'New Form' );
					if ( '' !== $search && ! str_contains( strtolower( $name . ' ' . get_the_title( $id ) ), $search ) ) {
						return;
					}
					$fields = array();
					foreach ( (array) ( $settings['form_fields'] ?? array() ) as $field ) {
						$field    = (array) $field;
						$fields[] = array(
							'id'       => (string) ( $field['custom_id'] ?? '' ),
							'label'    => (string) ( $field['field_label'] ?? '' ),
							'type'     => (string) ( $field['field_type'] ?? 'text' ),
							'required' => ! empty( $field['required'] ),
							'options'  => '' !== (string) ( $field['field_options'] ?? '' ) ? array_values( array_filter( array_map( 'trim', explode( "\n", (string) $field['field_options'] ) ) ) ) : array(),
						);
					}
					$out[] = array(
						'form'        => $key,
						'post_id'     => $id,
						'page'        => get_the_title( $id ),
						'element_id'  => (string) ( $element['id'] ?? '' ),
						'form_name'   => $name,
						'fields'      => $fields,
						'actions'     => array_values( array_map( 'strval', (array) ( $settings['submit_actions'] ?? array( 'email' ) ) ) ),
						'email'       => array(
							'to'      => (string) ( $settings['email_to'] ?? '' ),
							'subject' => (string) ( $settings['email_subject'] ?? '' ),
							'from'    => (string) ( $settings['email_from'] ?? '' ),
						),
						'submissions' => self::count( $key ),
					);
				}
			);
		}//end foreach
		return array(
			'count' => count( $out ),
			'forms' => $out,
		);
	}

	/**
	 * Stored submissions of a form (not trashed), through Pro's own query, or null when the user may not read them or
	 * none are kept.
	 *
	 * @param string $key Form key.
	 */
	private static function count( string $key ): ?int {
		if ( ! current_user_can( 'manage_options' ) || ! class_exists( ltrim( self::QUERY, '\\' ) ) ) {
			return null;
		}
		$result = (array) self::query()->get_submissions(
			array(
				'page'     => 1,
				'per_page' => 1,
				'filters'  => array(
					'status' => array( 'value' => 'all' ),
					'form'   => array( 'value' => $key ),
				),
			)
		);
		return (int) ( $result['meta']['pagination']['total'] ?? 0 );
	}

	/**
	 * Submissions of a form or of all forms.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 */
	private static function submissions( array $input ): array {
		$filters = array( 'status' => array( 'value' => (string) ( $input['status'] ?? 'all' ) ) );
		if ( ! empty( $input['form'] ) ) {
			$filters['form'] = array( 'value' => (string) $input['form'] );
			AuditLog::target( 'elementor_form', (string) $input['form'] );
		}
		if ( ! empty( $input['search'] ) ) {
			$filters['search'] = array( 'value' => (string) $input['search'] );
		}
		$per    = (int) ( $input['per_page'] ?? 20 );
		$result = (array) self::query()->get_submissions(
			array(
				'page'             => (int) ( $input['page'] ?? 1 ),
				'per_page'         => $per,
				'filters'          => $filters,
				'order'            => array(
					'order' => 'desc',
					'by'    => 'created_at',
				),
				'with_meta'        => true,
				'with_form_fields' => true,
			)
		);
		$full   = ! empty( $input['include_pii'] );
		$out    = array();
		foreach ( (array) ( $result['data'] ?? array() ) as $row ) {
			$out[] = self::masked( self::shape( (array) $row ), $full );
		}
		return array(
			'submissions' => $out,
			'total'       => (int) ( $result['meta']['pagination']['total'] ?? count( $out ) ),
		);
	}

	/**
	 * One submission in the tool's shape, or a ToolError.
	 *
	 * @param int $id Submission id.
	 * @return array<string, mixed>
	 * @throws ToolError When there is none.
	 */
	private static function submission( int $id ): array {
		$found = self::query()->get_submission( $id );
		if ( ! is_array( $found ) || empty( $found['data'] ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No Elementor Pro form submission with id ' . $id . '.' );
		}
		AuditLog::target( 'elementor_submission', (string) $id );
		$out        = self::shape( (array) $found['data'] );
		$out['log'] = array();
		foreach ( (array) ( $found['data']['form_actions_log'] ?? array() ) as $log ) {
			$log          = (array) $log;
			$out['log'][] = array(
				'action'  => (string) ( $log['name'] ?? $log['action_name'] ?? '' ),
				'status'  => (string) ( $log['status'] ?? '' ),
				'message' => (string) ( $log['log'] ?? '' ),
				'date'    => (string) ( $log['created_at'] ?? '' ),
			);
		}
		return $out;
	}

	/**
	 * A submission from Pro's store in the shape of the forms tools (fields with name, label, kind, value).
	 *
	 * @param array<string, mixed> $row Submission as Query returns it.
	 * @return array<string, mixed>
	 */
	private static function shape( array $row ): array {
		$labels = array();
		$types  = array();
		foreach ( (array) ( $row['form']['fields'] ?? array() ) as $field ) {
			$field          = (array) $field;
			$fid            = (string) ( $field['id'] ?? '' );
			$labels[ $fid ] = (string) ( $field['label'] ?? $fid );
			$types[ $fid ]  = (string) ( $field['type'] ?? 'text' );
		}
		$fields = array();
		foreach ( (array) ( $row['values'] ?? array() ) as $value ) {
			$value    = (array) $value;
			$key      = (string) ( $value['key'] ?? '' );
			$type     = $types[ $key ] ?? 'text';
			$fields[] = array(
				'name'  => $key,
				'label' => $labels[ $key ] ?? $key,
				'kind'  => self::KINDS[ $type ] ?? Mask::kind_from_name( $key . ' ' . ( $labels[ $key ] ?? '' ) ),
				'value' => $value['value'] ?? '',
			);
		}
		$post = (array) ( $row['post'] ?? array() );
		return array(
			'plugin'    => 'elementor-pro',
			'id'        => (int) ( $row['id'] ?? 0 ),
			'form'      => (int) ( $post['id'] ?? 0 ) . '_' . (string) ( $row['element_id'] ?? '' ),
			'form_name' => (string) ( $row['form']['name'] ?? '' ),
			'post_id'   => (int) ( $post['id'] ?? 0 ),
			'date'      => (string) ( $row['created_at'] ?? '' ),
			'status'    => 'trash' === ( $row['status'] ?? '' ) ? 'trash' : 'active',
			'read'      => ! empty( $row['is_read'] ),
			'starred'   => null,
			'fields'    => $fields,
			'meta'      => array(
				'ip'         => (string) ( $row['user_ip'] ?? '' ),
				'user_agent' => (string) ( $row['user_agent'] ?? '' ),
				'source_url' => (string) ( $row['referer'] ?? '' ),
			),
		);
	}

	/**
	 * Mask personal data unless asked.
	 *
	 * @param array<string, mixed> $entry Entry.
	 * @param bool                 $full  Show in full.
	 * @return array<string, mixed>
	 */
	private static function masked( array $entry, bool $full ): array {
		return Mask::entry( $entry, $full );
	}

	/**
	 * Read flag and status of a submission.
	 *
	 * @param int $id Submission id.
	 * @return array{read: bool, status: string}
	 */
	private static function state( int $id ): array {
		$entry = self::submission( $id );
		return array(
			'read'   => (bool) $entry['read'],
			'status' => (string) $entry['status'],
		);
	}

	/**
	 * Apply read and status.
	 *
	 * @param int                  $id     Submission id.
	 * @param array<string, mixed> $change read, status.
	 * @throws ToolError When Pro fails.
	 */
	private static function apply( int $id, array $change ): void {
		$query = self::query();
		$ok    = true;
		if ( isset( $change['status'] ) ) {
			$ok = false !== ( 'trash' === $change['status'] ? $query->move_to_trash_submission( $id ) : $query->restore( $id ) );
		}
		if ( $ok && isset( $change['read'] ) ) {
			$ok = false !== $query->update_submission( $id, array( 'is_read' => $change['read'] ? 1 : 0 ) );
		}
		if ( ! $ok ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'Elementor Pro could not change submission ' . $id . '.' );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the submission is missing.
	 */
	public function snapshot( array $input ): ?array {
		if ( 'submission-update' !== $this->mode ) {
			return null;
		}
		$id = (int) $input['id'];
		return array(
			'object_type' => 'elementor_submission',
			'object_id'   => (string) $id,
			'payload'     => array( 'id' => $id ) + self::state( $id ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 * @throws ToolError When the submission is gone.
	 */
	public function restore( array $payload ): void {
		$id = (int) ( $payload['id'] ?? 0 );
		self::submission( $id );
		self::apply(
			$id,
			array(
				'status' => (string) ( $payload['status'] ?? 'active' ),
				'read'   => ! empty( $payload['read'] ),
			)
		);
	}

	/**
	 * Mark or trash a submission.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When nothing is asked or Pro fails.
	 */
	private static function update( array $input ): array {
		$id     = (int) $input['id'];
		$change = array_intersect_key( $input, array_flip( array( 'read', 'status' ) ) );
		if ( ! $change ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, 'Give read and/or status. Nothing was changed.' );
		}
		self::submission( $id );
		self::apply( $id, $change );
		return self::masked( self::submission( $id ), false );
	}
}
