<?php
/**
 * AI Page Designer Main Class
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner;

use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;
use NewfoldLabs\WP\ModuleLoader\Container;

/**
 * Class AIPageDesigner
 *
 * Main module class that initializes the AI Page Designer functionality
 */
class AIPageDesigner {

	/**
	 * Pattern provider configuration.
	 *
	 * Determines which layout provider to use for new page generation:
	 * - 'wonderblocks': Use WonderBlocks patterns with intent-based selection (recommended)
	 * - 'blueprints': Use random blueprints from Hiive API
	 * - '': Empty string for pure AI generation without layout scaffolding
	 *
	 * @var string
	 */
	const PATTERN_PROVIDER = '';

	/**
	 * Dependency injection container.
	 *
	 * @var Container
	 */
	protected $container;

	/**
	 * Constructor.
	 *
	 * @param Container $container The primary module container
	 */
	public function __construct( Container $container ) {
		$this->container = $container;

		// Always enqueue assets so the React app can render a proper message when
		// canAccessAIPageDesigner is missing, rather than showing a blank page.
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		if ( ! CapabilityGate::has_ai_site_gen() || ! CapabilityGate::has_ai_page_designer() ) {
			return;
		}

		// Register REST API routes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Load text domain
		add_action( 'init', array( __CLASS__, 'load_text_domain' ), 100 );

		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_animation_classes' ), 10, 2 );
		add_filter( 'safe_style_css', array( $this, 'allow_animation_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_animations' ) );
		add_action( 'enqueue_block_editor_assets', array( $this, 'enqueue_editor_animations' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$controllers = array(
			'NewfoldLabs\\WP\\Module\\AIPageDesigner\\RestApi\\AIPageDesignerController',
			'NewfoldLabs\\WP\\Module\\AIPageDesigner\\RestApi\\WordPressProxyController',
			'NewfoldLabs\\WP\\Module\\AIPageDesigner\\RestApi\\IntentClassifierController',
			'NewfoldLabs\\WP\\Module\\AIPageDesigner\\RestApi\\PagePlanController',
		);

		foreach ( $controllers as $controller ) {
			if ( class_exists( $controller ) ) {
				$instance = new $controller();
				$instance->register_routes();
			}
		}
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook
	 */
	public function enqueue_assets( $hook ) {
		// Only load on the main plugin pages (since we're integrating with the main app)
		if ( false === strpos( $hook, 'web' ) ) {
			return;
		}

		// Enqueue AI Designer React app (will be loaded by the main plugin's router).
		// Version by the bundle's mtime so a rebuild busts the browser cache (the static
		// module version alone left stale JS served while the CSS — versioned below — updated).
		$script_path    = NFD_MODULE_AI_PAGE_DESIGNER_DIR . '/build/index.js';
		$script_version = file_exists( $script_path )
			? NFD_MODULE_AI_PAGE_DESIGNER_VERSION . '.' . filemtime( $script_path )
			: NFD_MODULE_AI_PAGE_DESIGNER_VERSION;
		wp_enqueue_script(
			'nfd-ai-page-designer',
			NFD_MODULE_AI_PAGE_DESIGNER_URL . 'build/index.js',
			array(
				'react',
				'react-dom',
				'wp-api-fetch',
				'wp-element',
				'wp-blocks',
				'wp-components',
				'wp-data',
				'wp-a11y',
				'wp-compose',
				'wp-deprecated',
				'wp-i18n',
				'wp-plugins',
				'wp-preferences',
				'wp-viewport',
			),
			$script_version,
			true
		);

		// Ensure media picker is available for featured image selection.
		wp_enqueue_media();

		// Core component styles (buttons, popovers, etc.) used by the workspace
		// shell's @wordpress/interface + @wordpress/components primitives.
		wp_enqueue_style( 'wp-components' );

		// Enqueue styles with cache busting
		wp_enqueue_style(
			'nfd-ai-page-designer',
			NFD_MODULE_AI_PAGE_DESIGNER_URL . 'build/index.css',
			array( 'wp-components' ),
			NFD_MODULE_AI_PAGE_DESIGNER_VERSION . '.' . time()
		);

		// Collect preview stylesheets for block rendering in the iframe
		global $wp_styles;
		$block_library_url = '';
		if ( isset( $wp_styles->registered['wp-block-library'] ) ) {
			$style = $wp_styles->registered['wp-block-library'];
			$src   = $style->src;
			// Prepend site URL if the path is relative
			if ( $src && ! preg_match( '/^https?:\/\//', $src ) ) {
				$src = site_url( $src );
			}
			$ver               = $style->ver ?? NFD_MODULE_AI_PAGE_DESIGNER_VERSION;
			$block_library_url = add_query_arg( 'ver', $ver, $src );
		}

		// Global styles CSS compiled from theme.json (WP 5.9+)
		$global_styles_css = function_exists( 'wp_get_global_stylesheet' )
			? wp_get_global_stylesheet()
			: '';

		// Theme color palette from theme.json
		$color_palette = array();
		if ( function_exists( 'wp_get_global_settings' ) ) {
			$theme_settings = wp_get_global_settings();
			$theme_swatches = isset( $theme_settings['color']['palette']['theme'] )
				? $theme_settings['color']['palette']['theme']
				: array();
			foreach ( $theme_swatches as $swatch ) {
				$color_palette[] = array(
					'slug'  => isset( $swatch['slug'] ) ? $swatch['slug'] : '',
					'name'  => isset( $swatch['name'] ) ? $swatch['name'] : ( isset( $swatch['slug'] ) ? $swatch['slug'] : '' ),
					'color' => isset( $swatch['color'] ) ? $swatch['color'] : '',
				);
			}
		}

		// Localize script with configuration
		wp_localize_script(
			'nfd-ai-page-designer',
			'nfdAIPageDesigner',
			array(
				'apiUrl'                  => 'newfold-ai-page-designer/v1',
				'apiRoot'                 => esc_url_raw( rest_url() ),
				'nonce'                   => wp_create_nonce( 'wp_rest' ),
				'siteUrl'                 => get_site_url(),
				'canAccessAI'             => CapabilityGate::has_ai_site_gen(),
				'canAccessAIPageDesigner' => CapabilityGate::has_ai_page_designer(),
				'currentUserId'           => get_current_user_id(),
				'ajaxUrl'                 => admin_url( 'admin-ajax.php' ),
				'previewStylesheets'      => array(
					'blockLibrary' => $block_library_url,
					'themeUrl'     => get_stylesheet_directory_uri() . '/style.css',
					'globalStyles' => $global_styles_css,
				),
				'colorPalette'            => $color_palette,
				// Same motion-vocabulary CSS enqueue_frontend_animations() adds to the
				// published page, scoped to the preview iframe's #nfd-preview-root
				// instead — one source of truth (get_motion_css()) so preview and
				// front-end can never drift on which motion classes actually animate.
				'previewMotionCss'        => self::get_motion_css( '#nfd-preview-root' ),
				// Route edits through the AI intent classifier instead of the
				// frontend keyword regexes. On by default; disable via the filter:
				// add_filter( 'nfd_ai_page_designer_enable_intent_classifier', '__return_false' );
				'enableIntentClassifier'  => (bool) apply_filters( 'nfd_ai_page_designer_enable_intent_classifier', true ),
				// Render a new page from a PageAssembler page-plan (typed archetypes)
				// instead of freeform AI markup. On by default now that the v1
				// catalogue (10 archetypes) is complete; disable via the filter:
				// add_filter( 'nfd_ai_page_designer_enable_page_plan', '__return_false' );
				'enablePagePlan'          => (bool) apply_filters( 'nfd_ai_page_designer_enable_page_plan', true ),
			)
		);
	}

	/**
	 * Load text domain for Module
	 *
	 * @return void
	 */
	public static function load_text_domain() {
		load_plugin_textdomain(
			'wp-module-ai-page-designer',
			false,
			NFD_MODULE_AI_PAGE_DESIGNER_DIR . '/languages'
		);
	}

	/**
	 * Allow animation CSS classes in content
	 *
	 * @param array  $allowed_html Allowed HTML tags and attributes
	 * @param string $context Context for filtering
	 * @return array Modified allowed HTML
	 */
	public function allow_animation_classes( $allowed_html, $context ) {
		// Only apply to post content contexts
		if ( 'post' !== $context ) {
			return $allowed_html;
		}

		// Add class attribute to all allowed HTML tags if not already present
		foreach ( $allowed_html as $tag => $attributes ) {
			if ( ! isset( $attributes['class'] ) ) {
				$allowed_html[ $tag ]['class'] = true;
			}
		}

		return $allowed_html;
	}

	/**
	 * Allow animation-related CSS properties
	 *
	 * @param array $styles Allowed CSS properties
	 * @return array Modified allowed CSS properties
	 */
	public function allow_animation_styles( $styles ) {
		$animation_properties = array(
			'animation',
			'animation-name',
			'animation-duration',
			'animation-timing-function',
			'animation-delay',
			'animation-iteration-count',
			'animation-direction',
			'animation-fill-mode',
			'animation-play-state',
			'transition',
			'transition-property',
			'transition-duration',
			'transition-timing-function',
			'transition-delay',
			'transform',
			'transform-origin',
			'transform-style',
			'perspective',
			'perspective-origin',
			'backface-visibility',
		);

		return array_merge( $styles, $animation_properties );
	}

	/**
	 * The canonical motion vocabulary CSS, shared verbatim by the published
	 * front-end (this method, scoped to `.entry-content, .wp-block-post-content`)
	 * and the designer preview iframe (localized to the frontend as
	 * `previewMotionCss`, scoped to `#nfd-preview-root` — see `enqueue_assets()`
	 * and `usePreviewIframe.ts`).
	 *
	 * This is the single source of truth for every motion class the AI/archetypes
	 * may emit (`fade-in`, `slide-up`, `bounce-in`, `scale-in`, `fade-in-delay-1/2/3`,
	 * `pulse-hover`, `glow-hover`, `card-hover-lift`, `nfd-scroll-fade`) plus the
	 * decorative shell classes (`nfd-max-w-640/720`, `nfd-rounded-img`,
	 * `nfd-accent-bar`) that replaced the archetypes' old unbacked inline styles
	 * — a raw inline style with no matching block attribute fails Gutenberg's own
	 * re-validation in the editor even when it renders correctly, so anything
	 * purely decorative now goes through a real className instead (see
	 * `RendersMarkup::render_heading()`'s note). `nfd-scroll-fade` itself replaced
	 * a `data-aos="fade-up"` attribute for the same reason: a bare `data-*`
	 * attribute isn't a validated block attribute either. Previously the
	 * drifted — `pulse-hover`/`glow-hover` existed only in the preview's copy, so
	 * those classes animated in the editor but did nothing on the published page.
	 * Adding a class here now updates both surfaces at once; it cannot drift again.
	 *
	 * @param string $scope           CSS selector prefix scoping every rule (e.g. `.entry-content, .wp-block-post-content` or `#nfd-preview-root`).
	 * @param string $keyframe_prefix Prefix for `@keyframes` names, to avoid colliding with other stylesheets sharing the same document (e.g. `nfd-`). Pass `''` for the preview iframe, which is its own isolated document.
	 * @return string
	 */
	public static function get_motion_css( $scope, $keyframe_prefix = '' ) {
		$fade_in   = $keyframe_prefix . 'fadeIn';
		$slide_up  = $keyframe_prefix . 'slideUp';
		$bounce_in = $keyframe_prefix . 'bounceIn';
		$scale_in  = $keyframe_prefix . 'scaleIn';
		$pulse     = $keyframe_prefix . 'pulse';

		// The scope may be a selector LIST (".entry-content, .wp-block-post-content").
		// Naively prepending it to a rule ("$scope .pulse-hover:hover { … }") splits
		// at the comma, so the FIRST scope alone — the whole page container — received
		// every declaration, including the infinite pulse animation (the published
		// page visibly zoomed in and out on its own, continuously). Expand the list
		// so every scope prefixes every selector instead.
		$scopes = array_map( 'trim', explode( ',', (string) $scope ) );
		$sel    = static function ( $suffix ) use ( $scopes ) {
			$parts = array();
			foreach ( $scopes as $one_scope ) {
				if ( '' !== $one_scope ) {
					$parts[] = $one_scope . ' ' . $suffix;
				}
			}
			return implode( ', ', $parts );
		};

		return '
			@keyframes ' . $fade_in . ' { from { opacity: 0; } to { opacity: 1; } }
			@keyframes ' . $slide_up . ' { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
			@keyframes ' . $bounce_in . ' { 0% { opacity: 0; transform: scale(0.3); } 50% { opacity: 1; transform: scale(1.05); } 70% { transform: scale(0.9); } 100% { opacity: 1; transform: scale(1); } }
			@keyframes ' . $scale_in . ' { from { opacity: 0; transform: scale(0.8); } to { opacity: 1; transform: scale(1); } }
			@keyframes ' . $pulse . ' { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
			' . $sel( '.fade-in' ) . ' { animation: ' . $fade_in . ' 0.8s ease-out forwards; }
			' . $sel( '.slide-up' ) . ' { animation: ' . $slide_up . ' 0.8s ease-out forwards; }
			' . $sel( '.bounce-in' ) . ' { animation: ' . $bounce_in . ' 0.8s ease-out forwards; }
			' . $sel( '.scale-in' ) . ' { animation: ' . $scale_in . ' 0.8s ease-out forwards; }
			' . $sel( '.fade-in-delay-1' ) . ' { animation: ' . $fade_in . ' 0.8s ease-out 0.2s forwards; opacity: 0; }
			' . $sel( '.fade-in-delay-2' ) . ' { animation: ' . $fade_in . ' 0.8s ease-out 0.4s forwards; opacity: 0; }
			' . $sel( '.fade-in-delay-3' ) . ' { animation: ' . $fade_in . ' 0.8s ease-out 0.6s forwards; opacity: 0; }
			' . $sel( '.pulse-hover' ) . ' { transition: all 0.3s ease; }
			' . $sel( '.pulse-hover:hover' ) . ' { animation: ' . $pulse . ' 1s infinite; box-shadow: 0 0 20px rgba(59, 130, 246, 0.5); }
			' . $sel( '.glow-hover' ) . ' { transition: all 0.3s ease; }
			' . $sel( '.glow-hover:hover' ) . ' { box-shadow: 0 0 30px rgba(59, 130, 246, 0.6), 0 0 60px rgba(59, 130, 246, 0.4); }
			' . $sel( '.card-hover-lift' ) . ' { border-radius: 16px; box-shadow: 0 20px 60px -15px rgba(0,0,0,.35); overflow: hidden; transition: all 0.3s ease; }
			' . $sel( '.card-hover-lift:hover' ) . ' { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
			' . $sel( '.nfd-scroll-fade' ) . ' { opacity: 0; transform: translateY(30px); transition: all 0.8s ease; }
			' . $sel( '.nfd-scroll-fade.aos-animate' ) . ' { opacity: 1; transform: translateY(0); }
			' . $sel( '.nfd-max-w-640' ) . ' { max-width: 640px; margin-left: auto; margin-right: auto; }
			' . $sel( '.nfd-max-w-720' ) . ' { max-width: 720px; margin-left: auto; margin-right: auto; }
			' . $sel( '.nfd-rounded-img' ) . ' { border-radius: 16px; overflow: hidden; box-shadow: 0 12px 32px -12px rgba(0,0,0,.25); }
			' . $sel( '.nfd-rounded-img img' ) . ' { width: 100%; height: 100%; object-fit: cover; }
			' . $sel( '.nfd-accent-bar' ) . ' { width: 48px; height: 4px; border: none; margin: 0 0 16px 0; }
			' . $sel( '.nfd-accent-bar.nfd-accent-bar-center' ) . ' { margin-left: auto; margin-right: auto; }
			' . $sel( '.nfd-list-plain' ) . ' { text-align: center; list-style: none; padding-left: 0; margin-left: 0; }
			' . $sel( '.nfd-banner-strip' ) . ' { height: 120px; overflow: hidden; border-radius: 16px; }
			' . $sel( '.nfd-banner-strip img' ) . ' { width: 100%; height: 100%; object-fit: cover; }
			' . $sel( '.nfd-faq-card' ) . ' { border-radius: 12px; padding: 16px 24px; margin-bottom: 16px; }
			' . $sel( '.nfd-faq-card summary' ) . ' { cursor: pointer; font-weight: 600; }
			' . $sel( '.nfd-step-badge' ) . ' { width: 56px; height: 56px; line-height: 56px; border-radius: 9999px; margin-left: auto; margin-right: auto; font-weight: 700; font-size: 1.25rem; }
			' . $sel( '.nfd-avatar-112' ) . ' { display: block; width: 112px; height: 112px; border-radius: 9999px; overflow: hidden; margin-left: auto; margin-right: auto; }
			' . $sel( '.nfd-avatar-56' ) . ' { display: block; width: 56px; height: 56px; border-radius: 9999px; overflow: hidden; margin-left: auto; margin-right: auto; }
			' . $sel( '.nfd-avatar-112 img' ) . ', ' . $sel( '.nfd-avatar-56 img' ) . ' { width: 100%; height: 100%; object-fit: cover; }
			' . $sel( '.nfd-fancy-heading' ) . ' { font-family: "Cormorant Garamond", serif !important; font-style: italic !important; font-weight: 400 !important; font-size: clamp(3rem, 3rem + 5vw, 7rem) !important; line-height: 1.05 !important; margin-top: 0.15em !important; margin-bottom: 0.15em !important; }
		';
	}

	/**
	 * Build the CSS that re-colors/re-types a published page for a saved Design
	 * tab selection. Mirrors `window.nfdSetDesignTokens` in usePreviewIframe.ts
	 * exactly (same variable names, same override strategy) so a page looks
	 * identical in the designer preview and once published — colors override
	 * the theme's own `--wp--preset--color--{slug}` custom properties (block
	 * markup already reads them via var(...)), fonts need a direct !important
	 * override since the theme's font-family rules aren't variable-driven.
	 *
	 * @param array<string,string>|null $colors       Slug => hex color map.
	 * @param string                    $heading_font CSS font-family value, or '' for theme default.
	 * @param string                    $body_font    CSS font-family value, or '' for theme default.
	 * @return string
	 */
	private static function get_design_tokens_css( $colors, $heading_font, $body_font ) {
		$rules = array();

		if ( ! empty( $colors ) && is_array( $colors ) ) {
			$vars = array();
			foreach ( $colors as $slug => $value ) {
				$hex = sanitize_hex_color( $value );
				if ( ! $hex ) {
					continue;
				}
				$css_slug = str_replace( '_', '-', sanitize_key( $slug ) );
				// !important: WP core's own global-styles inline stylesheet
				// (also a `:root { --wp--preset--color--*: ... }` block, same
				// specificity) is enqueued after wp-block-library's inline
				// styles, so on equal specificity it would otherwise win by
				// source order alone and silently discard this override. The
				// iframe preview avoids this by scoping to a high-specificity
				// #nfd-preview-root ID instead — :root has no such advantage
				// here, so !important is the only reliable way to win.
				$vars[] = '--wp--preset--color--' . $css_slug . ': ' . $hex . ' !important;';
			}
			if ( ! empty( $vars ) ) {
				$rules[] = ':root { ' . implode( ' ', $vars ) . ' }';
			}
		}

		if ( ! empty( $body_font ) ) {
			$rules[] = 'body, body * { font-family: ' . sanitize_text_field( $body_font ) . ' !important; }';
		}

		if ( ! empty( $heading_font ) ) {
			// :not(.nfd-fancy-heading) — this rule and the fancy-heading rule in
			// get_motion_css() are both scoped `h1`/`.wp-block-heading`-level
			// selectors with equal specificity and !important, so without the
			// exclusion whichever stylesheet happens to load/apply last silently
			// wins the tie instead of the fancy heading's own font declaration.
			$rules[] = 'h1:not(.nfd-fancy-heading), h2:not(.nfd-fancy-heading), h3:not(.nfd-fancy-heading), h4:not(.nfd-fancy-heading), h5:not(.nfd-fancy-heading), h6:not(.nfd-fancy-heading), .wp-block-heading:not(.nfd-fancy-heading) { font-family: ' . sanitize_text_field( $heading_font ) . ' !important; }';
		}

		return implode( ' ', $rules );
	}

	/**
	 * Enqueue frontend animation styles and scripts
	 */
	public function enqueue_frontend_animations() {
		if ( ! is_page() && ! is_single() && ! is_front_page() ) {
			return;
		}

		wp_enqueue_style(
			'nfd-ai-page-fonts',
			// Includes Inter/Manrope alongside the archetype fonts so every
			// curated Design tab font pairing (designTokens.ts) is available
			// on the published page, not just inside the admin preview build.
			// Cormorant Garamond (italic 400 only) backs the "nfd-fancy-heading"
			// class every HeroCover/ParallaxBanner heading uses — see
			// RendersMarkup::render_heading()'s $fancy param.
			'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Montserrat:wght@400;600;700&family=Lora:ital,wght@0,400;0,700;1,400&family=Raleway:wght@400;600;700&family=Inter:wght@400;500;600&family=Manrope:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@1,400&display=swap',
			array(),
			NFD_MODULE_AI_PAGE_DESIGNER_VERSION
		);

		$content_scope = '.entry-content, .wp-block-post-content';
		$animation_css = self::get_motion_css( $content_scope, 'nfd-' );

		wp_add_inline_style( 'wp-block-library', $animation_css );

		$queried_id = get_queried_object_id();
		if ( $queried_id ) {
			$design_tokens = get_post_meta( $queried_id, RestApi\WordPressProxyController::DESIGN_TOKENS_META_KEY, true );
			if ( is_array( $design_tokens ) ) {
				$tokens_css = self::get_design_tokens_css(
					isset( $design_tokens['colors'] ) ? $design_tokens['colors'] : null,
					isset( $design_tokens['headingFont'] ) ? $design_tokens['headingFont'] : '',
					isset( $design_tokens['bodyFont'] ) ? $design_tokens['bodyFont'] : ''
				);
				if ( '' !== $tokens_css ) {
					wp_add_inline_style( 'wp-block-library', $tokens_css );
				}
			}
		}

		wp_register_script( 'nfd-ai-page-animations', false, array(), NFD_MODULE_AI_PAGE_DESIGNER_VERSION, true );
		wp_enqueue_script( 'nfd-ai-page-animations' );
		wp_add_inline_script(
			'nfd-ai-page-animations',
			'(function(){
				var observer = new IntersectionObserver(function(entries){
					entries.forEach(function(entry){
						if(entry.isIntersecting){
							entry.target.classList.add("aos-animate");
							observer.unobserve(entry.target);
						}
					});
				},{threshold:0});
				document.querySelectorAll(".entry-content .nfd-scroll-fade, .wp-block-post-content .nfd-scroll-fade").forEach(function(el){ observer.observe(el); });
			})()'
		);
	}

	/**
	 * Enqueue the same motion/typography CSS + fonts inside the Gutenberg
	 * editor canvas. Without this, generated pages only look right on the
	 * published page and in the Designer's own preview — the block editor
	 * has no idea `.nfd-fancy-heading` or the animation classes exist, so
	 * headings fall back to the theme's default font while editing.
	 *
	 * WordPress copies any stylesheet enqueued during this hook into the
	 * post editor's iframed canvas automatically, so a plain wp_enqueue_style()
	 * here is sufficient — no add_editor_style() call needed.
	 */
	public function enqueue_editor_animations() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! in_array( $screen->post_type, array( 'page', 'post' ), true ) ) {
			return;
		}

		wp_enqueue_style(
			'nfd-ai-page-fonts',
			'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Montserrat:wght@400;600;700&family=Lora:ital,wght@0,400;0,700;1,400&family=Raleway:wght@400;600;700&family=Inter:wght@400;500;600&family=Manrope:wght@400;600;700;800&family=Cormorant+Garamond:ital,wght@1,400&display=swap',
			array(),
			NFD_MODULE_AI_PAGE_DESIGNER_VERSION
		);

		// .editor-styles-wrapper is the class WordPress wraps the iframed
		// canvas content in; .wp-block-post-content mirrors the frontend scope.
		$content_scope = '.editor-styles-wrapper, .wp-block-post-content';
		wp_add_inline_style( 'wp-block-library', self::get_motion_css( $content_scope, 'nfd-editor-' ) );

		// get_motion_css()'s `.nfd-scroll-fade` rule starts elements at opacity:0,
		// relying on enqueue_frontend_animations()'s IntersectionObserver script to
		// add `.aos-animate` once scrolled into view. That script only ever runs on
		// the published page (and, separately, inside the Designer's own preview
		// iframe via usePreviewIframe.ts) — a script enqueued on this hook executes
		// in the top wp-admin document, not inside the block editor's iframed
		// canvas, so it could never reach these elements anyway. Without this
		// override every scroll-fade section would stay permanently invisible
		// while editing.
		$scroll_fade_override = array();
		foreach ( array_map( 'trim', explode( ',', $content_scope ) ) as $scope_part ) {
			$scroll_fade_override[] = $scope_part . ' .nfd-scroll-fade { opacity: 1 !important; transform: none !important; }';
		}
		wp_add_inline_style( 'wp-block-library', implode( ' ', $scroll_fade_override ) );

		$post = get_post();
		if ( $post ) {
			$design_tokens = get_post_meta( $post->ID, RestApi\WordPressProxyController::DESIGN_TOKENS_META_KEY, true );
			if ( is_array( $design_tokens ) ) {
				$tokens_css = self::get_design_tokens_css(
					isset( $design_tokens['colors'] ) ? $design_tokens['colors'] : null,
					isset( $design_tokens['headingFont'] ) ? $design_tokens['headingFont'] : '',
					isset( $design_tokens['bodyFont'] ) ? $design_tokens['bodyFont'] : ''
				);
				if ( '' !== $tokens_css ) {
					wp_add_inline_style( 'wp-block-library', $tokens_css );
				}
			}
		}
	}
}
