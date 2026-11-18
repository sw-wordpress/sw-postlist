<?php
/**
 * The visitor filter types of 1.0 and their option schemas.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Filter;

use SW\Postlist\Domain\View\QueryCriteria;

final class BuiltinFilterTypes {

	public const IDS = array( 'taxonomy', 'author', 'search', 'sort' );

	public const DISPLAYS_TAXONOMY = array( 'buttons', 'dropdown', 'checkboxes' );

	public const DISPLAYS_AUTHOR = array( 'dropdown', 'buttons' );

	public const LABEL_PATTERN = '/^[^<>\p{Cc}]{1,60}$/u';

	/**
	 * @return list<FilterTypeDefinition>
	 */
	public static function all(): array {
		$label = array(
			'type'    => 'string',
			'pattern' => self::LABEL_PATTERN,
			'null'    => true,
		);
		return array(
			new FilterTypeDefinition(
				'taxonomy',
				'Taxonomy',
				array(
					'taxonomy' => array(
						'type'    => 'string',
						'pattern' => '/^[a-z0-9_-]{1,32}$/',
					),
					'display'  => array(
						'type'   => 'enum',
						'values' => self::DISPLAYS_TAXONOMY,
					),
					'terms'    => array( 'type' => 'id_list' ),
					'label'    => $label,
				)
			),
			new FilterTypeDefinition(
				'author',
				'Author',
				array(
					'display' => array(
						'type'   => 'enum',
						'values' => self::DISPLAYS_AUTHOR,
					),
					'label'   => $label,
				)
			),
			new FilterTypeDefinition(
				'search',
				'Search',
				array(
					'label'       => $label,
					'placeholder' => $label,
				)
			),
			new FilterTypeDefinition(
				'sort',
				'Sorting',
				array(
					'options' => array(
						'type'   => 'enum_list',
						'values' => QueryCriteria::ORDERBY,
					),
					'label'   => $label,
				)
			),
		);
	}
}
