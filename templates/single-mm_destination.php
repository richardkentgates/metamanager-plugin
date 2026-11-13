<?php
/**
 * Template: Single Destination (TouristDestination)
 *
 * Plugin-provided template for mm_destination CPT. No theme dependency.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

while ( have_posts() ) {
	the_post();
	?>
	<article id="post-<?php the_ID(); ?>" <?php post_class(); ?>>
		<header class="entry-header">
			<?php if ( has_post_thumbnail() ) : ?>
				<div class="entry-thumbnail">
					<?php the_post_thumbnail( 'large' ); ?>
				</div>
			<?php endif; ?>
			<h1 class="entry-title"><?php the_title(); ?></h1>
		</header>

		<div class="entry-content">
			<?php
			the_content();
			MM_Schema_Post_Types::render_details_table( get_the_ID() );
			?>
		</div>
	</article>
	<?php
}

get_footer();
