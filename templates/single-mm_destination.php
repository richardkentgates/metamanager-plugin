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
			$saved = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
			if ( is_array( $saved ) && ! empty( $saved ) ) {
				echo '<div class="mm-destination-details">';
				if ( ! empty( $saved['destination_address'] ) ) {
					echo '<p><strong>Location:</strong> ' . esc_html( $saved['destination_address'] ) . '</p>';
				}
				if ( ! empty( $saved['destination_tourist_type'] ) ) {
					echo '<p><strong>Type:</strong> ' . esc_html( $saved['destination_tourist_type'] ) . '</p>';
				}
				if ( ! empty( $saved['destination_attractions'] ) ) {
					echo '<p><strong>Attractions:</strong> ' . esc_html( $saved['destination_attractions'] ) . '</p>';
				}
				if ( ! empty( $saved['destination_booking_url'] ) ) {
					echo '<p><a href="' . esc_url( $saved['destination_booking_url'] ) . '" class="button">Book Tours</a></p>';
				}
				if ( ! empty( $saved['destination_map_url'] ) ) {
					echo '<p><a href="' . esc_url( $saved['destination_map_url'] ) . '" target="_blank">View Map</a></p>';
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
