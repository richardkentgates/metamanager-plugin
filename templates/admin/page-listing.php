<?php
/**
 * Admin page — Button Styles.
 *
 * Sitewide button/link styles for listing cards, contact card buttons,
 * and Person widget clickable elements.
 *
 * @var MM_Site_Settings $settings
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

$s         = $settings->get( 'listing' );
$opt       = MM_META_OPT_SETTINGS;
$biz_style = MM_Mod_Business_Contact::get_style_settings();
?>

<div class="mm-meta-panel" id="page-listing">

	<h2><?php esc_html_e( 'Button Styles', 'metamanager' ); ?></h2>
	<p class="description">
		<?php esc_html_e( 'Sitewide button and link styles used by listing cards, contact card action buttons, and Person widget phone/links. These settings control the appearance of all clickable buttons across the site.', 'metamanager' ); ?>
	</p>

	<h3><?php esc_html_e( 'Link Card Button Text', 'metamanager' ); ?></h3>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="listing-btn_text"><?php esc_html_e( 'Button Text', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="listing-btn_text"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_text]"
					value="<?php echo esc_attr( $s['btn_text'] ); ?>"
					class="regular-text">
				<p class="description"><?php esc_html_e( 'The text displayed on the link/button under each item on listing pages.', 'metamanager' ); ?></p>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Button Appearance', 'metamanager' ); ?></h3>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="listing-btn_bg"><?php esc_html_e( 'Background Color', 'metamanager' ); ?></label></th>
			<td>
				<input type="color" id="listing-btn_bg"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_bg]"
					value="<?php echo esc_attr( $s['btn_bg'] ); ?>">
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_text_color"><?php esc_html_e( 'Text Color', 'metamanager' ); ?></label></th>
			<td>
				<input type="color" id="listing-btn_text_color"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_text_color]"
					value="<?php echo esc_attr( $s['btn_text_color'] ); ?>">
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_radius"><?php esc_html_e( 'Border Radius', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="listing-btn_radius"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_radius]"
					value="<?php echo esc_attr( $s['btn_radius'] ); ?>"
					class="small-text">
				<p class="description"><?php esc_html_e( 'e.g. 0px for square, 4px for subtle, 99px for pill shape.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_padding"><?php esc_html_e( 'Padding', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="listing-btn_padding"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_padding]"
					value="<?php echo esc_attr( $s['btn_padding'] ); ?>"
					class="small-text">
				<p class="description"><?php esc_html_e( 'CSS shorthand: top/right/bottom/left or vertical/horizontal.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_font_size"><?php esc_html_e( 'Font Size', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="listing-btn_font_size"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_font_size]"
					value="<?php echo esc_attr( $s['btn_font_size'] ); ?>"
					class="small-text">
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_font_weight"><?php esc_html_e( 'Font Weight', 'metamanager' ); ?></label></th>
			<td>
				<select id="listing-btn_font_weight"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_font_weight]">
					<?php
					foreach ( array(
						'400' => 'Normal',
						'500' => 'Medium',
						'600' => 'Semibold',
						'700' => 'Bold',
					) as $val => $label ) :
						?>
						<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $s['btn_font_weight'], $val ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Border', 'metamanager' ); ?></h3>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label for="listing-btn_border_thickness"><?php esc_html_e( 'Border Thickness', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="listing-btn_border_thickness"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_border_thickness]"
					value="<?php echo esc_attr( $s['btn_border_thickness'] ?? '0px' ); ?>"
					class="small-text" placeholder="0px">
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_border_color"><?php esc_html_e( 'Border Color', 'metamanager' ); ?></label></th>
			<td>
				<input type="color" id="listing-btn_border_color"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_border_color]"
					value="<?php echo esc_attr( $s['btn_border_color'] ?? '#0073aa' ); ?>">
			</td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Box Shadow', 'metamanager' ); ?></h3>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label><?php esc_html_e( 'Enable Box Shadow', 'metamanager' ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[listing][btn_box_shadow]" value="1" <?php checked( ! empty( $s['btn_box_shadow'] ) ); ?>>
					<?php esc_html_e( 'Add box shadow to buttons', 'metamanager' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_box_shadow_color"><?php esc_html_e( 'Shadow Color (with alpha)', 'metamanager' ); ?></label></th>
			<td>
				<input type="text" id="listing-btn_box_shadow_color"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_box_shadow_color]"
					value="<?php echo esc_attr( $s['btn_box_shadow_color'] ?? 'rgba(0,0,0,0.3)' ); ?>"
					class="regular-text" placeholder="rgba(0,0,0,0.3)">
				<p class="description"><?php esc_html_e( 'RGBA color with alpha channel, e.g. rgba(0,0,0,0.3) for 30% opacity.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_box_shadow_x"><?php esc_html_e( 'Horizontal Offset', 'metamanager' ); ?></label></th>
			<td><input type="text" id="listing-btn_box_shadow_x" name="<?php echo esc_attr( $opt ); ?>[listing][btn_box_shadow_x]" value="<?php echo esc_attr( $s['btn_box_shadow_x'] ?? '0px' ); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th><label for="listing-btn_box_shadow_y"><?php esc_html_e( 'Vertical Offset', 'metamanager' ); ?></label></th>
			<td><input type="text" id="listing-btn_box_shadow_y" name="<?php echo esc_attr( $opt ); ?>[listing][btn_box_shadow_y]" value="<?php echo esc_attr( $s['btn_box_shadow_y'] ?? '2px' ); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th><label for="listing-btn_box_shadow_blur"><?php esc_html_e( 'Blur', 'metamanager' ); ?></label></th>
			<td><input type="text" id="listing-btn_box_shadow_blur" name="<?php echo esc_attr( $opt ); ?>[listing][btn_box_shadow_blur]" value="<?php echo esc_attr( $s['btn_box_shadow_blur'] ?? '4px' ); ?>" class="small-text"></td>
		</tr>
		<tr>
			<th><label for="listing-btn_box_shadow_spread"><?php esc_html_e( 'Spread', 'metamanager' ); ?></label></th>
			<td><input type="text" id="listing-btn_box_shadow_spread" name="<?php echo esc_attr( $opt ); ?>[listing][btn_box_shadow_spread]" value="<?php echo esc_attr( $s['btn_box_shadow_spread'] ?? '0px' ); ?>" class="small-text"></td>
		</tr>
	</table>

	<h3><?php esc_html_e( 'Hover Effect', 'metamanager' ); ?></h3>
	<table class="form-table gcm-form-table">
		<tr>
			<th><label><?php esc_html_e( 'Enable Hover Effect', 'metamanager' ); ?></label></th>
			<td>
				<label>
					<input type="checkbox" name="<?php echo esc_attr( $opt ); ?>[listing][btn_hover_enabled]" value="1" <?php checked( ! empty( $s['btn_hover_enabled'] ?? true ) ); ?>>
					<?php esc_html_e( 'Apply hover effect to buttons', 'metamanager' ); ?>
				</label>
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_hover_bg"><?php esc_html_e( 'Hover Background', 'metamanager' ); ?></label></th>
			<td>
				<input type="color" id="listing-btn_hover_bg"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_hover_bg]"
					value="<?php echo esc_attr( $s['btn_hover_bg'] ?? '' ); ?>">
				<p class="description"><?php esc_html_e( 'Leave empty to use opacity instead.', 'metamanager' ); ?></p>
			</td>
		</tr>
		<tr>
			<th><label for="listing-btn_hover_text"><?php esc_html_e( 'Hover Text Color', 'metamanager' ); ?></label></th>
			<td>
				<input type="color" id="listing-btn_hover_text"
					name="<?php echo esc_attr( $opt ); ?>[listing][btn_hover_text]"
					value="<?php echo esc_attr( $s['btn_hover_text'] ?? '' ); ?>">
				<p class="description"><?php esc_html_e( 'Leave empty to keep default text color.', 'metamanager' ); ?></p>
			</td>
		</tr>

	</table>

	<h3><?php esc_html_e( 'Preview', 'metamanager' ); ?></h3>
	<div style="background:#f0f0f1;padding:20px;border-radius:4px;">
		<p style="margin:0 0 8px;font-size:12px;color:#666;">Normal state:</p>
		<a id="listing-preview-btn" style="
			display: inline-block;
			padding: <?php echo esc_attr( $s['btn_padding'] ); ?>;
			background: <?php echo esc_attr( $s['btn_bg'] ); ?>;
			color: <?php echo esc_attr( $s['btn_text_color'] ); ?>;
			border-radius: <?php echo esc_attr( $s['btn_radius'] ); ?>;
			font-size: <?php echo esc_attr( $s['btn_font_size'] ); ?>;
			font-weight: <?php echo esc_attr( $s['btn_font_weight'] ); ?>;
			border: <?php echo esc_attr( $s['btn_border_thickness'] ?? '0px' ); ?> solid <?php echo esc_attr( $s['btn_border_color'] ?? '#0073aa' ); ?>;
			<?php if ( ! empty( $s['btn_box_shadow'] ) ) : ?>
			box-shadow: <?php echo esc_attr( $s['btn_box_shadow_x'] ?? '0px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_y'] ?? '2px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_blur'] ?? '4px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_spread'] ?? '0px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_color'] ?? 'rgba(0,0,0,0.3)' ); ?>;
			<?php endif; ?>
			text-decoration: none;
		"><?php echo esc_html( $s['btn_text'] ); ?></a>
		<p style="margin:16px 0 8px;font-size:12px;color:#666;">Hover state:</p>
		<?php
		$h_bg           = trim( (string) ( $s['btn_hover_bg'] ?? '' ) );
		$h_text         = trim( (string) ( $s['btn_hover_text'] ?? '' ) );
		$hover_bg_css   = $h_bg ? $h_bg : $s['btn_bg'];
		$hover_text_css = $h_text ? $h_text : $s['btn_text_color'];
		$hover_opacity  = ( ! $h_bg && ! $h_text ) ? '0.88' : '1';
		?>
		<a id="listing-preview-btn-hover" style="
			display: inline-block;
			padding: <?php echo esc_attr( $s['btn_padding'] ); ?>;
			background: <?php echo esc_attr( $hover_bg_css ); ?>;
			color: <?php echo esc_attr( $hover_text_css ); ?>;
			border-radius: <?php echo esc_attr( $s['btn_radius'] ); ?>;
			font-size: <?php echo esc_attr( $s['btn_font_size'] ); ?>;
			font-weight: <?php echo esc_attr( $s['btn_font_weight'] ); ?>;
			border: <?php echo esc_attr( $s['btn_border_thickness'] ?? '0px' ); ?> solid <?php echo esc_attr( $s['btn_border_color'] ?? '#0073aa' ); ?>;
			<?php if ( ! empty( $s['btn_box_shadow'] ) ) : ?>
			box-shadow: <?php echo esc_attr( $s['btn_box_shadow_x'] ?? '0px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_y'] ?? '2px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_blur'] ?? '4px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_spread'] ?? '0px' ); ?> <?php echo esc_attr( $s['btn_box_shadow_color'] ?? 'rgba(0,0,0,0.3)' ); ?>;
			<?php endif; ?>
			opacity: <?php echo esc_attr( $hover_opacity ); ?>;
			text-decoration: none;
			pointer-events: none;
		"><?php echo esc_html( $s['btn_text'] ); ?></a>
	</div>
	<script>
	(function() {
		function upd() {
			var b = document.getElementById('listing-preview-btn');
			if (!b) return;
			var f = function(id) { return document.getElementById('listing-' + id); };
			b.textContent = f('btn_text') ? f('btn_text').value : 'Learn more';
			b.style.padding = f('btn_padding') ? f('btn_padding').value : '';
			b.style.background = f('btn_bg') ? f('btn_bg').value : '';
			b.style.color = f('btn_text_color') ? f('btn_text_color').value : '';
			b.style.borderRadius = f('btn_radius') ? f('btn_radius').value : '';
			b.style.fontSize = f('btn_font_size') ? f('btn_font_size').value : '';
			b.style.fontWeight = f('listing][btn_font_weight'] ? f('listing][btn_font_weight'].value : '';
		}
		document.querySelectorAll('#page-listing input, #page-listing select').forEach(function(el) {
			el.addEventListener('input', upd);
			el.addEventListener('change', upd);
		});
	})();
	</script>

</div>
