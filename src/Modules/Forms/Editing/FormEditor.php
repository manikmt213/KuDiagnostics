<?php
/**
 * Contract of a form editor: one per forms plugin, on that plugin's own save path (phase B2, decision D57).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Forms\Editing;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * What FormEditTools needs from a forms plugin. Field ids are strings (WPForms and Fluent use keys inside the form,
 * Ninja Forms and Formidable use database ids). Field properties are the plugin's own names, as kuduy-P-field-types
 * lists them per type.
 */
interface FormEditor {

	/**
	 * Tool name part, e.g. "wpforms" for kuduy-wpforms-form-edit.
	 */
	public function prefix(): string;

	/**
	 * Plugin name for messages.
	 */
	public function name(): string;

	/**
	 * True when the plugin and the calls this editor uses are loaded, else why not.
	 */
	public function available(): bool|string;

	/**
	 * Whether the current user may create forms in the plugin.
	 */
	public function can_create(): bool;

	/**
	 * Whether the current user may change this form in the plugin.
	 *
	 * @param int $form_id Form id.
	 */
	public function can_edit( int $form_id ): bool;

	/**
	 * Field types offered on this site.
	 *
	 * @return list<array{type:string, label:string, properties:list<string>}>
	 */
	public function field_types(): array;

	/**
	 * The form as the tools show it: id, title, fields in order (id, type, label, required and the type's
	 * properties), submit_text, confirmation_message.
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>
	 * @throws ToolError When the form does not exist.
	 */
	public function form( int $form_id ): array;

	/**
	 * Check a new field's or a change's properties for the type. Changes nothing.
	 *
	 * @param string               $type       Field type.
	 * @param array<string, mixed> $properties Properties.
	 * @throws ToolError When the type is unknown or a property does not belong to it or has a bad value.
	 */
	public function check_properties( string $type, array $properties ): void;

	/**
	 * Number of entries that hold a value for the field (0 when the plugin keeps no entries on the site).
	 *
	 * @param int    $form_id  Form id.
	 * @param string $field_id Field id.
	 */
	public function entry_values( int $form_id, string $field_id ): int;

	/**
	 * Apply checked operations in one save where the plugin allows it.
	 *
	 * @param int                        $form_id    Form id.
	 * @param list<array<string, mixed>> $operations Operations (op add|update|move|remove|form).
	 * @return list<string> Ids of added fields, in the order of the add operations.
	 * @throws ToolError When the plugin refuses the save.
	 */
	public function apply( int $form_id, array $operations ): array;

	/**
	 * Create an empty form (the plugin's own defaults, e.g. its default notification and confirmation).
	 *
	 * @param string $title Title.
	 * @return int Form id.
	 * @throws ToolError When the plugin refuses.
	 */
	public function create( string $title ): int;

	/**
	 * Everything apply() can change, for undo.
	 *
	 * @param int $form_id Form id.
	 * @return array<string, mixed>
	 */
	public function snapshot( int $form_id ): array;

	/**
	 * Put a snapshot back through the plugin's own save path.
	 *
	 * @param int                  $form_id Form id.
	 * @param array<string, mixed> $state   From snapshot().
	 * @throws ToolError When it cannot be restored.
	 */
	public function restore( int $form_id, array $state ): void;

	/**
	 * Email notifications of the form: id, name, enabled, to, from_name, from_email, reply_to, cc, bcc, subject,
	 * message.
	 *
	 * @param int $form_id Form id.
	 * @return list<array<string, mixed>>
	 */
	public function notifications( int $form_id ): array;

	/**
	 * Create (id null) or change one notification; keys left out stay as they are.
	 *
	 * @param int                  $form_id Form id.
	 * @param string|null          $id      Notification id.
	 * @param array<string, mixed> $values  Values (keys as notifications() returns them).
	 * @return string Notification id.
	 * @throws ToolError When the plugin refuses.
	 */
	public function save_notification( int $form_id, ?string $id, array $values ): string;
}
