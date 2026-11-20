<?php
/**
 * Tool: kuduy-unlimited-elements-widgets.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Elementor;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * Unlimited Elements for Elementor: the widgets installed on the site (phase F2, D69; source of 2.0.28). Its widgets
 * live in its own table (addonlibrary_addons, type "elementor") and register with Elementor as ucaddon_{alias}. They
 * are read through its classes (UniteCreatorAddons::getArrAddons(), UniteCreatorAddon getters): title, name, alias,
 * active, category, description and the parameter definitions (the settings the widget offers). Templates (HTML, CSS,
 * JavaScript) are code and are not returned. Installing widgets from its remote library is not offered (D55).
 */
final class UnlimitedElements implements Tool {

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-unlimited-elements-widgets';
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return __( 'Unlimited Elements widgets', 'kuduy-mcp' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return 'Lists the widgets installed with Unlimited Elements for Elementor: id, title, alias, the Elementor widget name to use with the Elementor tools (ucaddon_{alias}), active, category and description. With name (alias or Elementor name) it returns that widget with its parameter definitions (name, type, title, default). Templates are code and are not returned; installing widgets from its online library is not offered.';
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'name'   => array(
					'type'    => 'string',
					'pattern' => '^[A-Za-z0-9_-]{1,120}$',
				),
				'search' => array(
					'type'      => 'string',
					'maxLength' => 100,
				),
			),
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
				'widgets' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'object' ),
				),
				'widget'  => array( 'type' => 'object' ),
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
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		return class_exists( 'UniteCreatorAddons' ) && class_exists( 'GlobalsUC' ) ? true : 'Unlimited Elements for Elementor is not active on this site.';
	}

	/**
	 * Summary of one widget.
	 *
	 * @param \UniteCreatorAddon $addon Widget.
	 * @return array<string, mixed>
	 */
	private static function row( \UniteCreatorAddon $addon ): array {
		$alias = (string) $addon->getAlias();
		return array(
			'id'          => (int) $addon->getID(),
			'title'       => wp_strip_all_tags( (string) $addon->getTitle() ),
			'alias'       => $alias,
			'elementor'   => 'ucaddon_' . ( '' !== $alias ? $alias : (string) $addon->getName() ),
			'active'      => (bool) $addon->getIsActive(),
			'category'    => wp_strip_all_tags( (string) $addon->getCatTitle() ),
			'description' => wp_strip_all_tags( (string) $addon->getDescription() ),
		);
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 * @throws ToolError When the named widget is not installed.
	 */
	public function execute( array $input ): array {
		$addons = ( new \UniteCreatorAddons() )->getArrAddons( '', array(), \GlobalsUC::ADDON_TYPE_ELEMENTOR );
		$name   = (string) ( $input['name'] ?? '' );
		$search = (string) ( $input['search'] ?? '' );
		$rows   = array();
		foreach ( is_array( $addons ) ? $addons : array() as $addon ) {
			if ( ! $addon instanceof \UniteCreatorAddon ) {
				continue;
			}
			$row = self::row( $addon );
			if ( '' !== $name ) {
				if ( $row['alias'] !== $name && $row['elementor'] !== $name ) {
					continue;
				}
				$params = array();
				foreach ( array_merge( (array) $addon->getParams(), (array) $addon->getParamsItems() ) as $param ) {
					if ( ! is_array( $param ) || '' === (string) ( $param['name'] ?? '' ) ) {
						continue;
					}
					$default  = $param['default_value'] ?? null;
					$params[] = array(
						'name'    => (string) $param['name'],
						'type'    => (string) ( $param['type'] ?? '' ),
						'title'   => wp_strip_all_tags( (string) ( $param['title'] ?? '' ) ),
						'default' => is_scalar( $default ) ? mb_substr( (string) $default, 0, 500 ) : $default,
					);
				}
				return array( 'widget' => $row + array( 'params' => $params ) );
			}
			if ( '' !== $search && false === stripos( $row['title'] . ' ' . $row['alias'] . ' ' . $row['description'], $search ) ) {
				continue;
			}
			$rows[] = $row;
		}//end foreach
		if ( '' !== $name ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No Unlimited Elements widget "' . $name . '" is installed (see kuduy-unlimited-elements-widgets).' );
		}
		return array( 'widgets' => $rows );
	}
}
