<?php
/**
 * WooCommerce dynamic-template detection for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

/**
 * Detects WordPress pages that are WooCommerce-managed dynamic templates
 * (Cart, Checkout, My Account, Mini-Cart) so the Designer can keep them out
 * of its dashboard and refuse to generate/save over them.
 *
 * These pages store near-empty container block markup — WooCommerce's own
 * blocks (`woocommerce/checkout`, `woocommerce/cart`, `woocommerce/mini-cart`)
 * populate the actual content at render time from cart/session state, and
 * their inner blocks are template-locked by WooCommerce itself (no color/
 * spacing/typography supports, `templateLock:"insert"`, `inserter:false`).
 * There is nothing in them for the AI pipeline to safely edit.
 */
class WooCommerceGuard {

	/**
	 * The WooCommerce block names that identify a dynamic template page,
	 * whether or not the page is one of WooCommerce's registered special
	 * pages (a page can also embed one of these manually).
	 *
	 * @var string[]
	 */
	const DYNAMIC_BLOCK_NAMES = array(
		'woocommerce/checkout',
		'woocommerce/cart',
		'woocommerce/mini-cart',
	);

	/**
	 * Whether a post is a WooCommerce-managed dynamic template that the
	 * Designer should not list, open, generate for, or save over.
	 *
	 * @param \WP_Post|null $post Post object.
	 * @return bool
	 */
	public static function is_dynamic_template( $post ) {
		if ( ! $post instanceof \WP_Post || 'page' !== $post->post_type ) {
			return false;
		}

		if ( self::is_registered_woocommerce_page( $post->ID ) ) {
			return true;
		}

		return self::has_dynamic_block( $post->post_content );
	}

	/**
	 * Whether the given page ID is one of WooCommerce's own registered
	 * special pages (Cart, Checkout, My Account).
	 *
	 * @param int $post_id Post ID.
	 * @return bool
	 */
	private static function is_registered_woocommerce_page( $post_id ) {
		if ( ! function_exists( 'wc_get_page_id' ) ) {
			return false;
		}

		$special_pages = array( 'cart', 'checkout', 'myaccount' );

		foreach ( $special_pages as $special_page ) {
			$page_id = (int) wc_get_page_id( $special_page );
			if ( $page_id > 0 && $page_id === (int) $post_id ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Whether the markup's top-level blocks contain one of WooCommerce's
	 * dynamic container blocks (defense in depth for pages that embed one
	 * without being a registered WooCommerce special page).
	 *
	 * @param string $content Post content (raw block markup).
	 * @return bool
	 */
	private static function has_dynamic_block( $content ) {
		if ( false === strpos( (string) $content, 'wp:woocommerce/' ) ) {
			return false;
		}

		$blocks = parse_blocks( (string) $content );

		foreach ( $blocks as $block ) {
			if ( ! empty( $block['blockName'] ) && in_array( $block['blockName'], self::DYNAMIC_BLOCK_NAMES, true ) ) {
				return true;
			}
		}

		return false;
	}
}
