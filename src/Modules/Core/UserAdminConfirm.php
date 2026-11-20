<?php
/**
 * Tool: kuduy-user-delete (two-step).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

use Kuduy\Mcp\Execution\Confirmable;

/**
 * The delete mode of UserAdmin, run in two steps with a confirmation token (D45).
 */
final class UserAdminConfirm extends UserAdmin implements Confirmable {}
