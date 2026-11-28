<?php
/**
 * Template: How-To Archive
 *
 * Theme-agnostic listing with selectable layout (Menu or Grid).
 * Layout is set per-CPT in Appearance > Customize > MM Archives.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

get_header();

// Enqueue archive layout CSS.
wp_enqueue_style( 'mm-archive-layouts', MM_META_URL . 'assets/css/archive-layouts.css', array(), MM_VERSION );

// Get the CPT label for the heading.
$pt = get_post_type_object( 'mm_how_to' );
?>
<div id="primary" class="content-area">
	<main id="main" class="site-main">
		<header class="page-header">
			<h1 class="page-title"><?php echo esc_html( $pt->labels->name ?? 'How-To' ); ?></h1>
		</header>

		<?php MM_Schema_Post_Types::render_archive( 'mm_how_to' ); ?>
	</main>
</div>

<?php
get_footer();
