<?php
/**
 * Tools: kuduy-plugin-upload, kuduy-plugin-delete (two-step).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Confirmable;

/**
 * The installing and deleting modes of PluginAdmin, run in two steps with a confirmation token (D45): plugin code is
 * never added to or removed from the site in one call.
 */
final class PluginAdminConfirm extends PluginAdmin implements Confirmable {}
