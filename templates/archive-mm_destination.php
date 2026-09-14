<?php
/**
 * Template: Archive Destinations
 *
 * Plugin-provided archive template for mm_destination CPT. No theme dependency.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();
?>

<header class="page-header">
	<h1 class="page-title"><?php post_type_archive_title(); ?></h1>
</header>

<?php if ( have_posts() ) : ?>
	<div class="mm-archive-grid">
		<?php while ( have_posts() ) : the_post(); ?>
			<article id="post-<?php the_ID(); ?>" <?php post_class( 'mm-archive-item' ); ?>>
				<?php if ( has_post_thumbnail() ) : ?>
					<a href="<?php the_permalink(); ?>">
						<?php the_post_thumbnail( 'medium' ); ?>
					</a>
				<?php endif; ?>
				<h2><a href="<?php the_permalink(); ?>"><?php the_title(); ?></a></h2>
				<?php
				$saved = get_post_meta( get_the_ID(), 'mm_schema_fields', true );
				if ( is_array( $saved ) && ! empty( $saved['destination_address'] ) ) {
					echo '<p class="mm-location">' . esc_html( $saved['destination_address'] ) . '</p>';
				}
				the_excerpt();
				?>
			</article>
		<?php endwhile; ?>
	</div>
	<?php the_posts_pagination(); ?>
<?php else : ?>
	<p><?php esc_html_e( 'No destinations found.', 'metamanager' ); ?></p>
<?php endif; ?>

<?php
get_footer();
