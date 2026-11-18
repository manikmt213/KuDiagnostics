<?php
/**
 * Contract of a redirect store: one per SEO plugin with redirects, on that plugin's own save path (phase L2, D59).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Seo\Redirects;

use Kuduy\Mcp\Mcp\ToolError;

/**
 * What RedirectTools needs from an SEO plugin. A redirect is shown as id (string), source, target, type (301, 302,
 * 307, 410, 451), regex, enabled and hits (null where the plugin counts none).
 */
interface RedirectStore {

	/**
	 * Tool name part, e.g. "yoast" for kuduy-yoast-redirects.
	 */
	public function prefix(): string;

	/**
	 * Plugin name for messages.
	 */
	public function name(): string;

	/**
	 * True when the plugin's redirects are loaded, else why not.
	 */
	public function available(): bool|string;

	/**
	 * Throws when the current user may not manage the plugin's redirects (checked by the plugin's own rule).
	 *
	 * @throws ToolError When not allowed.
	 */
	public function check_access(): void;

	/**
	 * Redirects, newest first where the plugin orders them.
	 *
	 * @param string $search Text in source or target ('' = all).
	 * @param int    $page   Page (50 per page).
	 * @return array{redirects: list<array<string, mixed>>, total: int}
	 */
	public function all( string $search, int $page ): array;

	/**
	 * One redirect, or null.
	 *
	 * @param string $id Id.
	 * @return array<string, mixed>|null
	 */
	public function get( string $id ): ?array;

	/**
	 * Create (id null) or change a redirect. $fields holds only what is set: source, target, type, regex, enabled.
	 *
	 * @param string|null          $id     Id.
	 * @param array<string, mixed> $fields Fields.
	 * @return array{redirect: array<string, mixed>, warnings: list<string>}
	 * @throws ToolError When the plugin refuses; nothing was saved then.
	 */
	public function save( ?string $id, array $fields ): array;

	/**
	 * Delete a redirect.
	 *
	 * @param string $id Id.
	 * @throws ToolError When the plugin refuses.
	 */
	public function delete( string $id ): void;
}
