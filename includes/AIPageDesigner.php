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
				'apiUrl'           => 'newfold-ai-page-designer/v1',
				'apiRoot'          => esc_url_raw( rest_url() ),
				'nonce'            => wp_create_nonce( 'wp_rest' ),
				'siteUrl'          => get_site_url(),
				'canAccessAI'      => CapabilityGate::has_ai_site_gen(),
				'currentUserId'    => get_current_user_id(),
				'ajaxUrl'          => admin_url( 'admin-ajax.php' ),
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
}
