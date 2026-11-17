<?php
/**
 * Tools: kuduy-code-snippets-save, kuduy-code-snippets-php-save.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Code;

use Kuduy\Mcp\Execution\Confirmable;

/**
 * The saving modes of CodeSnippets, run in two steps with a confirmation token (D45): code that runs on the site is
 * never changed or switched on in one call.
 */
final class CodeSnippetSave extends CodeSnippets implements Confirmable {}
