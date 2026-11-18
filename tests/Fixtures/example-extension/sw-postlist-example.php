<?php
/**
 * Plugin Name: SW Postlist Example Extension
 * Description: Reference extension of the documented contracts: an entry source, a formatter, a layout and a filter type. Used by tests/wp/tests/ExtensionContractTest.php; see docs/hooks.md.
 *
 * @package sw-postlist-example
 */

use SW\Postlist\Application\Filter\FilterTypeDefinition;
use SW\Postlist\Application\Layout\LayoutDefinition;
use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\EntryResult;
use SW\Postlist\Domain\Source\QueryArgs;
use SW\Postlist\TemplateEngine\Formatter\CallbackFormatter;
use SW\Postlist\TemplateEngine\FormatterSignature;
use SW\Postlist\TemplateEngine\ValueType;

/**
 * A source of events from a fixed list (a real one would read its own table or an API). Only public events are
 * returned and can_view() agrees: that is the contract.
 */
if ( ! class_exists( 'Example_Events_Source' ) ) {
	final class Example_Events_Source implements EntrySourceInterface {

		private const EVENTS = array(
			1 => array( 'Spring fair', 'north', true ),
			2 => array( 'Summer concert', 'south', true ),
			3 => array( 'Autumn market', 'north', true ),
			4 => array( 'Staff party', 'north', false ),
		);

		public function id(): string {
			return 'example:events';
		}

		public function label(): string {
			return 'Example events';
		}

		public function query_fields(): array {
			return array(
				'taxonomies'      => array(),
				'orderby'         => array( 'date' ),
				'visitor_filters' => array( 'search' ),
				'count_mode'      => 'exact',
			);
		}

		public function query( QueryArgs $args ): EntryResult {
			$needle  = null === $args->visitor ? null : $args->visitor->search;
			$visible = array();
			foreach ( self::EVENTS as $id => list( $title, $region, $public ) ) {
				if ( $public && ( null === $needle || false !== stripos( $title . ' ' . $region, $needle ) ) ) {
					$visible[] = new Entry(
						$id,
						$this->id(),
						array(
							'title'  => $title,
							'region' => $region,
						)
					);
				}
			}
			$per_page = $args->criteria->per_page;
			$slice    = array_slice( $visible, ( $args->page - 1 ) * $per_page, $per_page );
			return new EntryResult( $slice, count( $visible ), count( $visible ) > $args->page * $per_page );
		}

		public function placeholders(): array {
			return array(
				'entry.title'  => array(
					'type'        => 'text',
					'description' => 'Event title',
					'example'     => 'Spring fair',
				),
				'entry.region' => array(
					'type'        => 'text',
					'description' => 'Region',
					'example'     => 'north',
				),
			);
		}

		public function can_view( Entry $entry, ?int $user_id ): bool {
			return true === ( self::EVENTS[ $entry->id ][2] ?? false );
		}
	}
}

add_filter(
	'sw_postlist_entry_sources',
	static function ( array $sources ): array {
		$sources[] = new Example_Events_Source();
		return $sources;
	}
);

add_filter(
	'sw_postlist_formatters',
	static function ( array $formatters ): array {
		$formatters[] = new CallbackFormatter(
			'shout',
			new FormatterSignature( array( ValueType::Text ), ValueType::Text ),
			static fn ( mixed $value ): string => strtoupper( (string) $value ) . '!'
		);
		return $formatters;
	}
);

add_filter(
	'sw_postlist_layouts',
	static function ( array $layouts ): array {
		$layouts[] = new LayoutDefinition(
			'timeline',
			'Timeline',
			array( 'dense' => array( 'type' => 'bool' ) ),
			null,
			static fn ( string $items, array $options ): string => '<ol class="example-timeline' . ( ! empty( $options['dense'] ) ? ' is-dense' : '' ) . '">' . $items . '</ol>',
			'li'
		);
		return $layouts;
	}
);

add_filter(
	'sw_postlist_filter_types',
	static function ( array $types ): array {
		$types[] = new FilterTypeDefinition(
			'region',
			'Region',
			array(
				'label' => array(
					'type' => 'string',
					'null' => true,
				),
			),
			static fn ( array $config, array $values ): array => array( 'search' => $values[0] ?? null ),
			static fn ( array $config, array $values, string $name, string $id ): string => '<select id="' . esc_attr( $id ) . '" name="' . esc_attr( $name ) . '"><option value="">All</option><option value="north"' . selected( 'north' === ( $values[0] ?? '' ), true, false ) . '>North</option><option value="south"' . selected( 'south' === ( $values[0] ?? '' ), true, false ) . '>South</option></select>'
		);
		return $types;
	}
);
