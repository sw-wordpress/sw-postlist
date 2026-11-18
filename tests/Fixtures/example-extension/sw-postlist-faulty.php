<?php
/**
 * Plugin Name: SW Postlist Faulty Extension
 * Description: Registers everything wrongly. The contract is: diagnostics instead of fatal errors. Used by tests/wp/tests/ExtensionContractTest.php.
 *
 * @package sw-postlist-example
 */

use SW\Postlist\Application\Filter\FilterTypeDefinition;
use SW\Postlist\Application\Layout\LayoutDefinition;

add_filter( 'sw_postlist_entry_sources', static fn ( array $sources ): array => array_merge( $sources, array( 'not a source', new stdClass() ) ) );
add_filter( 'sw_postlist_formatters', static fn ( array $formatters ): array => array_merge( $formatters, array( 42 ) ) );
add_filter( 'sw_postlist_layouts', static fn ( array $layouts ): array => array_merge( $layouts, array( new LayoutDefinition( 'Bad Id', 'Bad' ), new LayoutDefinition( 'grid', 'Duplicate' ), 'junk' ) ) );
add_filter( 'sw_postlist_filter_types', static fn ( array $types ): array => array_merge( $types, array( new FilterTypeDefinition( 'nocallbacks', 'None' ), 'junk' ) ) );
add_filter( 'sw_postlist_presets', static fn ( array $presets ): array => array_merge( $presets, array( 'broken' => array( 'schema_version' => 1 ) ) ) );
