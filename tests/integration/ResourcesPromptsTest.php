<?php
/**
 * Phase N (test matrix RP1–RP6): MCP resources and prompts in both protocol eras, and the skills tools (D72, D73).
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Tests\Integration;

use Kuduy\Mcp\Mcp\Prompts;
use Kuduy\Mcp\Mcp\ToolRegistry;
use Kuduy\Mcp\Plugin;
use PHPUnit\Framework\TestCase;

require_once __DIR__ . '/CallsTools.php';

final class ResourcesPromptsTest extends TestCase {

	use CallsTools;

	private const MODERN = '2026-07-28';

	private static int $admin;

	public static function setUpBeforeClass(): void {
		if ( ! is_plugin_active( 'kuduy-mcp/kuduy-mcp.php' ) ) {
			activate_plugin( 'kuduy-mcp/kuduy-mcp.php' );
		}
		Plugin::boot();
		self::$admin = self::make_user( 'administrator' );
	}

	protected function setUp(): void {
		wp_set_current_user( self::$admin );
	}

	/**
	 * JSON-RPC to the endpoint: modern (stateless, with headers) or legacy (2025-11-25, no _meta).
	 *
	 * @param string               $method JSON-RPC method.
	 * @param array<string, mixed> $params Params.
	 * @param bool                 $modern Era.
	 * @return array{0:int, 1:array<string, mixed>}
	 */
	private function rpc( string $method, array $params, bool $modern ): array {
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		if ( $modern ) {
			$params['_meta'] = array(
				'io.modelcontextprotocol/protocolVersion' => self::MODERN,
				'io.modelcontextprotocol/clientCapabilities' => new \stdClass(),
			);
			$request->set_header( 'MCP-Protocol-Version', self::MODERN );
			$request->set_header( 'Mcp-Method', $method );
			if ( isset( $params['uri'] ) || isset( $params['name'] ) ) {
				$request->set_header( 'Mcp-Name', (string) ( $params['uri'] ?? $params['name'] ) );
			}
		} else {
			$request->set_header( 'MCP-Protocol-Version', '2025-11-25' );
		}
		$request->set_body(
			(string) wp_json_encode(
				array(
					'jsonrpc' => '2.0',
					'id'      => 3,
					'method'  => $method,
					'params'  => $params ? $params : new \stdClass(),
				)
			)
		);
		$response = rest_ensure_response( rest_get_server()->dispatch( $request ) );
		return array( $response->get_status(), json_decode( (string) wp_json_encode( $response->get_data() ), true ) );
	}

	/**
	 * RP1: capabilities announce resources and prompts in both eras.
	 */
	public function test_capabilities(): void {
		[ , $discover ] = $this->rpc( 'server/discover', array(), true );
		$this->assertSame( false, $discover['result']['capabilities']['resources']['subscribe'] );
		$this->assertArrayHasKey( 'prompts', $discover['result']['capabilities'] );
		$request = new \WP_REST_Request( 'POST', '/kuduy-mcp/v1/mcp' );
		$request->set_header( 'Content-Type', 'application/json' );
		$request->set_body( (string) wp_json_encode( array( 'jsonrpc' => '2.0', 'id' => 1, 'method' => 'initialize', 'params' => array( 'protocolVersion' => '2025-11-25', 'capabilities' => new \stdClass(), 'clientInfo' => array( 'name' => 't', 'version' => '1' ) ) ) ) ); // phpcs:ignore WordPress.Arrays.ArrayDeclarationSpacing.AssociativeArrayFound -- one-off request body.
		$init = rest_ensure_response( rest_get_server()->dispatch( $request ) )->get_data();
		$this->assertArrayHasKey( 'resources', $init['result']['capabilities'] );
		$this->assertArrayHasKey( 'prompts', $init['result']['capabilities'] );
	}

	/**
	 * RP2: resources listed and read in both eras; the text is the backing tool's JSON; modern results carry cache fields.
	 */
	public function test_resources(): void {
		foreach ( array( true, false ) as $modern ) {
			[ $status, $list ] = $this->rpc( 'resources/list', array(), $modern );
			$this->assertSame( 200, $status );
			$uris = array_column( $list['result']['resources'], 'uri' );
			foreach ( array( 'kuduy://site/overview', 'kuduy://site/integrations', 'kuduy://site/content-model', 'kuduy://memory/context', 'kuduy://design/tokens' ) as $uri ) {
				$this->assertContains( $uri, $uris );
			}
			[ , $read ] = $this->rpc( 'resources/read', array( 'uri' => 'kuduy://site/overview' ), $modern );
			$content    = $read['result']['contents'][0];
			$this->assertSame( 'kuduy://site/overview', $content['uri'] );
			$this->assertSame( 'application/json', $content['mimeType'] );
			$data = json_decode( $content['text'], true );
			$this->assertSame( (string) get_bloginfo( 'version' ), $data['wordpress_version'] ?? null );
			if ( $modern ) {
				$this->assertSame( 'complete', $read['result']['resultType'] );
				$this->assertSame( 'private', $read['result']['cacheScope'] );
				$this->assertSame( 0, $read['result']['ttlMs'] );
			}
			[ , $templates ] = $this->rpc( 'resources/templates/list', array(), $modern );
			$this->assertSame( array(), $templates['result']['resourceTemplates'] );
		}
	}

	/**
	 * RP3: unknown or forbidden resources are errors (never empty contents): -32602 modern, -32002 legacy; and a resource
	 * whose tool the user may not use is neither listed nor readable.
	 */
	public function test_resource_errors_and_access(): void {
		[ $status, $body ] = $this->rpc( 'resources/read', array( 'uri' => 'kuduy://no/such' ), true );
		$this->assertSame( 400, $status );
		$this->assertSame( -32602, $body['error']['code'] );
		$this->assertSame( 'kuduy://no/such', $body['error']['data']['uri'] );
		[ , $legacy ] = $this->rpc( 'resources/read', array( 'uri' => 'kuduy://no/such' ), false );
		$this->assertSame( -32002, $legacy['error']['code'] );

		wp_set_current_user( self::make_user( 'subscriber' ) );
		[ , $list ] = $this->rpc( 'resources/list', array(), true );
		$uris       = array_column( $list['result']['resources'], 'uri' );
		$this->assertNotContains( 'kuduy://memory/context', $uris, 'Memory needs edit_posts.' );
		[ , $read ] = $this->rpc( 'resources/read', array( 'uri' => 'kuduy://memory/context' ), true );
		$this->assertSame( -32602, $read['error']['code'] );

		wp_set_current_user( 0 );
		[ $status ] = $this->rpc( 'resources/list', array(), true );
		$this->assertSame( 401, $status, 'Signing in is required.' );
	}

	/**
	 * RP4: prompts listed and filled in both eras; a missing required argument and an unknown prompt are -32602.
	 */
	public function test_prompts(): void {
		foreach ( array( true, false ) as $modern ) {
			[ , $list ] = $this->rpc( 'prompts/list', array(), $modern );
			$names      = array_column( $list['result']['prompts'], 'name' );
			$this->assertSame( array( 'site-audit', 'build-page', 'seo-review', 'safe-change', 'start-session', 'match-design' ), array_slice( $names, 0, 6 ) );
			[ , $get ] = $this->rpc(
				'prompts/get',
				array(
					'name'      => 'seo-review',
					'arguments' => array( 'post' => 'Kuduy about page' ),
				),
				$modern
			);
			$message   = $get['result']['messages'][0];
			$this->assertSame( 'user', $message['role'] );
			$this->assertSame( 'text', $message['content']['type'] );
			$this->assertStringContainsString( 'Kuduy about page', $message['content']['text'] );
			[ , $missing ] = $this->rpc( 'prompts/get', array( 'name' => 'seo-review' ), $modern );
			$this->assertSame( -32602, $missing['error']['code'] );
			[ , $unknown ] = $this->rpc( 'prompts/get', array( 'name' => 'no-such-prompt' ), $modern );
			$this->assertSame( -32602, $unknown['error']['code'] );
		}
	}

	/**
	 * RP5: every tool a built-in prompt names exists.
	 */
	public function test_prompts_name_real_tools(): void {
		$tools = array_map( static fn( $t ) => $t->name(), ToolRegistry::create()->all() );
		foreach ( Prompts::list() as $prompt ) {
			if ( str_starts_with( $prompt['name'], 'skill-' ) ) {
				continue;
			}
			$args = array();
			foreach ( $prompt['arguments'] as $arg ) {
				$args[ $arg['name'] ] = 'x';
			}
			$text = Prompts::get( $prompt['name'], $args )['messages'][0]['content']['text'];
			preg_match_all( '/kuduy-[a-z0-9-]+[a-z0-9]/', $text, $m );
			foreach ( array_unique( $m[0] ) as $name ) {
				$this->assertContains( $name, $tools, $prompt['name'] . ' names ' . $name );
			}
		}
	}

	/**
	 * RP6: site skills — a shared memory of kind "skill" becomes a prompt and a skill; private ones do not.
	 */
	public function test_site_skills(): void {
		$skill      = $this->ok(
			'kuduy-memory-save',
			array(
				'kind'  => 'skill',
				'title' => 'Kuduy weekly product check',
				'body'  => 'List products without images with kuduy-woo-products, then report them.',
			)
		)['memory'];
		$private    = $this->ok(
			'kuduy-memory-save',
			array(
				'kind'   => 'skill',
				'title'  => 'Kuduy private skill',
				'shared' => false,
			)
		)['memory'];
		[ , $list ] = $this->rpc( 'prompts/list', array(), true );
		$names      = array_column( $list['result']['prompts'], 'name' );
		$this->assertContains( 'skill-' . $skill['id'], $names );
		$this->assertNotContains( 'skill-' . $private['id'], $names );
		[ , $get ] = $this->rpc(
			'prompts/get',
			array(
				'name'      => 'skill-' . $skill['id'],
				'arguments' => array( 'details' => 'only drafts' ),
			),
			true
		);
		$this->assertStringContainsString( 'only drafts', $get['result']['messages'][0]['content']['text'] );

		$listed = array_column( $this->ok( 'kuduy-skills' )['skills'], 'name' );
		$this->assertContains( 'skill-' . $skill['id'], $listed );
		$this->assertContains( 'safe-change', $listed );
		$filled = $this->ok(
			'kuduy-skill-get',
			array(
				'name'      => 'safe-change',
				'arguments' => array( 'change' => 'rename the menu' ),
			)
		);
		$this->assertStringContainsString( 'rename the menu', $filled['text'] );
		$this->fails( 'kuduy-skill-get', array( 'name' => 'safe-change' ), 'missing argument' );
	}
}
