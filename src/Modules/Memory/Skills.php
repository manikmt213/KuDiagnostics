<?php
/**
 * Tools: kuduy-skills, kuduy-skill-get.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Memory;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\Prompts;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Prompts as tools (phase N, D73; D32), for clients that do not show MCP prompts: the built-in workflows and the site's
 * own skills (shared memories of kind "skill"), listed and returned as filled-in text.
 */
final class Skills implements Tool {

	/**
	 * Mode.
	 *
	 * @param string $mode list | get.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'list' === $this->mode ? 'kuduy-skills' : 'kuduy-skill-get';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return 'list' === $this->mode ? __( 'List workflows and site skills', 'kuduy-mcp' ) : __( 'Get a workflow or site skill', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'list' === $this->mode
			? 'Lists the ready-made workflows (site-audit, build-page, seo-review, safe-change, start-session, match-design) and the site\'s own skills (saved with kuduy-memory-save, kind "skill", shared): name, title, description and arguments. The same as the server\'s MCP prompts, for clients that do not show prompts.'
			: 'Returns the instructions of one workflow or site skill (name from kuduy-skills), filled in with its arguments, to follow step by step. Nothing is run.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		if ( 'list' === $this->mode ) {
			return array(
				'type'                 => 'object',
				'properties'           => array(),
				'additionalProperties' => false,
			);
		}
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'      => array(
					'type'    => 'string',
					'pattern' => '^[a-z0-9-]{1,60}$',
				),
				'arguments' => array(
					'type'                 => 'object',
					'additionalProperties' => array(
						'type'      => 'string',
						'maxLength' => 2000,
					),
				),
			),
			'required'             => array( 'name' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'skills'      => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'name'        => array( 'type' => 'string' ),
				'description' => array( 'type' => 'string' ),
				'text'        => array( 'type' => 'string' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		return Content::read_only();
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		return 'read';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool {
		return true;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the workflow is unknown or a required argument is missing.
	 */
	public function execute( array $input ): array {
		if ( 'list' === $this->mode ) {
			return array( 'skills' => Prompts::list() );
		}
		try {
			$prompt = Prompts::get( (string) $input['name'], (array) ( $input['arguments'] ?? array() ) );
		} catch ( \InvalidArgumentException $e ) {
			throw ToolError::of( ToolError::INVALID_ARGUMENT, $e->getMessage() );
		}
		return array(
			'name'        => (string) $input['name'],
			'description' => $prompt['description'],
			'text'        => (string) $prompt['messages'][0]['content']['text'],
		);
	}
}
