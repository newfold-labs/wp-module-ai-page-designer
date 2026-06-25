<?php
/**
 * Test-only polyfill for parse_blocks()/serialize_blocks().
 *
 * Loads WordPress's standalone block parser (pure PHP, no DB) by searching
 * upward for a wp-includes directory, then reimplements the thin serialize
 * wrappers faithfully. This lets the parse_blocks-based harness rules
 * (ColorLegibility, UnwrapLoneGroup) be exercised in unit tests without a full
 * WordPress bootstrap. If no WordPress install is found, the functions are not
 * defined and those tests skip.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner
 */

if ( function_exists( 'parse_blocks' ) ) {
	return;
}

$nfd_wp_includes = null;
$dir             = __DIR__;
for ( $i = 0; $i < 10; $i++ ) {
	$candidate = $dir . '/wp-includes/class-wp-block-parser.php';
	if ( is_file( $candidate ) ) {
		$nfd_wp_includes = $dir . '/wp-includes';
		break;
	}
	$parent = dirname( $dir );
	if ( $parent === $dir ) {
		break;
	}
	$dir = $parent;
}

if ( null === $nfd_wp_includes ) {
	return;
}

require_once $nfd_wp_includes . '/class-wp-block-parser-block.php';
require_once $nfd_wp_includes . '/class-wp-block-parser-frame.php';
require_once $nfd_wp_includes . '/class-wp-block-parser.php';

if ( ! function_exists( 'wp_json_encode' ) ) {
	function wp_json_encode( $data, $options = 0, $depth = 512 ) {
		return json_encode( $data, $options, $depth ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode
	}
}

if ( ! function_exists( 'parse_blocks' ) ) {
	function parse_blocks( $content ) {
		$parser = new WP_Block_Parser();
		return $parser->parse( $content );
	}
}

if ( ! function_exists( 'serialize_block_attributes' ) ) {
	function serialize_block_attributes( $block_attributes ) {
		$encoded = wp_json_encode( $block_attributes, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE );
		$encoded = preg_replace( '/--/', '\\u002d\\u002d', $encoded );
		$encoded = preg_replace( '/</', '\\u003c', $encoded );
		$encoded = preg_replace( '/>/', '\\u003e', $encoded );
		$encoded = preg_replace( '/&/', '\\u0026', $encoded );
		$encoded = preg_replace( '/\\\\"/', '\\u0022', $encoded );
		return $encoded;
	}
}

if ( ! function_exists( 'get_comment_delimited_block_content' ) ) {
	function get_comment_delimited_block_content( $block_name, $block_attributes, $block_content ) {
		if ( is_null( $block_name ) ) {
			return $block_content;
		}
		$serialized_block_name = 0 === strpos( (string) $block_name, 'core/' ) ? substr( $block_name, 5 ) : $block_name;
		$serialized_attributes = empty( $block_attributes ) ? '' : serialize_block_attributes( $block_attributes ) . ' ';
		if ( empty( $block_content ) ) {
			return sprintf( '<!-- wp:%s %s/-->', $serialized_block_name, $serialized_attributes );
		}
		return sprintf( '<!-- wp:%s %s-->%s<!-- /wp:%s -->', $serialized_block_name, $serialized_attributes, $block_content, $serialized_block_name );
	}
}

if ( ! function_exists( 'serialize_block' ) ) {
	function serialize_block( $block ) {
		$block_content = '';
		$index         = 0;
		foreach ( $block['innerContent'] as $chunk ) {
			$block_content .= is_string( $chunk ) ? $chunk : serialize_block( $block['innerBlocks'][ $index++ ] );
		}
		if ( ! is_array( $block['attrs'] ) ) {
			$block['attrs'] = array();
		}
		return get_comment_delimited_block_content( $block['blockName'], $block['attrs'], $block_content );
	}
}

if ( ! function_exists( 'serialize_blocks' ) ) {
	function serialize_blocks( $blocks ) {
		return implode( '', array_map( 'serialize_block', $blocks ) );
	}
}
