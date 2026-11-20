<?php
/**
 * Tools: kuduy-jetengine-features, kuduy-jetengine-config, kuduy-jetengine-run.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Modules\Fields;

// phpcs:disable WordPress.Security.EscapeOutput.ExceptionNotEscaped -- tool errors are returned to the MCP client as JSON text, never printed as HTML.

use Kuduy\Mcp\Audit\AuditLog;
use Kuduy\Mcp\Mcp\Tool;
use Kuduy\Mcp\Mcp\ToolError;
use Kuduy\Mcp\Modules\Core\Content;

/**
 * JetEngine through its own features registry (phase L6, D61; read on JetEngine 3.8.15.4, includes/core/mcp-tools):
 * Jet_Engine\MCP_Tools\Registry::instance()->get_features() / get_feature( "tool-…" ), each Feature with its input
 * schema, check_permissions() (manage_options unless the feature sets its own) and run(). JetEngine loads this
 * registry only when its "Features API" setting is on (JetEngine → Settings, misc setting enable_features_api); the
 * tools say so when it is off. JetEngine's own features include reading the whole configuration (post types,
 * taxonomies, meta boxes, options pages, queries, relations, custom content types, REST endpoints, glossaries) and
 * adding post types, taxonomies, meta boxes, queries, listings, custom content types and glossaries, and switching
 * modules. Additions have no undo here: JetEngine removes its definitions on its own screens.
 */
final class JetEngineTools implements Tool {

	private const REGISTRY = '\Jet_Engine\MCP_Tools\Registry';

	/**
	 * Mode.
	 *
	 * @param string $mode features | config | run.
	 */
	public function __construct( private string $mode ) {}

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'kuduy-jetengine-' . $this->mode;
	}

	/**
	 * {@inheritDoc}
	 */
	public function title(): string {
		return match ( $this->mode ) {
			'features' => 'JetEngine features',
			'config'   => 'JetEngine configuration',
			default    => 'Run a JetEngine feature',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function description(): string {
		return match ( $this->mode ) {
			'features' => 'Lists the features JetEngine offers to AI tools on this site (e.g. tool-add-cpt, tool-add-meta-box, tool-add-query, resource-get-configuration, tool-manage-modules) with their descriptions and input schemas, to use with kuduy-jetengine-run.',
			'config'   => 'Reads JetEngine\'s configuration through its own get-configuration feature: post types, taxonomies, meta boxes and their fields, options pages, queries, relations, custom content types, REST API endpoints and glossaries. Choose parts or leave empty for all.',
			default    => 'Runs one JetEngine feature (name from kuduy-jetengine-features) with its input, as the signed-in user: JetEngine checks its own permission and validates the input. Features add definitions (post types, taxonomies, meta boxes, queries, listings, custom content types, glossaries) or switch modules. There is no undo for additions here: remove them in JetEngine.',
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function input_schema(): array {
		$properties = array();
		$required   = array();
		if ( 'config' === $this->mode ) {
			$properties['parts'] = array(
				'type'  => 'array',
				'items' => array(
					'type' => 'string',
					'enum' => array( 'post_types', 'taxonomies', 'meta_boxes', 'options_pages', 'queries', 'relations', 'custom_content_types', 'rest_api_endpoints', 'glossaries' ),
				),
			);
		} elseif ( 'run' === $this->mode ) {
			$properties = array(
				'feature' => array(
					'type'      => 'string',
					'pattern'   => '^[a-z0-9-]{3,80}$',
					'maxLength' => 80,
				),
				'input'   => array( 'type' => 'object' ),
			);
			$required   = array( 'feature' );
		}
		return array(
			'type'                 => 'object',
			'properties'           => $properties,
			'required'             => $required,
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
				'features' => array( 'type' => 'array' ),
				'feature'  => array( 'type' => 'string' ),
				'result'   => array(),
			),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function annotations(): array {
		if ( 'run' !== $this->mode ) {
			return Content::read_only();
		}
		return array(
			'readOnlyHint'    => false,
			'destructiveHint' => true,
			'idempotentHint'  => false,
			'openWorldHint'   => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function scope(): string {
		return 'run' === $this->mode ? 'kuduy:admin' : 'kuduy:read';
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function capability( array $input ): string {
		// Each feature's own permission check runs as well.
		return 'edit_posts';
	}

	/**
	 * {@inheritDoc}
	 */
	public function is_available(): bool|string {
		if ( ! function_exists( 'jet_engine' ) ) {
			return 'JetEngine is not active.';
		}
		return class_exists( self::REGISTRY ) ? true : 'JetEngine\'s Features API is off (JetEngine → Settings → enable "Features API").';
	}

	/**
	 * A feature by name, or a not-found error.
	 *
	 * @param string $name Name such as tool-add-cpt.
	 * @throws ToolError When there is none.
	 */
	private static function feature( string $name ): object {
		$feature = call_user_func( array( self::REGISTRY, 'instance' ) )->get_feature( $name );
		if ( ! is_object( $feature ) ) {
			throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'JetEngine has no feature "' . $name . '" on this site (see kuduy-jetengine-features).' );
		}
		return $feature;
	}

	/**
	 * Run a feature as the current user.
	 *
	 * @param string               $name  Feature.
	 * @param array<string, mixed> $input Input.
	 * @return mixed
	 * @throws ToolError When JetEngine refuses.
	 */
	private static function run( string $name, array $input ): mixed {
		$feature = self::feature( $name );
		$request = new \WP_REST_Request( 'POST', '/jet-engine/v1/mcp-tools/run/' . $name );
		$request->set_param( 'name', $name );
		$request->set_body_params( $input );
		$allowed = $feature->check_permissions( $request );
		if ( true !== $allowed ) {
			throw ToolError::of( ToolError::PERMISSION_DENIED, 'JetEngine does not let your account run ' . $name . ( is_wp_error( $allowed ) ? ': ' . $allowed->get_error_message() : '.' ) );
		}
		$result = $feature->run( $input );
		if ( is_wp_error( $result ) ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'JetEngine refused: ' . wp_strip_all_tags( $result->get_error_message() ) );
		}
		if ( is_array( $result ) && array_key_exists( 'success', $result ) && false === $result['success'] ) {
			throw ToolError::of( ToolError::VALIDATION_FAILED, 'JetEngine refused: ' . wp_strip_all_tags( (string) ( $result['message'] ?? $result['error'] ?? 'no reason given' ) ) );
		}
		return $result;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $input Arguments.
	 */
	public function execute( array $input ): array {
		if ( 'features' === $this->mode ) {
			$rows = array();
			foreach ( (array) call_user_func( array( self::REGISTRY, 'instance' ) )->get_features() as $feature ) {
				$rows[] = array(
					'name'         => (string) $feature->get_name(),
					'label'        => (string) $feature->get_label(),
					'description'  => (string) $feature->get_description(),
					'input_schema' => (object) $feature->get_input_schema(),
				);
			}
			return array( 'features' => $rows );
		}
		if ( 'config' === $this->mode ) {
			$parts = (array) ( $input['parts'] ?? array() );
			if ( ! $parts ) {
				$parts = array( 'post_types', 'taxonomies', 'meta_boxes', 'options_pages', 'queries', 'relations', 'custom_content_types', 'rest_api_endpoints', 'glossaries' );
			}
			// JetEngine registers get-configuration as a resource (found live on 3.8.15.4); older builds as a tool.
			$name = null !== call_user_func( array( self::REGISTRY, 'instance' ) )->get_feature( 'resource-get-configuration' ) ? 'resource-get-configuration' : 'tool-get-configuration';
			return array( 'result' => self::run( $name, array( 'parts' => array_fill_keys( $parts, true ) ) ) );
		}
		$name = (string) $input['feature'];
		AuditLog::target( 'jetengine_feature', $name );
		return array(
			'feature' => $name,
			'result'  => self::run( $name, (array) ( $input['input'] ?? array() ) ),
		);
	}
}
