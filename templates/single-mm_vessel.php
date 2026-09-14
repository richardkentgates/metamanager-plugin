<?php
/**
 * Template: Single Vessel (Vehicle)
 *
 * Plugin-provided template for mm_vessel CPT. No theme dependency.
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
				echo '<div class="mm-vessel-specs">';
				if ( ! empty( $saved['vessel_year'] ) ) {
					echo '<p><strong>Year:</strong> ' . esc_html( $saved['vessel_year'] ) . '</p>';
				}
				if ( ! empty( $saved['vessel_type'] ) ) {
					echo '<p><strong>Type:</strong> ' . esc_html( $saved['vessel_type'] ) . '</p>';
				}
				if ( ! empty( $saved['vessel_length'] ) ) {
					echo '<p><strong>Length:</strong> ' . esc_html( $saved['vessel_length'] ) . '</p>';
				}
				if ( ! empty( $saved['vessel_engine'] ) ) {
					echo '<p><strong>Engine:</strong> ' . esc_html( $saved['vessel_engine'] ) . '</p>';
				}
				if ( ! empty( $saved['vessel_passengers'] ) ) {
					echo '<p><strong>Capacity:</strong> ' . esc_html( $saved['vessel_passengers'] ) . ' passengers</p>';
				}
				if ( ! empty( $saved['vessel_manufacturer'] ) ) {
					echo '<p><strong>Manufacturer:</strong> ' . esc_html( $saved['vessel_manufacturer'] ) . '</p>';
				}
				if ( ! empty( $saved['vessel_amenities'] ) ) {
					$amenities = array_filter( array_map( 'trim', explode( "\n", $saved['vessel_amenities'] ) ) );
					if ( ! empty( $amenities ) ) {
						echo '<p><strong>Amenities:</strong></p><ul>';
						foreach ( $amenities as $amenity ) {
							echo '<li>' . esc_html( $amenity ) . '</li>';
						}
						echo '</ul>';
					}
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
