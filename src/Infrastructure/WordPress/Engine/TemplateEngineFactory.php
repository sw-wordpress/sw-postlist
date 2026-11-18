<?php
/**
 * Composes the template engine with the WordPress adapters for one entry source and template role.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Infrastructure\WordPress\Engine;

use SW\Postlist\Application\Port\EntrySourceInterface;
use SW\Postlist\Application\Map\ValueMaps;
use SW\Postlist\Domain\Source\Entry;
use SW\Postlist\TemplateEngine\CompileContext;
use SW\Postlist\TemplateEngine\Engine;
use SW\Postlist\TemplateEngine\EngineProfile;
use SW\Postlist\TemplateEngine\Formatter\BuiltinFormatters;
use SW\Postlist\TemplateEngine\Formatter\FormatterRegistry;
use SW\Postlist\TemplateEngine\FormatContext;
use SW\Postlist\TemplateEngine\Port\AstCacheInterface;
use SW\Postlist\TemplateEngine\Port\ValueResolverInterface;
use SW\Postlist\TemplateEngine\Renderer;

final class TemplateEngineFactory {

	private ?FormatterRegistry $formatters = null;

	private ?Engine $engine = null;

	public function __construct(
		private readonly PlaceholderRegistry $placeholders,
		private readonly MetaAllowlist $meta,
		private readonly AstGenerations $generations,
		private readonly ContentSanitizer $content = new ContentSanitizer(),
		private readonly ?AstCacheInterface $cache = null
	) {
	}

	/** Built-in formatters, the WordPress `image` formatter and whatever `sw_postlist_formatters` adds. */
	public function formatters(): FormatterRegistry {
		if ( null === $this->formatters ) {
			$formatters = array_merge( BuiltinFormatters::all(), array( new ImageFormatter(), new MapFormatter( $this->value_maps() ) ) );
			/**
			 * Registers formatters (objects implementing FormatterInterface). Faulty registrations are reported in the
			 * diagnostics and ignored.
			 *
			 * @since 0.1.0
			 *
			 * @param list<\SW\Postlist\TemplateEngine\Port\FormatterInterface> $formatters Registered formatters.
			 */
			$this->formatters = new FormatterRegistry( apply_filters( 'sw_postlist_formatters', $formatters ) );
		}
		return $this->formatters;
	}

	/** The named maps of the settings (see docs/templates.md, formatter `map`). */
	private function value_maps(): ValueMaps {
		$settings = get_option( MetaAllowlist::SETTINGS_OPTION, array() );
		return new ValueMaps( ValueMaps::sanitize( is_array( $settings ) ? ( $settings['maps'] ?? array() ) : array() ) );
	}

	public function engine(): Engine {
		return $this->engine ??= new Engine( $this->cache ?? new WpAstCache() );
	}

	public function context( EntrySourceInterface $source, string $role ): CompileContext {
		return new CompileContext( EngineProfile::postlist(), $this->placeholders->for_source( $source ), $this->formatters(), $role );
	}

	/** Cache generations of the source and the template, as part of the cache key. */
	public function generations( EntrySourceInterface $source, int|string $template_id ): string {
		return $this->generations->key( $source->id(), $template_id );
	}

	public function renderer(): Renderer {
		$escaper = new WpEscaper();
		return new Renderer( $this->formatters(), $escaper, new FormatContext( new WpLocale(), new WpClock(), $escaper ) );
	}

	public function entry_resolver( Entry $entry, EntrySourceInterface $source ): ValueResolverInterface {
		return new EntryValueResolver( $entry, $this->meta->keys( $source->id() ), $this->content );
	}
}
