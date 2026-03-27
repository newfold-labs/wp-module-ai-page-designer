<?php
/**
 * Capability gate helpers for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

use NewfoldLabs\WP\Module\Data\SiteCapabilities;

/**
 * Centralized capability checks for the AI Page Designer module.
 */
class CapabilityGate {

	/**
	 * Check whether AI SiteGen is enabled for the current site.
	 *
	 * @return bool
	 */
	public static function has_ai_site_gen() {
		if ( ! class_exists( SiteCapabilities::class ) ) {
			return false;
		}

		$capabilities = new SiteCapabilities();

		return (bool) $capabilities->get( 'canAccessAI' );
	}

	/**
	 * Check whether the current user can access the AI Page Designer routes.
	 *
	 * @return bool|\WP_Error
	 */
	public static function rest_permission() {
		if ( ! current_user_can( 'edit_pages' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You must have permission to edit pages', 'wp-module-ai-page-designer' ),
				array( 'status' => 401 )
			);
		}

		if ( ! self::has_ai_site_gen() ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'AI Site Generation is not enabled for your site', 'wp-module-ai-page-designer' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}
}
