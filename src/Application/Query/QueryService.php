<?php
/**
 * Runs a view's query against its single entry source and verifies the result contract (ADR-05).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Application\Query;

use SW\Postlist\Application\Port\EntryExtensionInterface;
use SW\Postlist\Application\Port\LoggerInterface;
use SW\Postlist\Domain\Source\ContextOrigin;
use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\Domain\Source\EntryResult;
use SW\Postlist\Domain\Source\QueryArgs;
use SW\Postlist\Domain\Source\VisitorFilters;
use SW\Postlist\Domain\View\ViewDocument;

final class QueryService {

	public function __construct(
		private readonly SourceRegistry $sources,
		private readonly EntryExtensionInterface $extension,
		private readonly LoggerInterface $logger
	) {
	}

	public function run(
		ViewDocument $view,
		int $page = 1,
		?VisitorFilters $visitor = null,
		?ContextOrigin $origin = null,
		?int $view_id = null,
		?int $user_id = null
	): QueryOutcome {
		$source = $this->sources->get( $view->source_id );
		if ( null === $source ) {
			$this->logger->warning( 'source_missing', 'The entry source of a view is not registered.', array( 'source' => $view->source_id ) );
			return QueryOutcome::failure( 'source_missing' );
		}
		$args   = new QueryArgs( $view->source_id, $view->query, max( 1, $page ), $visitor, $origin, $view_id, $user_id );
		$result = $source->query( $args );

		$filtered = $this->extension->filter_entries( $result->entries, $args );
		$entries  = $this->verified_entries( $result->entries, $filtered );
		if ( null === $entries ) {
			$this->logger->warning( 'entries_contract_violation', 'sw_postlist_entries changed the identity, membership or order of the entries.', array( 'source' => $view->source_id ) );
			return QueryOutcome::failure( 'entries_contract_violation' );
		}
		foreach ( $entries as $entry ) {
			if ( ! $source->can_view( $entry, $user_id ) ) {
				$this->logger->warning( 'visibility_violation', 'An entry was returned that the source itself does not consider visible.', array( 'source' => $view->source_id ) );
				return QueryOutcome::failure( 'visibility_violation' );
			}
		}
		return QueryOutcome::success( new EntryResult( $entries, $result->total, $result->has_more ) );
	}

	/**
	 * Extensions may only enrich presentation data: same entries (identity and source) in the same order.
	 *
	 * @param list<Entry> $original Entries as selected by the source.
	 * @param mixed       $filtered Value returned by the extension point.
	 * @return list<Entry>|null
	 */
	private function verified_entries( array $original, mixed $filtered ): ?array {
		if ( ! is_array( $filtered ) || count( $filtered ) !== count( $original ) ) {
			return null;
		}
		$filtered = array_values( $filtered );
		$verified = array();
		foreach ( $original as $index => $entry ) {
			$candidate = $filtered[ $index ];
			if ( ! $candidate instanceof Entry || $candidate->id !== $entry->id || $candidate->source_id !== $entry->source_id ) {
				return null;
			}
			$verified[] = $candidate;
		}
		return $verified;
	}
}
