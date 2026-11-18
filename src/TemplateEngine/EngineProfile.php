<?php
/**
 * Differences between host plugins as configuration instead of code forks: allowlists, class prefix, formatters.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\TemplateEngine;

final class EngineProfile {

	/** Bumped with every change of the AST format or of the compiled behaviour (part of every cache key). */
	public const ENGINE_VERSION = '1';

	public const MAX_TEMPLATE_BYTES = 102400;
	public const MAX_CSS_BYTES      = 51200;
	public const MAX_DEPTH          = 64;

	public const DEFAULT_TAGS = array(
		'div',
		'span',
		'p',
		'a',
		'strong',
		'em',
		'b',
		'i',
		'u',
		's',
		'small',
		'br',
		'hr',
		'wbr',
		'h1',
		'h2',
		'h3',
		'h4',
		'h5',
		'h6',
		'ul',
		'ol',
		'li',
		'dl',
		'dt',
		'dd',
		'article',
		'section',
		'header',
		'footer',
		'aside',
		'nav',
		'main',
		'figure',
		'figcaption',
		'img',
		'time',
		'address',
		'blockquote',
		'q',
		'cite',
		'abbr',
		'mark',
		'sup',
		'sub',
		'code',
		'table',
		'thead',
		'tbody',
		'tfoot',
		'tr',
		'th',
		'td',
		'caption',
		'details',
		'summary',
	);

	public const DEFAULT_GLOBAL_ATTRIBUTES = array( 'class', 'id', 'title', 'lang', 'dir', 'role', 'hidden', 'tabindex', 'style' );

	public const DEFAULT_TAG_ATTRIBUTES = array(
		'a'          => array( 'href', 'target', 'rel', 'hreflang' ),
		'img'        => array( 'src', 'alt', 'width', 'height', 'loading', 'decoding' ),
		'time'       => array( 'datetime' ),
		'td'         => array( 'colspan', 'rowspan', 'headers' ),
		'th'         => array( 'colspan', 'rowspan', 'headers', 'scope' ),
		'ol'         => array( 'start', 'reversed', 'type' ),
		'details'    => array( 'open' ),
		'blockquote' => array( 'cite' ),
		'q'          => array( 'cite' ),
	);

	public const VOID_TAGS = array( 'br', 'hr', 'img', 'wbr' );

	public const URL_ATTRIBUTES = array( 'href', 'src', 'cite' );

	public const BOOLEAN_ATTRIBUTES = array( 'hidden', 'open', 'reversed' );

	/**
	 * @param list<string>                $tags              Allowed elements.
	 * @param list<string>                $global_attributes Attributes allowed on every element (aria-* and data-* are implied).
	 * @param array<string, list<string>> $tag_attributes    Additional attributes per element.
	 * @param string                      $class_prefix      Prefix of the scope class, for example "swp-tpl-".
	 * @param bool                        $formatters        Whether `{{name|formatter}}` is allowed.
	 */
	public function __construct(
		public readonly array $tags = self::DEFAULT_TAGS,
		public readonly array $global_attributes = self::DEFAULT_GLOBAL_ATTRIBUTES,
		public readonly array $tag_attributes = self::DEFAULT_TAG_ATTRIBUTES,
		public readonly string $class_prefix = 'swp-tpl-',
		public readonly bool $formatters = true
	) {
	}

	/** Postlist: formatters on. */
	public static function postlist(): self {
		return new self();
	}

	/** CloudCalendar compatible profile: formatters off, calendar class prefix. */
	public static function cloudcalendar(): self {
		return new self( self::DEFAULT_TAGS, self::DEFAULT_GLOBAL_ATTRIBUTES, self::DEFAULT_TAG_ATTRIBUTES, 'swc-tpl-', false );
	}

	public function scope_class( int|string $template_id ): string {
		return $this->class_prefix . $template_id;
	}

	public function allows_tag( string $tag ): bool {
		return in_array( $tag, $this->tags, true );
	}

	public function allows_attribute( string $tag, string $attribute ): bool {
		if ( in_array( $attribute, $this->global_attributes, true ) || in_array( $attribute, $this->tag_attributes[ $tag ] ?? array(), true ) ) {
			return true;
		}
		return 1 === preg_match( '/^(aria-[a-z0-9_-]+|data-[a-z0-9_.:-]+)$/', $attribute );
	}

	/** Stable fingerprint: any change of the profile invalidates compiled templates. */
	public function fingerprint(): string {
		return hash(
			'sha256',
			(string) json_encode( array( $this->tags, $this->global_attributes, $this->tag_attributes, $this->class_prefix, $this->formatters ) ) // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- WordPress-free engine.
		);
	}
}
