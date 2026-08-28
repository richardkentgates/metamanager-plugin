<?php
/**
 * Template Name: Metamanager — Contact Page
 *
 * Auto-generated contact page from business profile settings.
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
