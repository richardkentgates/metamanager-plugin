<?php
/**
 * Template Name: Metamanager — Calendar
 *
 * Auto-generated calendar page from Event posts.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) {
	the_post();
	?><h1 class="entry-title"><?php echo esc_html( get_the_title() ); ?></h1>
	<?php
	remove_filter( 'the_content', 'wpautop' );
	the_content();
	add_filter( 'the_content', 'wpautop' );
}

get_footer();
