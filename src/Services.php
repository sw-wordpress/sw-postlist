<?php
/**
 * Service wiring. The only place that composes concrete implementations.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist;

use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Filter\FilterTypeRegistry;
use SW\Postlist\Application\Layout\LayoutRegistry;
use SW\Postlist\Application\Package\PackageService;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Application\Port\ClockInterface;
use SW\Postlist\Application\Port\LoggerInterface;
use SW\Postlist\Application\Port\TemplateRepositoryInterface;
use SW\Postlist\Application\Port\ViewRepositoryInterface;
use SW\Postlist\Application\Query\QueryService;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Infrastructure\WordPress\DocumentStore;
use SW\Postlist\Infrastructure\WordPress\Engine\AstGenerations;
use SW\Postlist\Infrastructure\WordPress\Engine\AstInvalidator;
use SW\Postlist\Infrastructure\WordPress\Engine\MetaAllowlist;
use SW\Postlist\Infrastructure\WordPress\Engine\PlaceholderRegistry;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateCompilation;
use SW\Postlist\Infrastructure\WordPress\Engine\TemplateEngineFactory;
use SW\Postlist\Infrastructure\WordPress\SourceRegistryFactory;
use SW\Postlist\Application\Preset\PresetValidator;
use SW\Postlist\Infrastructure\WordPress\PresetRepository;
use SW\Postlist\Infrastructure\WordPress\Render\ViewRenderer;
use SW\Postlist\Infrastructure\WordPress\TemplateUsageGuard;
use SW\Postlist\Infrastructure\WordPress\WpEntryExtension;
use SW\Postlist\Infrastructure\WordPress\WpLogger;
use SW\Postlist\Infrastructure\WordPress\WpTemplateRepository;
use SW\Postlist\Infrastructure\WordPress\WpViewRepository;
use SW\Postlist\Support\SystemClock;

final class Services {

	private static ?Services $instance = null;

	private ?ClockInterface $clock                         = null;
	private ?LoggerInterface $logger                       = null;
	private ?DocumentStore $store                          = null;
	private ?ViewDocumentValidator $view_validator         = null;
	private ?LayoutRegistry $layout_registry               = null;
	private ?FilterTypeRegistry $filter_type_registry      = null;
	private ?TemplateDocumentValidator $template_validator = null;
	private ?TemplateRepositoryInterface $templates        = null;
	private ?ViewRepositoryInterface $views                = null;
	private ?SourceRegistry $registry                      = null;
	private ?QueryService $query_service                   = null;
	private ?TemplateEngineFactory $engine_factory         = null;
	private ?PlaceholderRegistry $placeholders             = null;
	private ?AstGenerations $generations                   = null;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	/** Test seam: drops the shared instance. */
	public static function reset(): void {
		self::$instance = null;
	}

	public function clock(): ClockInterface {
		return $this->clock ??= new SystemClock();
	}

	public function logger(): LoggerInterface {
		return $this->logger ??= new WpLogger();
	}

	public function store(): DocumentStore {
		return $this->store ??= new DocumentStore();
	}

	public function layouts(): LayoutRegistry {
		/**
		 * Registers layouts (LayoutDefinition objects with option schema, optional module and render callback).
		 * Faulty registrations are reported in the diagnostics and ignored.
		 *
		 * @since 0.1.0
		 *
		 * @param list<\SW\Postlist\Application\Layout\LayoutDefinition> $layouts Additional layouts.
		 */
		return $this->layout_registry ??= new LayoutRegistry( apply_filters( 'sw_postlist_layouts', array() ) );
	}

	public function filter_types(): FilterTypeRegistry {
		/**
		 * Registers visitor filter types (FilterTypeDefinition objects with option schema, parse and render callbacks).
		 * Faulty registrations are reported in the diagnostics and ignored.
		 *
		 * @since 0.7.0
		 *
		 * @param list<\SW\Postlist\Application\Filter\FilterTypeDefinition> $types Additional filter types.
		 */
		return $this->filter_type_registry ??= new FilterTypeRegistry( apply_filters( 'sw_postlist_filter_types', array() ) );
	}

	public function view_validator(): ViewDocumentValidator {
		return $this->view_validator ??= new ViewDocumentValidator( $this->layouts(), null, $this->filter_types() );
	}

	public function template_validator(): TemplateDocumentValidator {
		return $this->template_validator ??= new TemplateDocumentValidator();
	}

	public function templates(): TemplateRepositoryInterface {
		return $this->templates ??= new WpTemplateRepository( $this->store(), $this->template_validator() );
	}

	public function views(): ViewRepositoryInterface {
		return $this->views ??= new WpViewRepository( $this->store(), $this->view_validator(), $this->templates() );
	}

	public function usage_guard(): TemplateUsageGuard {
		return new TemplateUsageGuard( fn (): ViewRepositoryInterface => $this->views(), fn (): DocumentStore => $this->store(), fn (): ViewDocumentValidator => $this->view_validator() );
	}

	/** Built lazily after `init`, when all post types are registered. */
	public function sources(): SourceRegistry {
		return $this->registry ??= ( new SourceRegistryFactory() )->create();
	}

	public function query(): QueryService {
		return $this->query_service ??= new QueryService( $this->sources(), new WpEntryExtension(), $this->logger() );
	}

	public function generations(): AstGenerations {
		return $this->generations ??= new AstGenerations();
	}

	public function placeholders(): PlaceholderRegistry {
		return $this->placeholders ??= new PlaceholderRegistry( new MetaAllowlist() );
	}

	public function engine(): TemplateEngineFactory {
		return $this->engine_factory ??= new TemplateEngineFactory( $this->placeholders(), new MetaAllowlist(), $this->generations() );
	}

	public function preset_validator(): PresetValidator {
		return new PresetValidator( $this->view_validator(), $this->template_validator() );
	}

	public function presets(): PresetRepository {
		return new PresetRepository( $this->preset_validator(), SW_POSTLIST_DIR . 'templates/presets' );
	}

	public function packages(): PackageService {
		$compilation = $this->template_compilation();
		$sources     = $this->sources();
		return new PackageService(
			$this->views(),
			$this->templates(),
			$this->presets(),
			$this->view_validator(),
			$this->template_validator(),
			$this->preset_validator(),
			$sources,
			static function ( TemplateDocument $document, string $source_id ) use ( $compilation, $sources ): array {
				$source = $sources->get( $source_id );
				if ( null === $source ) {
					return array();
				}
				$findings = array();
				foreach ( $compilation->validate( $document, $source )->errors as $error ) {
					$findings[] = array(
						'code'    => $error->code,
						'message' => $error->message,
					);
				}
				return $findings;
			},
			SW_POSTLIST_VERSION,
			static fn (): string => gmdate( 'c' )
		);
	}

	public function renderer(): ViewRenderer {
		return new ViewRenderer( $this->views(), $this->templates(), $this->sources(), $this->query(), $this->engine(), $this->view_validator(), $this->layouts(), $this->filter_types(), $this->logger() );
	}

	public function template_compilation(): TemplateCompilation {
		return new TemplateCompilation( $this->templates(), $this->engine() );
	}

	public function ast_invalidator(): AstInvalidator {
		return new AstInvalidator( $this->generations(), new MetaAllowlist() );
	}

	/**
	 * Findings of faulty extension registrations for the diagnostics.
	 *
	 * @return list<array{code: string, message: string, source: string}>
	 */
	public function diagnostics(): array {
		$presets = $this->presets();
		$presets->all();
		return array_merge( $this->sources()->diagnostics(), $this->engine()->formatters()->diagnostics(), $this->placeholders()->diagnostics(), $presets->diagnostics(), $this->layouts()->diagnostics(), $this->filter_types()->diagnostics() );
	}
}
