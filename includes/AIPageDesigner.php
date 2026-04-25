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

		if ( ! CapabilityGate::has_ai_site_gen() ) {
			return;
		}

		// Register REST API routes
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );

		// Register admin assets (menu is now handled by main plugin Admin class)
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );

		// Load text domain
		add_action( 'init', array( __CLASS__, 'load_text_domain' ), 100 );

		add_filter( 'wp_kses_allowed_html', array( $this, 'allow_animation_classes' ), 10, 2 );
		add_filter( 'safe_style_css', array( $this, 'allow_animation_styles' ) );
		add_action( 'wp_enqueue_scripts', array( $this, 'enqueue_frontend_animations' ) );
	}

	/**
	 * Register REST API routes
	 */
	public function register_routes() {
		$controllers = array(
			'NewfoldLabs\\WP\\Module\\AIPageDesigner\\RestApi\\AIPageDesignerController',
			'NewfoldLabs\\WP\\Module\\AIPageDesigner\\RestApi\\WordPressProxyController',
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

		// Only enqueue if capability is enabled
		if ( ! CapabilityGate::has_ai_site_gen() ) {
			return;
		}

		// Enqueue AI Designer React app (will be loaded by the main plugin's router)
		wp_enqueue_script(
			'nfd-ai-page-designer',
			NFD_MODULE_AI_PAGE_DESIGNER_URL . 'build/index.js',
			array( 'react', 'react-dom', 'wp-api-fetch', 'wp-element' ),
			NFD_MODULE_AI_PAGE_DESIGNER_VERSION,
			true
		);

		// Ensure media picker is available for featured image selection.
		wp_enqueue_media();

		// Enqueue styles with cache busting
		wp_enqueue_style(
			'nfd-ai-page-designer',
			NFD_MODULE_AI_PAGE_DESIGNER_URL . 'build/index.css',
			array(),
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

		// Localize script with configuration
		wp_localize_script(
			'nfd-ai-page-designer',
			'nfdAIPageDesigner',
			array(
				'apiUrl'             => 'newfold-ai-page-designer/v1',
				'apiRoot'            => esc_url_raw( rest_url() ),
				'nonce'              => wp_create_nonce( 'wp_rest' ),
				'siteUrl'            => get_site_url(),
				'canAccessAI'        => CapabilityGate::has_ai_site_gen(),
				'currentUserId'      => get_current_user_id(),
				'ajaxUrl'            => admin_url( 'admin-ajax.php' ),
				'previewStylesheets' => array(
					'blockLibrary' => $block_library_url,
					'themeUrl'     => get_stylesheet_directory_uri() . '/style.css',
					'globalStyles' => $global_styles_css,
				),
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
			// Allow data-aos and data-aos-* attributes for scroll animations
			$allowed_html[ $tag ]['data-aos']          = true;
			$allowed_html[ $tag ]['data-aos-duration'] = true;
			$allowed_html[ $tag ]['data-aos-delay']    = true;
			$allowed_html[ $tag ]['data-aos-offset']   = true;
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
	 * Enqueue frontend animation styles and scripts
	 */
	public function enqueue_frontend_animations() {
		if ( ! is_page() && ! is_single() && ! is_front_page() ) {
			return;
		}

		wp_enqueue_style(
			'nfd-ai-page-fonts',
			'https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,400;0,700;1,400&family=Montserrat:wght@400;600;700&family=Lora:ital,wght@0,400;0,700;1,400&family=Raleway:wght@400;600;700&display=swap',
			array(),
			NFD_MODULE_AI_PAGE_DESIGNER_VERSION
		);

		$content_scope = '.entry-content, .wp-block-post-content';

		$animation_css = '
			@keyframes nfd-fadeIn { from { opacity: 0; } to { opacity: 1; } }
			@keyframes nfd-slideUp { from { opacity: 0; transform: translateY(30px); } to { opacity: 1; transform: translateY(0); } }
			@keyframes nfd-bounceIn { 0% { opacity: 0; transform: scale(0.3); } 50% { opacity: 1; transform: scale(1.05); } 70% { transform: scale(0.9); } 100% { opacity: 1; transform: scale(1); } }
			@keyframes nfd-scaleIn { from { opacity: 0; transform: scale(0.8); } to { opacity: 1; transform: scale(1); } }
			@keyframes nfd-pulse { 0%, 100% { transform: scale(1); } 50% { transform: scale(1.05); } }
			' . $content_scope . ' .fade-in { animation: nfd-fadeIn 0.8s ease-out forwards; }
			' . $content_scope . ' .slide-up { animation: nfd-slideUp 0.8s ease-out forwards; }
			' . $content_scope . ' .bounce-in { animation: nfd-bounceIn 0.8s ease-out forwards; }
			' . $content_scope . ' .scale-in { animation: nfd-scaleIn 0.8s ease-out forwards; }
			' . $content_scope . ' .fade-in-delay-1 { animation: nfd-fadeIn 0.8s ease-out 0.2s forwards; opacity: 0; }
			' . $content_scope . ' .fade-in-delay-2 { animation: nfd-fadeIn 0.8s ease-out 0.4s forwards; opacity: 0; }
			' . $content_scope . ' .fade-in-delay-3 { animation: nfd-fadeIn 0.8s ease-out 0.6s forwards; opacity: 0; }
			' . $content_scope . ' .card-hover-lift { transition: all 0.3s ease; }
			' . $content_scope . ' .card-hover-lift:hover { transform: translateY(-10px); box-shadow: 0 20px 40px rgba(0,0,0,0.15); }
			' . $content_scope . ' [data-aos] { opacity: 0; transform: translateY(30px); transition: all 0.8s ease; }
			' . $content_scope . ' [data-aos].aos-animate { opacity: 1; transform: translateY(0); }
		';

		wp_add_inline_style( 'wp-block-library', $animation_css );

		wp_register_script( 'nfd-ai-page-animations', false, array(), NFD_MODULE_AI_PAGE_DESIGNER_VERSION, true );
		wp_enqueue_script( 'nfd-ai-page-animations' );
		wp_add_inline_script(
			'nfd-ai-page-animations',
			'(function(){
				var observer = new IntersectionObserver(function(entries){
					entries.forEach(function(entry){
						if(entry.isIntersecting){
							var delay = parseInt(entry.target.getAttribute("data-aos-delay")||"0",10);
							setTimeout(function(){ entry.target.classList.add("aos-animate"); }, delay);
							observer.unobserve(entry.target);
						}
					});
				},{threshold:0});
				document.querySelectorAll(".entry-content [data-aos], .wp-block-post-content [data-aos]").forEach(function(el){ observer.observe(el); });
			})()'
		);
	}
}
