<?php
/**
 * Value resolver backed by an array.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support\Engine;

use SW\Postlist\TemplateEngine\ImageValue;
use SW\Postlist\TemplateEngine\ListItem;
use SW\Postlist\TemplateEngine\Port\ValueResolverInterface;
use SW\Postlist\TemplateEngine\TrustedHtml;

final class ArrayResolver implements ValueResolverInterface {

	/**
	 * @param array<string, mixed> $values Values by placeholder name.
	 */
	public function __construct( private readonly array $values ) {
	}

	/**
	 * Builds typed values from the JSON of a contract fixture: `{"html": "…"}` is trusted HTML, `{"url": "…"}` an
	 * image, a list of `{"label", "url"}` objects a list.
	 *
	 * @param array<string, mixed> $data Fixture data.
	 */
	public static function from_fixture( array $data ): self {
		$values = array();
		foreach ( $data as $name => $value ) {
			if ( is_array( $value ) && isset( $value['html'] ) && is_string( $value['html'] ) ) {
				$values[ $name ] = new TrustedHtml( $value['html'] );
			} elseif ( is_array( $value ) && isset( $value['url'] ) && ! isset( $value['label'] ) ) {
				$values[ $name ] = new ImageValue( (string) $value['url'], (string) ( $value['alt'] ?? '' ) );
			} elseif ( is_array( $value ) && array_is_list( $value ) ) {
				$values[ $name ] = array_map( static fn ( array $item ): ListItem => new ListItem( (string) $item['label'], isset( $item['url'] ) ? (string) $item['url'] : null ), $value );
			} else {
				$values[ $name ] = $value;
			}
		}
		return new self( $values );
	}

	public function resolve( string $name ): mixed {
		return $this->values[ $name ] ?? null;
	}
}
