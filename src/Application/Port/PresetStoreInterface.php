<?php
/**
 * The preset storage as the package service sees it.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Port;

use SW\Postlist\Domain\Preset\Preset;

interface PresetStoreInterface {

	/**
	 * The effective preset (user override applied) as a document, null when unknown.
	 *
	 * @return array<string, mixed>|null
	 */
	public function export( string $id ): ?array;

	/** Whether any preset (bundled, extension or user) has this ID. */
	public function has( string $id ): bool;

	/** Saves (creates or overwrites) a user preset. */
	public function save_user( Preset $preset ): void;

	/** Removes a user preset; false when there is none with this ID. */
	public function delete_user( string $id ): bool;
}
