<?php
/**
 * Tools: kuduy-spectra-popups, -popup-get, -popup-save, -popup-trash, and the same for kuduy-spectra-blocks-*.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Blocks;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Execution\Snapshottable;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;
use Kuduy\Mcp\Modules\Core\PostWriter;

/**
 * Popups of Spectra Legacy and Spectra Blocks (phase C2). Both store a popup as a post of their own type (Spectra
 * Legacy 2.20.6: `spectra-popup`; Spectra Blocks 1.0.15: `spectra-blocks-popup`) whose content is one popup
 * builder block (`uagb/popup-builder` / `spectra/popup-builder`, holding the popup's design and trigger settings
 * as attributes) and three registered fields: type (unset, popup, banner), enabled (whether it shows on the site)
 * and repetition. Both plugins limit popups to site managers. Content is checked against the registered blocks
 * before saving; trashing keeps the popup restorable.
 */
final class SpectraPopups implements Tool, Snapshottable {

	/**
	 * Per plugin: post type, field prefix, popup builder block, display name.
	 *
	 * @var array<string, array{type: string, meta: string, block: string, label: string}>
	 */
	private const PLUGINS = array(
		'spectra'        => array(
			'type'  => 'spectra-popup',
			'meta'  => 'spectra-popup-',
			'block' => 'uagb/popup-builder',
			'label' => 'Spectra Legacy',
		),
		'spectra-blocks' => array(
			'type'  => 'spectra-blocks-popup',
			'meta'  => 'spectra-blocks-popup-',
			'block' => 'spectra/popup-builder',
			'label' => 'Spectra Blocks',
		),
	);

	/**
	 * Popup types both plugins know.
	 */
	private const TYPES = array( 'unset', 'popup', 'banner' );

	/**
	 * Plugin and mode.
	 *
	 * @param string $plugin spectra | spectra-blocks.
	 * @param string $mode   popups | popup-get | popup-save | popup-trash.
	 */
	public function __construct( private string $plugin, private string $mode ) {}

	/**
	 * Configuration of this plugin.
	 *
	 * @return array{type: string, meta: string, block: string, label: string}
	 */
	private function config(): array {
		return self::PLUGINS[ $this->plugin ];
	}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-' . $this->plugin . '-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		$b = $this->config()['label'];
		return match ( $this->mode ) {
			/* translators: %s: plugin name. */
			'popups'    => sprintf( __( 'List %s popups', 'kuduy-mcp' ), $b ),
			/* translators: %s: plugin name. */
			'popup-get' => sprintf( __( 'Read a %s popup', 'kuduy-mcp' ), $b ),
			/* translators: %s: plugin name. */
			'popup-save' => sprintf( __( 'Create or change a %s popup', 'kuduy-mcp' ), $b ),
			/* translators: %s: plugin name. */
			default     => sprintf( __( 'Trash a %s popup', 'kuduy-mcp' ), $b ),
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		$c = $this->config();
		$b = $c['label'];
		return match ( $this->mode ) {
			'popups'     => 'Lists ' . $b . ' popups (Popup Builder): id, title, status, type (popup, banner or unset), whether it is switched on for visitors, repetition. Filter by status. Its design and triggers are attributes of its ' . $c['block'] . ' block (kuduy-spectra' . ( 'spectra' === $this->plugin ? '' : '-blocks' ) . '-popup-get).',
			'popup-get'  => 'Returns one ' . $b . ' popup: title, status, type, switched on or off, repetition, its block markup and the attributes of its ' . $c['block'] . ' block (the popup\'s design and trigger settings; their names and allowed values: kuduy-block-schema with name ' . $c['block'] . ').',
			'popup-save' => 'Creates a ' . $b . ' popup (no id) or changes one (id): title, status (draft or publish), type (popup or banner), enabled (shown to visitors when published), repetition (how often it shows again), content (block markup: exactly one ' . $c['block'] . ' block with the popup\'s blocks inside; checked against the registered blocks first). Fields not given stay as they are. A new popup without content gets an empty ' . $c['block'] . ' block, to be filled in with content or kuduy-block-edit. Needs a site manager. Changes are snapshotted; undo with kuduy-snapshot-restore (a new popup: kuduy-' . $this->plugin . '-popup-trash).',
			default      => 'Moves a ' . $b . ' popup to the trash (it stops showing; WordPress keeps it for 30 days). Not deleted permanently. Snapshot first; undo with kuduy-snapshot-restore.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$id         = array(
			'type'    => 'integer',
			'minimum' => 1,
		);
		$properties = match ( $this->mode ) {
			'popups'     => array(
				'status' => array(
					'type'    => 'string',
					'enum'    => array( 'any', 'publish', 'draft', 'trash' ),
					'default' => 'any',
				),
			),
			'popup-save' => array(
				'id'         => $id,
				'title'      => array(
					'type'      => 'string',
					'maxLength' => 200,
				),
				'status'     => array(
					'type' => 'string',
					'enum' => array( 'draft', 'publish' ),
				),
				'type'       => array(
					'type' => 'string',
					'enum' => self::TYPES,
				),
				'enabled'    => array( 'type' => 'boolean' ),
				'repetition' => array(
					'type'    => 'integer',
					'minimum' => 1,
					'maximum' => 1000,
				),
				'content'    => array(
					'type'      => 'string',
					'maxLength' => 500000,
				),
			),
			default      => array( 'id' => $id ),
		};//end match
		$schema = array(
			'type'                 => 'object',
			'properties'           => $properties,
			'additionalProperties' => false,
		);
		if ( in_array( $this->mode, array( 'popup-get', 'popup-trash' ), true ) ) {
			$schema['required'] = array( 'id' );
		}
		return $schema;
	}

	/**
	 * {@inheritDoc}
	 */
	public function output_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'popups'       => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'id'           => array( 'type' => 'integer' ),
				'title'        => array( 'type' => 'string' ),
				'status'       => array( 'type' => 'string' ),
				'type'         => array( 'type' => 'string' ),
				'enabled'      => array( 'type' => 'boolean' ),
				'repetition'   => array( 'type' => 'integer' ),
				'content'      => array( 'type' => 'string' ),
				'attributes'   => array( 'type' => 'object' ),
				'_snapshot_id' => array( 'type' => 'integer' ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( in_array( $this->mode, array( 'popups', 'popup-get' ), true ) ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => 'popup-trash' === $this->mode,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return in_array( $this->mode, array( 'popups', 'popup-get' ), true ) ? 'kuduy:read' : 'kuduy:write';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// Both plugins map every popup capability to manage_options.
		return 'manage_options';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		$active = 'spectra' === $this->plugin ? defined( 'UAGB_VER' ) : defined( 'SPECTRA_BLOCKS_VER' );
		if ( ! $active ) {
			return $this->config()['label'] . ' is not active on this site.';
		}
		return post_type_exists( $this->config()['type'] ) ? true : $this->config()['label'] . '\'s Popup Builder is not available on this site.';
	}

	/**
	 * A popup of this plugin.
	 *
	 * @param int $id Post id.
	 * @throws ToolError When it is not one.
	 */
	private function popup( int $id ): \WP_Post {
		$post = get_post( $id );
		if ( ! $post instanceof \WP_Post || $this->config()['type'] !== $post->post_type ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No ' . $this->config()['label'] . ' popup with id ' . $id . ' (see kuduy-' . $this->plugin . '-popups).' );
		}
		return $post;
	}

	/**
	 * The popup's three fields.
	 *
	 * @param int $id Post id.
	 * @return array{type: string, enabled: bool, repetition: int}
	 */
	private function fields( int $id ): array {
		$m    = $this->config()['meta'];
		$type = (string) get_post_meta( $id, $m . 'type', true );
		$rep  = get_post_meta( $id, $m . 'repetition', true );
		return array(
			'type'       => '' === $type ? 'unset' : $type,
			'enabled'    => (bool) get_post_meta( $id, $m . 'enabled', true ),
			'repetition' => '' === $rep ? 1 : (int) $rep,
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function snapshot( array $input ): ?array {
		if ( ! isset( $input['id'] ) || in_array( $this->mode, array( 'popups', 'popup-get' ), true ) ) {
			return null;
		}
		$post = get_post( (int) $input['id'] );
		if ( ! $post instanceof \WP_Post || $this->config()['type'] !== $post->post_type ) {
			return null;
		}
		return array(
			'object_type' => 'post',
			'object_id'   => (string) $post->ID,
			'payload'     => array(
				'post'   => PostWriter::state( $post ),
				'fields' => $this->fields( $post->ID ),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $payload Saved state.
	 */
	public function restore( array $payload ): void {
		$post = (array) ( $payload['post'] ?? array() );
		PostWriter::restore_state( $post );
		$this->write_fields( (int) ( $post['id'] ?? 0 ), (array) ( $payload['fields'] ?? array() ) );
	}

	/**
	 * Store fields that are given.
	 *
	 * @param int                  $id     Post id.
	 * @param array<string, mixed> $fields type, enabled, repetition.
	 */
	private function write_fields( int $id, array $fields ): void {
		$m = $this->config()['meta'];
		if ( isset( $fields['type'] ) ) {
			update_post_meta( $id, $m . 'type', (string) $fields['type'] );
		}
		if ( isset( $fields['enabled'] ) ) {
			update_post_meta( $id, $m . 'enabled', (bool) $fields['enabled'] );
		}
		if ( isset( $fields['repetition'] ) ) {
			update_post_meta( $id, $m . 'repetition', (int) $fields['repetition'] );
		}
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the popup is unknown, its content is invalid or WordPress refuses.
	 */
	public function execute( array $input ): array {
		return match ( $this->mode ) {
			'popups'     => $this->listing( (string) ( $input['status'] ?? 'any' ) ),
			'popup-get'  => $this->read( $this->popup( (int) $input['id'] ) ),
			'popup-save' => $this->save( $input ),
			default      => $this->trash( $this->popup( (int) $input['id'] ) ),
		};
	}

	/**
	 * All popups.
	 *
	 * @param string $status Status filter.
	 * @return array<string, mixed>
	 */
	private function listing( string $status ): array {
		$out = array();
		foreach ( get_posts(
			array(
				'post_type'      => $this->config()['type'],
				'post_status'    => 'any' === $status ? array( 'publish', 'draft', 'pending', 'private', 'future' ) : $status,
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page -- the list tool has no paging; 200 popups is far above a real site.
				'orderby'        => 'ID',
				'order'          => 'DESC',
			)
		) as $post ) {
			$out[] = array(
				'id'     => $post->ID,
				'title'  => get_the_title( $post ),
				'status' => $post->post_status,
			) + $this->fields( $post->ID );
		}
		return array( 'popups' => $out );
	}

	/**
	 * One popup.
	 *
	 * @param \WP_Post $post Popup.
	 * @return array<string, mixed>
	 */
	private function read( \WP_Post $post ): array {
		AuditLog::target( 'post', (string) $post->ID );
		$attributes = array();
		foreach ( parse_blocks( $post->post_content ) as $block ) {
			if ( ( $block['blockName'] ?? '' ) === $this->config()['block'] ) {
				$attributes = (array) $block['attrs'];
				break;
			}
		}
		return array(
			'id'         => $post->ID,
			'title'      => $post->post_title,
			'status'     => $post->post_status,
			'content'    => $post->post_content,
			'attributes' => (object) $attributes,
		) + $this->fields( $post->ID );
	}

	/**
	 * Check popup content: one popup builder block, everything registered and valid.
	 *
	 * @param string $content Block markup.
	 * @throws ToolError When it is not valid popup content.
	 */
	private function check_content( string $content ): void {
		$blocks  = BlockTree::parse_markup( $content );
		$wrapper = $this->config()['block'];
		if ( 1 !== count( $blocks ) || ( $blocks[0]['blockName'] ?? '' ) !== $wrapper ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'Popup content must be exactly one ' . $this->config()['block'] . ' block with the popup\'s blocks inside it. Nothing was saved.' );
		}
		// Spectra Legacy registers its popup builder block only in the editor (JavaScript), so the wrapper is accepted
		// by name when the server does not know it; the blocks inside it are always checked.
		$name     = $this->config()['block'];
		$problems = array();
		if ( \WP_Block_Type_Registry::get_instance()->is_registered( $name ) ) {
			$problems = BlockTree::document_problems( $blocks );
		} else {
			foreach ( (array) ( $blocks[0]['innerBlocks'] ?? array() ) as $k => $inner ) {
				$problems = array_merge( $problems, BlockTree::problems( (array) $inner, $name, array( $name ), '0.' . $k ) );
			}
		}
		if ( $problems ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'The popup content was refused, nothing was saved: ' . implode( ' ', array_slice( $problems, 0, 10 ) ) );
		}
	}

	/**
	 * Create or change a popup.
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @return array<string, mixed>
	 * @throws ToolError When the content is invalid or WordPress refuses.
	 */
	private function save( array $input ): array {
		if ( isset( $input['content'] ) ) {
			$this->check_content( (string) $input['content'] );
		}
		$postarr = array();
		foreach ( array(
			'title'   => 'post_title',
			'status'  => 'post_status',
			'content' => 'post_content',
		) as $key => $field ) {
			if ( isset( $input[ $key ] ) ) {
				$postarr[ $field ] = (string) $input[ $key ];
			}
		}
		if ( isset( $input['id'] ) ) {
			$post = $this->popup( (int) $input['id'] );
			if ( $postarr ) {
				$result = wp_update_post( wp_slash( array( 'ID' => $post->ID ) + $postarr ), true );
				if ( is_wp_error( $result ) ) {
					throw new ToolError( 'WordPress refused the change: ' . wp_strip_all_tags( $result->get_error_message() ) );
				}
			}
			$id = $post->ID;
		} else {
			$result = wp_insert_post(
				wp_slash(
					$postarr + array(
						'post_type'    => $this->config()['type'],
						'post_status'  => 'draft',
						'post_title'   => 'Popup',
						'post_content' => '<!-- wp:' . $this->config()['block'] . ' /-->',
					)
				),
				true
			);
			if ( is_wp_error( $result ) ) {
				throw new ToolError( 'WordPress refused the popup: ' . wp_strip_all_tags( $result->get_error_message() ) );
			}
			$id = (int) $result;
		}//end if
		AuditLog::target( 'post', (string) $id );
		$this->write_fields( $id, $input );
		$fresh = get_post( $id );
		return $fresh instanceof \WP_Post ? $this->read( $fresh ) : array( 'id' => $id );
	}

	/**
	 * Trash a popup.
	 *
	 * @param \WP_Post $post Popup.
	 * @return array<string, mixed>
	 * @throws ToolError When WordPress does not trash it.
	 */
	private function trash( \WP_Post $post ): array {
		AuditLog::target( 'post', (string) $post->ID );
		if ( ! wp_trash_post( $post->ID ) ) {
			throw new ToolError( 'WordPress did not move this popup to the trash.' );
		}
		return array(
			'id'     => $post->ID,
			'status' => 'trash',
		);
	}
}
