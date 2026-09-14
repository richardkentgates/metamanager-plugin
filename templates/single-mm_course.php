<?php
/**
 * Template: Single Course
 *
 * Plugin-provided template for mm_course CPT. No theme dependency.
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
				echo '<div class="mm-course-details">';
				if ( ! empty( $saved['course_credential'] ) ) {
					echo '<p><strong>Credential Awarded:</strong> ' . esc_html( $saved['course_credential'] ) . '</p>';
				}
				if ( ! empty( $saved['course_mode'] ) ) {
					echo '<p><strong>Delivery Mode:</strong> ' . esc_html( $saved['course_mode'] ) . '</p>';
				}
				if ( ! empty( $saved['course_duration'] ) ) {
					echo '<p><strong>Duration:</strong> ' . esc_html( $saved['course_duration'] ) . '</p>';
				}
				if ( ! empty( $saved['course_prerequisites'] ) ) {
					echo '<p><strong>Prerequisites:</strong> ' . esc_html( $saved['course_prerequisites'] ) . '</p>';
				}
				if ( ! empty( $saved['course_price'] ) ) {
					$currency = ! empty( $saved['course_currency'] ) ? $saved['course_currency'] : 'USD';
					echo '<p><strong>Price:</strong> ' . esc_html( $currency . ' ' . $saved['course_price'] ) . '</p>';
				}
				if ( ! empty( $saved['course_provider'] ) ) {
					echo '<p><strong>Provider:</strong> ' . esc_html( $saved['course_provider'] ) . '</p>';
				}
				if ( ! empty( $saved['course_enrollment_url'] ) ) {
					echo '<p><a href="' . esc_url( $saved['course_enrollment_url'] ) . '" class="button">Enroll Now</a></p>';
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
