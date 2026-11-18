<?php
/**
 * In-memory test double for the package service tests (no WordPress).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support;

use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Domain\View\ViewDocument;

final class MemoryViews implements ViewRepositoryInterface {

	/** @var array<int, ViewDocument> */
	public array $items = array();

	/** @var array<int, string> */
	public array $statuses = array();

	/** @var list<int> */
	public array $trashed = array();

	/** @var list<string> Log of writes: "save:ID:status". */
	public array $log = array();

	public int $next = 10;

	public ?int $fail_on_save = null;

	private int $saves = 0;

	public function save( ?int $view_id, ViewDocument $document, string $status = 'publish' ): int {
		++$this->saves;
		if ( $this->saves === $this->fail_on_save ) {
			throw new \RuntimeException( 'disk full' );
		}
		$id                    = $view_id ?? ++$this->next;
		$this->items[ $id ]    = $document;
		$this->statuses[ $id ] = $status;
		$this->log[]           = 'save:' . $id . ':' . $status;
		return $id;
	}

	public function find( int $view_id ): ?ViewDocument {
		return in_array( $view_id, $this->trashed, true ) ? null : ( $this->items[ $view_id ] ?? null );
	}

	public function view_ids_using_template( int $template_id ): array {
		$ids = array();
		foreach ( $this->items as $id => $document ) {
			if ( ! in_array( $id, $this->trashed, true ) && in_array( $template_id, $document->template_ids(), true ) ) {
				$ids[] = $id;
			}
		}
		return $ids;
	}

	public function summaries(): array {
		$out = array();
		foreach ( $this->items as $id => $document ) {
			if ( ! in_array( $id, $this->trashed, true ) ) {
				$out[] = array(
					'id'       => $id,
					'title'    => $document->title,
					'status'   => $this->statuses[ $id ],
					'source'   => $document->source_id,
					'layout'   => null,
					'modified' => '',
				);
			}
		}
		return $out;
	}

	public function trash( int $view_id ): bool {
		$this->trashed[] = $view_id;
		return true;
	}

	public function status( int $view_id ): ?string {
		return in_array( $view_id, $this->trashed, true ) ? null : ( $this->statuses[ $view_id ] ?? null );
	}
}
