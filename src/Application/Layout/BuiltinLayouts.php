<?php
/**
 * The five layouts of 1.0 and the option schemas they share.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Layout;

final class BuiltinLayouts {

	public const ASPECT_RATIOS = array( 'auto', '1/1', '4/3', '3/2', '16/9', '2/1' );

	/**
	 * Options of every grid-like layout.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function common_schema(): array {
		return array(
			'columns'      => array(
				'type' => 'device_int',
				'min'  => 1,
				'max'  => 6,
			),
			'gap'          => array(
				'type' => 'device_int',
				'min'  => 0,
				'max'  => 64,
			),
			'font_size'    => array(
				'type' => 'device_int',
				'min'  => 50,
				'max'  => 200,
			),
			'hidden'       => array( 'type' => 'device_bool' ),
			'aspect_ratio' => array(
				'type'   => 'enum',
				'values' => self::ASPECT_RATIOS,
			),
			'accent'       => array(
				'type'    => 'string',
				'pattern' => '/^[a-z0-9-]{1,40}$/',
				'null'    => true,
			),
		);
	}

	/**
	 * @return list<LayoutDefinition>
	 */
	public static function all(): array {
		$common = self::common_schema();
		$slider = array(
			'slides'   => array(
				'type' => 'device_int',
				'min'  => 1,
				'max'  => 6,
			),
			'autoplay' => array( 'type' => 'bool' ),
			'interval' => array(
				'type' => 'int',
				'min'  => 2000,
				'max'  => 20000,
			),
			'loop'     => array( 'type' => 'bool' ),
			'arrows'   => array( 'type' => 'bool' ),
			'dots'     => array( 'type' => 'bool' ),
		);
		return array(
			new LayoutDefinition( 'grid', 'Grid', $common ),
			new LayoutDefinition( 'list', 'List', $common, null, null, 'li' ),
			new LayoutDefinition( 'masonry', 'Masonry', $common, 'masonry' ),
			new LayoutDefinition( 'hover_grid', 'Hover grid', $common ),
			new LayoutDefinition( 'slider', 'Slider', $common + $slider, 'slider' ),
		);
	}
}
