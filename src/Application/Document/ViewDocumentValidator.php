<?php
/**
 * Validates and normalizes raw view documents (stored, imported or submitted through REST).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Document;

use SW\Postlist\Application\Filter\FilterTypeRegistry;
use SW\Postlist\Application\Filter\VisitorInputParser;
use SW\Postlist\Application\Layout\LayoutRegistry;
use SW\Postlist\Domain\Validation\ValidationError;
use SW\Postlist\Domain\Validation\ValidationResult;
use SW\Postlist\Domain\View\QueryCriteria;
use SW\Postlist\Domain\View\ViewDocument;

final class ViewDocumentValidator {

	public const PAGINATION_MODES = array( 'classic', 'none', 'ajax', 'load_more', 'infinite' );
	public const MAX_PER_PAGE     = 100;
	public const MAX_IDS          = 500;
	public const MAX_TERMS        = 200;
	public const MAX_FILTERS      = 6;
	public const DEVICES          = array( 'desktop', 'tablet', 'mobile' );
	public const META_COMPARES    = array( '=', '!=', '>', '>=', '<', '<=', 'LIKE', 'EXISTS', 'NOT EXISTS' );

	private DocumentMigrator $migrator;

	private LayoutRegistry $layouts;

	private FilterTypeRegistry $filter_types;

	/**
	 * @param LayoutRegistry|null     $layouts      Known layouts with their option schemas (built-in ones by default).
	 * @param FilterTypeRegistry|null $filter_types Known visitor filter types (built-in ones by default).
	 */
	public function __construct( ?LayoutRegistry $layouts = null, ?DocumentMigrator $migrator = null, ?FilterTypeRegistry $filter_types = null ) {
		$this->layouts      = $layouts ?? new LayoutRegistry();
		$this->migrator     = $migrator ?? new DocumentMigrator( ViewDocument::SCHEMA_VERSION );
		$this->filter_types = $filter_types ?? new FilterTypeRegistry();
	}

	/**
	 * @param array<string, mixed> $raw Raw document.
	 * @return ValidationResult<ViewDocument>
	 */
	public function validate( array $raw ): ValidationResult {
		$migrated = $this->migrator->migrate( $raw );
		if ( ! $migrated->is_valid() ) {
			return ValidationResult::invalid( $migrated->errors() );
		}
		$document = $migrated->value();
		$errors   = array();

		$title      = $this->title( $document, $errors );
		$source     = $this->source( $document, $errors );
		$query      = $this->query( $document['query'] ?? array(), $errors );
		$layout     = $this->layout( $document['layout'] ?? null, $errors );
		$pagination = $this->pagination( $document['pagination'] ?? null, $errors );
		$filters    = $this->filters( $document['filters'] ?? null, $errors );
		$container  = $this->template_id( $document['templates'] ?? array(), 'container', $errors );
		$entry      = $this->template_id( $document['templates'] ?? array(), 'entry', $errors );

		if ( array() !== $errors || null === $query ) {
			return ValidationResult::invalid( $errors );
		}
		return ValidationResult::valid( new ViewDocument( $title, $source, $query, $layout, $pagination, $container, $entry, $filters ) );
	}

	/**
	 * @param array<string, mixed>  $document Migrated document.
	 * @param list<ValidationError> $errors   Collected findings.
	 */
	private function title( array $document, array &$errors ): string {
		$title = $document['title'] ?? null;
		if ( ! is_string( $title ) || '' === trim( $title ) || mb_strlen( $title ) > 200 ) {
			$errors[] = new ValidationError( 'title_invalid', 'title', 'The title must be a non-empty string of at most 200 characters.' );
			return '';
		}
		return trim( $title );
	}

	/**
	 * A view selects exactly one entry source: a single string ID, never a list (PLAN.md §4).
	 *
	 * @param array<string, mixed>  $document Migrated document.
	 * @param list<ValidationError> $errors   Collected findings.
	 */
	private function source( array $document, array &$errors ): string {
		$source = $document['source'] ?? null;
		if ( is_array( $source ) ) {
			$errors[] = new ValidationError( 'source_multiple', 'source', 'A view must reference exactly one entry source.' );
			return '';
		}
		if ( ! is_string( $source ) || 1 !== preg_match( '/^[a-z0-9_-]+(?::[a-z0-9_-]+)*$/', $source ) || 'any' === $source || 'post:any' === $source ) {
			$errors[] = new ValidationError( 'source_invalid', 'source', 'The source must be a single, concrete entry source ID.' );
			return '';
		}
		return $source;
	}

	/**
	 * @param mixed                 $raw    Raw query section.
	 * @param list<ValidationError> $errors Collected findings.
	 */
	private function query( mixed $raw, array &$errors ): ?QueryCriteria {
		if ( ! is_array( $raw ) ) {
			$errors[] = new ValidationError( 'query_invalid', 'query', 'The query must be an object.' );
			return null;
		}
		$orderby = $raw['orderby'] ?? 'date';
		if ( ! is_string( $orderby ) || ! in_array( $orderby, QueryCriteria::ORDERBY, true ) ) {
			$errors[] = new ValidationError( 'orderby_invalid', 'query.orderby', 'Unknown sort field.' );
			$orderby  = 'date';
		}
		$order = strtolower( (string) ( is_string( $raw['order'] ?? 'desc' ) ? ( $raw['order'] ?? 'desc' ) : '' ) );
		if ( ! in_array( $order, array( 'asc', 'desc' ), true ) ) {
			$errors[] = new ValidationError( 'order_invalid', 'query.order', 'The sort direction must be asc or desc.' );
			$order    = 'desc';
		}
		$meta_key = $raw['order_meta_key'] ?? null;
		if ( null !== $meta_key && ( ! is_string( $meta_key ) || '' === $meta_key || mb_strlen( $meta_key ) > 191 ) ) {
			$errors[] = new ValidationError( 'order_meta_key_invalid', 'query.order_meta_key', 'The meta key must be a non-empty string.' );
			$meta_key = null;
		}
		if ( in_array( $orderby, array( 'meta_num', 'meta_text' ), true ) && null === $meta_key ) {
			$errors[] = new ValidationError( 'order_meta_key_missing', 'query.order_meta_key', 'Sorting by meta needs a meta key.' );
		}
		$context = $raw['context'] ?? 'none';
		if ( ! is_string( $context ) || ! in_array( $context, QueryCriteria::CONTEXT_MODES, true ) ) {
			$errors[] = new ValidationError( 'context_invalid', 'query.context', 'Unknown context mode.' );
			$context  = 'none';
		}
		$date = $raw['date'] ?? array();
		if ( ! is_array( $date ) ) {
			$errors[] = new ValidationError( 'date_invalid', 'query.date', 'The date range must be an object.' );
			$date     = array();
		}
		$date_after  = $this->date( $date['after'] ?? null, 'query.date.after', $errors );
		$date_before = $this->date( $date['before'] ?? null, 'query.date.before', $errors );

		return new QueryCriteria(
			$this->taxonomies( $raw['taxonomies'] ?? array(), $errors ),
			$this->id_list( $raw['authors'] ?? array(), 'query.authors', $errors ),
			$date_after,
			$date_before,
			$this->meta( $raw['meta'] ?? array(), $errors ),
			$this->id_list( $raw['include'] ?? array(), 'query.include', $errors ),
			$this->id_list( $raw['exclude'] ?? array(), 'query.exclude', $errors ),
			$this->bounded_int( $raw['per_page'] ?? 10, 1, self::MAX_PER_PAGE, 'query.per_page', $errors ),
			$this->bounded_int( $raw['offset'] ?? 0, 0, 10000, 'query.offset', $errors ),
			$orderby,
			$order,
			$meta_key,
			$context
		);
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param string                $path   Error path.
	 * @param list<ValidationError> $errors Collected findings.
	 */
	private function date( mixed $value, string $path, array &$errors ): ?string {
		if ( null === $value ) {
			return null;
		}
		if ( ! is_string( $value ) || 1 !== preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			$errors[] = new ValidationError( 'date_invalid', $path, 'Dates use the format YYYY-MM-DD.' );
			return null;
		}
		$parsed = \DateTimeImmutable::createFromFormat( '!Y-m-d', $value );
		if ( false === $parsed || $parsed->format( 'Y-m-d' ) !== $value ) {
			$errors[] = new ValidationError( 'date_invalid', $path, 'The date does not exist.' );
			return null;
		}
		return $value;
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param int                   $min    Lower bound.
	 * @param int                   $max    Upper bound.
	 * @param string                $path   Error path.
	 * @param list<ValidationError> $errors Collected findings.
	 */
	private function bounded_int( mixed $value, int $min, int $max, string $path, array &$errors ): int {
		if ( ! is_int( $value ) || $value < $min || $value > $max ) {
			$errors[] = new ValidationError( 'number_out_of_range', $path, sprintf( 'Expected an integer between %d and %d.', $min, $max ) );
			return $min;
		}
		return $value;
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param string                $path   Error path.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return list<int>
	 */
	private function id_list( mixed $value, string $path, array &$errors ): array {
		if ( ! is_array( $value ) || count( $value ) > self::MAX_IDS ) {
			$errors[] = new ValidationError( 'id_list_invalid', $path, 'Expected a list of IDs.' );
			return array();
		}
		$ids = array();
		foreach ( $value as $id ) {
			if ( ! is_int( $id ) || $id < 1 ) {
				$errors[] = new ValidationError( 'id_list_invalid', $path, 'IDs must be positive integers.' );
				return array();
			}
			$ids[ $id ] = $id;
		}
		return array_values( $ids );
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return list<array{taxonomy: string, terms: list<int>, operator: string}>
	 */
	private function taxonomies( mixed $value, array &$errors ): array {
		if ( ! is_array( $value ) || count( $value ) > 20 ) {
			$errors[] = new ValidationError( 'taxonomies_invalid', 'query.taxonomies', 'Expected a list of taxonomy filters.' );
			return array();
		}
		$filters = array();
		foreach ( array_values( $value ) as $index => $filter ) {
			$path = 'query.taxonomies[' . $index . ']';
			if ( ! is_array( $filter ) || ! is_string( $filter['taxonomy'] ?? null ) || 1 !== preg_match( '/^[a-z0-9_-]{1,32}$/', $filter['taxonomy'] ) ) {
				$errors[] = new ValidationError( 'taxonomy_invalid', $path . '.taxonomy', 'Invalid taxonomy name.' );
				continue;
			}
			$operator = $filter['operator'] ?? 'in';
			if ( ! is_string( $operator ) || ! in_array( $operator, QueryCriteria::OPERATORS, true ) ) {
				$errors[] = new ValidationError( 'operator_invalid', $path . '.operator', 'Unknown term operator.' );
				continue;
			}
			$terms = $this->id_list( $filter['terms'] ?? null, $path . '.terms', $errors );
			if ( array() === $terms ) {
				continue;
			}
			$filters[] = array(
				'taxonomy' => $filter['taxonomy'],
				'terms'    => array_slice( $terms, 0, self::MAX_TERMS ),
				'operator' => $operator,
			);
		}
		return $filters;
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return list<array{key: string, value: string, compare: string}>
	 */
	private function meta( mixed $value, array &$errors ): array {
		if ( ! is_array( $value ) || count( $value ) > 10 ) {
			$errors[] = new ValidationError( 'meta_invalid', 'query.meta', 'Expected a list of meta filters.' );
			return array();
		}
		$filters = array();
		foreach ( array_values( $value ) as $index => $filter ) {
			$path    = 'query.meta[' . $index . ']';
			$compare = is_array( $filter ) ? ( $filter['compare'] ?? '=' ) : null;
			if ( ! is_array( $filter ) || ! is_string( $filter['key'] ?? null ) || '' === $filter['key'] || mb_strlen( $filter['key'] ) > 191 ) {
				$errors[] = new ValidationError( 'meta_key_invalid', $path . '.key', 'Invalid meta key.' );
				continue;
			}
			if ( ! is_string( $compare ) || ! in_array( $compare, self::META_COMPARES, true ) ) {
				$errors[] = new ValidationError( 'meta_compare_invalid', $path . '.compare', 'Unknown meta comparison.' );
				continue;
			}
			$meta_value = $filter['value'] ?? '';
			if ( ! is_string( $meta_value ) || mb_strlen( $meta_value ) > 500 ) {
				$errors[] = new ValidationError( 'meta_value_invalid', $path . '.value', 'The meta value must be a string.' );
				continue;
			}
			$filters[] = array(
				'key'     => $filter['key'],
				'value'   => $meta_value,
				'compare' => $compare,
			);
		}
		return $filters;
	}

	/**
	 * Every layout declares a closed option schema; options outside of it are rejected.
	 *
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return array<string, mixed>
	 */
	private function layout( mixed $value, array &$errors ): array {
		$definition = is_array( $value ) && is_string( $value['type'] ?? null ) ? $this->layouts->get( $value['type'] ) : null;
		if ( ! is_array( $value ) || null === $definition ) {
			$errors[] = new ValidationError( 'layout_invalid', 'layout.type', 'Unknown layout type.' );
			return array( 'type' => 'grid' );
		}
		foreach ( $value as $key => $option ) {
			if ( 'type' === $key ) {
				continue;
			}
			if ( ! isset( $definition->schema[ $key ] ) ) {
				$errors[] = new ValidationError( 'layout_unknown_key', 'layout.' . $key, 'Unknown layout option.' );
				continue;
			}
			OptionSchema::validate( $definition->schema[ $key ], $option, 'layout.' . $key, 'layout_option_invalid', $errors );
		}
		return $value;
	}

	/**
	 * Visitor filters: a short list of configured filter types, each with a closed option schema.
	 *
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return list<array<string, mixed>>
	 */
	private function filters( mixed $value, array &$errors ): array {
		if ( null === $value ) {
			return array();
		}
		if ( ! is_array( $value ) || ! array_is_list( $value ) || count( $value ) > self::MAX_FILTERS ) {
			$errors[] = new ValidationError( 'filters_invalid', 'filters', 'Filters are a list of at most ' . self::MAX_FILTERS . ' entries.' );
			return array();
		}
		$seen  = array();
		$clean = array();
		foreach ( $value as $index => $config ) {
			$path       = 'filters.' . $index;
			$definition = is_array( $config ) && is_string( $config['type'] ?? null ) ? $this->filter_types->get( $config['type'] ) : null;
			if ( ! is_array( $config ) || null === $definition ) {
				$errors[] = new ValidationError( 'filter_invalid', $path . '.type', 'Unknown filter type.' );
				continue;
			}
			foreach ( $config as $key => $option ) {
				if ( 'type' === $key ) {
					continue;
				}
				if ( ! isset( $definition->schema[ $key ] ) ) {
					$errors[] = new ValidationError( 'filter_unknown_key', $path . '.' . $key, 'Unknown filter option.' );
					continue;
				}
				OptionSchema::validate( $definition->schema[ $key ], $option, $path . '.' . $key, 'filter_option_invalid', $errors );
			}
			if ( 'taxonomy' === $definition->id && ! is_string( $config['taxonomy'] ?? null ) ) {
				$errors[] = new ValidationError( 'filter_option_invalid', $path . '.taxonomy', 'A taxonomy filter needs a taxonomy.' );
			}
			$param = VisitorInputParser::param_for( $config );
			if ( isset( $seen[ $param ] ) ) {
				$errors[] = new ValidationError( 'filter_duplicate', $path, 'Each filter may be used once.' );
			}
			$seen[ $param ] = true;
			$clean[]        = $config;
		}
		return $clean;
	}

	/**
	 * @param mixed                 $value  Raw value.
	 * @param list<ValidationError> $errors Collected findings.
	 * @return array<string, mixed>
	 */
	private function pagination( mixed $value, array &$errors ): array {
		if ( ! is_array( $value ) || ! is_string( $value['mode'] ?? null ) || ! in_array( $value['mode'], self::PAGINATION_MODES, true ) ) {
			$errors[] = new ValidationError( 'pagination_invalid', 'pagination.mode', 'Unknown pagination mode.' );
			return array( 'mode' => 'classic' );
		}
		return $value;
	}

	/**
	 * @param mixed                 $templates Raw templates section.
	 * @param string                $role      container|entry.
	 * @param list<ValidationError> $errors    Collected findings.
	 */
	private function template_id( mixed $templates, string $role, array &$errors ): ?int {
		if ( ! is_array( $templates ) ) {
			$errors[] = new ValidationError( 'templates_invalid', 'templates', 'The templates section must be an object.' );
			return null;
		}
		$id = $templates[ $role ] ?? null;
		if ( null === $id ) {
			return null;
		}
		if ( ! is_int( $id ) || $id < 1 ) {
			$errors[] = new ValidationError( 'template_id_invalid', 'templates.' . $role, 'Template references are positive integer IDs or null.' );
			return null;
		}
		return $id;
	}
}
