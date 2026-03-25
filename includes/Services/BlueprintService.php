<?php
/**
 * Blueprint service for AI Page Designer.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

/**
 * Fetches, caches, and selects blueprints from the Hiive blueprints API.
 * Extracts Gutenberg page markup from blueprint SQL exports to use as base layouts.
 */
class BlueprintService {

	/**
	 * Blueprints API endpoint.
	 *
	 * @var string
	 */
	const BLUEPRINTS_API_URL = 'https://patterns.hiive.cloud/api/v1/blueprints';

	/**
	 * Our cache option key. We read from the onboarding option but never write to it.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nfd_aipd_state_blueprints';

	/**
	 * Onboarding blueprints option key (read-only).
	 *
	 * @var string
	 */
	const ONBOARDING_OPTION_KEY = 'nfd_module_onboarding_state_blueprints';

	/**
	 * Get the full blueprints list, from cache or API.
	 *
	 * @return array
	 */
	public function get_blueprints() {
		// Check our own cache first.
		$cached = get_option( self::OPTION_KEY );
		if ( ! empty( $cached['blueprints'] ) ) {
			return $cached['blueprints'];
		}

		// Check if onboarding already fetched and cached the list.
		$onboarding = get_option( self::ONBOARDING_OPTION_KEY );
		if ( ! empty( $onboarding['blueprints'] ) ) {
			return $onboarding['blueprints'];
		}

		// Fetch from API and cache.
		$response = wp_remote_get(
			self::BLUEPRINTS_API_URL,
			array( 'timeout' => 15 )
		);

		if ( is_wp_error( $response ) ) {
			return array();
		}

		if ( 200 !== wp_remote_retrieve_response_code( $response ) ) {
			return array();
		}

		$body       = json_decode( wp_remote_retrieve_body( $response ), true );
		$blueprints = $body['data'] ?? array();

		if ( empty( $blueprints ) ) {
			return array();
		}

		$this->save_option( array( 'blueprints' => $blueprints ) );

		return $blueprints;
	}

	/**
	 * Get the slug of the selected blueprint, running the priority chain if needed.
	 *
	 * Priority:
	 * 1. Onboarding selected blueprint (user explicitly picked one)
	 * 2. Our cached selection
	 * 3. Pick by site type (onboarding site_info → WooCommerce → 'business' fallback)
	 *
	 * @return string Blueprint slug, or empty string on failure.
	 */
	public function get_selected_blueprint() {
		// Priority 1: onboarding explicitly selected one.
		$onboarding = get_option( self::ONBOARDING_OPTION_KEY );
		if ( ! empty( $onboarding['selectedBlueprint'] ) ) {
			return $onboarding['selectedBlueprint'];
		}

		// Priority 2: we already picked one in a previous request.
		$cached = get_option( self::OPTION_KEY );
		if ( ! empty( $cached['selectedBlueprint'] ) ) {
			return $cached['selectedBlueprint'];
		}

		// Priority 3: pick by site type.
		$blueprints = $this->get_blueprints();
		if ( empty( $blueprints ) ) {
			return '';
		}

		$site_type = $this->get_site_type();
		$slug      = $this->pick_blueprint_by_type( $blueprints, $site_type );

		if ( ! empty( $slug ) ) {
			$this->save_option( array_merge( $cached ?: array(), array( 'selectedBlueprint' => $slug ) ) );
		}

		return $slug;
	}

	/**
	 * Get the Gutenberg base layout markup from the selected blueprint.
	 *
	 * @return string Minified block markup, or empty string on failure.
	 */
	public function get_base_layout() {
		$slug = $this->get_selected_blueprint();
		if ( empty( $slug ) ) {
			return '';
		}

		// Check transient cache for parsed markup.
		$transient_key = 'nfd_aipd_blueprint_markup_' . sanitize_key( $slug );
		$cached_markup = get_transient( $transient_key );
		if ( false !== $cached_markup ) {
			return $cached_markup;
		}

		$markup = $this->fetch_and_parse_blueprint_markup( $slug );

		if ( ! empty( $markup ) ) {
			set_transient( $transient_key, $markup, WEEK_IN_SECONDS );
		}

		return $markup;
	}

	/**
	 * Determine the site type using the onboarding fallback chain.
	 *
	 * @return string One of: 'ecommerce', 'personal', 'business'.
	 */
	public function get_site_type() {
		// 1. Onboarding completed and stored site_type.
		$site_info = get_option( 'nfd_module_onboarding_site_info' );
		if ( ! empty( $site_info['site_type'] ) ) {
			return $site_info['site_type'];
		}

		// 2. WooCommerce is active.
		if ( class_exists( 'WooCommerce' ) ) {
			return 'ecommerce';
		}

		// 3. Default.
		return 'business';
	}

	/**
	 * Pick a blueprint slug from the list, filtered by type, with rotation.
	 *
	 * @param array  $blueprints Full blueprints list.
	 * @param string $type       Blueprint type to filter by.
	 * @return string Blueprint slug, or empty string.
	 */
	private function pick_blueprint_by_type( array $blueprints, $type ) {
		$filtered = array_values(
			array_filter(
				$blueprints,
				function ( $blueprint ) use ( $type ) {
					return ( $blueprint['type'] ?? '' ) === $type;
				}
			)
		);

		// Fallback to 'business' if no blueprints found for the type.
		if ( empty( $filtered ) && 'business' !== $type ) {
			return $this->pick_blueprint_by_type( $blueprints, 'business' );
		}

		if ( empty( $filtered ) ) {
			return '';
		}

		shuffle( $filtered );

		// Avoid repeating the last used blueprint for this type.
		$transient_key = 'nfd_aipd_last_blueprint_' . sanitize_key( $type );
		$last_slug     = get_transient( $transient_key );
		$selected      = $filtered[0];

		if ( $last_slug && count( $filtered ) > 1 ) {
			foreach ( $filtered as $blueprint ) {
				if ( ( $blueprint['slug'] ?? '' ) !== $last_slug ) {
					$selected = $blueprint;
					break;
				}
			}
		}

		$slug = $selected['slug'] ?? '';
		if ( $slug ) {
			set_transient( $transient_key, $slug, HOUR_IN_SECONDS );
		}

		return $slug;
	}

	/**
	 * Download the blueprint ZIP, parse the SQL, and extract page markup.
	 *
	 * @param string $slug Blueprint slug.
	 * @return string Minified block markup, or empty string on failure.
	 */
	private function fetch_and_parse_blueprint_markup( $slug ) {
		$resources_url = $this->get_resources_url( $slug );
		if ( empty( $resources_url ) ) {
			return '';
		}

		// download_url() and unzip_file() live in wp-admin/includes/file.php,
		// which is not loaded during REST API requests.
		if ( ! function_exists( 'download_url' ) ) {
			require_once \ABSPATH . 'wp-admin/includes/file.php';
		}

		// Download ZIP to a temp file.
		$tmp = \download_url( $resources_url );
		if ( \is_wp_error( $tmp ) ) {
			return '';
		}

		// unzip_file() requires WP_Filesystem to be initialized, which does not
		// happen automatically in REST API context.
		global $wp_filesystem;
		if ( empty( $wp_filesystem ) ) {
			\WP_Filesystem();
		}

		// Extract ZIP.
		$extract_dir  = sys_get_temp_dir() . '/nfd_aipd_blueprint_' . sanitize_key( $slug );
		$unzip_result = \unzip_file( $tmp, $extract_dir );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

		if ( \is_wp_error( $unzip_result ) ) {
			return '';
		}

		$sql_file = $extract_dir . '/blueprint.sql';
		if ( ! file_exists( $sql_file ) ) {
			return '';
		}

		$markup = $this->extract_page_markup_from_sql( $sql_file, 'home' );

		// Clean up temp directory.
		$this->delete_directory( $extract_dir );

		return $markup;
	}

	/**
	 * Get the resources_url for a blueprint slug from the cached list.
	 *
	 * @param string $slug Blueprint slug.
	 * @return string URL or empty string.
	 */
	private function get_resources_url( $slug ) {
		$blueprints = $this->get_blueprints();
		foreach ( $blueprints as $blueprint ) {
			if ( ( $blueprint['slug'] ?? '' ) === $slug ) {
				return $blueprint['resources_url'] ?? '';
			}
		}
		return '';
	}

	/**
	 * Parse blueprint SQL and extract the best available page markup.
	 *
	 * Tries preferred slugs in order, then falls back to the first published
	 * page with non-empty content.
	 *
	 * @param string $sql_file  Path to blueprint.sql.
	 * @param string $post_name Preferred post_name slug (e.g. 'home').
	 * @return string Minified block markup, or empty string.
	 */
	private function extract_page_markup_from_sql( $sql_file, $post_name ) {
		$sql = file_get_contents( $sql_file ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		if ( empty( $sql ) ) {
			return '';
		}

		// Find the INSERT VALUES block for the posts table.
		$values_start = strpos( $sql, "INSERT INTO `{{PREFIX}}posts`" );
		if ( false === $values_start ) {
			return '';
		}

		$values_start = strpos( $sql, "VALUES\n", $values_start );
		if ( false === $values_start ) {
			return '';
		}

		$values_start += strlen( "VALUES\n" );
		$values_end    = strpos( $sql, ";\n", $values_start );
		$values_block  = substr( $sql, $values_start, $values_end - $values_start );

		// Split into individual row strings.
		$rows = explode( "\n(", $values_block );

		// Preferred slug order: try the requested slug, then common homepage/landing slugs.
		$preferred_slugs = array_unique( array( $post_name, 'home', 'shop', 'about', 'landing' ) );

		// Build a map of post_name => content for all published pages with content.
		// Columns (0-indexed): 0=ID, 4=post_content, 5=post_title, 7=post_status, 11=post_name, 20=post_type
		$pages = array();

		foreach ( $rows as $row ) {
			$row = ltrim( $row, '(' );
			$row = rtrim( $row, '),' );

			$parts = $this->parse_sql_row( $row );

			if ( count( $parts ) < 21 ) {
				continue;
			}

			if ( 'page' !== $parts[20] || 'publish' !== $parts[7] ) {
				continue;
			}

			$content = trim( stripslashes( $parts[4] ) );
			if ( empty( $content ) ) {
				continue;
			}

			$pages[ $parts[11] ] = $content;
		}

		// Try preferred slugs in order.
		foreach ( $preferred_slugs as $slug ) {
			if ( ! empty( $pages[ $slug ] ) ) {
				return $this->minify_markup( $pages[ $slug ] );
			}
		}

		// Absolute fallback: first published page with content.
		if ( ! empty( $pages ) ) {
			return $this->minify_markup( reset( $pages ) );
		}

		return '';
	}

	/**
	 * Parse a single SQL INSERT row string into an array of values.
	 * Handles single-quoted strings with escaped quotes inside.
	 *
	 * @param string $row Raw row string without surrounding parentheses.
	 * @return array
	 */
	private function parse_sql_row( $row ) {
		$parts   = array();
		$current = '';
		$in_str  = false;
		$len     = strlen( $row );

		for ( $i = 0; $i < $len; $i++ ) {
			$char = $row[ $i ];

			if ( $char === "'" && ! $in_str ) {
				$in_str = true;
				continue;
			}

			if ( $char === "'" && $in_str ) {
				// Escaped quote inside string: ''
				if ( isset( $row[ $i + 1 ] ) && $row[ $i + 1 ] === "'" ) {
					$current .= "'";
					$i++;
					continue;
				}
				$in_str = false;
				continue;
			}

			if ( $char === ',' && ! $in_str ) {
				$parts[] = $current;
				$current = '';
				continue;
			}

			$current .= $char;
		}

		$parts[] = $current;

		return $parts;
	}

	/**
	 * Minify block markup to reduce AI token usage.
	 *
	 * @param string $markup Raw block markup.
	 * @return string Minified markup.
	 */
	private function minify_markup( $markup ) {
		$markup = preg_replace( '/>\s+</', '><', $markup );
		$markup = preg_replace( '/\s+/', ' ', $markup );
		return trim( $markup );
	}

	/**
	 * Merge data into our option and save.
	 *
	 * @param array $data Data to save.
	 */
	private function save_option( array $data ) {
		$existing = get_option( self::OPTION_KEY, array() );
		update_option( self::OPTION_KEY, array_merge( $existing, $data, array( 'last_updated' => time() ) ), false );
	}

	/**
	 * Recursively delete a directory.
	 *
	 * @param string $dir Directory path.
	 */
	private function delete_directory( $dir ) {
		if ( ! is_dir( $dir ) ) {
			return;
		}
		$files = array_diff( scandir( $dir ), array( '.', '..' ) );
		foreach ( $files as $file ) {
			$path = $dir . DIRECTORY_SEPARATOR . $file;
			is_dir( $path ) ? $this->delete_directory( $path ) : @unlink( $path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
		}
		@rmdir( $dir ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
	}
}
