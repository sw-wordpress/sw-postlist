<?php
// Seeds the disposable E2E site through the plugin's own services: the M3 acceptance content.
define( 'WP_INSTALLING', false );
$_SERVER['HTTP_HOST']   = '127.0.0.1:8090';
$_SERVER['REQUEST_URI'] = '/';
require getenv( 'SWP_E2E_WP' ) . '/wp-load.php';

$services = \SW\Postlist\Services::instance();
$category = wp_insert_term( 'Match', 'category' );
$term_id  = (int) $category['term_id'];

foreach ( range( 1, 6 ) as $number ) {
	wp_insert_post(
		array(
			'post_title'    => 'Published ' . $number,
			'post_status'   => 'publish',
			'post_category' => array( $term_id ),
			'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '2026-01-0' . $number . ' 10:00:00' ) ),
		)
	);
}
wp_insert_post( array( 'post_title' => 'Draft entry', 'post_status' => 'draft', 'post_category' => array( $term_id ) ) );
wp_insert_post( array( 'post_title' => 'Private entry', 'post_status' => 'private', 'post_category' => array( $term_id ) ) );
wp_insert_post( array( 'post_title' => 'Protected entry', 'post_status' => 'publish', 'post_password' => 'secret', 'post_category' => array( $term_id ) ) );

$raw    = array(
	'schema_version' => 1,
	'title'          => 'Acceptance',
	'source'         => 'post:post',
	'query'          => array(
		'per_page'   => 2,
		'offset'     => 1,
		'orderby'    => 'date',
		'order'      => 'asc',
		'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $term_id ), 'operator' => 'in' ) ),
	),
	'layout'         => array( 'type' => 'grid' ),
	'pagination'     => array( 'mode' => 'classic' ),
	'templates'      => array(),
);
$result = $services->view_validator()->validate( $raw );
if ( ! $result->is_valid() ) {
	fwrite( STDERR, 'Seed view is invalid' );
	exit( 1 );
}
$view_id = $services->views()->save( null, $result->value() );

wp_insert_post(
	array(
		'post_title'   => 'Two lists',
		'post_name'    => 'two-lists',
		'post_status'  => 'publish',
		'post_type'    => 'page',
		'post_content' => '[sw_postlist id="' . $view_id . '"] [sw_postlist id="' . $view_id . '"]',
	)
);
// M6: one page per layout, so that the pages prove which modules they load.
$layout_pages = array(
	'layout-masonry' => array( 'type' => 'masonry', 'columns' => array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ) ),
	'layout-hover'   => array( 'type' => 'hover_grid' ),
	'layout-slider'  => array( 'type' => 'slider', 'slides' => array( 'desktop' => 3, 'tablet' => 2, 'mobile' => 1 ) ),
	'layout-autoplay' => array( 'type' => 'slider', 'autoplay' => true, 'interval' => 2000, 'loop' => true, 'slides' => array( 'desktop' => 2, 'tablet' => 2, 'mobile' => 1 ) ),
);
foreach ( $layout_pages as $slug => $layout ) {
	$layout_view = $services->view_validator()->validate(
		array(
			'schema_version' => 1,
			'title'          => $slug,
			'source'         => 'post:post',
			'query'          => array( 'per_page' => 6, 'orderby' => 'date', 'order' => 'asc', 'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $term_id ), 'operator' => 'in' ) ) ),
			'layout'         => $layout,
			'pagination'     => array( 'mode' => 'none' ),
			'templates'      => array(),
		)
	);
	if ( ! $layout_view->is_valid() ) {
		fwrite( STDERR, 'Layout view is invalid: ' . $slug );
		exit( 1 );
	}
	$layout_view_id = $services->views()->save( null, $layout_view->value() );
	wp_insert_post( array( 'post_title' => $slug, 'post_name' => $slug, 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[sw_postlist id="' . $layout_view_id . '"]' ) );
}
// M7: dynamic views over ten entries in two categories (Match 1-6, Other 1-4), three per page.
$other      = wp_insert_term( 'Other', 'category' );
$other_id   = (int) $other['term_id'];
foreach ( range( 1, 4 ) as $number ) {
	wp_insert_post(
		array(
			'post_title'    => 'Other ' . $number,
			'post_status'   => 'publish',
			'post_category' => array( $other_id ),
			'post_date'     => gmdate( 'Y-m-d H:i:s', strtotime( '2026-02-0' . $number . ' 10:00:00' ) ),
		)
	);
}
$dynamic_pages = array(
	'dyn-classic'  => 'classic',
	'dyn-ajax'     => 'ajax',
	'dyn-more'     => 'load_more',
	'dyn-infinite' => 'infinite',
);
$dynamic_ids   = array();
foreach ( $dynamic_pages as $slug => $mode ) {
	$dynamic_view = $services->view_validator()->validate(
		array(
			'schema_version' => 1,
			'title'          => $slug,
			'source'         => 'post:post',
			'query'          => array( 'per_page' => 3, 'orderby' => 'date', 'order' => 'asc', 'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => array( $term_id, $other_id ), 'operator' => 'in' ) ) ),
			'layout'         => array( 'type' => 'list' ),
			'pagination'     => array( 'mode' => $mode ),
			'filters'        => array(
				array( 'type' => 'taxonomy', 'taxonomy' => 'category', 'display' => 'buttons', 'terms' => array( $term_id, $other_id ) ),
				array( 'type' => 'search' ),
			),
			'templates'      => array(),
		)
	);
	if ( ! $dynamic_view->is_valid() ) {
		fwrite( STDERR, 'Dynamic view is invalid: ' . $slug );
		exit( 1 );
	}
	$dynamic_id           = $services->views()->save( null, $dynamic_view->value() );
	$dynamic_ids[ $slug ] = $dynamic_id;
	wp_insert_post( array( 'post_title' => $slug, 'post_name' => $slug, 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[sw_postlist id="' . $dynamic_id . '"]' ) );
}
wp_insert_post( array( 'post_title' => 'dyn-two', 'post_name' => 'dyn-two', 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[sw_postlist id="' . $dynamic_ids['dyn-ajax'] . '"] [sw_postlist id="' . $dynamic_ids['dyn-ajax'] . '"]' ) );
// M9 presets: a showcase page per bundled preset with generated images, four posts and two categories.
require_once ABSPATH . 'wp-admin/includes/image.php';
require_once ABSPATH . 'wp-admin/includes/file.php';
require_once ABSPATH . 'wp-admin/includes/media.php';
$upload = wp_upload_dir();
$cat_a  = wp_insert_term( 'Team', 'category' );
$cat_b  = wp_insert_term( 'Projekte', 'category' );
$cats   = array( (int) $cat_a['term_id'], (int) $cat_b['term_id'] );
$people = array( 'Anna Keller', 'Jonas Brandt', 'Mira Schulz', 'Tim Hoffmann' );
$titles = array( 'Was gutes Onboarding wirklich ausmacht', 'Werkstattbericht: Ein Tag mit dem Serviceteam', 'Nachhaltig planen, ohne den Überblick zu verlieren', 'Warum wir weniger Meetings machen' );
$colors = array( array( 220, 235, 225, 31, 122, 77 ), array( 230, 226, 245, 255, 138, 101 ), array( 243, 235, 223, 224, 125, 59 ), array( 235, 235, 235, 50, 50, 60 ) );
foreach ( $titles as $index => $title ) {
	$user = wp_insert_user( array( 'user_login' => 'author' . $index, 'user_pass' => 'x-' . $index . '-pass', 'display_name' => $people[ $index ], 'role' => 'author' ) );
	$post = wp_insert_post( array( 'post_title' => $title, 'post_status' => 'publish', 'post_author' => $user, 'post_category' => array( $cats[ $index % 2 ], $cats[ ( $index + 1 ) % 2 ] ), 'post_excerpt' => 'Die ersten Wochen entscheiden, wie neue Kolleginnen und Kollegen ankommen. Wir teilen, was bei uns funktioniert und was wir unterwegs geändert haben.', 'post_date' => gmdate( 'Y-m-d H:i:s', strtotime( '2026-11-1' . ( 8 - $index ) . ' 10:00:00' ) ) ) );
	$img    = imagecreatetruecolor( 800, 600 );
	list( $br, $bg, $bb, $fr, $fg, $fb ) = $colors[ $index ];
	imagefill( $img, 0, 0, imagecolorallocate( $img, $br, $bg, $bb ) );
	imagefilledellipse( $img, 560 + $index * 20, 170, 360, 360, imagecolorallocate( $img, $fr, $fg, $fb ) );
	imagefilledrectangle( $img, 80, 360, 420, 440, imagecolorallocate( $img, 245, 196, 80 ) );
	$file = $upload['path'] . '/preset-sample-' . $index . '.png';
	imagepng( $img, $file );
	$attachment = wp_insert_attachment( array( 'post_mime_type' => 'image/png', 'post_title' => 'sample ' . $index, 'post_status' => 'inherit' ), $file, $post );
	wp_update_attachment_metadata( $attachment, wp_generate_attachment_metadata( $attachment, $file ) );
	set_post_thumbnail( $post, $attachment );
}
$preset_pages = array();
foreach ( glob( dirname( __DIR__, 2 ) . '/templates/presets/*.json' ) ?: array() as $preset_file ) {
	$preset = json_decode( (string) file_get_contents( $preset_file ), true );
	if ( ! is_array( $preset ) ) {
		continue;
	}
	$container = $services->templates()->save( null, new \SW\Postlist\Domain\Template\TemplateDocument( $preset['title'] . ' container', \SW\Postlist\Domain\Template\TemplateRole::Container, $preset['container']['html'], $preset['container']['css'] ) );
	$entry     = $services->templates()->save( null, new \SW\Postlist\Domain\Template\TemplateDocument( $preset['title'] . ' entry', \SW\Postlist\Domain\Template\TemplateRole::Entry, $preset['entry']['html'], $preset['entry']['css'] ) );
	$check     = $services->view_validator()->validate(
		array(
			'schema_version' => 1,
			'title'          => 'Preset ' . $preset['id'],
			'source'         => 'post:post',
			'query'          => array( 'per_page' => 4, 'orderby' => 'date', 'order' => 'desc', 'taxonomies' => array( array( 'taxonomy' => 'category', 'terms' => $cats, 'operator' => 'in' ) ) ),
			'layout'         => $preset['layout'],
			'pagination'     => array( 'mode' => 'classic' ),
			'filters'        => array( array( 'type' => 'taxonomy', 'taxonomy' => 'category', 'display' => 'buttons', 'terms' => $cats ) ),
			'templates'      => array( 'container' => $container, 'entry' => $entry ),
		)
	);
	if ( ! $check->is_valid() ) {
		fwrite( STDERR, 'Preset view invalid: ' . $preset['id'] );
		exit( 1 );
	}
	$preset_view = $services->views()->save( null, $check->value() );
	wp_insert_post( array( 'post_title' => 'preset-' . $preset['id'], 'post_name' => 'preset-' . $preset['id'], 'post_status' => 'publish', 'post_type' => 'page', 'post_content' => '[sw_postlist id="' . $preset_view . '"]' ) );
	$preset_pages[] = $preset['id'];
}
file_put_contents( dirname( getenv( 'SWP_E2E_WP' ) ) . '/seed.json', wp_json_encode( array( 'view_id' => $view_id, 'term_id' => $term_id, 'other_id' => $other_id, 'dynamic' => $dynamic_ids, 'presets' => $preset_pages ) ) ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- E2E helper.
echo "seeded view {$view_id}\n";
