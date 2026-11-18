<?php
/**
 * Validated, schema-versioned view document (PLAN.md §2).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Domain\View;

final class ViewDocument {

	public const SCHEMA_VERSION = 1;

	/**
	 * @param array<string, mixed>       $layout     Layout configuration, at least `type`.
	 * @param array<string, mixed>       $pagination Pagination configuration, at least `mode`.
	 * @param list<array<string, mixed>> $filters Visitor filters (validated configuration, may be empty).
	 */
	public function __construct(
		public readonly string $title,
		public readonly string $source_id,
		public readonly QueryCriteria $query,
		public readonly array $layout,
		public readonly array $pagination,
		public readonly ?int $container_template_id,
		public readonly ?int $entry_template_id,
		public readonly array $filters = array()
	) {
	}

	/**
	 * Canonical array form, as stored and exported.
	 *
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		$document = array(
			'schema_version' => self::SCHEMA_VERSION,
			'title'          => $this->title,
			'source'         => $this->source_id,
			'query'          => array(
				'taxonomies'     => $this->query->taxonomies,
				'authors'        => $this->query->authors,
				'date'           => array(
					'after'  => $this->query->date_after,
					'before' => $this->query->date_before,
				),
				'meta'           => $this->query->meta,
				'include'        => $this->query->include,
				'exclude'        => $this->query->exclude,
				'per_page'       => $this->query->per_page,
				'offset'         => $this->query->offset,
				'orderby'        => $this->query->orderby,
				'order'          => $this->query->order,
				'order_meta_key' => $this->query->order_meta_key,
				'context'        => $this->query->context,
			),
			'layout'         => $this->layout,
			'pagination'     => $this->pagination,
			'templates'      => array(
				'container' => $this->container_template_id,
				'entry'     => $this->entry_template_id,
			),
		);
		// Written only when used, so documents without filters stay byte-identical to earlier exports.
		if ( array() !== $this->filters ) {
			$document['filters'] = $this->filters;
		}
		return $document;
	}

	/**
	 * IDs of the templates this view references (container first, no duplicates, no nulls).
	 *
	 * @return list<int>
	 */
	public function template_ids(): array {
		return array_values(
			array_unique(
				array_filter(
					array( $this->container_template_id, $this->entry_template_id ),
					static fn ( ?int $template_id ): bool => null !== $template_id
				)
			)
		);
	}
}
