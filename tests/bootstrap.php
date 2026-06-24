<?php
/**
 * PHPUnit bootstrap.
 *
 * Registers a minimal PSR-4 autoloader for the module namespace so the harness
 * unit tests run without a full WordPress bootstrap. Rules that depend on
 * WordPress (parse_blocks) degrade to no-ops when WP is absent, so their
 * behaviour is exercised under an integration bootstrap when available.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

spl_autoload_register(
	static function ( $class ) {
		// Test classes live under tests/ — map this sub-namespace first.
		$test_prefix = 'NewfoldLabs\\WP\\Module\\AIPageDesigner\\Tests\\';
		if ( 0 === strpos( $class, $test_prefix ) ) {
			$relative = substr( $class, strlen( $test_prefix ) );
			$file     = __DIR__ . '/' . str_replace( '\\', '/', $relative ) . '.php';
			if ( is_file( $file ) ) {
				require $file;
			}
			return;
		}

		// Module classes live under includes/.
		$prefix = 'NewfoldLabs\\WP\\Module\\AIPageDesigner\\';
		if ( 0 !== strpos( $class, $prefix ) ) {
			return;
		}
		$relative = substr( $class, strlen( $prefix ) );
		$file     = dirname( __DIR__ ) . '/includes/' . str_replace( '\\', '/', $relative ) . '.php';
		if ( is_file( $file ) ) {
			require $file;
		}
	}
);
