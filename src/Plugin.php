<?php
/**
 * Plugin bootstrap: wires WordPress hooks. Business logic lives in services.
 *
 * @package sw-postlist
 */

declare(strict_types=1);

namespace SW\Postlist;

use SW\Postlist\Infrastructure\WordPress\Capabilities;
use SW\Postlist\Infrastructure\WordPress\PostTypes;
use SW\Postlist\Presentation\Admin\AdminPage;
use SW\Postlist\Presentation\Block\ViewBlock;
use SW\Postlist\Presentation\Frontend\FrontendAssets;
use SW\Postlist\Presentation\Rest\AdminPackageController;
use SW\Postlist\Presentation\Rest\AdminPresetsController;
use SW\Postlist\Presentation\Rest\AdminSystemController;
use SW\Postlist\Presentation\Rest\PublicViewController;
use SW\Postlist\Presentation\Rest\AdminTemplatesController;
use SW\Postlist\Presentation\Rest\AdminViewsController;
use SW\Postlist\Presentation\Rest\ViewsController;
use SW\Postlist\Presentation\Shortcode\PostlistShortcode;

final class Plugin {

	private static ?Plugin $instance = null;

	private bool $booted = false;

	public static function instance(): self {
		return self::$instance ??= new self();
	}

	public function boot(): void {
		if ( $this->booted ) {
			return;
		}
		$this->booted = true;

		add_action( 'init', array( $this, 'load_textdomain' ), 1 );
		add_action( 'init', array( new PostTypes(), 'register' ) );
		add_action( 'init', array( $this, 'register_frontend' ) );
		add_action( 'rest_api_init', array( $this, 'register_rest' ) );
		add_action( 'wp_enqueue_scripts', array( FrontendAssets::class, 'register' ) );
		( new Capabilities() )->register();
		Services::instance()->usage_guard()->register();
		Services::instance()->ast_invalidator()->register();

		if ( is_admin() ) {
			( new AdminPage() )->register();
		}
	}

	public function load_textdomain(): void {
		load_plugin_textdomain( 'sw-postlist', false, dirname( plugin_basename( SW_POSTLIST_FILE ) ) . '/languages' );
	}

	public function register_frontend(): void {
		$renderer = Services::instance()->renderer();
		( new PostlistShortcode( $renderer ) )->register();
		( new ViewBlock( $renderer ) )->register();
	}

	public function register_rest(): void {
		$services = Services::instance();
		( new ViewsController( $services->renderer() ) )->register();
		( new PublicViewController( $services->renderer() ) )->register();
		( new AdminPackageController( $services->packages(), $services->views(), $services->templates() ) )->register();
		( new AdminViewsController( $services->views(), $services->templates(), $services->view_validator(), $services->renderer(), $services->template_compilation(), $services->sources() ) )->register();
		( new AdminTemplatesController( $services->templates(), $services->views(), $services->template_validator(), $services->template_compilation(), $services->sources(), $services->renderer() ) )->register();
		( new AdminPresetsController( $services->presets(), $services->preset_validator(), $services->templates(), $services->template_compilation(), $services->sources() ) )->register();
		( new AdminSystemController( $services->sources(), $services->engine(), fn (): array => $services->diagnostics(), $services->layouts(), $services->filter_types() ) )->register();
	}
}
