<?php
/**
 * Team grid section archetype: people cards with circular avatars.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services\PageAssembly\Archetypes;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\MarkupHarness\Context;

/**
 * Renders a {@see RendersMarkup::render_section()} wide section with a
 * `core/columns` row, one member per column: a circular avatar (the same raw
 * `<img>` treatment {@see Testimonials} already uses, larger), centered name,
 * role, and optional short bio, each column wrapped in a light
 * {@see RendersMarkup::render_floating_card()} card. Columns never declare a
 * `width` attr — the Validator-safe auto-distributed state.
 *
 * Content shape (avatarQuery slots are resolved to avatarUrl by PageAssembler):
 * ```
 * [
 *   'heading' => string|null,
 *   'intro'   => string|null,
 *   'members' => [ [ 'name' => string, 'role' => string, 'bio' => string|null, 'avatarUrl' => string|null ], ... ] (2-4, capped at 4),
 * ]
 * ```
 *
 * Single variant, `cards`.
 */
class TeamGrid implements Archetype {

	use RendersMarkup;

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
	public function name(): string {
		return 'teamGrid';
	}

	/**
	 * {@inheritDoc}
	 */
	public function render( array $content, ?string $variant, Context $ctx, ?string $background_slug ): string {
		$heading = isset( $content['heading'] ) ? (string) $content['heading'] : '';
		$intro   = isset( $content['intro'] ) ? (string) $content['intro'] : '';
		$members = isset( $content['members'] ) && is_array( $content['members'] ) ? array_slice( $content['members'], 0, 4 ) : array();

		$columns = empty( $members ) ? '' : $this->render_columns( $members, $ctx, $background_slug );

		return $this->render_section( $heading, $intro, $columns, $ctx, $background_slug );
	}

	/**
	 * Render one card column per member.
	 *
	 * @param array<int, array<string, mixed>> $members         Member definitions.
	 * @param Context                          $ctx             Theme/conformance context.
	 * @param string|null                      $background_slug The section's own background slug.
	 * @return string
	 */
	private function render_columns( array $members, Context $ctx, ?string $background_slug ): string {
		$card_slug = $this->card_slug_for_section( $ctx, $background_slug );
		$card_text = null !== $card_slug ? $this->text_slug_for_background( $ctx, $card_slug ) : null;

		$columns = '';
		foreach ( $members as $member ) {
			$name       = isset( $member['name'] ) ? (string) $member['name'] : '';
			$role       = isset( $member['role'] ) ? (string) $member['role'] : '';
			$bio        = isset( $member['bio'] ) ? (string) $member['bio'] : '';
			$avatar_url = isset( $member['avatarUrl'] ) ? (string) $member['avatarUrl'] : '';

			$card_inner = '';
			if ( '' !== $avatar_url ) {
				$card_inner .= '<div style="text-align:center"><img src="' . $this->esc_url( $avatar_url ) . '" alt="" width="112" height="112" style="border-radius:9999px;object-fit:cover"/></div>';
			}
			$card_inner .= $this->render_heading( $name, 3, $card_text, true );
			if ( '' !== $role ) {
				$card_inner .= $this->render_paragraph( $role, $card_text, true );
			}
			if ( '' !== $bio ) {
				$card_inner .= $this->render_paragraph( $bio, $card_text, true );
			}

			$card     = $this->render_floating_card( $card_inner, $ctx, $card_slug, $card_text );
			$columns .= $this->comment_wrap( 'column', array(), '<div class="wp-block-column">' . $card . '</div>' );
		}

		return $this->render_columns_wrap( $columns, $ctx, false, 'md', true );
	}
}
