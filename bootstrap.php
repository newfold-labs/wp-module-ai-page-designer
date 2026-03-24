<?php
/**
 * AI Page Designer Module Bootstrap
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner;

use NewfoldLabs\WP\Module\AIPageDesigner\AIPageDesigner;
use NewfoldLabs\WP\Module\AIPageDesigner\Services\CapabilityGate;
use NewfoldLabs\WP\ModuleLoader\Container;

use function NewfoldLabs\WP\ModuleLoader\register;

if ( function_exists( 'add_action' ) ) {

	add_action(
		'plugins_loaded',
		function () {
			// Only load module if AI SiteGen is enabled.
			if ( ! CapabilityGate::has_ai_site_gen() ) {
				return;
			}

			// Set Global Constants
			if ( ! defined( 'NFD_MODULE_AI_PAGE_DESIGNER_DIR' ) ) {
				define( 'NFD_MODULE_AI_PAGE_DESIGNER_DIR', __DIR__ );
			}

			if ( ! defined( 'NFD_MODULE_AI_PAGE_DESIGNER_VERSION' ) ) {
				define( 'NFD_MODULE_AI_PAGE_DESIGNER_VERSION', '1.0.0' );
			}

			if ( ! defined( 'NFD_MODULE_AI_PAGE_DESIGNER_URL' ) ) {
				$plugin_path = dirname( dirname( dirname( __DIR__ ) ) );
				$plugin_url  = plugins_url( '', $plugin_path . '/wp-plugin-web.php' );
				define( 'NFD_MODULE_AI_PAGE_DESIGNER_URL', $plugin_url . '/vendor/newfold-labs/wp-module-ai-page-designer/' );
			}

			// Register the module
			register(
				array(
					'name'     => 'ai-page-designer',
					'label'    => __( 'AI Page Designer', 'wp-module-ai-page-designer' ),
					'callback' => function ( Container $container ) {
						return new AIPageDesigner( $container );
					},
					'isActive' => true,
					'isHidden' => true,
				)
			);
		}
	);

}
