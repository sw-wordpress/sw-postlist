<?php
/**
 * Locale and time zone aware formatting supplied by the host (site time zone and language).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine\Port;

interface LocaleInterface {

	/** Formats a date with a PHP date format in the site time zone; null format means the site default. */
	public function format_date( \DateTimeImmutable $date, ?string $format ): string;

	/** "3 days ago" style text for the distance between $date and $now. */
	public function relative_date( \DateTimeImmutable $date, \DateTimeImmutable $now ): string;

	public function format_number( int|float $number, int $decimals ): string;

	public function format_currency( int|float $amount, string $currency ): string;
}
