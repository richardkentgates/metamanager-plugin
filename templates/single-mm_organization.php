<?php
/**
 * Template: Single Organization
 *
 * Plugin-provided template for mm_organization CPT. No theme dependency.
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
			$saved = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				echo '<div class="mm-organization-details">';
				if ( ! empty( $saved['org_address'] ) ) {
					echo '<p><strong>Address:</strong> ' . esc_html( $saved['org_address'] ) . '</p>';
				}
				if ( ! empty( $saved['org_telephone'] ) ) {
					echo '<p><strong>Phone:</strong> ' . esc_html( $saved['org_telephone'] ) . '</p>';
				}
				if ( ! empty( $saved['org_area_served'] ) ) {
					echo '<p><strong>Area Served:</strong> ' . esc_html( $saved['org_area_served'] ) . '</p>';
				}
				if ( ! empty( $saved['org_url'] ) ) {
					echo '<p><a href="' . esc_url( $saved['org_url'] ) . '" class="button">Visit Website</a></p>';
				}
				echo '</div>';
			}
			the_content();
			?>
		</div>
	</article>
	<?php
}

get_footer();
