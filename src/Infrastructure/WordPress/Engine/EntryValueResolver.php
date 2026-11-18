<?php
/**
 * Resolves the `entry.*` placeholders of one WordPress post at render time (always current data, never cached).
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\TemplateEngine\ImageValue;
use SW\Postlist\TemplateEngine\ListItem;
use SW\Postlist\TemplateEngine\Port\ValueResolverInterface;
use SW\Postlist\TemplateEngine\TrustedHtml;

final class EntryValueResolver implements ValueResolverInterface {

	/**
	 * @param list<string> $allowed_meta_keys Keys readable through `entry.meta.*`.
	 */
	public function __construct(
		private readonly Entry $entry,
		private readonly array $allowed_meta_keys,
		private readonly ContentSanitizer $content
	) {
	}

	public function resolve( string $name ): mixed {
		$post = $this->entry->native;
		if ( ! $post instanceof \WP_Post ) {
			return $this->from_data( $name );
		}
		if ( str_starts_with( $name, 'entry.terms.' ) ) {
			return $this->terms( $post, substr( $name, strlen( 'entry.terms.' ) ) );
		}
		if ( str_starts_with( $name, 'entry.meta.' ) ) {
			return $this->meta( $post, substr( $name, strlen( 'entry.meta.' ) ) );
		}
		return match ( $name ) {
			'entry.id'          => $post->ID,
			'entry.title'       => self::plain( get_the_title( $post ) ),
			'entry.excerpt'     => self::plain( get_the_excerpt( $post ) ),
			'entry.content'     => new TrustedHtml( $this->content->sanitize( $post->post_content ) ),
			'entry.date'        => $this->date( $post->post_date_gmt, $post->post_date ),
			'entry.modified'    => $this->date( $post->post_modified_gmt, $post->post_modified ),
			'entry.url'         => (string) get_permalink( $post ),
			'entry.image'       => $this->image( $post ),
			'entry.author.name' => self::plain( (string) get_the_author_meta( 'display_name', (int) $post->post_author ) ),
			'entry.author.url'  => get_author_posts_url( (int) $post->post_author ),
			default             => null,
		};
	}

	/** Text is escaped once at output time: decode the entities WordPress filters produced. */
	/**
	 * Entries of other sources carry their values in `Entry::data`, by full placeholder name (`entry.title`) or without
	 * the prefix (`title`). Only plain values are accepted; the engine escapes them like any other text.
	 */
	private function from_data( string $name ): mixed {
		$data  = $this->entry->data;
		$value = $data[ $name ] ?? $data[ preg_replace( '/^entry\./', '', $name ) ?? $name ] ?? null;
		if ( is_string( $value ) || is_int( $value ) || $value instanceof \DateTimeImmutable || $value instanceof ImageValue || $value instanceof TrustedHtml ) {
			return $value;
		}
		if ( is_float( $value ) ) {
			return (string) $value;
		}
		if ( is_array( $value ) && array_is_list( $value ) ) {
			return array_values( array_filter( $value, 'is_string' ) );
		}
		return null;
	}

	private static function plain( string $text ): string {
		return html_entity_decode( wp_strip_all_tags( $text ), ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	private function date( string $gmt, string $local ): ?\DateTimeImmutable {
		try {
			if ( '' !== $gmt && '0000-00-00 00:00:00' !== $gmt ) {
				return ( new \DateTimeImmutable( $gmt, new \DateTimeZone( 'UTC' ) ) )->setTimezone( wp_timezone() );
			}
			return '' === $local ? null : new \DateTimeImmutable( $local, wp_timezone() );
		} catch ( \Exception $error ) {
			return null;
		}
	}

	private function image( \WP_Post $post ): ?ImageValue {
		$attachment_id = (int) get_post_thumbnail_id( $post );
		if ( $attachment_id <= 0 ) {
			return null;
		}
		$url = wp_get_attachment_image_url( $attachment_id, 'large' );
		if ( ! is_string( $url ) || '' === $url ) {
			return null;
		}
		return new ImageValue( $url, (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ), $attachment_id );
	}

	/**
	 * @return list<ListItem>|null
	 */
	private function terms( \WP_Post $post, string $taxonomy ): ?array {
		if ( ! taxonomy_exists( $taxonomy ) || ! is_object_in_taxonomy( $post->post_type, $taxonomy ) ) {
			return null;
		}
		$terms = get_the_terms( $post, $taxonomy );
		if ( ! is_array( $terms ) ) {
			return array();
		}
		$items = array();
		foreach ( $terms as $term ) {
			$link    = get_term_link( $term );
			$items[] = new ListItem( self::plain( $term->name ), is_string( $link ) ? $link : null, $term->slug );
		}
		return $items;
	}

	/** Only allowed keys, only plain scalar values: never serialized data and never markup that was not escaped. */
	private function meta( \WP_Post $post, string $key ): ?string {
		if ( ! in_array( $key, $this->allowed_meta_keys, true ) ) {
			return null;
		}
		$value = get_post_meta( $post->ID, $key, true );
		return is_scalar( $value ) ? (string) $value : null;
	}
}
