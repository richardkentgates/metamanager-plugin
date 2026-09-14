<?php
/**
 * Template Name: Metamanager — Area Guide
 *
 * Tourism area guide page. Aggregates destinations, attractions, and events.
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
			<h1 class="entry-title"><?php the_title(); ?></h1>
		</header>

		<div class="entry-content">
			<?php the_content(); ?>
		</div>

		<?php
		$destinations = new WP_Query( [
			'post_type'      => 'mm_destination',
			'posts_per_page' => 20,
			'post_status'    => 'publish',
			'orderby'        => 'title',
			'order'          => 'ASC',
		] );
		if ( $destinations->have_posts() ) :
		?>
			<section class="mm-area-destinations">
				<h2>Destinations</h2>
				<div class="mm-archive-grid">
					<?php while ( $destinations->have_posts() ) : $destinations->the_post(); ?>
						<div class="mm-archive-item">
							<?php if ( has_post_thumbnail() ) : ?>
								<a href="<?php the_permalink(); ?>"><?php the_post_thumbnail( 'medium' ); ?></a>
							<?php endif; ?>
							<h3><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h3>
							<?php
							$saved = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
							if ( is_array( $saved ) && ! empty( $saved['destination_address'] ) ) {
								echo '<p>' . esc_html( $saved['destination_address'] ) . '</p>';
							}
							?>
						</div>
					<?php endwhile; wp_reset_postdata(); ?>
				</div>
			</section>
		<?php endif; ?>

		<?php
		$events = new WP_Query( [
			'post_type'      => 'mm_event',
			'posts_per_page' => 5,
			'post_status'    => 'publish',
			'meta_key'       => 'mm_schema_fields',
			'orderby'        => 'meta_value',
			'order'          => 'ASC',
			'meta_query'     => [
				[ 'key' => 'mm_schema_fields', 'compare' => 'EXISTS' ],
			],
		] );
		if ( $events->have_posts() ) :
		?>
			<section class="mm-area-events">
				<h2>Upcoming Events</h2>
				<table class="widefat striped">
					<thead><tr><th>Event</th><th>Date</th><th>Location</th></tr></thead>
					<tbody>
						<?php while ( $events->have_posts() ) : $events->the_post(); ?>
							<tr>
								<td><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></td>
								<td>
									<?php
									$saved = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
									$start = $saved['event_start_date'] ?? '';
									echo $start ? esc_html( date_i18n( get_option( 'date_format' ), strtotime( $start ) ) ) : '—';
									?>
								</td>
								<td><?php echo esc_html( $saved['event_location_name'] ?? '—' ); ?></td>
							</tr>
						<?php endwhile; wp_reset_postdata(); ?>
					</tbody>
				</table>
			</section>
		<?php endif; ?>
	</article>
	<?php
}

get_footer();
