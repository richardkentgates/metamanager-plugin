<?php
/**
 * Template Name: Metamanager — Venue
 *
 * Event venue page. Shows venue details and upcoming events.
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
			<?php the_content(); ?>
		</div>

		<?php
		$events = new WP_Query( [
			'post_type'      => 'mm_event',
			'posts_per_page' => 10,
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
			<section class="mm-venue-events">
				<h2>Upcoming Events</h2>
				<table class="widefat striped">
					<thead><tr><th>Event</th><th>Date</th><th>Details</th></tr></thead>
					<tbody>
						<?php while ( $events->have_posts() ) : $events->the_post(); ?>
							<tr>
								<td><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></td>
								<td>
									<?php
									$saved = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
									$start = $saved['event_start_date'] ?? '';
									echo $start ? esc_html( date_i18n( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), strtotime( $start ) ) ) : '—';
									?>
								</td>
								<td>
									<?php
									$parts = [];
									if ( ! empty( $saved['event_price'] ) ) {
										$currency = $saved['event_currency'] ?? 'USD';
										$parts[] = $currency . ' ' . $saved['event_price'];
									}
									echo $parts ? esc_html( implode( ' · ', $parts ) ) : '—';
									?>
								</td>
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
