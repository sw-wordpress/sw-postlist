<?php
/**
 * Site time zone and language for the date and number formatters.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\TemplateEngine\Port\LocaleInterface;

final class WpLocale implements LocaleInterface {

	public function format_date( \DateTimeImmutable $date, ?string $format ): string {
		$pattern = null === $format || '' === $format ? (string) get_option( 'date_format', 'Y-m-d' ) : $format;
		return wp_date( $pattern, $date->getTimestamp(), wp_timezone() ) ?: '';
	}

	public function relative_date( \DateTimeImmutable $date, \DateTimeImmutable $now ): string {
		$distance = human_time_diff( $date->getTimestamp(), $now->getTimestamp() );
		/* translators: %s: distance such as "3 days" */
		$template = $date->getTimestamp() > $now->getTimestamp() ? __( 'in %s', 'sw-postlist' ) : __( '%s ago', 'sw-postlist' );
		return sprintf( $template, $distance );
	}

	public function format_number( int|float $number, int $decimals ): string {
		return number_format_i18n( (float) $number, $decimals );
	}

	public function format_currency( int|float $amount, string $currency ): string {
		$formatted = number_format_i18n( (float) $amount, 2 );
		if ( class_exists( \NumberFormatter::class ) ) {
			$formatter = new \NumberFormatter( determine_locale(), \NumberFormatter::CURRENCY );
			$result    = $formatter->formatCurrency( (float) $amount, $currency );
			if ( is_string( $result ) && '' !== $result ) {
				return $result;
			}
		}
		return $formatted . ' ' . $currency;
	}
}
