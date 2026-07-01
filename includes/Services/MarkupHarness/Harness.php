<?php
/**
 * Markup conformance harness: conform AI-generated block markup to the theme.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\Rule;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\RepairDelimiters;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\SanitizeCss;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\BackgroundImagePlaceholder;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\UnwrapLoneGroup;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\SectionGroupPattern;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\GroupPaddingSymmetry;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ColumnWidthNormalize;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\CoverImage;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\CoverDefaults;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\StyleRawFormButtons;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ButtonBackgroundCollision;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules\ColorLegibility;

/**
 * Runs an ordered, idempotent rule pipeline against block markup, then asserts
 * the result against the {@see Validator}. Because rules are idempotent, the
 * harness runs at generate time (before preview) and again on save without the
 * saved markup ever diverging from what was previewed (WYSIWYG).
 */
class Harness {

	/**
	 * Maximum repair passes before giving up and returning best effort.
	 *
	 * @var int
	 */
	const MAX_PASSES = 3;

	/**
	 * Ordered rule pipeline.
	 *
	 * @var Rule[]
	 */
	private $rules;

	/**
	 * Validation oracle.
	 *
	 * @var Validator
	 */
	private $validator;

	/**
	 * Constructor.
	 *
	 * @param Rule[]|null    $rules     Custom pipeline (defaults to the built-in pipeline).
	 * @param Validator|null $validator Custom validator (defaults to a new Validator).
	 */
	public function __construct( ?array $rules = null, ?Validator $validator = null ) {
		$this->rules     = null === $rules ? self::default_rules() : $rules;
		$this->validator = null === $validator ? new Validator() : $validator;
	}

	/**
	 * The built-in rule pipeline, in execution order.
	 *
	 * Order matters: sanitize invalid CSS, then resolve structure, then layout,
	 * then forms.
	 *
	 * @return Rule[]
	 */
	public static function default_rules(): array {
		return array(
			new RepairDelimiters(),
			new SanitizeCss(),
			new BackgroundImagePlaceholder(),
			new UnwrapLoneGroup(),
			new SectionGroupPattern(),
			new GroupPaddingSymmetry(),
			new ColumnWidthNormalize(),
			new CoverImage(),
			new CoverDefaults(),
			new StyleRawFormButtons(),
			new ButtonBackgroundCollision(),
			new ColorLegibility(),
		);
	}

	/**
	 * Conform markup to the theme and gate on the validator.
	 *
	 * Always returns markup (never blocks the user); residual violations after
	 * MAX_PASSES are reported via {@see Harness::validate()} for logging/tests.
	 *
	 * @param string       $markup Block markup.
	 * @param Context|null $ctx    Conformance context (defaults to the active theme).
	 * @return string Conformed markup.
	 */
	public function conform( string $markup, ?Context $ctx = null ): string {
		if ( '' === trim( $markup ) ) {
			return $markup;
		}

		$context = null === $ctx ? Context::from_theme() : $ctx;
		$result  = $markup;

		for ( $pass = 0; $pass < self::MAX_PASSES; $pass++ ) {
			foreach ( $this->rules as $rule ) {
				$result = $rule->apply( $result, $context );
			}
			if ( $this->validator->is_valid( $result, $context ) ) {
				break;
			}
		}

		return $result;
	}

	/**
	 * Run the validator and return residual violations (for logging/tests).
	 *
	 * @param string       $markup Block markup.
	 * @param Context|null $ctx    Conformance context (defaults to the active theme).
	 * @return array<int, string>
	 */
	public function validate( string $markup, ?Context $ctx = null ): array {
		$context = null === $ctx ? Context::from_theme() : $ctx;
		return $this->validator->validate( $markup, $context );
	}
}
