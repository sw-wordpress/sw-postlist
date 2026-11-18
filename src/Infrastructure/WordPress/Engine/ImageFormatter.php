<?php
/**
 * `image:"size"`: the featured image as an `<img>` with WordPress' srcset and sizes attributes.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\TemplateEngine\Formatter\AbstractFormatter;
use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\FormatterSignature;
use SW\Postlist\TemplateEngine\ImageValue;
use SW\Postlist\TemplateEngine\ParamSpec;
use SW\Postlist\TemplateEngine\TrustedHtml;
use SW\Postlist\TemplateEngine\ValueType;

final class ImageFormatter extends AbstractFormatter {

	public function __construct() {
		parent::__construct(
			'image',
			new FormatterSignature(
				array( ValueType::Image ),
				ValueType::Html,
				array( new ParamSpec( 'size', ParamSpec::TYPE_STRING, false, null, null, self::sizes() ) ),
				'Renders an image with the given WordPress size (srcset and sizes included).'
			)
		);
	}

	/**
	 * @return list<string>
	 */
	public static function sizes(): array {
		return array_values( array_unique( array_merge( array( 'full' ), get_intermediate_image_sizes() ) ) );
	}

	public function apply( mixed $value, array $params, FormatContext $context ): mixed {
		if ( ! $value instanceof ImageValue ) {
			return null;
		}
		$size = isset( $params[0] ) && is_string( $params[0] ) ? $params[0] : 'large';
		if ( $value->attachment_id > 0 ) {
			$image = wp_get_attachment_image(
				$value->attachment_id,
				$size,
				false,
				array(
					'loading'  => 'lazy',
					'decoding' => 'async',
				)
			);
			if ( '' !== $image ) {
				return new TrustedHtml( $image );
			}
		}
		$url = $context->escaper->url( $value->url );
		return null === $url ? null : new TrustedHtml( '<img src="' . $context->escaper->attribute( $url ) . '" alt="' . $context->escaper->attribute( $value->alt ) . '" loading="lazy" decoding="async">' );
	}
}
