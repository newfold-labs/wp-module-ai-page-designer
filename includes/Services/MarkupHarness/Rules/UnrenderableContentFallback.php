<?php
/**
 * Replace content this site cannot render with something it can.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\BookingForm;

/**
 * The model knows the wider WordPress ecosystem, so when asked for something
 * core has no block for — a contact form above all — it reaches for a plugin
 * it has seen in training. It does this two ways, and both fail on a site
 * without that plugin:
 *
 *  - **As a block**: `<!-- wp:forminator/contact-form {"id":42} /-->`,
 *    `wp:jetpack/contact-form`, `wp:wpforms/form-selector`, or the
 *    still-experimental `wp:core/form`. A self-closing block with no saved
 *    HTML renders *nothing* when its type is not registered — the section's
 *    heading and intro show, the form silently does not.
 *  - **As a shortcode**: `[contact-form-7 id="0" title="Contact form"]`. An
 *    unregistered shortcode is not stripped, it is printed verbatim, so the
 *    visitor reads the raw tag as body text. (Note the invented `id="0"`:
 *    the model has no way to know a real form ID, so the shortcode is broken
 *    even on a site that *does* have the plugin — which is why a form
 *    shortcode with an empty or `0` id counts as unrenderable regardless.)
 *
 * Installing the plugin is not an option a page generator gets to take, so
 * both are substituted with markup that works everywhere: the same
 * theme-styled `core/html` form {@see BookingForm} renders (one definition of
 * "what a form looks like", already correct-by-construction and covered by its
 * own tests).
 *
 * The two cases differ in what happens when the content ISN'T form-shaped, and
 * deliberately so:
 *  - An unrenderable **block** is dropped. A block delimiter is never prose,
 *    and a block that renders nothing on the front end while showing "your
 *    site doesn't include support for this block" in the editor is strictly
 *    worse than clean markup.
 *  - An unrecognised **shortcode** is left alone. `[see below]` is ordinary
 *    prose, and silently deleting a visitor-visible sentence is a worse
 *    failure than leaving one stray tag on screen. Only form shortcodes —
 *    where the intent is unambiguous and a real replacement exists — are
 *    touched.
 *
 * Two guards keep the block half from eating markup it shouldn't:
 *  - Only blocks that render NOTHING are replaced. A static block whose plugin
 *    is missing still outputs its saved HTML (and plugins that register their
 *    block in JS only never appear in the PHP registry at all), so anything
 *    with real saved content is left alone.
 *  - If the block registry has not been populated yet — `core/paragraph`
 *    missing is the sentinel — the registry is not trusted and only the
 *    namespace is used, so an early/odd call site can never blank a page. The
 *    shortcode half has the same sentinel on `[gallery]`.
 *
 * Uses native parse_blocks/serialize_blocks; no-ops if WordPress is
 * unavailable. Idempotent: after one pass nothing unrenderable remains, so a
 * second pass finds nothing to do.
 */
class UnrenderableContentFallback implements Rule {

	/**
	 * Name segments (split on `/`, `-`, `_`) that mark a block or shortcode as
	 * form-shaped.
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
		'wpcf7',
		'gravityform',
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
	 * Shortcodes WordPress itself registers — the fallback "is this known?"
	 * answer when the shortcode API is unavailable (unit tests).
	 *
	 * @var string[]
	 */
	const CORE_SHORTCODES = array(
		'audio',
		'caption',
		'embed',
		'gallery',
		'playlist',
		'video',
		'wp_caption',
	);

	/**
	 * The substitute form's fields — the universal contact set. The plugin
	 * block or shortcode carried only a form ID, so there is nothing to
	 * recover the real field list from; name/email/message is what a visitor
	 * expects and what the user can then edit.
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
	 * Matches one shortcode call: a lowercase tag optionally followed by
	 * attributes. Deliberately narrow — a tag that could be prose
	 * ("[10:00 AM]", "[See below]") never matches.
	 *
	 * @var string
	 */
	const SHORTCODE_PATTERN = '/\[([a-z][a-z0-9_-]*)((?:\s[^\]\[]*)?)\]/';

	/**
	 * Overrides the block registry lookup — tests inject a predicate so both
	 * the "plugin block" and "unregistered core block" cases are exercisable
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
		return 'unrenderable_content_fallback';
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
	 * Static for the same reason as {@see UnrenderableContentFallback::block_is_supported()}.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return bool
	 */
	public static function block_renders_nothing( array $block ): bool {
		return self::html_renders_nothing( self::inner_html( $block ) );
	}

	/**
	 * The form shortcodes in a block's OWN text that this site cannot render.
	 *
	 * Only the block's own literal chunks are inspected, never its children's,
	 * so a group is not blamed for a shortcode belonging to the paragraph
	 * inside it.
	 *
	 * Static for the same reason as {@see UnrenderableContentFallback::block_is_supported()}.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<int, array{tag: string, text: string}> Matched shortcodes, in order.
	 */
	public static function unrenderable_form_shortcodes( array $block ): array {
		$found = array();

		if ( ! preg_match_all( self::SHORTCODE_PATTERN, self::own_html( $block ), $matches, PREG_SET_ORDER ) ) {
			return $found;
		}

		foreach ( $matches as $match ) {
			$tag = $match[1];
			if ( ! self::is_form_name( $tag ) ) {
				// Not a form: could be prose or a working plugin shortcode.
				// Either way there is no safe substitute, so leave it be.
				continue;
			}
			if ( self::shortcode_is_renderable( $tag, $match[2] ) ) {
				continue;
			}
			$found[] = array(
				'tag'  => $tag,
				'text' => $match[0],
			);
		}

		return $found;
	}

	/**
	 * Whether a form shortcode will actually produce a form on this site.
	 *
	 * @param string $tag        Shortcode tag.
	 * @param string $attributes Raw attribute string from the shortcode call.
	 * @return bool
	 */
	private static function shortcode_is_renderable( string $tag, string $attributes ): bool {
		if ( ! self::shortcode_is_registered( $tag ) ) {
			return false;
		}

		// The plugin is here, but the model invented the form ID — an empty or
		// zero id renders the plugin's own "form not found" notice, not a form.
		if ( preg_match( '/\bid\s*=\s*["\']?([^"\'\s\]]*)/i', $attributes, $id_match ) ) {
			$id = trim( $id_match[1] );
			if ( '' === $id || '0' === $id ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Whether a shortcode tag is registered on this site.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	private static function shortcode_is_registered( string $tag ): bool {
		if ( ! function_exists( 'shortcode_exists' ) ) {
			return in_array( $tag, self::CORE_SHORTCODES, true );
		}

		// Same sentinel as the block registry: if core's own `[gallery]` is
		// missing, shortcodes have not been registered yet and nothing here
		// can be trusted, so change nothing.
		if ( ! shortcode_exists( 'gallery' ) ) {
			return true;
		}

		return shortcode_exists( $tag );
	}

	/**
	 * The blocks that stand in for one parsed block: itself (with its children
	 * and shortcodes resolved), a substitute form, or nothing at all.
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
			return $this->substitute_block( $block_name, $ctx, $top_level );
		}

		$block      = $this->resolve_children( $block, $ctx );
		$shortcodes = self::unrenderable_form_shortcodes( $block );
		if ( array() === $shortcodes ) {
			return array( $block );
		}

		return $this->substitute_shortcodes( $block, $shortcodes, $ctx, $top_level );
	}

	/**
	 * Swap a block's unrenderable form shortcodes for a real form.
	 *
	 * The shortcode text is cut out of the block first: what is left is often
	 * an empty paragraph (the model gave the shortcode a block of its own),
	 * which is dropped, but a shortcode written inline after a sentence leaves
	 * that sentence standing, with the form following it.
	 *
	 * @param array<string, mixed>                     $block      Parsed block.
	 * @param array<int, array{tag: string, text: string}> $shortcodes Shortcodes to remove.
	 * @param Context                                  $ctx        Theme/conformance context.
	 * @param bool                                     $top_level  Whether the block sits at the top level of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function substitute_shortcodes( array $block, array $shortcodes, Context $ctx, bool $top_level ): array {
		$block = self::strip_shortcodes( $block, $shortcodes );
		$form  = $this->native_form( $ctx, $top_level );

		if ( empty( $block['innerBlocks'] ) && self::html_renders_nothing( self::own_html( $block ) ) ) {
			return $form;
		}

		return array_merge( array( $block ), $form );
	}

	/**
	 * Remove shortcode text from a block's own literal chunks.
	 *
	 * @param array<string, mixed>                     $block      Parsed block.
	 * @param array<int, array{tag: string, text: string}> $shortcodes Shortcodes to remove.
	 * @return array<string, mixed>
	 */
	private static function strip_shortcodes( array $block, array $shortcodes ): array {
		$search = array();
		foreach ( $shortcodes as $shortcode ) {
			$search[] = $shortcode['text'];
		}

		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			foreach ( $block['innerContent'] as $index => $chunk ) {
				if ( is_string( $chunk ) ) {
					$block['innerContent'][ $index ] = str_replace( $search, '', $chunk );
				}
			}
		}
		if ( isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ) {
			$block['innerHTML'] = str_replace( $search, '', $block['innerHTML'] );
		}

		return $block;
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
	 * @param string  $block_name Fully-qualified block name.
	 * @param Context $ctx        Theme/conformance context.
	 * @param bool    $top_level  Whether the block sits at the top level of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function substitute_block( string $block_name, Context $ctx, bool $top_level ): array {
		if ( ! self::is_form_name( $block_name ) ) {
			return array();
		}

		return $this->native_form( $ctx, $top_level );
	}

	/**
	 * The substitute form, parsed into blocks.
	 *
	 * A top-level replacement gets the whole {@see BookingForm} section, so the
	 * form lands with real section padding; a nested one gets the bare form,
	 * because the model's own section (with its heading and intro — the part
	 * the user could see) is already wrapped around it.
	 *
	 * @param Context $ctx       Theme/conformance context.
	 * @param bool    $top_level Whether the replacement sits at the top level of the page.
	 * @return array<int, array<string, mixed>>
	 */
	private function native_form( Context $ctx, bool $top_level ): array {
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
	 * Whether a block name or shortcode tag is form-shaped — matched on whole
	 * name segments.
	 *
	 * @param string $name Block name or shortcode tag.
	 * @return bool
	 */
	private static function is_form_name( string $name ): bool {
		$segments = preg_split( '/[\/_-]+/', strtolower( $name ) );
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
	 * Whether a fragment of HTML contributes nothing to the rendered page.
	 *
	 * @param string $html HTML fragment.
	 * @return bool
	 */
	private static function html_renders_nothing( string $html ): bool {
		// Void/replaced elements carry no text but do render.
		if ( preg_match( '/<(img|iframe|video|audio|svg|canvas|form|input|select|textarea|button|hr)\b/i', $html ) ) {
			return false;
		}

		return '' === trim( strip_tags( $html ) );
	}

	/**
	 * A block's own literal HTML chunks, excluding its inner blocks'.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private static function own_html( array $block ): string {
		if ( isset( $block['innerContent'] ) && is_array( $block['innerContent'] ) ) {
			$html = '';
			foreach ( $block['innerContent'] as $chunk ) {
				if ( is_string( $chunk ) ) {
					$html .= $chunk;
				}
			}
			return $html;
		}

		return isset( $block['innerHTML'] ) && is_string( $block['innerHTML'] ) ? $block['innerHTML'] : '';
	}

	/**
	 * Every literal HTML chunk in a block and its descendants, concatenated.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string
	 */
	private static function inner_html( array $block ): string {
		$html = self::own_html( $block );

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
