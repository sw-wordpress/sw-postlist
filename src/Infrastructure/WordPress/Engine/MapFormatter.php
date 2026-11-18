<?php
/**
 * `map:"name"`: applies a named map of the settings to a list (hide values, replace texts, strip a prefix) and returns
 * the list again, so `join`, `link` and `chips` can follow: `{{entry.terms.category|map:"categories"|chips}}`.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\Application\Map\ValueMaps;
use SW\Postlist\TemplateEngine\Formatter\AbstractFormatter;
use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\FormatterSignature;
use SW\Postlist\TemplateEngine\ListItem;
use SW\Postlist\TemplateEngine\ParamSpec;
use SW\Postlist\TemplateEngine\ValueType;

final class MapFormatter extends AbstractFormatter {

	public function __construct( private readonly ValueMaps $maps ) {
		parent::__construct(
			'map',
			new FormatterSignature(
				array( ValueType::List ),
				ValueType::List,
				array( new ParamSpec( 'map', ParamSpec::TYPE_STRING, true, null, null, $maps->names() ) ),
				'Applies a named map from the settings to a list: hides values, replaces texts, removes a prefix.'
			)
		);
	}

	public function apply( mixed $value, array $params, FormatContext $context ): mixed {
		$name = isset( $params[0] ) && is_string( $params[0] ) ? $params[0] : '';
		$out  = array();
		foreach ( is_array( $value ) ? $value : array() as $item ) {
			if ( ! $item instanceof ListItem ) {
				continue;
			}
			$label = $this->maps->apply( $name, $item->label, $item->key );
			if ( null !== $label ) {
				$out[] = new ListItem( $label, $item->url, $item->key );
			}
		}
		return $out;
	}
}
