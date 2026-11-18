<?php
/**
 * Storage of template documents.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

use SW\Postlist\Domain\Template\TemplateDocument;

interface TemplateRepositoryInterface {

	/**
	 * @param int|null $template_id Existing template ID or null.
	 * @throws DocumentRepositoryException When WordPress refuses the write.
	 */
	public function save( ?int $template_id, TemplateDocument $document ): int;

	public function find( int $template_id ): ?TemplateDocument;

	/**
	 * Templates for management screens.
	 *
	 * @return list<array{id: int, title: string, role: string, modified: string}>
	 */
	public function summaries(): array;

	/**
	 * Moves a template to the trash; refused (false) while a view uses it.
	 */
	public function trash( int $template_id ): bool;
}
