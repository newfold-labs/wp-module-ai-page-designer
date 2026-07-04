<?php
/**
 * AI client for AI Page Designer - Worker Integration Version.
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

use NewfoldLabs\WP\Module\Data\HiiveConnection;
use Web\AIPageDesignerDebug;

/**
 * AI client that delegates to Cloudflare Worker.
 *
 * Replaces the complex AiClient.php with a simple proxy to the Worker.
 * Follows the same pattern as SiteGen.php in wp-module-ai.
 */
class AiClientWorker {

	/**
	 * Request generated content from the Worker.
	 *
	 * @param array $ai_messages Message payload for the AI service.
	 * @param array $options Optional settings (current_markup, selected_block_markup, etc.).
	 * @return array|\WP_Error
	 */
	public function generate_content( array $ai_messages, array $options = array() ) {
		$hiive_token = HiiveConnection::get_auth_token();

		if ( ! $hiive_token ) {
			AIPageDesignerDebug::debug_log( 'Hiive authentication failed' );
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to make this call.', 'wp-module-ai-page-designer' ),
				array( 'status' => 403 )
			);
		}

		if ( ! defined( 'NFD_AI_BASE' ) ) {
			return new \WP_Error(
				'configuration_error',
				__( 'AI service is not configured. Please contact support.', 'wp-module-ai-page-designer' ),
				array( 'status' => 503 )
			);
		}

		$worker_url = NFD_AI_BASE . 'ai-page-designer/generate';

		$request_body = array(
			'hiivetoken'     => $hiive_token,
			'messages'       => $ai_messages,
			'context'        => $this->build_context_from_options( $options ),
			'current_markup' => $options['current_markup'] ?? '',
			'content_type'   => $options['content_type'] ?? 'page',
			'theme_context'  => $this->get_theme_context(),
			'base_layout'    => $options['base_layout'] ?? '',
		);

		AIPageDesignerDebug::debug_log(
			'Calling Worker generate endpoint',
			array(
				'url'                => $worker_url,
				'messages_count'     => count( $ai_messages ),
				'has_current_markup' => ! empty( $options['current_markup'] ),
			)
		);

		$response = wp_remote_post(
			$worker_url,
			array(
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-Newfold-Brand' => $this->get_brand(),
				),
				'timeout' => 120,
				'body'    => wp_json_encode( $request_body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			AIPageDesignerDebug::debug_log(
				'Worker request failed',
				array(
					'error' => $response->get_error_message(),
				)
			);

			return new \WP_Error(
				'ai_service_timeout',
				$this->get_ai_transport_error_message(),
				array( 'status' => 408 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			AIPageDesignerDebug::debug_log(
				'Worker returned error',
				array(
					'status_code' => $response_code,
					'response'    => $raw_body,
				)
			);

			$error_body    = json_decode( $raw_body, true );
			$error_message = $error_body['error'] ?? 'We are unable to process the request at this moment';

			return new \WP_Error(
				'ai_generation_error',
				$error_message,
				array( 'status' => $response_code )
			);
		}

		$result = json_decode( $raw_body, true );

		if ( ! $result['success'] ) {
			return new \WP_Error(
				'ai_generation_error',
				$result['error'] ?? 'Unknown error from Worker',
				array( 'status' => 500 )
			);
		}

		AIPageDesignerDebug::debug_log(
			'Worker request successful',
			array(
				'has_content' => ! empty( $result['data']['content'] ),
				'has_title'   => ! empty( $result['data']['title'] ),
				'response_id' => $result['data']['response_id'] ?? null,
			)
		);

		return $result['data'];
	}

	/**
	 * Stream generated content from the Worker.
	 *
	 * @param array    $ai_messages Message payload for the AI service.
	 * @param array    $options Optional settings.
	 * @param callable $on_event Callback for stream events.
	 * @return string|\WP_Error Response ID or WP_Error on failure.
	 */
	public function stream_content( array $ai_messages, array $options, callable $on_event ) {
		$hiive_token = HiiveConnection::get_auth_token();

		if ( ! $hiive_token ) {
			AIPageDesignerDebug::debug_log( 'Hiive authentication failed for streaming' );
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to make this call.', 'wp-module-ai-page-designer' ),
				array( 'status' => 403 )
			);
		}

		if ( ! defined( 'NFD_AI_BASE' ) ) {
			return new \WP_Error(
				'configuration_error',
				__( 'AI service is not configured. Please contact support.', 'wp-module-ai-page-designer' ),
				array( 'status' => 503 )
			);
		}

		$worker_url = NFD_AI_BASE . 'ai-page-designer/stream';

		$request_body = array(
			'hiivetoken'     => $hiive_token,
			'messages'       => $ai_messages,
			'context'        => $this->build_context_from_options( $options ),
			'current_markup' => $options['current_markup'] ?? '',
			'content_type'   => $options['content_type'] ?? 'page',
			'theme_context'  => $this->get_theme_context(),
			'base_layout'    => $options['base_layout'] ?? '',
		);

		AIPageDesignerDebug::debug_log(
			'Calling Worker stream endpoint',
			array(
				'url'            => $worker_url,
				'messages_count' => count( $ai_messages ),
			)
		);

		// Use cURL for proper streaming support
		return $this->stream_with_curl( $worker_url, $request_body, $on_event );
	}

	/**
	 * Stream content using cURL for proper Server-Sent Events support.
	 *
	 * @param string   $url The streaming endpoint URL.
	 * @param array    $request_body The request payload.
	 * @param callable $on_event Callback for stream events.
	 * @return string|\WP_Error Response ID or WP_Error on failure.
	 */
	private function stream_with_curl( $url, $request_body, callable $on_event ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_init
		$curl = curl_init();

		// Buffer for accumulating partial SSE events
		$buffer      = '';
		$response_id = null;
		$http_code   = 0;

		// Callback function to process streaming data
		$write_function = function ( $ch, $data ) use ( &$buffer, &$response_id, $on_event ) {
			$buffer .= $data;

			// Process complete SSE events
			$pos = strpos( $buffer, "\n\n" );
			while ( false !== $pos ) {
				$event_block = substr( $buffer, 0, $pos );
				$buffer      = substr( $buffer, $pos + 2 );

				$response_id_from_event = $this->process_sse_event( $event_block, $on_event );
				if ( $response_id_from_event ) {
					$response_id = $response_id_from_event;
				}

				$pos = strpos( $buffer, "\n\n" );
			}

			return strlen( $data );
		};

		// Callback to capture response headers for debugging
		$header_function = function ( $ch, $header ) use ( &$http_code ) {
			// Extract HTTP status code from first header
			if ( preg_match( '/^HTTP\/\d\.\d\s+(\d+)/', $header, $matches ) ) {
				$http_code = (int) $matches[1];
			}
			return strlen( $header );
		};

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_setopt_array
		curl_setopt_array(
			$curl,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( $request_body ),
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'X-Newfold-Brand: ' . $this->get_brand(),
					'Accept: text/event-stream',
				),
				CURLOPT_WRITEFUNCTION  => $write_function,
				CURLOPT_HEADERFUNCTION => $header_function,
				CURLOPT_TIMEOUT        => 300, // 5 minutes total timeout
				CURLOPT_CONNECTTIMEOUT => 10,  // 10 seconds to establish connection
				CURLOPT_FOLLOWLOCATION => true,
				CURLOPT_MAXREDIRS      => 3,
				CURLOPT_SSL_VERIFYPEER => true,
				CURLOPT_SSL_VERIFYHOST => 2,
				CURLOPT_USERAGENT      => 'WordPress/' . get_bloginfo( 'version' ) . '; ' . home_url(),
				// SSE must stay uncompressed: gzip/br buffer many events into a
				// single compression block, so the preview updates in large
				// batches instead of streaming token-by-token. Request identity.
				CURLOPT_ENCODING       => 'identity',
			)
		);

		AIPageDesignerDebug::debug_log( 'Starting cURL streaming request', array( 'url' => $url ) );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_exec
		$curl_result = curl_exec( $curl );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_error
		$curl_error = curl_error( $curl );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_getinfo
		$final_http_code = curl_getinfo( $curl, CURLINFO_HTTP_CODE );

		// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_close
		curl_close( $curl );

		// Use the HTTP code from the parsed status line when present, otherwise
		// fall back to curl_getinfo. Note: $http_code starts at 0, so guard on
		// the truthy value rather than null (?? would never see 0 as missing).
		$response_code = $http_code ? $http_code : $final_http_code;

		if ( false === $curl_result ) {
			AIPageDesignerDebug::debug_log(
				'cURL streaming failed',
				array(
					'error'     => $curl_error,
					'http_code' => $response_code,
				)
			);

			return new \WP_Error(
				'ai_stream_error',
				$this->get_ai_transport_error_message(),
				array( 'status' => 500 )
			);
		}

		if ( $response_code >= 400 ) {
			AIPageDesignerDebug::debug_log(
				'Streaming returned error status',
				array(
					'status_code' => $response_code,
					// On an error the Worker returns a JSON body (not SSE), so it has no "\n\n"
					// terminator and stays unprocessed in the buffer — surface it for debugging.
					'raw_response' => substr( (string) $buffer, 0, 2000 ),
				)
			);

			return new \WP_Error(
				'ai_stream_error',
				$this->get_ai_transport_error_message(),
				array( 'status' => $response_code )
			);
		}

		AIPageDesignerDebug::debug_log(
			'cURL streaming completed',
			array(
				'http_code'   => $response_code,
				'response_id' => $response_id,
			)
		);

		return $response_id;
	}

	/**
	 * Process a single Server-Sent Event block.
	 *
	 * @param string   $event_block A complete SSE event block.
	 * @param callable $on_event Callback for stream events.
	 * @return string|null Response ID if found in this event, null otherwise.
	 */
	private function process_sse_event( $event_block, callable $on_event ) {
		$lines      = preg_split( "/\r?\n/", trim( $event_block ) );
		$event      = 'message';
		$data_lines = array();

		foreach ( $lines as $line ) {
			if ( 0 === strpos( $line, 'event:' ) ) {
				$event = trim( substr( $line, 6 ) );
				continue;
			}
			if ( 0 === strpos( $line, 'data:' ) ) {
				$data_lines[] = trim( substr( $line, 5 ) );
			}
		}

		if ( empty( $data_lines ) ) {
			return null;
		}

		$data = implode( "\n", $data_lines );
		if ( '[DONE]' === $data ) {
			$on_event( array( 'type' => 'done' ) );
			return null;
		}

		$payload = json_decode( $data, true );
		if ( is_array( $payload ) ) {
			// Forward the event from the Worker
			$on_event( $payload );

			// Extract and return response_id if present
			if ( isset( $payload['response_id'] ) ) {
				return $payload['response_id'];
			}
		}

		return null;
	}

	/**
	 * Send a lightweight analysis request to the Worker (no system-prompt injection).
	 *
	 * The caller supplies the full messages array including its own system prompt.
	 * Unlike generate_content(), the Worker forwards messages as-is to the AI.
	 *
	 * @param array $ai_messages Message payload including caller's system prompt.
	 * @return array|\WP_Error
	 */
	public function analyze( array $ai_messages ) {
		$hiive_token = HiiveConnection::get_auth_token();

		if ( ! $hiive_token ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You are not authorized to make this call.', 'wp-module-ai-page-designer' ),
				array( 'status' => 403 )
			);
		}

		if ( ! defined( 'NFD_AI_BASE' ) ) {
			return new \WP_Error(
				'configuration_error',
				__( 'AI service is not configured. Please contact support.', 'wp-module-ai-page-designer' ),
				array( 'status' => 503 )
			);
		}

		$worker_url = NFD_AI_BASE . 'ai-page-designer/analyze';

		$response = wp_remote_post(
			$worker_url,
			array(
				'headers' => array(
					'Content-Type'    => 'application/json',
					'X-Newfold-Brand' => $this->get_brand(),
				),
				'timeout' => 30,
				'body'    => wp_json_encode(
					array(
						'hiivetoken' => $hiive_token,
						'messages'   => $ai_messages,
					)
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new \WP_Error(
				'ai_service_timeout',
				$response->get_error_message(),
				array( 'status' => 408 )
			);
		}

		$response_code = wp_remote_retrieve_response_code( $response );
		$raw_body      = wp_remote_retrieve_body( $response );

		if ( 200 !== $response_code ) {
			$error_body    = json_decode( $raw_body, true );
			$error_message = $error_body['error'] ? $error_body['error'] : 'Analysis request failed';
			return new \WP_Error(
				'ai_analysis_error',
				$error_message,
				array( 'status' => $response_code )
			);
		}

		$result = json_decode( $raw_body, true );

		if ( empty( $result['success'] ) ) {
			return new \WP_Error(
				'ai_analysis_error',
				$result['error'] ? $result['error'] : 'Unknown error from Worker',
				array( 'status' => 500 )
			);
		}

		return $result['data'];
	}

	/**
	 * User-facing message for AI transport failures (details remain in debug logs).
	 *
	 * @return string
	 */
	private function get_ai_transport_error_message() {
		return __(
			'Unable to reach the AI service. Check your network connection and try again.',
			'wp-module-ai-page-designer'
		);
	}

	/**
	 * Get the current brand identifier.
	 *
	 * @return string
	 */
	private function get_brand() {
		$brand = get_option( 'mm_brand', 'web' );
		return apply_filters( 'newfold_ai_sitegen_brand', $brand );
	}

	/**
	 * Build context array from options for Worker request.
	 *
	 * @param array $options Optional settings (selected_block_markup, single_block_edit).
	 * @return array Context array for the Worker request.
	 */
	private function build_context_from_options( $options ) {
		$context = array();

		if ( ! empty( $options['selected_block_markup'] ) ) {
			$context['selected_block_markup'] = $options['selected_block_markup'];
		}

		if ( ! empty( $options['single_block_edit'] ) ) {
			$context['single_block_edit'] = true;
		}

		return $context;
	}

	/**
	 * Get theme context for Worker request.
	 *
	 * @return array
	 */
	private function get_theme_context() {
		if ( ! function_exists( 'wp_get_global_settings' ) ) {
			return array();
		}

		$settings = wp_get_global_settings();
		$context  = array();

		// Color palette — only pass solid, usable colors. Functional tokens
		// (color-mix(), var(), gradients, transparent, alpha'd values) cannot be
		// used as a solid background or text color; when handed to the AI they
		// get applied as section backgrounds / text and render invisible. For
		// example, Twenty Twenty-Five's accent-6 is
		// `color-mix(in srgb, currentColor 20%, transparent)`.
		$theme_swatches = $settings['color']['palette']['theme'] ?? array();
		$theme_swatches = array_values(
			array_filter(
				$theme_swatches,
				function ( $swatch ) {
					return $this->is_solid_color( $swatch['color'] ?? '' );
				}
			)
		);
		if ( ! empty( $theme_swatches ) ) {
			$context['colorPalette'] = array_map(
				function ( $swatch ) {
					$slug = $swatch['slug'] ?? '';
					// Normalize underscores to hyphens to match WordPress's actual CSS variable output.
					$slug = str_replace( '_', '-', $slug );
					return array(
						'slug'  => $slug,
						'name'  => $swatch['name'] ?? $swatch['slug'] ?? '',
						'color' => $swatch['color'] ?? '',
					);
				},
				$theme_swatches,
			);
		}

		// Font families
		$font_families = $settings['typography']['fontFamilies']['theme'] ?? array();
		if ( ! empty( $font_families ) ) {
			$context['fontFamilies'] = array_map(
				function ( $font ) {
					return array(
						'slug'       => $font['slug'] ?? '',
						'fontFamily' => $font['fontFamily'] ?? '',
					);
				},
				$font_families,
			);
		}

		// Site name
		$site_name = get_bloginfo( 'name' );
		if ( $site_name ) {
			$context['siteName'] = $site_name;
		}

		// Site tagline — a short business/niche signal (e.g. "Coffee that wakes you up")
		// that costs nothing extra to attach and helps the model write copy specific to
		// the actual site rather than generic placeholder text.
		$site_tagline = get_bloginfo( 'description' );
		if ( $site_tagline ) {
			$context['siteTagline'] = $site_tagline;
		}

		return $context;
	}

	/**
	 * Whether a CSS color value is a solid, opaque color usable as a background
	 * or text color. Rejects functional tokens (color-mix(), var(), gradients),
	 * transparent, and alpha'd values that would render invisible.
	 *
	 * @param string $color CSS color value from the theme palette.
	 * @return bool
	 */
	private function is_solid_color( $color ) {
		$color = strtolower( trim( (string) $color ) );

		if ( '' === $color ) {
			return false;
		}

		// Reject functional / non-solid expressions outright.
		if ( false !== strpos( $color, 'color-mix' )
			|| false !== strpos( $color, 'var(' )
			|| false !== strpos( $color, 'gradient' )
			|| false !== strpos( $color, 'currentcolor' )
			|| 'transparent' === $color ) {
			return false;
		}

		// Solid 3 or 6 digit hex.
		if ( preg_match( '/^#([0-9a-f]{3}|[0-9a-f]{6})$/', $color ) ) {
			return true;
		}

		// 4 or 8 digit hex carries alpha — keep only if effectively opaque.
		if ( preg_match( '/^#([0-9a-f]{4}|[0-9a-f]{8})$/', $color ) ) {
			$hex   = ltrim( $color, '#' );
			$alpha = 4 === strlen( $hex )
				? hexdec( str_repeat( substr( $hex, 3, 1 ), 2 ) )
				: hexdec( substr( $hex, 6, 2 ) );
			return $alpha >= 0xF0;
		}

		// rgb()/hsl() are solid; rgba()/hsla() only if alpha is near opaque.
		if ( preg_match( '/^(rgb|hsl)\(/', $color ) ) {
			return true;
		}
		if ( preg_match( '/^(rgba|hsla)\(.*,\s*([0-9.]+)\s*\)$/', $color, $matches ) ) {
			return (float) $matches[2] >= 0.9;
		}

		// Named CSS colors (e.g. "navy", "tomato"); "transparent" handled above.
		if ( preg_match( '/^[a-z]+$/', $color ) ) {
			return true;
		}

		return false;
	}
}
