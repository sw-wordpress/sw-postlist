<?php
/**
 * Minimal classic theme template: the_content() of pages and posts, wp_head and wp_footer as every theme must have.
 */
?><!doctype html>
<html <?php language_attributes(); ?>>
<head><meta charset="<?php bloginfo( 'charset' ); ?>"><meta name="viewport" content="width=device-width, initial-scale=1"><?php wp_head(); ?></head>
<body <?php body_class(); ?>>
<header><a href="<?php echo esc_url( home_url( '/' ) ); ?>"><?php bloginfo( 'name' ); ?></a></header>
<main>
<?php
while ( have_posts() ) :
	the_post();
	?>
	<article><h1><?php the_title(); ?></h1><?php the_content(); ?></article>
<?php endwhile; ?>
</main>
<?php wp_footer(); ?>
</body>
</html>
