<?php
/**
 * Outcome of an import (or of its dry run).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Package;

final class PackageReport {

	/**
	 * @param list<array{code: string, path: string, message: string}>             $errors   Findings that prevent the import.
	 * @param array{views: int, templates: int, presets: int}                      $counts   What the package contains.
	 * @param array{views: list<int>, templates: list<int>, presets: list<string>} $created IDs created (empty for a dry run or on failure).
	 * @param list<string>                                                         $renamed  Titles or IDs that were changed to avoid collisions.
	 */
	public function __construct(
		public readonly bool $ok,
		public readonly array $errors,
		public readonly array $counts,
		public readonly array $created = array(
			'views'     => array(),
			'templates' => array(),
			'presets'   => array(),
		),
		public readonly array $renamed = array(),
		public readonly bool $dry_run = false
	) {
	}

	/**
	 * @return array<string, mixed>
	 */
	public function to_array(): array {
		return array(
			'ok'      => $this->ok,
			'dry_run' => $this->dry_run,
			'errors'  => $this->errors,
			'counts'  => $this->counts,
			'created' => $this->created,
			'renamed' => $this->renamed,
		);
	}
}
