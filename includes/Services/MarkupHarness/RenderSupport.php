<?php
/**
 * Answers "does this site actually have what this markup needs?".
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness;

/**
 * Every question the harness asks about the *environment* rather than about the
 * markup: is this block type registered, is this shortcode registered, does the
 * pattern / template part / synced pattern this block points at exist, does
 * this form plugin's form ID exist in the database.
 *
 * It lives apart from the rules for two reasons. The repair
 * ({@see Rules\UnrenderableContentFallback}) and the assertion
 * ({@see Validator}) must agree exactly — a rule that fixes what the validator
 * doesn't flag, or vice versa, makes the harness loop or lie — so they share
 * one instance of this instead of each carrying their own copy. And it is the
 * only part of the harness that touches WordPress globals and the database,
 * which keeps the rules pure markup transformations and lets tests substitute
 * a double.
 *
 * Its answers are deliberately three-valued where the environment is
 * ambiguous: `true` (present), `false` (definitely absent) and `null`
 * (cannot tell). Callers act only on `false`. "I could not verify this" must
 * never be allowed to read as "delete it" — that is how a harness eats a
 * working page.
 */
class RenderSupport {

	/**
	 * Shortcode tags WordPress itself registers — the fallback answer when the
	 * shortcode API is unavailable (unit tests).
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
	 * Form plugins that store each form as a custom post type, keyed by the
	 * shortcode tag AND the block namespace they are reached by, so a lookup
	 * works from either. `get_post()` answers definitively for these.
	 *
	 * Plugins that store forms in their own tables instead (Gravity Forms
	 * excepted — see {@see RenderSupport::form_exists()} — plus Ninja Forms,
	 * Fluent Forms, Formidable) are deliberately absent: guessing at a private
	 * schema or an API this module cannot exercise risks deleting a form that
	 * is really there, and "cannot tell" is the safe answer.
	 *
	 * @var array<string, string>
	 */
	const FORM_POST_TYPES = array(
		'contact-form-7' => 'wpcf7_contact_form',
		'wpcf7'          => 'wpcf7_contact_form',
		'wpforms'        => 'wpforms',
		'forminator'     => 'forminator_forms',
		'happyforms'     => 'happyform',
	);

	/**
	 * Whether this site can render the named block type.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	public function block_is_registered( string $block_name ): bool {
		if ( ! class_exists( '\WP_Block_Type_Registry' ) ) {
			return $this->is_core_namespace( $block_name );
		}

		$registry = \WP_Block_Type_Registry::get_instance();

		// Sentinel: blocks register on `init`, so a call before that would see
		// an empty registry and condemn the entire page. If core's most basic
		// block is missing, the registry is not ready — fall back to the
		// namespace and change nothing that isn't obviously third-party.
		if ( ! $registry->is_registered( 'core/paragraph' ) ) {
			return $this->is_core_namespace( $block_name );
		}

		return $registry->is_registered( $block_name );
	}

	/**
	 * Whether a shortcode tag is registered on this site.
	 *
	 * @param string $tag Shortcode tag.
	 * @return bool
	 */
	public function shortcode_is_registered( string $tag ): bool {
		if ( ! function_exists( 'shortcode_exists' ) ) {
			return in_array( $tag, self::CORE_SHORTCODES, true );
		}

		// Same sentinel as the block registry: if core's own `[gallery]` is
		// missing, shortcodes have not been registered yet and nothing here
		// can be trusted, so treat everything as present.
		if ( ! shortcode_exists( 'gallery' ) ) {
			return true;
		}

		return shortcode_exists( $tag );
	}

	/**
	 * Whether a post of the given ID (and optionally type) exists.
	 *
	 * @param mixed       $id        Post ID.
	 * @param string|null $post_type Required post type, or null for any.
	 * @return bool|null True/false, or null when it cannot be determined.
	 */
	public function post_exists( $id, ?string $post_type = null ) {
		if ( ! function_exists( 'get_post' ) ) {
			return null;
		}

		$id = is_numeric( $id ) ? (int) $id : 0;
		if ( $id <= 0 ) {
			return false;
		}

		$post = get_post( $id );
		if ( ! $post ) {
			return false;
		}

		return null === $post_type ? true : ( $post_type === $post->post_type );
	}

	/**
	 * Whether a block pattern slug is registered.
	 *
	 * @param string $slug Pattern slug.
	 * @return bool|null True/false, or null when it cannot be determined.
	 */
	public function pattern_exists( string $slug ) {
		if ( '' === trim( $slug ) ) {
			return false;
		}
		if ( ! class_exists( '\WP_Block_Patterns_Registry' ) ) {
			return null;
		}

		// Sentinel: patterns register on `init`, so before that an empty
		// registry means "not ready", not "this site has no patterns". Ask
		// `init` directly rather than inspecting the registry — the only way
		// to enumerate it is get_all_registered(), which hydrates every
		// pattern's content and resolves hooked blocks for each. That is far
		// too expensive to run on every conform, and this answers the actual
		// question more precisely anyway.
		if ( function_exists( 'did_action' ) && ! did_action( 'init' ) ) {
			return null;
		}

		return \WP_Block_Patterns_Registry::get_instance()->is_registered( $slug );
	}

	/**
	 * Whether a template part slug resolves on this site.
	 *
	 * A classic theme has none at all, which is a real `false`: a
	 * `core/template-part` block genuinely renders nothing there.
	 *
	 * @param string $slug Template part slug.
	 * @return bool|null True/false, or null when it cannot be determined.
	 */
	public function template_part_exists( string $slug ) {
		if ( '' === trim( $slug ) ) {
			return false;
		}
		if ( ! function_exists( 'get_block_templates' ) ) {
			return null;
		}

		$parts = get_block_templates( array( 'slug__in' => array( $slug ) ), 'wp_template_part' );

		return is_array( $parts ) && array() !== $parts;
	}

	/**
	 * Whether a form plugin's form ID exists in the database.
	 *
	 * @param string $source Shortcode tag or block namespace identifying the plugin.
	 * @param mixed  $id     Form ID as written in the markup.
	 * @return bool|null True/false, or null when it cannot be determined.
	 */
	public function form_exists( string $source, $id ) {
		$id = is_scalar( $id ) ? trim( (string) $id ) : '';

		// No plugin knowledge needed: the model cannot know a real form ID, so
		// it invents one, and an empty or zero ID is never a real form.
		if ( '' === $id || '0' === $id ) {
			return false;
		}

		$source = strtolower( $source );

		if ( isset( self::FORM_POST_TYPES[ $source ] ) ) {
			// Newer Contact Form 7 accepts a hash instead of a post ID; a
			// non-numeric ID is one of those, and only the plugin can resolve
			// it, so leave that judgement alone.
			if ( ! is_numeric( $id ) ) {
				return null;
			}
			return $this->post_exists( $id, self::FORM_POST_TYPES[ $source ] );
		}

		// Gravity Forms keeps forms in its own tables but exposes a stable,
		// documented reader for exactly this question.
		if ( in_array( $source, array( 'gravityforms', 'gravityform' ), true ) ) {
			if ( ! class_exists( '\GFAPI' ) || ! is_numeric( $id ) ) {
				return null;
			}
			return (bool) \GFAPI::get_form( (int) $id );
		}

		return null;
	}

	/**
	 * Whether a block name is in the `core/` namespace.
	 *
	 * @param string $block_name Fully-qualified block name.
	 * @return bool
	 */
	private function is_core_namespace( string $block_name ): bool {
		return 0 === strpos( $block_name, 'core/' );
	}
}
