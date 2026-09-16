<?php
/**
 * User SEO profile fields.
 *
 * Variables:
 *   @var WP_User          $user
 *   @var array            $meta   Current _mm_meta values
 *   @var MM_Site_Settings $settings
 */
defined( 'ABSPATH' ) || exit;
$noindex        = $meta['noindex']        ?? null;
$social_fields  = MM_User_Meta_Panel::SOCIAL_FIELDS;
$upload_receipt = MM_Upload_Notify::user_wants_receipt( $user->ID );
?>
<h2>Metamanager</h2>

<table class="form-table">
	<tr>
		<th>Search Indexing</th>
		<td>
			<label>
				<input type="checkbox" id="mm_meta_noindex" name="mm_meta_noindex" value="1"
					<?php checked( $noindex, true ); ?>>
				Hide author archive from search engines
			</label>
		</td>
	</tr>
	<tr>
		<th>Upload Receipts</th>
		<td>
			<label>
				<input type="checkbox" name="mm_upload_receipt" value="1"
					<?php checked( $upload_receipt ); ?>>
				Send me an email receipt when I upload images to the Media Library
			</label>
			<p class="description">Multiple files uploaded within 60 seconds are batched into one email.</p>
			<?php wp_nonce_field( 'mm_upload_receipt_' . $user->ID, 'mm_upload_receipt_nonce' ); ?>
		</td>
	</tr>

	<tr>
		<th colspan="2"><h3 style="padding-bottom:0">Social Profiles</h3>
			<p class="description">Used in <code>Person</code> schema <code>sameAs</code> array and Twitter card <code>twitter:creator</code>.</p>
		</th>
	</tr>

	<?php foreach ( $social_fields as $field => $label ) :
		$val = $meta[ 'social_' . $field ] ?? '';
	?>
	<tr>
		<th><label for="mm_meta_social_<?php echo esc_attr($field); ?>"><?php echo esc_html($label); ?></label></th>
		<td>
			<input type="<?php echo in_array($field,['linkedin','instagram','website'],true)?'url':'text'; ?>"
				   id="mm_meta_social_<?php echo esc_attr($field); ?>"
				   name="mm_meta_social_<?php echo esc_attr($field); ?>"
				   value="<?php echo esc_attr($val); ?>"
				   class="regular-text"
				   placeholder="<?php echo 'twitter'===$field?'@handle':'https://…'; ?>">
		</td>
	</tr>
	<?php endforeach; ?>
</table>
