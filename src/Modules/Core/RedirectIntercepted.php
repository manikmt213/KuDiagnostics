<?php
/**
 * Thrown when a plugin tries to redirect the browser while KuDiagnostics activates it.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Core;

/**
 * Some plugins send the browser to their welcome page right after activation with exit( wp_redirect( … ) ) (Breakdance
 * 3.0.0-beta.4 does this on its first activation, in an "activated_plugin" callback). In an MCP request that exit
 * would end the request and return the redirect instead of the tool result, so the redirect is stopped with this
 * exception (thrown from the "wp_redirect" filter, before the exit runs) and reported. The message is the location.
 */
final class RedirectIntercepted extends \RuntimeException {}
