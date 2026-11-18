<?php
/**
 * Storage of view documents.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

use SW\Postlist\Domain\View\ViewDocument;

interface ViewRepositoryInterface {

	/**
	 * Creates (null ID) or updates a view. Referenced templates must exist and have the matching role.
	 *
	 * @param int|null $view_id Existing view ID or null.
	 * @param string   $status  publish|draft.
	 * @throws DocumentRepositoryException When references or the status are invalid or WordPress refuses the write.
	 */
	public function save( ?int $view_id, ViewDocument $document, string $status = 'publish' ): int;

	/**
	 * Published or draft view, null when missing, trashed or corrupt.
	 */
	public function find( int $view_id ): ?ViewDocument;

	/**
	 * IDs of non-trashed views that reference the template.
	 *
	 * @return list<int>
	 */
	public function view_ids_using_template( int $template_id ): array;

	/**
	 * Draft and published views for management screens, newest changes first.
	 *
	 * @return list<array{id: int, title: string, status: string, source: string|null, layout: string|null, modified: string}>
	 */
	public function summaries(): array;

	/**
	 * Moves a view to the trash.
	 */
	public function trash( int $view_id ): bool;

	/** Status of a non-trashed view ("publish" or "draft"), null otherwise. */
	public function status( int $view_id ): ?string;
}
