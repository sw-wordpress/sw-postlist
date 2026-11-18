<?php
/**
 * In-memory test double for the package service tests (no WordPress).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support;

use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Domain\Template\TemplateDocument;

final class MemoryTemplates implements TemplateRepositoryInterface {

	/** @var array<int, TemplateDocument> */
	public array $items = array();

	/** @var list<int> */
	public array $trashed = array();

	public int $next = 100;

	public function save( ?int $template_id, TemplateDocument $document ): int {
		$id                 = $template_id ?? ++$this->next;
		$this->items[ $id ] = $document;
		return $id;
	}

	public function find( int $template_id ): ?TemplateDocument {
		return in_array( $template_id, $this->trashed, true ) ? null : ( $this->items[ $template_id ] ?? null );
	}

	public function summaries(): array {
		$out = array();
		foreach ( $this->items as $id => $document ) {
			if ( ! in_array( $id, $this->trashed, true ) ) {
				$out[] = array(
					'id'       => $id,
					'title'    => $document->title,
					'role'     => $document->role->value,
					'modified' => '',
				);
			}
		}
		return $out;
	}

	public function trash( int $template_id ): bool {
		$this->trashed[] = $template_id;
		return true;
	}
}
