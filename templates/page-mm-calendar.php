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
	the_content();
}

get_footer();
