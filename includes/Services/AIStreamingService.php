<?php
/**
 * AI Streaming Service
 * 
 * Handles Server-Sent Events (SSE) streaming for AI API calls
 *
 * @package NewfoldLabs\WP\Module\AIPageDesigner\Services
 */

namespace NewfoldLabs\WP\Module\AIPageDesigner\Services;

class AIStreamingService {

	/**
	 * Initialize SSE headers and disable buffering
	 */
	public static function init_streaming() {
		// Disable PHP output buffering
		if ( ob_get_level() ) {
			ob_end_clean();
		}

		// Set SSE headers
		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'X-Accel-Buffering: no' );
		header( 'Connection: keep-alive' );
		
		// Flush any output buffers
		flush();
	}

	/**
	 * Send a chunk of data via SSE
	 *
	 * @param mixed  $data    The data to send
	 * @param string $event   Optional event type
	 * @param string $id      Optional event ID
	 */
	public static function send_chunk( $data, $event = 'message', $id = null ) {
		$sse_data = '';
		
		if ( $id ) {
			$sse_data .= "id: {$id}\n";
		}
		
		$sse_data .= "event: {$event}\n";
		$sse_data .= 'data: ' . wp_json_encode( $data ) . "\n\n";
		
		echo $sse_data;
		
		// Force flush to send immediately
		if ( ob_get_level() ) {
			ob_flush();
		}
		flush();
		
		usleep( 10000 );
	}

	/**
	 * Send progress update
	 *
	 * @param string $message Progress message
	 * @param int    $percent Progress percentage (0-100)
	 */
	public static function send_progress( $message, $percent = 0 ) {
		self::send_chunk(
			array(
				'type'    => 'progress',
				'message' => $message,
				'percent' => $percent,
			),
			'progress'
		);
	}

	/**
	 * Send completion event
	 *
	 * @param array $data Final data
	 */
	public static function send_complete( $data ) {
		self::send_chunk(
			array(
				'type' => 'complete',
				'data' => $data,
			),
			'complete'
		);
	}

	/**
	 * Send error event
	 *
	 * @param string $message Error message
	 * @param mixed  $code    Error code
	 */
	public static function send_error( $message, $code = null ) {
		self::send_chunk(
			array(
				'type'    => 'error',
				'message' => $message,
				'code'    => $code,
			),
			'error'
		);
	}

	/**
	 * Make streaming request to AI service using cURL
	 *
	 * @param string   $url      API URL
	 * @param array    $args     Request arguments
	 * @param callable $on_chunk Callback for each chunk received
	 * @return bool|\WP_Error True on success, WP_Error on failure
	 */
	public static function streaming_request( $url, $args, $on_chunk = null ) {
		$ch = curl_init();

		curl_setopt( $ch, CURLOPT_URL, $url );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_FOLLOWLOCATION, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 120 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 10 );
		
		$headers = array();
		if ( isset( $args['headers'] ) ) {
			foreach ( $args['headers'] as $key => $value ) {
				$headers[] = "{$key}: {$value}";
			}
		}
		curl_setopt( $ch, CURLOPT_HTTPHEADER, $headers );
		
		if ( isset( $args['body'] ) ) {
			curl_setopt( $ch, CURLOPT_POST, true );
			curl_setopt( $ch, CURLOPT_POSTFIELDS, $args['body'] );
		}
		
		curl_setopt( $ch, CURLOPT_WRITEFUNCTION,
			function( $curl, $data ) use ( $on_chunk ) {
				if ( $on_chunk ) {
					call_user_func( $on_chunk, $data );
				}
				return strlen( $data );
			}
		);
		
		$result = curl_exec( $ch );
		$http_code = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$error = curl_error( $ch );
		$errno = curl_errno( $ch );
		
		curl_close( $ch );
		
		if ( $errno || 200 !== $http_code ) {
			return new \WP_Error(
				'streaming_request_failed',
				$error ?: "HTTP {$http_code}",
				array( 'status' => $http_code )
			);
		}
		
		return true;
	}

	/**
	 * Close the SSE connection
	 */
	public static function close_stream() {
		self::send_chunk( array( 'type' => 'close' ), 'close' );
		
		if ( ob_get_level() ) {
			ob_end_flush();
		}
		flush();
	}
}
