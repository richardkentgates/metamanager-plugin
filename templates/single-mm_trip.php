<?php
/**
 * Template: Single Trip (TouristTrip)
 *
 * Plugin-provided template for mm_trip CPT. No theme dependency.
 * Schema is emitted by MM_Mod_Schema — this template handles layout only.
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
				echo '<div class="mm-trip-details">';
				if ( ! empty( $saved['trip_departure_name'] ) ) {
					echo '<p><strong>Departure:</strong> ' . esc_html( $saved['trip_departure_name'] ) . '</p>';
				}
				if ( ! empty( $saved['trip_destination_name'] ) ) {
					echo '<p><strong>Destination:</strong> ' . esc_html( $saved['trip_destination_name'] ) . '</p>';
				}
				if ( ! empty( $saved['trip_duration'] ) ) {
					echo '<p><strong>Duration:</strong> ' . esc_html( $saved['trip_duration'] ) . '</p>';
				}
				if ( ! empty( $saved['trip_max_passengers'] ) ) {
					echo '<p><strong>Max Passengers:</strong> ' . esc_html( $saved['trip_max_passengers'] ) . '</p>';
				}
				if ( ! empty( $saved['trip_price'] ) ) {
					$currency = ! empty( $saved['trip_currency'] ) ? $saved['trip_currency'] : 'USD';
					echo '<p><strong>Price:</strong> ' . esc_html( $currency . ' ' . $saved['trip_price'] ) . '</p>';
				}
				if ( ! empty( $saved['trip_includes'] ) ) {
					echo '<p><strong>Includes:</strong> ' . esc_html( $saved['trip_includes'] ) . '</p>';
				}
				if ( ! empty( $saved['trip_booking_url'] ) ) {
					echo '<p><a href="' . esc_url( $saved['trip_booking_url'] ) . '" class="button">Book Now</a></p>';
				}
				echo '</div>';
			}
			the_content();
			?>
		</div>

		<?php
		$dest_id = (int) get_post_meta( get_the_ID(), '_mm_destination_id', true );
		if ( $dest_id && get_post_status( $dest_id ) === 'publish' ) :
		?>
			<div class="mm-trip-destination" style="background:#f0f0f1;padding:15px;border-radius:4px;margin-top:20px;">
				<h3>Destination: <a href="<?php echo esc_url( get_permalink( $dest_id ) ); ?>"><?php echo esc_html( get_the_title( $dest_id ) ); ?></a></h3>
				<?php
				$dest_saved = get_post_meta( $dest_id, 'mm_schema_fields', true );
				if ( is_array( $dest_saved ) && ! empty( $dest_saved['destination_address'] ) ) {
					echo '<p>' . esc_html( $dest_saved['destination_address'] ) . '</p>';
				}
				?>
			</div>
		<?php endif; ?>

		<?php
		$vessel_id = (int) get_post_meta( get_the_ID(), '_mm_vessel_id', true );
		if ( $vessel_id && get_post_status( $vessel_id ) === 'publish' ) :
			$vessel_saved = get_post_meta( $vessel_id, 'mm_schema_fields', true );
			?>
			<div class="mm-trip-vessel" style="background:#f0f0f1;padding:15px;border-radius:4px;margin-top:20px;">
				<h3>Vessel: <a href="<?php echo esc_url( get_permalink( $vessel_id ) ); ?>"><?php echo esc_html( get_the_title( $vessel_id ) ); ?></a></h3>
				<?php
				if ( is_array( $vessel_saved ) ) {
					if ( ! empty( $vessel_saved['vessel_length'] ) || ! empty( $vessel_saved['vessel_engine'] ) ) {
						$specs = array_filter( [ $vessel_saved['vessel_length'] ?? '', $vessel_saved['vessel_engine'] ?? '' ] );
						echo '<p>' . esc_html( implode( ', ', $specs ) ) . '</p>';
					}
					if ( ! empty( $vessel_saved['vessel_passengers'] ) ) {
						echo '<p>Capacity: ' . esc_html( $vessel_saved['vessel_passengers'] ) . ' passengers</p>';
					}
				}
				?>
			</div>
		<?php endif; ?>
	</article>
	<?php
}

get_footer();
