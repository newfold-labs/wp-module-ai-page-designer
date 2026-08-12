<?php
/**
 * Replace blocks this site cannot render with something it can.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\BookingForm;

/**
 * The model knows the wider WordPress ecosystem, so when asked for something
 * core has no block for — a contact form above all — it reaches for a plugin
 * block it has seen in training: `<!-- wp:forminator/contact-form {"id":42} /-->`,
 * `wp:jetpack/contact-form`, `wp:wpforms/form-selector`, or the still-experimental
 * `wp:core/form`. None of those are registered on a typical site, and a
 * self-closing block with no saved HTML renders *nothing* when its block type
 * is missing: the section's heading and intro show, the form itself silently
 * does not. That is the reported "I asked for a contact form and only the
 * headings came back".
 *
 * Installing the plugin is not an option a page generator gets to take, so the
 * fix is to substitute markup that works everywhere: for a form-shaped block,
 * the same theme-styled `core/html` form {@see BookingForm} renders (one
 * definition of "what a form looks like", already correct-by-construction and
 * covered by its own tests); for anything else unrenderable, nothing at all —
 * an unregistered block that renders nothing on the front end but shows
 * "your site doesn't include support for this block" in the editor is strictly
 * worse than clean markup.
 *
 * Two guards keep this from eating markup it shouldn't:
 *  - Only blocks that render NOTHING are replaced. A static block whose plugin
 *    is missing still outputs its saved HTML (and plugins that register their
 *    block in JS only never appear in the PHP registry at all), so anything
 *    with real saved content is left alone.
 *  - If the block registry has not been populated yet — `core/paragraph`
 *    missing is the sentinel — the registry is not trusted and only the
 *    namespace is used, so an early/odd call site can never blank a page.
 *
 * Uses native parse_blocks/serialize_blocks; no-ops if WordPress is
 * unavailable. Idempotent: after one pass no unrenderable block remains, so a
 * second pass finds nothing to do.
 */
class UnsupportedBlockFallback implements Rule {

	/**
	 * Name segments (split on `/`, `-`, `_`) that mark a block as form-shaped.
	 *
	 * Matched as whole segments, never as substrings — "platform" contains
	 * "form" but is not a form.
	 *
	 * @var string[]
	 */
	const FORM_HINTS = array(
		'form',
		'forms',
		'contact',
		'contactform',
		'booking',
		'bookings',
		'appointment',
		'appointments',
		'enquiry',
		'inquiry',
		'reservation',
		'reservations',
		'subscribe',
		'newsletter',
		'optin',
		'signup',
		'forminator',
		'wpforms',
		'gravityforms',
		'formidable',
		'fluentform',
		'happyforms',
		'weforms',
		'jotform',
		'typeform',
		'mailchimp',
		'mc4wp',
		'hubspot',
	);

	/**
	 * The substitute form's fields — the universal contact set. The plugin
	 * block carried only a form ID, so there is nothing to recover the real
	 * field list from; name/email/message is what a visitor expects and what
	 * the user can then edit.
	 *
	 * @var array<int, array<string, mixed>>
	 */
	const FALLBACK_FIELDS = array(
		array(
			'type'     => 'text',
			'name'     => 'name',
			'label'    => 'Name',
			'required' => true,
		),
		array(
			'type'     => 'email',
			'name'     => 'email',
			'label'    => 'Email',
			'required' => true,
		),
		array(
			'type'     => 'textarea',
			'name'     => 'message',
			'label'    => 'Message',
			'required' => true,
		),
	);

	/**
	 * Submit label for the substitute form.
	 *
	 * @var string
	 */
	const FALLBACK_SUBMIT = 'Send Message';

	/**
	 * Overrides the registry lookup — tests inject a predicate so both the
	 * "plugin block" and "unregistered core block" cases are exercisable
	 * without a WordPress bootstrap.
	 *
	 * @var callable|null
	 */
	private $is_supported;

	/**
	 * Constructor.
	 *
	 * @param callable|null $is_supported Optional `fn( string $block_name ): bool` override.
	 */
	public function __construct( ?callable $is_supported = null ) {
		$this->is_supported = $is_supported;
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string  $markup Block markup.
	 * @param Context $ctx    Theme/conformance context.
	 * @return string
	 */
	public function apply( string $markup, Context $ctx ): string {
		if ( ! function_exists( 'parse_blocks' ) || ! function_exists( 'serialize_blocks' ) ) {
			return $markup;
		}

		$blocks = array();
		foreach ( parse_blocks( $markup ) as $block ) {
			foreach ( $this->replace( $block, $ctx, true ) as $replacement ) {
				$blocks[] = $replacement;
			}
		}

		return serialize_blocks( $blocks );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @return string
	 */
	public function name(): string {
		return 'unsupported_block_fallback';
	}

	/**
	 * Whether this site can render the named block.
	 *
	 * Static so {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator}
	 * asserts against the exact same predicate the rule repairs against.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public static function block_is_supported( string $block_name ): bool {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return self::is_core_block( $block_name );
		}

		$registry = \WP_Block_Type_Registry::get_instance();

		// Sentinel: blocks register on `init`, so a call before that would see
		// an empty registry and condemn the entire page. If core's most basic
		// block is missing, the registry is not ready — fall back to the
		// namespace and change nothing that isn't obviously third-party.
		if ( ! $registry->is_registered( 'core/paragraph' ) ) {
			return self::is_core_block( $block_name );
		}

		return $registry->is_registered( $block_name );
	}

	/**
	 * Whether a block contributes nothing to the rendered page — no text, no
	 * embedded media, no form controls, at any depth.
	 *
	 * This is what separates "the plugin is missing and the section is empty"
	 * from "the plugin is missing but its saved HTML still renders fine".
	 *
	 * Static for the same reason as {@see UnsupportedBlockFallback::block_is_supported()}.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return bool
	 */
	public static function block_renders_nothing( array $block ): bool {
		$html = self::inner_html( $block );

		// Void/replaced elements carry no text but do render.
		if ( preg_match( '/<(img|iframe|video|audio|svg|canvas|form|input|select|textarea|button|hr)\b/i', $html ) ) {
			return false;
		}

		return '' === trim( strip_tags( $html ) );
	}

	/**
	 * The blocks that stand in for one parsed block: itself (with its own
	 * children resolved), a substitute form, or nothing at all.
	 *
	 * @param array<string, mixed> $block     Parsed block.
	 * @param Context              $ctx       Theme/conformance context.
	 * @param bool                 $top_level Whether the block sits at the top level of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function replace( array $block, Context $ctx, bool $top_level ): array {
		$block_name = isset( $block['blockName'] ) ? $block['blockName'] : null;

		if ( is_string( $block_name ) && '' !== $block_name
			&& ! $this->supports( $block_name )
			&& self::block_renders_nothing( $block ) ) {
			return $this->substitute( $block_name, $ctx, $top_level );
		}

		return array( $this->resolve_children( $block, $ctx ) );
	}

	/**
	 * Rebuild a block's `innerBlocks` and `innerContent` in lockstep after
	 * resolving each child.
	 *
	 * `innerContent` interleaves literal HTML chunks with `null` placeholders,
	 * one per inner block, and serialize_blocks() consumes them in that order —
	 * so a child that expands into two blocks (or vanishes) has to add or drop
	 * its placeholder too, or every block after it serializes in the wrong slot.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @param Context              $ctx   Theme/conformance context.
	 * @return array<string, mixed>
	 */
	private function resolve_children( array $block, Context $ctx ): array {
		if ( empty( $block['innerBlocks'] ) || ! isset( $block['innerContent'] ) || ! is_array( $block['innerContent'] ) ) {
			return $block;
		}

		$inner_blocks  = array();
		$inner_content = array();
		$child_index   = 0;

		foreach ( $block['innerContent'] as $chunk ) {
			if ( null !== $chunk ) {
				$inner_content[] = $chunk;
				continue;
			}

			$child = isset( $block['innerBlocks'][ $child_index ] ) ? $block['innerBlocks'][ $child_index ] : null;
			++$child_index;
			if ( ! is_array( $child ) ) {
				continue;
			}

			foreach ( $this->replace( $child, $ctx, false ) as $replacement ) {
				$inner_blocks[]  = $replacement;
				$inner_content[] = null;
			}
		}

		$block['innerBlocks']  = $inner_blocks;
		$block['innerContent'] = $inner_content;

		return $block;
	}

	/**
	 * What replaces an unrenderable block: a native form when it was
	 * form-shaped, nothing otherwise.
	 *
	 * A top-level block is replaced by the whole {@see BookingForm} section, so
	 * the form lands with real section padding; a nested one is replaced by the
	 * bare form, because the model's own section (with its heading and intro —
	 * the part the user could see) is already wrapped around it.
	 *
	 * @param string  $block_name Fully-qualified block name.
	 * @param Context $ctx        Theme/conformance context.
	 * @param bool    $top_level  Whether the block sits at the top level of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function substitute( string $block_name, Context $ctx, bool $top_level ): array {
		if ( ! $this->is_form_block( $block_name ) ) {
			return array();
		}

		$form = new BookingForm();

		if ( $top_level ) {
			// No heading/intro: the archetype omits both when empty, and
			// inventing one risks duplicating a heading the model already
			// wrote right above the form.
			$markup = $form->render(
				array(
					'fields'      => self::FALLBACK_FIELDS,
					'submitLabel' => self::FALLBACK_SUBMIT,
				),
				null,
				$ctx,
				null
			);
		} else {
			$markup = $form->render_form_block( self::FALLBACK_FIELDS, self::FALLBACK_SUBMIT, $ctx, null );
		}

		// The archetypes pad their markup with trailing newlines, which parse
		// as blank freeform blocks; drop them rather than splice whitespace
		// blocks into someone's columns.
		return array_values(
			array_filter(
				parse_blocks( $markup ),
				static function ( $parsed ) {
					return ! empty( $parsed['blockName'] ) || '' !== trim( isset( $parsed['innerHTML'] ) ? $parsed['innerHTML'] : '' );
				}
			)
		);
	}

	/**
	 * Whether the block is one this site can render, honouring an injected
	 * predicate when present.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	private function supports( string $block_name ): bool {
		if ( null !== $this->is_supported ) {
			return (bool) call_user_func( $this->is_supported, $block_name );
		}
		return self::block_is_supported( $block_name );
	}

	/**
	 * Whether a block name is form-shaped — matched on whole name segments.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	private function is_form_block( string $block_name ): bool {
		$segments = preg_split( '/[\/_-]+/', strtolower( $block_name ) );
		if ( ! is_array( $segments ) ) {
			return false;
		}
		return array() !== array_intersect( $segments, self::FORM_HINTS );
	}

	/**
	 * Whether a block name is in the `core/` namespace.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	private static function is_core_block( string $block_name ): bool {
		return 0 === strpos( $block_name, 'core/' );
	}

	/**
	 * Every literal HTML chunk in a block and its descendants, concatenated.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private static function inner_html( array $block ): string {
		$html = '';

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
		} elseif ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$html .= $block['innerHTML'];
		}

		if ( ! empty( $block['innerBlocks'] ) && is_array( $block['innerBlocks'] ) ) {
			foreach ( $block['innerBlocks'] as $child ) {
				if ( is_array( $child ) ) {
					$html .= self::inner_html( $child );
				}
			}
		}

		return $html;
	}
}
