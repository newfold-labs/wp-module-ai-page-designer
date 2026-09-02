<?php
/**
 * Replace content this site cannot render with something it can.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Rules;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\RenderSupport;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes\BookingForm;

/**
 * The model knows the wider WordPress ecosystem, so when asked for something
 * core has no block for — a contact form above all — it reaches for whatever it
 * saw in training. What it reaches for is frequently not on this site, and the
 * failure is silent every time. Three distinct shapes, all seen live:
 *
 *  1. **A plugin block** — `<!-- wp:forminator/contact-form {"id":42} /-->`.
 *     A self-closing block whose type is not registered renders *nothing*: no
 *     plugin, no output, no error. The section's heading and intro show; the
 *     form silently does not.
 *  2. **A plugin shortcode** — `[contact-form-7 id="0" title="Contact form"]`.
 *     An unregistered shortcode is not stripped, it is printed, so the visitor
 *     reads the raw tag as body text.
 *  3. **A reference to a resource that does not exist** — `wp:pattern` naming a
 *     theme pattern this site never registered, `wp:block` pointing at a synced
 *     pattern ID it invented, `wp:template-part` in post content,
 *     `wp:navigation` with a made-up menu ref. These block types ARE
 *     registered, so registration alone says they are fine; they still render
 *     nothing, because what they point at isn't there.
 *
 * Shape 3 is the general case the first two are instances of: **the model
 * cannot know what this particular site has.** A form ID is the sharpest
 * example — even with the plugin installed, `id="0"` (or any ID it made up)
 * renders the plugin's "form not found" notice rather than a form, so the ID is
 * checked against the plugin's own records, not just the plugin's presence.
 *
 * Every "is it here?" question is delegated to {@see RenderSupport}, which the
 * {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator}
 * shares, so the repair and the assertion can never disagree. Crucially, those
 * answers are three-valued and only a definite `false` is acted on: "I could
 * not verify this" never becomes "delete it".
 *
 * What replaces the content depends on what it was:
 *  - Form-shaped anything becomes the theme-styled `core/html` form
 *    {@see BookingForm} renders — one definition of "what a form looks like",
 *    already correct-by-construction and covered by its own tests.
 *  - An unrenderable **block** is otherwise dropped. A block delimiter is never
 *    prose, and a block that renders nothing on the front end while showing
 *    "your site doesn't include support for this block" in the editor is
 *    strictly worse than clean markup.
 *  - An unrecognised **shortcode** is otherwise left alone. `[see below]` is
 *    ordinary prose, and silently deleting a visitor-visible sentence is a
 *    worse failure than leaving one stray tag on screen. Only form shortcodes —
 *    where the intent is unambiguous and a real replacement exists — are
 *    touched.
 *
 * One further guard on the block half: only blocks that render NOTHING are
 * replaced. A static block whose plugin is missing still outputs its saved
 * HTML (and plugins that register their block in JS only never appear in the
 * PHP registry at all), so anything with real saved content is left alone.
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
	 * Attribute keys (lowercased) a form block carries its form ID in.
	 *
	 * @var string[]
	 */
	const FORM_ID_KEYS = array( 'id', 'formid', 'form_id', 'moduleid', 'module_id' );

	/**
	 * Registered core blocks that render nothing when the resource they point
	 * at is absent, and how to check that resource.
	 *
	 * `required` marks an attribute whose absence is itself fatal: a
	 * `core/pattern` with no slug can never render, whereas a `core/navigation`
	 * with no ref falls back to a page list and is left alone.
	 *
	 * @var array<string, array<string, mixed>>
	 */
	const RESOURCE_BLOCKS = array(
		'core/block'         => array(
			'attr'      => 'ref',
			'kind'      => 'post',
			'post_type' => 'wp_block',
			'required'  => true,
		),
		'core/navigation'    => array(
			'attr'      => 'ref',
			'kind'      => 'post',
			'post_type' => 'wp_navigation',
			'required'  => false,
		),
		'core/pattern'       => array(
			'attr'     => 'slug',
			'kind'     => 'pattern',
			'required' => true,
		),
		'core/template-part' => array(
			'attr'     => 'slug',
			'kind'     => 'template_part',
			'required' => true,
		),
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
	 * Answers every "does this site have it?" question.
	 *
	 * @var RenderSupport
	 */
	private $support;

	/**
	 * Constructor.
	 *
	 * @param RenderSupport|null $support Environment probe (defaults to a real one; tests inject a double).
	 */
	public function __construct( ?RenderSupport $support = null ) {
		$this->support = null === $support ? new RenderSupport() : $support;
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
	 * Why this ONE block cannot render on this site, as a violation string, or
	 * null when it can.
	 *
	 * Inspects only the block itself, never its descendants — the caller walks
	 * the tree. {@see \NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Validator}
	 * asserts through this exact method, so the assertion cannot drift from
	 * the repair.
	 *
	 * @param array<string, mixed> $block Parsed block.
	 * @return string|null
	 */
	public function unrenderable_reason( array $block ) {
		$block_name = isset( $block['blockName'] ) ? $block['blockName'] : null;

		if ( is_string( $block_name ) && '' !== $block_name ) {
			if ( ! $this->support->block_is_registered( $block_name ) ) {
				if ( self::block_renders_nothing( $block ) ) {
					return 'unsupported_block:' . $block_name;
				}
			} elseif ( $this->has_missing_form( $block, $block_name ) ) {
				return 'missing_form:' . $block_name;
			} elseif ( $this->has_missing_resource( $block, $block_name ) ) {
				return 'missing_resource:' . $block_name;
			}
		}

		$shortcodes = $this->unrenderable_form_shortcodes( $block );
		if ( array() !== $shortcodes ) {
			return 'unsupported_shortcode:' . $shortcodes[0]['tag'];
		}

		return null;
	}

	/**
	 * Whether a block contributes nothing to the rendered page — no text, no
	 * embedded media, no form controls, at any depth.
	 *
	 * This is what separates "the plugin is missing and the section is empty"
	 * from "the plugin is missing but its saved HTML still renders fine".
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
	 * @param array<string, mixed> $block Parsed block.
	 * @return array<int, array{tag: string, text: string}> Matched shortcodes, in order.
	 */
	public function unrenderable_form_shortcodes( array $block ): array {
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
			if ( ! $this->shortcode_form_is_missing( $tag, $match[2] ) ) {
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
	 * Whether a form shortcode will fail to produce a form on this site —
	 * either the plugin is absent, or the form ID it names is not in the
	 * database.
	 *
	 * @param string $tag        Shortcode tag.
	 * @param string $attributes Raw attribute string from the shortcode call.
	 * @return bool
	 */
	private function shortcode_form_is_missing( string $tag, string $attributes ): bool {
		if ( ! $this->support->shortcode_is_registered( $tag ) ) {
			return true;
		}

		if ( ! preg_match( '/\bid\s*=\s*["\']?([^"\'\s\]]*)/i', $attributes, $id_match ) ) {
			return false;
		}

		return false === $this->support->form_exists( $tag, $id_match[1] );
	}

	/**
	 * Whether a registered form block names a form that is not in the database.
	 *
	 * The block-side twin of {@see UnrenderableContentFallback::shortcode_form_is_missing()}:
	 * an installed plugin still renders "form not found" for an ID the model
	 * invented.
	 *
	 * @param array<string, mixed> $block      Parsed block.
	 * @param string               $block_name Fully-qualified block name.
	 * @return bool
	 */
	private function has_missing_form( array $block, string $block_name ): bool {
		if ( ! self::is_form_name( $block_name ) ) {
			return false;
		}

		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		foreach ( $attrs as $key => $value ) {
			if ( ! in_array( strtolower( (string) $key ), self::FORM_ID_KEYS, true ) ) {
				continue;
			}
			return false === $this->support->form_exists( self::namespace_of( $block_name ), $value );
		}

		// No ID attribute at all — nothing to verify, so nothing to act on.
		return false;
	}

	/**
	 * Whether a registered block points at a pattern, synced pattern, template
	 * part or menu that does not exist here.
	 *
	 * @param array<string, mixed> $block      Parsed block.
	 * @param string               $block_name Fully-qualified block name.
	 * @return bool
	 */
	private function has_missing_resource( array $block, string $block_name ): bool {
		if ( ! isset( self::RESOURCE_BLOCKS[ $block_name ] ) ) {
			return false;
		}

		// Saved content of its own still renders, whatever the reference does.
		if ( ! self::block_renders_nothing( $block ) ) {
			return false;
		}

		$spec  = self::RESOURCE_BLOCKS[ $block_name ];
		$attrs = isset( $block['attrs'] ) && is_array( $block['attrs'] ) ? $block['attrs'] : array();
		$value = isset( $attrs[ $spec['attr'] ] ) ? $attrs[ $spec['attr'] ] : null;

		if ( null === $value || '' === $value ) {
			return (bool) $spec['required'];
		}

		if ( 'post' === $spec['kind'] ) {
			return false === $this->support->post_exists( $value, $spec['post_type'] );
		}
		if ( 'pattern' === $spec['kind'] ) {
			return false === $this->support->pattern_exists( (string) $value );
		}

		return false === $this->support->template_part_exists( (string) $value );
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
		$reason     = $this->unrenderable_reason( $block );

		if ( null !== $reason && 0 !== strpos( $reason, 'unsupported_shortcode:' ) ) {
			// A form — from any of the three shapes — earns a real form;
			// anything else unrenderable is dropped.
			if ( is_string( $block_name ) && self::is_form_name( $block_name ) ) {
				return $this->native_form( $ctx, $top_level );
			}
			return array();
		}

		$block      = $this->resolve_children( $block, $ctx );
		$shortcodes = $this->unrenderable_form_shortcodes( $block );
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
	 * @param array<string, mixed>                         $block      Parsed block.
	 * @param array<int, array{tag: string, text: string}> $shortcodes Shortcodes to remove.
	 * @param Context                                      $ctx        Theme/conformance context.
	 * @param bool                                         $top_level  Whether the block sits at the top level of the page.
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
	 * @param array<string, mixed>                         $block      Parsed block.
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
	 * The vendor half of a block name (`forminator/contact-form` → `forminator`).
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return string
	 */
	private static function namespace_of( string $block_name ): string {
		$parts = explode( '/', $block_name );
		return $parts[0];
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
