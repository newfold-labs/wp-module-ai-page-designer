<?php
/**
 * A scriptable stand-in for the site environment.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\MarkupHarness;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\RenderSupport;

/**
 * Describes a hypothetical site — which blocks and shortcodes are registered,
 * which patterns/posts/forms exist — so the rule can be tested against
 * environments a unit test could never really stand up (a site with Contact
 * Form 7 installed but form 12 deleted, a stock install without the
 * experimental `core/form`, a block theme missing a template part).
 *
 * Anything not listed answers "cannot tell" (null) rather than "absent", which
 * is exactly how the real {@see RenderSupport} behaves for environments it
 * cannot inspect — so tests prove the caller ignores uncertainty too.
 */
class FakeRenderSupport extends RenderSupport {

	/**
	 * Registered block names.
	 *
	 * @var string[]
	 */
	private $blocks;

	/**
	 * Registered shortcode tags.
	 *
	 * @var string[]
	 */
	private $shortcodes;

	/**
	 * Existing posts as `id => post_type`.
	 *
	 * @var array<int, string>
	 */
	private $posts;

	/**
	 * Registered pattern slugs.
	 *
	 * @var string[]
	 */
	private $patterns;

	/**
	 * Resolvable template part slugs.
	 *
	 * @var string[]
	 */
	private $template_parts;

	/**
	 * Constructor.
	 *
	 * @param array<string, array> $env Any of: blocks, shortcodes, posts, patterns, template_parts.
	 */
	public function __construct( array $env = array() ) {
		$this->blocks         = isset( $env['blocks'] ) ? $env['blocks'] : array();
		$this->shortcodes     = isset( $env['shortcodes'] ) ? $env['shortcodes'] : array();
		$this->posts          = isset( $env['posts'] ) ? $env['posts'] : array();
		$this->patterns       = isset( $env['patterns'] ) ? $env['patterns'] : array();
		$this->template_parts = isset( $env['template_parts'] ) ? $env['template_parts'] : array();
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public function block_is_registered( string $block_name ): bool {
		return in_array( $block_name, $this->blocks, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	public function shortcode_is_registered( string $tag ): bool {
		return in_array( $tag, $this->shortcodes, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param mixed       $id        Post ID.
	 * @param string|null $post_type Required post type, or null for any.
	 * @return bool|null
	 */
	public function post_exists( $id, ?string $post_type = null ) {
		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( ! isset( $this->posts[ $id ] ) ) {
			return false;
		}
		return null === $post_type ? true : ( $post_type === $this->posts[ $id ] );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $slug Pattern slug.
	 * @return bool|null
	 */
	public function pattern_exists( string $slug ) {
		return in_array( $slug, $this->patterns, true );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param string $slug Template part slug.
	 * @return bool|null
	 */
	public function template_part_exists( string $slug ) {
		return in_array( $slug, $this->template_parts, true );
	}
}
