<?php
/**
 * MCP prompts (phase N, D73): built-in workflows and the site's own skills.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Memory\MemoryStore;

/**
 * Built-in prompts are short workflows that name the KuDiagnostics tools to use, in order. Site skills are shared,
 * current memories of kind "skill" (saved with kuduy-memory-save); they are offered as prompts "skill-{id}" with the
 * memory's title and body (the body passed the memory secret check when it was saved). A prompt's text is filled with
 * the arguments the user gives; nothing is run.
 */
final class Prompts {

	/**
	 * Built-in prompts: name => [title, description, arguments (name => [description, required]), text with {arg}].
	 */
	private const BUILT_IN = array(
		'site-audit'    => array(
			'Audit this site',
			'A health, content, SEO and integration check of the site, ending with a short list of fixes by priority.',
			array( 'focus' => array( 'What to look at most (e.g. "speed", "SEO", "forms"); empty for everything.', false ) ),
			"Audit this WordPress site{focus_line}.\n\n1. kuduy-site-info and kuduy-site-health for versions, health and the active theme and plugins.\n2. kuduy-integrations and kuduy-integration-diagnose for what is installed, inactive or blocked.\n3. kuduy-memory-context for what is already known about this site.\n4. kuduy-seo-audit for titles, descriptions and indexing; kuduy-media-list for images without alt text.\n5. kuduy-plugin-list for updates waiting.\n\nThen give me the findings as a short list ordered by priority, each with the tool that would fix it. Change nothing yet.",
		),
		'build-page'    => array(
			'Build a page',
			'Plan and build a new page with the site\'s page builder, matching the site design, as a draft.',
			array(
				'goal'    => array( 'What the page is for and what it should contain.', true ),
				'builder' => array( 'Builder to use (e.g. "elementor", "blocks"); empty to use the one the site uses.', false ),
			),
			"Build a new page: {goal}.\n\n1. kuduy-design-scan for the site's colors, fonts and widths, and kuduy-memory-context for design decisions already made.\n2. kuduy-integrations to see which builder is active{builder_line}.\n3. Show me the outline (sections and their content) before building anything.\n4. After I agree, create it as a draft with the builder's tools (e.g. kuduy-elementor-create or kuduy-post-create with blocks) and check it with the builder's verify or get tool.\n5. Give me the draft link and save any new design decision with kuduy-memory-save (kind \"design\").",
		),
		'seo-review'    => array(
			'Review SEO of a post',
			'SEO title, description, headings, links and images of one post or page, with concrete changes to approve.',
			array( 'post' => array( 'Post or page id, title or address.', true ) ),
			"Review the SEO of {post}.\n\n1. Find it with kuduy-post-list (or kuduy-post-get if it is an id) and read it with kuduy-post-get.\n2. kuduy-seo-get for its SEO title, description and indexing; kuduy-seo-status for the SEO plugin.\n3. Check the headings, internal links, image alt texts (kuduy-media-get) and the focus keyword.\n4. Propose exact changes (old and new text). Apply them with kuduy-seo-update and kuduy-post-update only after I agree, and check with kuduy-seo-verify.",
		),
		'safe-change'   => array(
			'Make a change safely',
			'Any change made the careful way: look first, preview, change, check, and know how to undo it.',
			array( 'change' => array( 'The change to make.', true ) ),
			"I want to make this change: {change}.\n\n1. Read the current state with the matching get or list tool and show me exactly what will change.\n2. Use tools that save a snapshot first (their result has _snapshot_id); where a tool asks for a confirm_token, show me its preview and wait for my yes.\n3. Make the change, then read it back to check it.\n4. Tell me the snapshot id so it can be undone with kuduy-snapshot-restore.",
		),
		'start-session' => array(
			'Start a working session',
			'Load what is known about the site (memory, integrations, design) before starting work.',
			array( 'project' => array( 'Project name to load memories for; empty for all.', false ) ),
			"Start a working session on this site{project_line}.\n\n1. kuduy-memory-context{project_arg} and tell me what is known, especially memories with conflicts (they may be outdated: ask me, then mark them with kuduy-memory-save status \"outdated\").\n2. kuduy-site-info and kuduy-integrations for what runs here now.\n3. Ask me what we are working on. While working, save decisions and facts worth keeping with kuduy-memory-save (never passwords, keys or tokens).",
		),
		'match-design'  => array(
			'Match the site design',
			'Make new or changed content follow the site\'s existing colors, fonts and spacing.',
			array( 'task' => array( 'What is being designed or changed.', true ) ),
			"Task: {task}.\n\nBefore designing, run kuduy-design-scan and read memories of kind \"design\" (kuduy-memory-search with kind \"design\"). Use only the site's colors, fonts, sizes and widths (prefer the theme's or the Elementor Kit's global values over fixed values), say which tokens you use, and save any new design decision with kuduy-memory-save (kind \"design\").",
		),
	);

	/**
	 * Site skills the current user may use: shared, current memories of kind "skill".
	 *
	 * @return list<array<string, mixed>>
	 */
	private static function skills(): array {
		if ( ! current_user_can( 'edit_posts' ) ) {
			return array();
		}
		[ $rows ] = MemoryStore::find(
			array(
				'kind'   => 'skill',
				'status' => 'current',
			),
			get_current_user_id(),
			100
		);
		return array_values( array_filter( $rows, static fn( array $r ): bool => 0 === (int) $r['user_id'] ) );
	}

	/**
	 * Prompts for prompts/list (and kuduy-skills).
	 *
	 * @return list<array<string, mixed>>
	 */
	public static function list(): array {
		$out = array();
		foreach ( self::BUILT_IN as $name => [ $title, $description, $args ] ) {
			$arguments = array();
			foreach ( $args as $arg => [ $help, $required ] ) {
				$arguments[] = array(
					'name'        => $arg,
					'description' => $help,
					'required'    => $required,
				);
			}
			$out[] = array(
				'name'        => $name,
				'title'       => $title,
				'description' => $description,
				'arguments'   => $arguments,
			);
		}
		foreach ( self::skills() as $row ) {
			$out[] = array(
				'name'        => 'skill-' . (int) $row['id'],
				'title'       => (string) $row['title'],
				'description' => 'Site skill' . ( '' !== (string) $row['project'] ? ' (project ' . (string) $row['project'] . ')' : '' ) . ': ' . mb_substr( wp_strip_all_tags( (string) $row['body'] ), 0, 160 ),
				'arguments'   => array(
					array(
						'name'        => 'details',
						'description' => 'Anything to add for this run.',
						'required'    => false,
					),
				),
			);
		}
		return $out;
	}

	/**
	 * One prompt, filled in.
	 *
	 * @param string               $name      Prompt name.
	 * @param array<string, mixed> $arguments Argument values.
	 * @return array{description: string, messages: list<array<string, mixed>>}
	 * @throws \InvalidArgumentException When the prompt is unknown or a required argument is missing.
	 */
	public static function get( string $name, array $arguments ): array {
		$args = array();
		foreach ( $arguments as $key => $value ) {
			if ( is_scalar( $value ) ) {
				$args[ (string) $key ] = mb_substr( trim( (string) $value ), 0, 2000 );
			}
		}
		if ( isset( self::BUILT_IN[ $name ] ) ) {
			[ , $description, $defined, $text ] = self::BUILT_IN[ $name ];
			foreach ( $defined as $arg => [ , $required ] ) {
				if ( $required && '' === ( $args[ $arg ] ?? '' ) ) {
					throw new \InvalidArgumentException( 'Prompt "' . $name . '" needs the argument "' . $arg . '".' );
				}
			}
			$focus   = $args['focus'] ?? '';
			$builder = $args['builder'] ?? '';
			$project = $args['project'] ?? '';
			$text    = strtr(
				$text,
				array(
					'{focus_line}'   => '' !== $focus ? ', with a focus on ' . $focus : '',
					'{builder_line}' => '' !== $builder ? ' (I want ' . $builder . ')' : '',
					'{project_line}' => '' !== $project ? ' for the project "' . $project . '"' : '',
					'{project_arg}'  => '' !== $project ? ' with project "' . $project . '"' : '',
					'{goal}'         => $args['goal'] ?? '',
					'{post}'         => $args['post'] ?? '',
					'{change}'       => $args['change'] ?? '',
					'{task}'         => $args['task'] ?? '',
				)
			);
			return self::message( $description, $text );
		}//end if
		if ( preg_match( '/^skill-(\d+)$/', $name, $m ) ) {
			foreach ( self::skills() as $row ) {
				if ( (int) $row['id'] === (int) $m[1] ) {
					$text = (string) $row['body'] . ( '' !== ( $args['details'] ?? '' ) ? "\n\nFor this run: " . $args['details'] : '' );
					return self::message( 'Site skill: ' . (string) $row['title'], $text );
				}
			}
		}
		throw new \InvalidArgumentException( 'Unknown prompt "' . $name . '". See prompts/list or kuduy-skills.' );
	}

	/**
	 * A prompt result with one user message.
	 *
	 * @param string $description Description.
	 * @param string $text        Message text.
	 * @return array{description: string, messages: list<array<string, mixed>>}
	 */
	private static function message( string $description, string $text ): array {
		return array(
			'description' => $description,
			'messages'    => array(
				array(
					'role'    => 'user',
					'content' => array(
						'type' => 'text',
						'text' => $text,
					),
				),
			),
		);
	}
}
