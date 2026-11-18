<?php
/**
 * Diagnostic log: keeps the latest findings in a bounded option for the diagnostics page and mirrors them to the
 * `sw_postlist_log` action for external loggers.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress;

use SW\Postlist\Application\Port\LoggerInterface;

final class WpLogger implements LoggerInterface {

	public const OPTION = 'sw_postlist_diagnostics';
	public const LIMIT  = 50;

	public function warning( string $code, string $message, array $context = array() ): void {
		$entries   = self::entries();
		$entries[] = array(
			'time'    => gmdate( 'c' ),
			'code'    => $code,
			'message' => $message,
			'context' => $context,
		);
		update_option( self::OPTION, array_slice( $entries, -self::LIMIT ), false );
		do_action( 'sw_postlist_log', 'warning', $code, $message, $context );
	}

	/**
	 * @return list<array{time: string, code: string, message: string, context: array<string, mixed>}>
	 */
	public static function entries(): array {
		$stored = get_option( self::OPTION, array() );
		if ( ! is_array( $stored ) ) {
			return array();
		}
		$entries = array();
		foreach ( $stored as $entry ) {
			if ( is_array( $entry ) && is_string( $entry['time'] ?? null ) && is_string( $entry['code'] ?? null ) && is_string( $entry['message'] ?? null ) ) {
				$entries[] = array(
					'time'    => $entry['time'],
					'code'    => $entry['code'],
					'message' => $entry['message'],
					'context' => is_array( $entry['context'] ?? null ) ? $entry['context'] : array(),
				);
			}
		}
		return $entries;
	}
}
