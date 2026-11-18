<?php
/**
 * Configurable entry source double.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support;

use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\EntryResult;
use SW\Postlist\Domain\Source\QueryArgs;

final class FakeSource implements EntrySourceInterface {

	/** @var list<QueryArgs> */
	public array $received = array();

	/**
	 * @param list<Entry>          $entries      Entries returned for every query.
	 * @param list<int|string>     $hidden_ids   IDs for which can_view() answers false.
	 * @param array<string, mixed> $fields       Declared query fields.
	 */
	public function __construct(
		private readonly string $id = 'fake:items',
		private readonly array $entries = array(),
		private readonly array $hidden_ids = array(),
		private readonly array $fields = array(
			'taxonomies'      => array(),
			'orderby'         => array( 'date' ),
			'visitor_filters' => array(),
			'count_mode'      => 'exact',
		),
		private readonly ?int $total = 0,
		private readonly bool $has_more = false,
		private readonly string $label = 'Fake'
	) {
	}

	public function id(): string {
		return $this->id;
	}

	public function label(): string {
		return $this->label;
	}

	public function query_fields(): array {
		return $this->fields;
	}

	public function query( QueryArgs $args ): EntryResult {
		$this->received[] = $args;
		return new EntryResult( $this->entries, $this->total, $this->has_more );
	}

	public function placeholders(): array {
		return array();
	}

	public function can_view( Entry $entry, ?int $user_id ): bool {
		return ! in_array( $entry->id, $this->hidden_ids, true );
	}
}
