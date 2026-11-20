<?php
/**
 * Tools: kuduy-user-delete, kuduy-user-password-reset.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Confirmation;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;

/**
 * User administration (phase O, D75; D46). Delete through wp_delete_user() with the content reassigned to another
 * user (or, only when asked, deleted with the account): never the connected user, never the last administrator; two
 * steps with a confirmation token (UserAdminConfirm). No undo: bringing a user back would mean storing the password
 * hash, which KuDiagnostics never keeps. Password reset only through WordPress' retrieve_password(): WordPress emails
 * the user a link; nobody sets or sees the password.
 */
class UserAdmin implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode delete | password-reset.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-user-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'delete' === $this->mode ? __( 'Delete a user', 'kuduy-mcp' ) : __( 'Send a password reset email', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'delete' === $this->mode
			? 'Deletes a user account. Their posts, pages and other content go to reassign_to (another user\'s id); without reassign_to their content is deleted with them, which the preview counts. You cannot delete yourself or the last administrator. Two steps: the first call changes nothing and returns a preview (name, role, how much content) with a confirm_token; show it to the user and only if they agree call again with the same arguments and the token. This cannot be undone. Needs the admin access level and the right to delete users.'
			: 'Sends a user WordPress\' own password reset email (a link to choose a new password). Nobody sets or sees the password. The first call only says who would get the email; call again with confirm: true to send it. Needs the admin access level and the right to edit that user.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id    = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$props = array( 'user_id' => $id );
		if ( 'delete' === $this->mode ) {
			$props['reassign_to']       = $id;
			$props[ Confirmation::ARG ] = Confirmation::token_input();
		} else {
			$props['confirm'] = array( 'type' => 'boolean' );
		}
		return array(
			'type'                 => 'object',
			'properties'           => $props,
			'required'             => array( 'user_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => Confirmation::output_properties() + array(
				'user_id' => array( 'type' => 'integer' ),
				'deleted' => array( 'type' => 'boolean' ),
				'sent'    => array( 'type' => 'boolean' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => 'delete' === $this->mode,
			'idempotentHint'  => false,
			'openWorldHint'   => 'password-reset' === $this->mode,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:admin';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string|array {
		$id = (int) ( $input['user_id'] ?? 0 );
		if ( 'delete' === $this->mode ) {
			return $id > 0 ? array( 'delete_user', $id ) : 'delete_users';
		}
		return $id > 0 ? array( 'edit_user', $id ) : 'edit_users';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * A user that exists.
	 *
	 * @param int $id User id.
	 * @throws ToolError When there is none.
	 */
	private static function user( int $id ): \WP_User {
		$user = get_userdata( $id );
		if ( ! $user instanceof \WP_User ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No user with id ' . $id . ' (see kuduy-user-list).' );
		}
		return $user;
	}

	/**
	 * Number of content items a user wrote (any type and status, without revisions).
	 *
	 * @param int $id User id.
	 */
	private static function content( int $id ): int {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_author = %d AND post_type <> 'revision'", $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- count only, as WordPress' own users screen does.
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the user may not be deleted or the reassignment target is not valid.
	 */
	public function preview( array $input ): array {
		$user = self::user( (int) $input['user_id'] );
		if ( get_current_user_id() === $user->ID ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'You cannot delete your own account through MCP.' );
		}
		if ( in_array( 'administrator', (array) $user->roles, true ) ) {
			$admins = get_users(
				array(
					'role'   => 'administrator',
					'fields' => 'ID',
					'number' => 2,
				)
			);
			if ( count( $admins ) < 2 ) {
				throw ToolError::of( ToolError::PERMISSION_DENIED, $user->user_login . ' is the last administrator; the site would have none. Nothing was deleted.' );
			}
		}
		$reassign = null;
		if ( isset( $input['reassign_to'] ) ) {
			$reassign = self::user( (int) $input['reassign_to'] );
			if ( $reassign->ID === $user->ID ) {
				throw ToolError::of( ToolError::INVALID_ARGUMENT, 'reassign_to must be another user.' );
			}
		}
		$count = self::content( $user->ID );
		return array(
			'user_id' => $user->ID,
			'login'   => $user->user_login,
			'name'    => $user->display_name,
			'roles'   => array_values( (array) $user->roles ),
			'content' => $count,
			'effect'  => 0 === $count ? 'The user has no content.' : ( null !== $reassign ? $count . ( 1 === $count ? ' item goes' : ' items go' ) . ' to ' . $reassign->display_name . ' (id ' . $reassign->ID . ').' : $count . ( 1 === $count ? ' item is' : ' items are' ) . ' deleted with the account (give reassign_to to keep it).' ),
			'undo'    => 'Not possible: the account cannot be brought back.',
			'version' => md5( $user->ID . '|' . $user->user_login . '|' . implode( ',', (array) $user->roles ) . '|' . $count . '|' . ( null === $reassign ? '' : (string) $reassign->ID ) ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When WordPress refuses or the email cannot be sent.
	 */
	public function execute( array $input ): array {
		$user = self::user( (int) $input['user_id'] );
		AuditLog::target( 'user', (string) $user->ID );
		if ( 'delete' === $this->mode ) {
			$this->preview( $input );
			require_once ABSPATH . 'wp-admin/includes/user.php';
			if ( ! wp_delete_user( $user->ID, isset( $input['reassign_to'] ) ? (int) $input['reassign_to'] : null ) ) {
				throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not delete the user.' );
			}
			return array(
				'user_id' => $user->ID,
				'deleted' => true,
			);
		}
		if ( empty( $input['confirm'] ) ) {
			return array(
				'user_id' => $user->ID,
				'sent'    => false,
				'to'      => 'The account ' . $user->user_login . ' (its email address on file). Call again with confirm: true to send WordPress\' password reset email.',
			);
		}
		$sent = retrieve_password( $user->user_login );
		if ( is_wp_error( $sent ) ) {
			throw ToolError::of( ToolError::OPERATION_FAILED, 'WordPress did not send the email: ' . wp_strip_all_tags( $sent->get_error_message() ) );
		}
		return array(
			'user_id' => $user->ID,
			'sent'    => true,
		);
	}
}
