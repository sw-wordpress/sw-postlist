<?php
/**
 * In-memory test double for the package service tests (no WordPress).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Support;

use SW\Postlist\Application\Port\PresetStoreInterface;
use SW\Postlist\Domain\Preset\Preset;

class MemoryPresets implements PresetStoreInterface {

	/** @var array<string, Preset> */
	public array $items = array();

	public function export( string $id ): ?array {
		return isset( $this->items[ $id ] ) ? $this->items[ $id ]->to_array() : null;
	}

	public function has( string $id ): bool {
		return isset( $this->items[ $id ] );
	}

	public function save_user( Preset $preset ): void {
		$this->items[ $preset->id ] = $preset;
	}

	public function delete_user( string $id ): bool {
		$had = isset( $this->items[ $id ] );
		unset( $this->items[ $id ] );
		return $had;
	}
}
