<?php
/**
 * Template Name: Metamanager — About Page
 *
 * Auto-generated about page from business profile settings.
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
