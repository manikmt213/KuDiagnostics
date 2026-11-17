<?php
/**
 * Contract for tools whose effect needs a two-step confirmation (decision D45).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Execution;

/**
 * A high-risk tool (permanent delete, refund, uninstall, …). The executor never runs `execute()` on the first
 * call: it returns `preview()` with a single-use token, and runs the tool only when the same user sends the same
 * arguments again with that token and the target has not changed in between.
 *
 * The tool adds `Confirmation::token_input()` to its input schema and `Confirmation::output_properties()` to its
 * output schema, and sets no top-level `required` in the output schema (a preview result has other fields).
 */
interface Confirmable {

	/**
	 * What the call would do, for the user to decide on. Changes nothing. Must include `version`: a fingerprint of
	 * the target's current state (e.g. a hash of its modified date and size); the executor compares it at
	 * confirmation and removes it from the result. Throw ToolError when the call cannot run at all.
	 *
	 * @param array<string, mixed> $input Validated arguments, without the confirmation token.
	 * @return array<string, mixed>
	 */
	public function preview( array $input ): array;
}
