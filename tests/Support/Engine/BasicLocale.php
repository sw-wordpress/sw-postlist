<?php
/**
 * Locale double: English texts, UTC, deterministic output.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support\Engine;

use SW\Postlist\TemplateEngine\Port\LocaleInterface;

final class BasicLocale implements LocaleInterface {

	public function format_date( \DateTimeImmutable $date, ?string $format ): string {
		return $date->format( $format ?? 'Y-m-d' );
	}

	public function relative_date( \DateTimeImmutable $date, \DateTimeImmutable $now ): string {
		$seconds = $now->getTimestamp() - $date->getTimestamp();
		$days    = intdiv( abs( $seconds ), 86400 );
		$text    = 1 === $days ? '1 day' : $days . ' days';
		return $seconds >= 0 ? $text . ' ago' : 'in ' . $text;
	}

	public function format_number( int|float $number, int $decimals ): string {
		return number_format( (float) $number, $decimals, '.', ',' );
	}

	public function format_currency( int|float $amount, string $currency ): string {
		return $currency . ' ' . number_format( (float) $amount, 2, '.', ',' );
	}
}
