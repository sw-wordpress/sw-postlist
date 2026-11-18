<?php
/**
 * Package export and import: references with package-local keys, validation before the first write, publication only
 * with complete references and rollback of partial imports.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist\Tests\Unit;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use SW\Postlist\Application\Document\TemplateDocumentValidator;
use SW\Postlist\Application\Document\ViewDocumentValidator;
use SW\Postlist\Application\Package\PackageService;
use SW\Postlist\Application\Preset\PresetValidator;
use SW\Postlist\Application\Query\SourceRegistry;
use SW\Postlist\Domain\Preset\Preset;
use SW\Postlist\Domain\Template\TemplateDocument;
use SW\Postlist\Domain\Template\TemplateRole;
use SW\Postlist\Domain\View\ViewDocument;
use SW\Postlist\Tests\Support\FailingPresets;
use SW\Postlist\Tests\Support\FakeSource;
use SW\Postlist\Tests\Support\MemoryPresets;
use SW\Postlist\Tests\Support\MemoryTemplates;
use SW\Postlist\Tests\Support\MemoryViews;

final class PackageServiceTest extends TestCase {

	private MemoryViews $views;
	private MemoryTemplates $templates;
	private MemoryPresets $presets;

	/** @var list<string> Template titles for which the check reports a language error. */
	private array $broken = array();

	protected function setUp(): void {
		$this->views     = new MemoryViews();
		$this->templates = new MemoryTemplates();
		$this->presets   = new MemoryPresets();
		$this->broken    = array();
	}

	private function service(): PackageService {
		$views     = new ViewDocumentValidator();
		$templates = new TemplateDocumentValidator();
		return new PackageService(
			$this->views,
			$this->templates,
			$this->presets,
			$views,
			$templates,
			new PresetValidator( $views, $templates ),
			new SourceRegistry( array( new FakeSource( 'post:post' ), new FakeSource( 'post:page' ) ) ),
			fn ( TemplateDocument $document, string $source ): array => in_array( $document->title, $this->broken, true ) ? array(
				array(
					'code'    => 'unknown_placeholder',
					'message' => 'bad',
				),
			) : array(),
			'0.8.0',
			static fn (): string => '2026-11-18T10:00:00+00:00'
		);
	}

	private function template( string $title, TemplateRole $role = TemplateRole::Entry ): int {
		return $this->templates->save( null, new TemplateDocument( $title, $role, '<p>{{entry.title}}</p>', '.x{color:red}' ) );
	}

	private function view( string $title, ?int $container, ?int $entry, string $status = 'publish', string $source = 'post:post' ): int {
		$result = ( new ViewDocumentValidator() )->validate(
			array(
				'schema_version' => 1,
				'title'          => $title,
				'source'         => $source,
				'layout'         => array( 'type' => 'grid' ),
				'pagination'     => array( 'mode' => 'classic' ),
				'templates'      => array(
					'container' => $container,
					'entry'     => $entry,
				),
				'filters'        => array( array( 'type' => 'search' ) ),
			)
		);
		$this->assertTrue( $result->is_valid() );
		$document = $result->value();
		$this->assertInstanceOf( ViewDocument::class, $document );
		return $this->views->save( null, $document, $status );
	}

	/**
	 * @param array<string, mixed> $override Replacement entries of the valid package.
	 * @return array<string, mixed>
	 */
	private function package( array $override = array() ): array {
		$container = $this->template( 'Frame', TemplateRole::Container );
		$entry     = $this->template( 'Card' );
		$this->view( 'News', $container, $entry );
		$this->view( 'Events', null, $entry, 'draft' );
		$package = $this->service()->export( array( 11, 12 ) )['package'];
		$package = json_decode( (string) json_encode( $package ), true );
		$this->setUp();
		return array_replace( $package, $override );
	}

	public function test_export_includes_every_template_once_with_package_local_keys(): void {
		$container = $this->template( 'Frame', TemplateRole::Container );
		$shared    = $this->template( 'Card' );
		$extra     = $this->template( 'Unused' );
		$one       = $this->view( 'News', $container, $shared );
		$two       = $this->view( 'Events', null, $shared, 'draft' );
		$this->presets->save_user(
			new Preset(
				'mine',
				'Mine',
				'',
				array( 'type' => 'grid' ),
				array(
					'html' => '<div>{{view.entries}}</div>',
					'css'  => '',
				),
				array(
					'html' => '<p>{{entry.title}}</p>',
					'css'  => '',
				)
			)
		);

		$export  = $this->service()->export( array( $one, $two, $one ), array( $extra, $shared ), array( 'mine' ) );
		$package = json_decode( (string) json_encode( $export['package'] ), true );

		$this->assertSame( array(), $export['missing'] );
		$this->assertSame( 'sw-postlist-package', $package['format'] );
		$this->assertSame( 1, $package['format_version'] );
		$this->assertSame( '0.8.0', $package['plugin_version'] );
		$this->assertSame( array( 't1', 't2', 't3' ), array_keys( $package['templates'] ), 'Three templates, the shared one only once.' );
		$this->assertSame(
			array(
				'container' => 't1',
				'entry'     => 't2',
			),
			$package['views'][0]['document']['templates']
		);
		$this->assertSame(
			array(
				'container' => null,
				'entry'     => 't2',
			),
			$package['views'][1]['document']['templates']
		);
		$this->assertSame( array( 'publish', 'draft' ), array_column( $package['views'], 'status' ) );
		$this->assertSame( array( 'mine' ), array_column( $package['presets'], 'id' ) );
		$this->assertStringNotContainsString( '"entry":101', (string) json_encode( $package ), 'No local IDs leave the site.' );
	}

	public function test_export_reports_missing_items(): void {
		$export = $this->service()->export( array( 999 ), array( 998 ), array( 'ghost' ) );
		$this->assertSame( array( 'view:999', 'template:998', 'preset:ghost' ), $export['missing'] );
		$this->assertSame( array(), $export['package']['views'] );
	}

	public function test_roundtrip_creates_new_ids_keeps_sharing_and_publishes_last(): void {
		$package = $this->package();
		$report  = $this->service()->import( $package );

		$this->assertTrue( $report->ok, (string) json_encode( $report->errors ) );
		$this->assertSame( 2, $report->counts['views'] );
		$this->assertCount( 2, $report->created['views'] );
		$this->assertCount( 2, $report->created['templates'] );
		list( $news, $events ) = $report->created['views'];
		$this->assertSame( 'publish', $this->views->statuses[ $news ] );
		$this->assertSame( 'draft', $this->views->statuses[ $events ] );
		$this->assertSame( $this->views->items[ $news ]->entry_template_id, $this->views->items[ $events ]->entry_template_id, 'The shared template stays shared.' );
		$this->assertSame( array( $news, $events ), $this->views->view_ids_using_template( (int) $this->views->items[ $news ]->entry_template_id ) );
		$this->assertCount( 1, $this->views->items[ $news ]->filters );
		$this->assertSame( array( 'save:11:draft', 'save:12:draft', 'save:11:publish' ), $this->views->log, 'Drafts first, then publication.' );
	}

	public function test_name_collisions_are_renamed_not_overwritten(): void {
		$package = $this->package();
		$this->service()->import( $package );
		$report = $this->service()->import( $package );
		$this->assertTrue( $report->ok );
		$titles = array_column( $this->views->summaries(), 'title' );
		$this->assertSame( array( 'News', 'Events', 'News (2)', 'Events (2)' ), $titles );
		$this->assertContains( 'Frame → Frame (2)', $report->renamed );
		$this->assertContains( 'News → News (2)', $report->renamed );
	}

	public function test_presets_get_a_new_id_when_taken(): void {
		$preset  = array(
			'schema_version' => 1,
			'id'             => 'mine',
			'title'          => 'Mine',
			'description'    => '',
			'layout'         => array( 'type' => 'grid' ),
			'container'      => array(
				'html' => '<div>{{view.entries}}</div>',
				'css'  => '',
			),
			'entry'          => array(
				'html' => '<p>{{entry.title}}</p>',
				'css'  => '',
			),
		);
		$package = array(
			'format'         => 'sw-postlist-package',
			'format_version' => 1,
			'presets'        => array( $preset ),
		);
		$first   = $this->service()->import( $package );
		$second  = $this->service()->import( $package );
		$this->assertSame( array( 'mine' ), $first->created['presets'] );
		$this->assertSame( array( 'mine-2' ), $second->created['presets'] );
		$this->assertSame( array( 'mine → mine-2' ), $second->renamed );
		$long                     = $package;
		$long['presets'][0]['id'] = str_repeat( 'a', 40 );
		$this->service()->import( $long );
		$this->assertSame( 40, strlen( $this->service()->import( $long )->created['presets'][0] ) );
	}

	public function test_dry_run_validates_and_writes_nothing(): void {
		$report = $this->service()->import( $this->package(), true );
		$this->assertTrue( $report->ok );
		$this->assertTrue( $report->dry_run );
		$this->assertSame(
			array(
				'views'     => 2,
				'templates' => 2,
				'presets'   => 0,
			),
			$report->counts
		);
		$this->assertSame( array(), $this->views->items );
		$this->assertSame( array(), $this->templates->items );
		$this->assertSame( array(), $report->created['views'] );
		$this->assertSame( true, $report->to_array()['dry_run'] );
	}

	/**
	 * @return array<string, array{0: callable(array<string, mixed>): array<string, mixed>, 1: string}>
	 */
	public static function broken_packages(): array {
		return array(
			'not an array'          => array( static fn (): array => array(), 'package_format_invalid' ),
			'wrong format'          => array( static fn ( array $p ): array => array_replace( $p, array( 'format' => 'other' ) ), 'package_format_invalid' ),
			'missing version'       => array(
				static function ( array $p ): array {
						unset( $p['format_version'] );
						return $p;
				},
				'package_version_invalid',
			),
			'string version'        => array( static fn ( array $p ): array => array_replace( $p, array( 'format_version' => '1' ) ), 'package_version_invalid' ),
			'zero version'          => array( static fn ( array $p ): array => array_replace( $p, array( 'format_version' => 0 ) ), 'package_version_invalid' ),
			'newer version'         => array( static fn ( array $p ): array => array_replace( $p, array( 'format_version' => 2 ) ), 'package_version_unsupported' ),
			'sections not arrays'   => array( static fn ( array $p ): array => array_replace( $p, array( 'views' => 'x' ) ), 'package_sections_invalid' ),
			'empty'                 => array(
				static fn ( array $p ): array => array_replace(
					$p,
					array(
						'views'     => array(),
						'templates' => array(),
						'presets'   => array(),
					)
				),
				'package_empty',
			),
			'too many views'        => array( static fn ( array $p ): array => array_replace( $p, array( 'views' => array_fill( 0, 101, $p['views'][0] ) ) ), 'package_too_large' ),
			'bad template key'      => array( static fn ( array $p ): array => array_replace( $p, array( 'templates' => array( 'Bad Key' => $p['templates']['t1'] ) ) ), 'template_key_invalid' ),
			'template invalid'      => array(
				static function ( array $p ): array {
						$p['templates']['t1']['role'] = 'sidebar';
						return $p;
				},
				'role_invalid',
			),
			'view without document' => array( static fn ( array $p ): array => array_replace( $p, array( 'views' => array( array( 'key' => 'v1' ) ) ) ), 'view_entry_invalid' ),
			'reference missing'     => array(
				static function ( array $p ): array {
						$p['views'][0]['document']['templates']['entry'] = 't99';
						return $p;
				},
				'template_reference_missing',
			),
			'reference is an ID'    => array(
				static function ( array $p ): array {
						$p['views'][0]['document']['templates']['entry'] = 5;
						return $p;
				},
				'template_reference_missing',
			),
			'role mismatch'         => array(
				static function ( array $p ): array {
						$p['views'][0]['document']['templates']['entry'] = 't1';
						return $p;
				},
				'template_role_mismatch',
			),
			'view invalid'          => array(
				static function ( array $p ): array {
						$p['views'][0]['document']['layout'] = array( 'type' => 'nope' );
						return $p;
				},
				'layout_invalid',
			),
			'view from the future'  => array(
				static function ( array $p ): array {
						$p['views'][0]['document']['schema_version'] = 99;
						return $p;
				},
				'schema_version_newer',
			),
			'unknown source'        => array(
				static function ( array $p ): array {
						$p['views'][0]['document']['source'] = 'plugin:events';
						return $p;
				},
				'source_unknown',
			),
			'template language'     => array(
				static function ( array $p ): array {
						$p['templates']['t2']['title'] = 'Broken';
						return $p;
				},
				'unknown_placeholder',
			),
			'preset invalid'        => array(
				static fn ( array $p ): array => array_replace(
					$p,
					array(
						'presets' => array(
							array(
								'schema_version' => 1,
								'id'             => 'BAD ID',
							),
						),
					)
				),
				'preset_id_invalid',
			),
			'preset not an object'  => array( static fn ( array $p ): array => array_replace( $p, array( 'presets' => array( 'x' ) ) ), 'preset_invalid' ),
		);
	}

	/**
	 * @param callable(array<string, mixed>): array<string, mixed> $break Makes the package faulty.
	 */
	#[DataProvider( 'broken_packages' )]
	public function test_faulty_packages_are_refused_before_anything_is_written( callable $break, string $code ): void {
		$package      = $break( $this->package() );
		$this->broken = array( 'Broken' );
		$report       = $this->service()->import( $package );
		$this->assertFalse( $report->ok );
		$this->assertContains( $code, array_column( $report->errors, 'code' ), (string) json_encode( $report->errors ) );
		$this->assertSame( array(), $this->views->items, 'No view was created.' );
		$this->assertSame( array(), $this->templates->items, 'No template was created.' );
		$this->assertSame( array(), $this->presets->items );
		$this->assertSame( array(), $report->created['views'] );
	}

	public function test_preset_duplicates_in_one_package_are_refused(): void {
		$preset = array(
			'schema_version' => 1,
			'id'             => 'twice',
			'title'          => 'Twice',
			'layout'         => array( 'type' => 'grid' ),
			'container'      => array(
				'html' => '<div>{{view.entries}}</div>',
				'css'  => '',
			),
			'entry'          => array(
				'html' => '<p>{{entry.title}}</p>',
				'css'  => '',
			),
		);
		$report = $this->service()->import(
			array(
				'format'         => 'sw-postlist-package',
				'format_version' => 1,
				'presets'        => array( $preset, $preset ),
			)
		);
		$this->assertContains( 'preset_id_duplicate', array_column( $report->errors, 'code' ) );
	}

	public function test_a_failing_write_undoes_the_import_and_publishes_nothing(): void {
		$package                   = $this->package();
		$this->views->fail_on_save = 2;
		$report                    = $this->service()->import( $package );

		$this->assertFalse( $report->ok );
		$this->assertSame( 'import_failed', $report->errors[0]['code'] );
		$this->assertStringContainsString( 'disk full', $report->errors[0]['message'] );
		$this->assertSame( array(), $this->views->summaries(), 'The view created before the failure was trashed again.' );
		$this->assertSame( array(), $this->templates->summaries(), 'So were the templates.' );
		$this->assertSame( array( 'save:11:draft' ), $this->views->log, 'Nothing was published.' );
		$this->assertSame( array(), $report->created['views'] );
	}

	public function test_a_failing_preset_write_undoes_views_templates_and_presets(): void {
		$package            = $this->package();
		$package['presets'] = array(
			array(
				'schema_version' => 1,
				'id'             => 'late',
				'title'          => 'Late',
				'layout'         => array( 'type' => 'grid' ),
				'container'      => array(
					'html' => '<div>{{view.entries}}</div>',
					'css'  => '',
				),
				'entry'          => array(
					'html' => '<p>{{entry.title}}</p>',
					'css'  => '',
				),
			),
		);
		$this->presets      = new FailingPresets();
		$report             = $this->service()->import( $package );
		$this->assertFalse( $report->ok );
		$this->assertSame( array(), $this->views->summaries() );
		$this->assertSame( array(), $this->templates->summaries() );
		$this->assertSame( array(), $this->presets->items );
	}
}
