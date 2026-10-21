<?php
/**
 * WooCommerceGuard tests.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Tests\Services;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\WooCommerceGuard;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass( WooCommerceGuard::class )]
class WooCommerceGuardTest extends TestCase {

	protected function setUp(): void {
		if ( ! class_exists( '\WP_Post' ) ) {
			$this->markTestSkipped( 'WP_Post unavailable in this environment.' );
		}
	}

	/**
	 * Build a lightweight WP_Post-like object for tests (no DB involved).
	 *
	 * @param array $props Properties to set (ID, post_type, post_content).
	 * @return \WP_Post
	 */
	private function make_post( array $props ): \WP_Post {
		$defaults = array(
			'ID'           => 1,
			'post_type'    => 'page',
			'post_content' => '',
		);
		return new \WP_Post( (object) array_merge( $defaults, $props ) );
	}

	public function test_ordinary_page_is_not_a_dynamic_template(): void {
		$post = $this->make_post(
			array(
				'post_content' => '<!-- wp:paragraph --><p>Hello</p><!-- /wp:paragraph -->',
			)
		);

		$this->assertFalse( WooCommerceGuard::is_dynamic_template( $post ) );
	}

	public function test_checkout_block_content_is_a_dynamic_template(): void {
		$post = $this->make_post(
			array(
				'post_content' => '<!-- wp:woocommerce/checkout --><div class="wp-block-woocommerce-checkout"></div><!-- /wp:woocommerce/checkout -->',
			)
		);

		$this->assertTrue( WooCommerceGuard::is_dynamic_template( $post ) );
	}

	public function test_cart_block_content_is_a_dynamic_template(): void {
		$post = $this->make_post(
			array(
				'post_content' => '<!-- wp:woocommerce/cart --><div class="wp-block-woocommerce-cart"></div><!-- /wp:woocommerce/cart -->',
			)
		);

		$this->assertTrue( WooCommerceGuard::is_dynamic_template( $post ) );
	}

	public function test_mini_cart_block_content_is_a_dynamic_template(): void {
		$post = $this->make_post(
			array(
				'post_content' => '<!-- wp:woocommerce/mini-cart --><div class="wp-block-woocommerce-mini-cart"></div><!-- /wp:woocommerce/mini-cart -->',
			)
		);

		$this->assertTrue( WooCommerceGuard::is_dynamic_template( $post ) );
	}

	public function test_unrelated_woocommerce_block_is_not_treated_as_dynamic_template(): void {
		// A page that merely embeds a product-grid style block (not one of the
		// system-page containers) should not be excluded by this narrow guard.
		$post = $this->make_post(
			array(
				'post_content' => '<!-- wp:woocommerce/product-collection --><div></div><!-- /wp:woocommerce/product-collection -->',
			)
		);

		$this->assertFalse( WooCommerceGuard::is_dynamic_template( $post ) );
	}

	public function test_non_page_post_type_is_never_a_dynamic_template(): void {
		$post = $this->make_post(
			array(
				'post_type'    => 'post',
				'post_content' => '<!-- wp:woocommerce/checkout --><div></div><!-- /wp:woocommerce/checkout -->',
			)
		);

		$this->assertFalse( WooCommerceGuard::is_dynamic_template( $post ) );
	}

	public function test_null_post_is_not_a_dynamic_template(): void {
		$this->assertFalse( WooCommerceGuard::is_dynamic_template( null ) );
	}
}
