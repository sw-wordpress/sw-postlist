<?php
/**
 * A preset: a named combination of layout options and container/entry templates (PLAN.md §7). Presets are pure
 * configuration; applying one creates ordinary templates.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\Preset;

final class Preset {

	public const SCHEMA_VERSION = 1;

	/**
	 * @param string                           $id          Slug ([a-z0-9-]{1,40}).
	 * @param string                           $title       Display name.
	 * @param string                           $description One line description.
	 * @param array<string, mixed>             $layout      Layout options (see the view document schema).
	 * @param array{html: string, css: string} $container Container template.
	 * @param array{html: string, css: string} $entry     Entry template.
	 * @param array<string, mixed>|null        $builder     Visual builder model of the entry template, when built with it.
	 */
	public function __construct(
		public readonly string $id,
		public readonly string $title,
		public readonly string $description,
		public readonly array $layout,
		public readonly array $container,
		public readonly array $entry,
		public readonly ?array $builder = null
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'schema_version' => self::SCHEMA_VERSION,
			'id'             => $this->id,
			'title'          => $this->title,
			'description'    => $this->description,
			'layout'         => $this->layout,
			'container'      => $this->container,
			'entry'          => $this->entry,
			'builder'        => $this->builder,
		);
	}
}
