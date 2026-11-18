<?php
/**
 * The built-in container and entry templates behind the grid and list layouts. They use the same template language
 * as user templates, so a user can copy them as a starting point.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Render;

use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;

final class BuiltinTemplates {

	public static function container_id( string $layout_type ): string {
		return 'builtin-container-' . self::normalize( $layout_type );
	}

	public static function entry_id( string $layout_type ): string {
		return 'builtin-entry-' . self::normalize( $layout_type );
	}

	public static function container( string $layout_type ): TemplateDocument {
		$layout = self::normalize( $layout_type );
		$html   = array(
			'grid'       => '<div class="swp-grid">{{view.entries}}</div>{{view.navigation}}',
			'list'       => '<ul class="swp-list">{{view.entries}}</ul>{{view.navigation}}',
			'masonry'    => '<div class="swp-grid swp-masonry">{{view.entries}}</div>{{view.navigation}}',
			'hover_grid' => '<div class="swp-grid swp-hover-grid">{{view.entries}}</div>{{view.navigation}}',
			'slider'     => '<div class="swp-slider"><div class="swp-slider__track" tabindex="0" role="group" aria-roledescription="carousel" aria-label="' . esc_attr__( 'Slides', 'sw-postlist' ) . '">{{view.entries}}</div></div>{{view.navigation}}',
		);
		return new TemplateDocument( ucfirst( str_replace( '_', ' ', $layout ) ), TemplateRole::Container, $html[ $layout ], '' );
	}

	public static function entry( string $layout_type ): TemplateDocument {
		$layout = self::normalize( $layout_type );
		if ( 'list' === $layout ) {
			return new TemplateDocument(
				'List row',
				TemplateRole::Entry,
				'<article class="swp-row">'
				. '<h3 class="swp-row__title"><a href="{{entry.url}}">{{entry.title}}</a></h3>'
				. "<time class=\"swp-row__date\" datetime='{{entry.date|date:\"c\"}}'>{{entry.date|date}}</time>"
				. '{{#entry.excerpt}}<p class="swp-row__excerpt">{{entry.excerpt|words:40}}</p>{{/entry.excerpt}}'
				. '</article>',
				''
			);
		}
		if ( 'hover_grid' === $layout ) {
			return new TemplateDocument(
				'Hover card',
				TemplateRole::Entry,
				'<article class="swp-hover">'
				. '<a class="swp-hover__link" href="{{entry.url}}">{{#entry.image}}{{entry.image|image:"large"}}{{/entry.image}}<span class="swp-hover__title">{{entry.title}}</span></a>'
				. '<div class="swp-hover__more">{{#entry.excerpt}}<p>{{entry.excerpt|words:25}}</p>{{/entry.excerpt}}<a class="swp-hover__button" href="{{entry.url}}">' . esc_html__( 'Read more', 'sw-postlist' ) . '</a></div>'
				. '</article>',
				''
			);
		}
		return new TemplateDocument(
			'Card',
			TemplateRole::Entry,
			'<article class="swp-card">'
			. '{{#entry.image}}<a class="swp-card__media" href="{{entry.url}}">{{entry.image|image:"medium_large"}}</a>{{/entry.image}}'
			. '<div class="swp-card__body">'
			. '<h3 class="swp-card__title"><a href="{{entry.url}}">{{entry.title}}</a></h3>'
			. "<time class=\"swp-card__date\" datetime='{{entry.date|date:\"c\"}}'>{{entry.date|date}}</time>"
			. '{{#entry.excerpt}}<p class="swp-card__excerpt">{{entry.excerpt|words:30}}</p>{{/entry.excerpt}}'
			. '</div></article>',
			''
		);
	}

	/** Layouts without their own built-in templates (third-party layouts) use the grid templates. */
	private static function normalize( string $layout_type ): string {
		return in_array( $layout_type, array( 'list', 'masonry', 'hover_grid', 'slider' ), true ) ? $layout_type : 'grid';
	}
}
