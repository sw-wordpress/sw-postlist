<?php
/**
 * Upgrades stored documents to the current schema version. Version 1 is the first version, so the
 * framework only rejects unknown, missing and newer versions; steps are registered when version 2 exists.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Document;

use SW\Postlist\Domain\Validation\ValidationError;
use SW\Postlist\Domain\Validation\ValidationResult;

final class DocumentMigrator {

	/**
	 * @param int                                                              $current_version Newest supported version.
	 * @param array<int, callable(array<string, mixed>): array<string, mixed>> $steps Migration from version N to N+1, keyed by N.
	 */
	public function __construct(
		private readonly int $current_version,
		private readonly array $steps = array()
	) {
	}

	/**
	 * @param array<string, mixed> $document Raw document.
	 * @return ValidationResult<array<string, mixed>>
	 */
	public function migrate( array $document ): ValidationResult {
		$version = $document['schema_version'] ?? null;
		if ( ! is_int( $version ) || $version < 1 ) {
			return ValidationResult::invalid( array( new ValidationError( 'schema_version_missing', 'schema_version', 'The document has no valid schema version.' ) ) );
		}
		if ( $version > $this->current_version ) {
			return ValidationResult::invalid( array( new ValidationError( 'schema_version_newer', 'schema_version', 'The document was created by a newer version of the plugin.' ) ) );
		}
		while ( $version < $this->current_version ) {
			if ( ! isset( $this->steps[ $version ] ) ) {
				return ValidationResult::invalid( array( new ValidationError( 'schema_version_unsupported', 'schema_version', 'This document version can no longer be migrated.' ) ) );
			}
			$document = ( $this->steps[ $version ] )( $document );
			++$version;
			$document['schema_version'] = $version;
		}
		return ValidationResult::valid( $document );
	}
}
