<?php
/**
 * Expected tool failure.
 *
 * @package Kuduy\Mcp
 */

namespace Kuduy\Mcp\Mcp;

/**
 * Its message is shown to the client; any other exception is replaced by a generic message. The category (decision
 * D52) tells the client what kind of failure it is without parsing the message.
 */
final class ToolError extends \RuntimeException {

	public const PLUGIN_NOT_INSTALLED       = 'PLUGIN_NOT_INSTALLED';
	public const PLUGIN_NOT_ACTIVE          = 'PLUGIN_NOT_ACTIVE';
	public const PLUGIN_VERSION_UNSUPPORTED = 'PLUGIN_VERSION_UNSUPPORTED';
	public const PLUGIN_DEPENDENCY_MISSING  = 'PLUGIN_DEPENDENCY_MISSING';
	public const LICENSE_REQUIRED           = 'LICENSE_REQUIRED';
	public const FEATURE_NOT_SUPPORTED      = 'FEATURE_NOT_SUPPORTED';
	public const PERMISSION_DENIED          = 'PERMISSION_DENIED';
	public const RESOURCE_NOT_FOUND         = 'RESOURCE_NOT_FOUND';
	public const INVALID_ARGUMENT           = 'INVALID_ARGUMENT';
	public const VALIDATION_FAILED          = 'VALIDATION_FAILED';
	public const DUPLICATE_RESOURCE         = 'DUPLICATE_RESOURCE';
	public const CONFLICT                   = 'CONFLICT';
	public const RATE_LIMITED               = 'RATE_LIMITED';
	public const OPERATION_FAILED           = 'OPERATION_FAILED';
	public const INTERNAL_ERROR             = 'INTERNAL_ERROR';

	/**
	 * Every category.
	 */
	public const CATEGORIES = array(
		self::PLUGIN_NOT_INSTALLED,
		self::PLUGIN_NOT_ACTIVE,
		self::PLUGIN_VERSION_UNSUPPORTED,
		self::PLUGIN_DEPENDENCY_MISSING,
		self::LICENSE_REQUIRED,
		self::FEATURE_NOT_SUPPORTED,
		self::PERMISSION_DENIED,
		self::RESOURCE_NOT_FOUND,
		self::INVALID_ARGUMENT,
		self::VALIDATION_FAILED,
		self::DUPLICATE_RESOURCE,
		self::CONFLICT,
		self::RATE_LIMITED,
		self::OPERATION_FAILED,
		self::INTERNAL_ERROR,
	);

	/**
	 * Failure category.
	 *
	 * @var string
	 */
	private string $category;

	/**
	 * Create the error.
	 *
	 * @param string $message  Message for the client.
	 * @param string $category One of the category constants; anything else becomes OPERATION_FAILED.
	 */
	public function __construct( string $message, string $category = self::OPERATION_FAILED ) {
		parent::__construct( $message );
		$this->category = in_array( $category, self::CATEGORIES, true ) ? $category : self::OPERATION_FAILED;
	}

	/**
	 * An error of one category: `throw ToolError::of( ToolError::RESOURCE_NOT_FOUND, 'No such item.' )`.
	 *
	 * @param string $category One of the category constants.
	 * @param string $message  Message for the client.
	 */
	public static function of( string $category, string $message ): self {
		return new self( $message, $category );
	}

	/**
	 * Failure category.
	 */
	public function category(): string {
		return $this->category;
	}

	/**
	 * Whether calling again later, or after re-reading the object, can succeed.
	 *
	 * @param string $category Category.
	 */
	public static function retryable( string $category ): bool {
		return in_array( $category, array( self::CONFLICT, self::RATE_LIMITED ), true );
	}
}
