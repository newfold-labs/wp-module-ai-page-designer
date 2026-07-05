<?php
/**
 * Gallery grid section archetype: a grid of rounded images.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} wide section holding rows of
 * `core/columns`, one rounded {@see RendersMarkup::render_image_block()} image
 * per column, 3 per row (portfolio / product shots / interior photos). Columns
 * never declare a `width` attr — the one width state the Validator's
 * column-width check always accepts, same as {@see FeatureGrid}.
 *
 * Content shape (imageQuery slots are resolved to imageUrl by PageAssembler
 * before this archetype ever runs — archetypes stay pure):
 * ```
 * [
 *   'heading' => string|null,
 *   'intro'   => string|null,
 *   'images'  => [ [ 'imageUrl' => string ], ... ] (3-6 typical, capped at 6),
 * ]
 * ```
 *
 * Single variant, `grid-3`.
 */
class GalleryGrid implements Archetype {

	use RendersMarkup;

	/**
	 * {@inheritDoc}
	 */
	public function name(): string {
		return 'galleryGrid';
	}

	/**
	 * {@inheritDoc}
	 *
	 * No fixed default — see {@see FeatureGrid::default_background()} for why.
	 */
	public function default_background( Context $ctx ): ?string {
		return null;
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$images  = isset( $content['images'] ) && is_array( $content['images'] ) ? array_slice( $content['images'], 0, 6 ) : array();

		$urls = array();
		foreach ( $images as $image ) {
			$url = is_array( $image ) && isset( $image['imageUrl'] ) ? (string) $image['imageUrl'] : '';
			if ( '' !== $url ) {
				$urls[] = $url;
			}
		}

		$rows = '';
		foreach ( array_chunk( $urls, 3 ) as $row_urls ) {
			$columns = '';
			foreach ( $row_urls as $url ) {
				$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $this->render_image_block( $url, true ) . '</div>' );
			}
			$rows .= $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
		}

		return $this->render_section( $heading, $intro, $rows, $ctx, $background_slug );
	}
}
