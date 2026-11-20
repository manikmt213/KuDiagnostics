<?php
/**
 * Tools: kuduy-wpcode-snippet-save, kuduy-wpcode-snippet-php-save, kuduy-wpcode-headers-footers-save.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Code;

use Kuduy\Mcp\Execution\Confirmable;

/**
 * The saving modes of WpCode, run in two steps with a confirmation token (D45): code that runs on the site is never
 * changed or switched on in one call.
 */
final class WpCodeSave extends WpCode implements Confirmable {}
