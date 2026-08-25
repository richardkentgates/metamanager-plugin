<?php
/**
 * Metamanager Metadata History
 *
 * Tracks changes to the 14 MM-managed attachment metadata fields.
 * Creates a snapshot each time metadata is saved via the admin edit screen,
 * REST API, or WP-CLI. Provides a diff view for comparing versions.
 *
 * @package Metamanager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Class MM_Metadata_History
 */
class MM_Metadata_History {

	private const TABLE_SUFFIX = 'mm_meta_history';

	/**
	 * The 14 MM-managed metadata fields tracked for versioning.
	 */
	private const TRACKED_KEYS = [
		MM_Metadata::META_CREATOR,
		MM_Metadata::META_COPYRIGHT,
		MM_Metadata::META_OWNER,
		MM_Metadata::META_HEADLINE,
		MM_Metadata::META_CREDIT,
		MM_Metadata::META_KEYWORDS,
		MM_Metadata::META_DATE,
		MM_Metadata::META_CITY,
		MM_Metadata::META_STATE,
		MM_Metadata::META_COUNTRY,
		MM_Metadata::META_RATING,
		MM_Metadata::META_GPS_LAT,
		MM_Metadata::META_GPS_LON,
		MM_Metadata::META_GPS_ALT,
	];

	/**
	 * User-friendly labels for each tracked field.
	 */
	private const FIELD_LABELS = [
		MM_Metadata::META_CREATOR   => 'Creator',
		MM_Metadata::META_COPYRIGHT => 'Copyright',
		MM_Metadata::META_OWNER     => 'Owner',
		MM_Metadata::META_HEADLINE  => 'Headline',
		MM_Metadata::META_CREDIT    => 'Credit',
		MM_Metadata::META_KEYWORDS  => 'Keywords',
		MM_Metadata::META_DATE      => 'Date Created',
		MM_Metadata::META_CITY      => 'City',
		MM_Metadata::META_STATE     => 'State/Province',
		MM_Metadata::META_COUNTRY   => 'Country',
		MM_Metadata::META_RATING    => 'Rating',
		MM_Metadata::META_GPS_LAT   => 'GPS Latitude',
		MM_Metadata::META_GPS_LON   => 'GPS Longitude',
		MM_Metadata::META_GPS_ALT   => 'GPS Altitude',
	];

	// -----------------------------------------------------------------------
	// Table management
	// -----------------------------------------------------------------------

	/**
	 * Full (prefixed) table name.
	 */
	public static function table_name(): string {
		global $wpdb;
		return $wpdb->prefix . self::TABLE_SUFFIX;
	}

	/**
	 * Create or update the history table using dbDelta.
	 * Safe to call on every admin_init.
	 */
	public static function create_or_update_table(): void {
		global $wpdb;

		$table          = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$sql = "CREATE TABLE {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			version INT(10) UNSIGNED NOT NULL,
			meta_values LONGTEXT NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			change_source VARCHAR(32) NOT NULL DEFAULT 'edit',
			PRIMARY KEY (id),
			UNIQUE KEY uniq_version (attachment_id, version),
			KEY idx_attachment (attachment_id),
			KEY idx_created (created_at)
		) {$charset_collate};";

		$sql = implode( "\n", array_map( 'ltrim', explode( "\n", $sql ) ) );
		dbDelta( $sql );

		// Fallback for environments where dbDelta silently fails.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "CREATE TABLE IF NOT EXISTS {$table} (
			id BIGINT(20) UNSIGNED NOT NULL AUTO_INCREMENT,
			attachment_id BIGINT(20) UNSIGNED NOT NULL,
			version INT(10) UNSIGNED NOT NULL,
			meta_values LONGTEXT NOT NULL,
			user_id BIGINT(20) UNSIGNED NOT NULL DEFAULT 0,
			created_at DATETIME NOT NULL,
			change_source VARCHAR(32) NOT NULL DEFAULT 'edit',
			PRIMARY KEY (id),
			UNIQUE KEY uniq_version (attachment_id, version),
			KEY idx_attachment (attachment_id),
			KEY idx_created (created_at)
		) {$charset_collate};" );
	}

	/**
	 * Drop the history table. Called during uninstall when data deletion is enabled.
	 */
	public static function drop_table(): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.DirectDatabaseQuery.SchemaChange
		$wpdb->query( "DROP TABLE IF EXISTS {$table}" );
	}

	// -----------------------------------------------------------------------
	// Snapshot capture
	// -----------------------------------------------------------------------

	/**
	 * Capture the current metadata state for an attachment.
	 * Called on save_post (priority 20) after metadata is written.
	 * Only creates a new version if values actually changed from the last snapshot.
	 *
	 * @param int      $post_id Attachment ID.
	 * @param \WP_Post $post    Post object.
	 */
	public static function capture_snapshot( int $post_id, \WP_Post $post ): void {
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( 'attachment' !== $post->post_type ) {
			return;
		}

		$mime = (string) get_post_mime_type( $post_id );
		if ( ! wp_attachment_is_image( $post_id ) && ! MM_Metadata::is_av_mime( $mime ) && ! MM_Metadata::is_pdf_mime( $mime ) ) {
			return;
		}
		if ( 'read_only' === MM_Metadata::write_capability( $mime ) ) {
			return;
		}

		$current = self::read_current_values( $post_id );
		$last    = self::get_latest_snapshot( $post_id );

		// Only create a version if something actually changed.
		if ( null !== $last && $current === $last['meta_values'] ) {
			return;
		}

		self::insert_snapshot( $post_id, $current );
	}

	/**
	 * Read the current values of all tracked metadata fields for an attachment.
	 *
	 * @param int $post_id Attachment ID.
	 * @return array<string, string> Keyed by meta key => value.
	 */
	public static function read_current_values( int $post_id ): array {
		$values = [];
		foreach ( self::TRACKED_KEYS as $key ) {
			$values[ $key ] = (string) get_post_meta( $post_id, $key, true );
		}
		return $values;
	}

	/**
	 * Insert a new version snapshot.
	 *
	 * @param int                    $post_id Attachment ID.
	 * @param array<string, string>  $values  Metadata values.
	 * @param string                 $source  Change source (edit, import, bulk, cli, rest).
	 */
	private static function insert_snapshot( int $post_id, array $values, string $source = 'edit' ): void {
		global $wpdb;

		$next_version = self::get_next_version( $post_id );

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table_name(),
			[
				'attachment_id' => $post_id,
				'version'       => $next_version,
				'meta_values'   => wp_json_encode( $values ),
				'user_id'       => get_current_user_id(),
				'created_at'    => current_time( 'mysql' ),
				'change_source' => sanitize_key( $source ),
			],
			[ '%d', '%d', '%s', '%d', '%s', '%s' ]
		);
	}

	/**
	 * Get the next version number for an attachment.
	 *
	 * @param int $post_id Attachment ID.
	 * @return int
	 */
	private static function get_next_version( int $post_id ): int {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$max = (int) $wpdb->get_var( $wpdb->prepare(
			'SELECT MAX(version) FROM %i WHERE attachment_id = %d',
			$table,
			$post_id
		) );
		return $max + 1;
	}

	// -----------------------------------------------------------------------
	// History retrieval
	// -----------------------------------------------------------------------

	/**
	 * Get the latest snapshot for an attachment.
	 *
	 * @param int $post_id Attachment ID.
	 * @return array{version: int, meta_values: array<string, string>, created_at: string, user_id: int, change_source: string}|null
	 */
	public static function get_latest_snapshot( int $post_id ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT version, meta_values, created_at, user_id, change_source FROM %i WHERE attachment_id = %d ORDER BY version DESC LIMIT 1',
			$table,
			$post_id
		) );
		if ( ! $row ) {
			return null;
		}
		return [
			'version'       => (int) $row->version,
			'meta_values'   => (array) json_decode( $row->meta_values, true ),
			'created_at'    => (string) $row->created_at,
			'user_id'       => (int) $row->user_id,
			'change_source' => (string) $row->change_source,
		];
	}

	/**
	 * Get all version snapshots for an attachment, newest first.
	 *
	 * @param int $post_id Attachment ID.
	 * @return list<array{version: int, meta_values: array<string, string>, created_at: string, user_id: int, change_source: string}>
	 */
	public static function get_history( int $post_id ): array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$rows = $wpdb->get_results( $wpdb->prepare(
			'SELECT version, meta_values, created_at, user_id, change_source FROM %i WHERE attachment_id = %d ORDER BY version DESC',
			$table,
			$post_id
		) );
		if ( ! $rows ) {
			return [];
		}
		return array_map( function ( $row ): array {
			return [
				'version'       => (int) $row->version,
				'meta_values'   => (array) json_decode( $row->meta_values, true ),
				'created_at'    => (string) $row->created_at,
				'user_id'       => (int) $row->user_id,
				'change_source' => (string) $row->change_source,
			];
		}, $rows );
	}

	/**
	 * Get a specific version snapshot.
	 *
	 * @param int $post_id Attachment ID.
	 * @param int $version Version number.
	 * @return array{version: int, meta_values: array<string, string>, created_at: string, user_id: int, change_source: string}|null
	 */
	public static function get_version( int $post_id, int $version ): ?array {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$row = $wpdb->get_row( $wpdb->prepare(
			'SELECT version, meta_values, created_at, user_id, change_source FROM %i WHERE attachment_id = %d AND version = %d',
			$table,
			$post_id,
			$version
		) );
		if ( ! $row ) {
			return null;
		}
		return [
			'version'       => (int) $row->version,
			'meta_values'   => (array) json_decode( $row->meta_values, true ),
			'created_at'    => (string) $row->created_at,
			'user_id'       => (int) $row->user_id,
			'change_source' => (string) $row->change_source,
		];
	}

	/**
	 * Delete all history for an attachment. Hooked to delete_attachment.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function delete_history_for_attachment( int $post_id ): void {
		global $wpdb;
		$table = self::table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->delete( $table, [ 'attachment_id' => $post_id ], [ '%d' ] );
	}

	// -----------------------------------------------------------------------
	// Diff computation
	// -----------------------------------------------------------------------

	/**
	 * Compute a diff between two metadata snapshots.
	 *
	 * @param array<string, string> $old_values Older snapshot values.
	 * @param array<string, string> $new_values Newer snapshot values (or current).
	 * @return list<array{field: string, label: string, old: string, new: string}> Changed fields only.
	 */
	public static function compute_diff( array $old_values, array $new_values ): array {
		$diff = [];
		foreach ( self::TRACKED_KEYS as $key ) {
			$old = $old_values[ $key ] ?? '';
			$new = $new_values[ $key ] ?? '';
			if ( $old !== $new ) {
				$diff[] = [
					'field' => $key,
					'label' => self::FIELD_LABELS[ $key ],
					'old'   => $old,
					'new'   => $new,
				];
			}
		}
		return $diff;
	}

	/**
	 * Get the label for a tracked field.
	 *
	 * @param string $key Meta key.
	 * @return string
	 */
	public static function field_label( string $key ): string {
		return self::FIELD_LABELS[ $key ] ?? $key;
	}

	// -----------------------------------------------------------------------
	// Admin UI
	// -----------------------------------------------------------------------

	/**
	 * Register hooks for version history capture and admin UI.
	 */
	public static function register_hooks(): void {
		// Create/update table on admin_init.
		add_action( 'admin_init', [ self::class, 'create_or_update_table' ] );

		// Capture snapshot on save (priority 20, after MM_Metadata::on_save_post_attachment at 20).
		add_action( 'save_post', [ self::class, 'capture_snapshot' ], 25, 2 );

		// Clean up on attachment delete.
		add_action( 'delete_attachment', [ self::class, 'delete_history_for_attachment' ] );

		// Admin UI — history pane on attachment edit screen.
		add_action( 'edit_attachment', [ self::class, 'render_history_pane' ] );

		// AJAX handler for version diff.
		add_action( 'wp_ajax_mm_meta_diff', [ self::class, 'ajax_diff' ] );
	}

	/**
	 * Render the metadata history pane on the attachment edit screen.
	 *
	 * @param int $post_id Attachment ID.
	 */
	public static function render_history_pane( int $post_id ): void {
		$post = get_post( $post_id );
		if ( ! $post || 'attachment' !== $post->post_type ) {
			return;
		}

		$mime = (string) get_post_mime_type( $post_id );
		if ( ! wp_attachment_is_image( $post_id ) && ! MM_Metadata::is_av_mime( $mime ) && ! MM_Metadata::is_pdf_mime( $mime ) ) {
			return;
		}

		$history = self::get_history( $post_id );
		if ( count( $history ) < 1 ) {
			return;
		}

		$nonce = wp_create_nonce( 'mm_meta_diff_' . $post_id );

		echo '<div class="postbox mm-meta-history-pane"><div class="postbox-header">'
			. '<h2 class="hndle">' . esc_html__( 'Metadata Version History', 'metamanager' ) . '</h2>'
			. '</div><div class="inside">';

		// Summary line.
		$count = count( $history );
		echo '<p style="margin:0 0 8px;font-size:13px;">'
			. sprintf(
				/* translators: %d: number of saved versions */
				esc_html( _n( '%d version saved', '%d versions saved', $count, 'metamanager' ) ),
				$count
			)
			. '</p>';

		// Version selector for diff comparison.
		echo '<div style="display:flex;align-items:center;gap:8px;margin-bottom:12px;flex-wrap:wrap;">';
		echo '<label style="font-size:13px;font-weight:600;">' . esc_html__( 'Compare:', 'metamanager' ) . '</label>';
		echo '<select id="mm-meta-diff-old" style="font-size:13px;">';
		echo '<option value="0">' . esc_html__( 'Current values', 'metamanager' ) . '</option>';
		foreach ( $history as $entry ) {
			$label = sprintf(
				/* translators: 1: version number, 2: formatted date */
				__( 'v%1$s (%2$s)', 'metamanager' ),
				$entry['version'],
				wp_date( 'M j, Y g:i a', strtotime( $entry['created_at'] ) )
			);
			echo '<option value="' . esc_attr( (string) $entry['version'] ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<span style="font-size:13px;">' . esc_html__( 'vs', 'metamanager' ) . '</span>';

		echo '<select id="mm-meta-diff-new" style="font-size:13px;">';
		echo '<option value="0">' . esc_html__( 'Current values', 'metamanager' ) . '</option>';
		foreach ( $history as $entry ) {
			$label = sprintf(
				/* translators: 1: version number, 2: formatted date */
				__( 'v%1$s (%2$s)', 'metamanager' ),
				$entry['version'],
				wp_date( 'M j, Y g:i a', strtotime( $entry['created_at'] ) )
			);
			echo '<option value="' . esc_attr( (string) $entry['version'] ) . '">' . esc_html( $label ) . '</option>';
		}
		echo '</select>';

		echo '<button type="button" class="button button-secondary" id="mm-meta-diff-btn">'
			. esc_html__( 'Show Diff', 'metamanager' ) . '</button>';
		echo '</div>';

		// Diff result container.
		echo '<div id="mm-meta-diff-result" style="display:none;"></div>';

		// Version history table.
		echo '<table class="widefat striped" style="font-size:13px;">';
		echo '<thead><tr>'
			. '<th style="width:60px;">' . esc_html__( 'Version', 'metamanager' ) . '</th>'
			. '<th style="width:150px;">' . esc_html__( 'Date', 'metamanager' ) . '</th>'
			. '<th style="width:100px;">' . esc_html__( 'Source', 'metamanager' ) . '</th>'
			. '<th>' . esc_html__( 'Changed Fields', 'metamanager' ) . '</th>'
			. '</tr></thead><tbody>';

		$prev_values = null;
		foreach ( $history as $entry ) {
			$source_labels = [
				'edit'   => __( 'Edit screen', 'metamanager' ),
				'import' => __( 'File import', 'metamanager' ),
				'bulk'   => __( 'Bulk edit', 'metamanager' ),
				'cli'    => __( 'WP-CLI', 'metamanager' ),
				'rest'   => __( 'REST API', 'metamanager' ),
			];
			$source_label = $source_labels[ $entry['change_source'] ] ?? $entry['change_source'];

			// Compute changed fields vs previous version (or empty for first).
			if ( null !== $prev_values ) {
				$changed = self::compute_diff( $prev_values, $entry['meta_values'] );
			} else {
				// First version — show all non-empty fields as "set".
				$changed = [];
				foreach ( $entry['meta_values'] as $k => $v ) {
					if ( '' !== $v ) {
						$changed[] = [
							'field' => $k,
							'label' => self::FIELD_LABELS[ $k ] ?? $k,
							'old'   => '',
							'new'   => $v,
						];
					}
				}
			}

			$changed_labels = array_column( $changed, 'label' );
			$user_name      = '';
			if ( $entry['user_id'] > 0 ) {
				$user = get_user_by( 'id', $entry['user_id'] );
				$user_name = $user ? $user->display_name : (string) $entry['user_id'];
			}

			echo '<tr>';
			echo '<td><strong>v' . esc_html( (string) $entry['version'] ) . '</strong></td>';
			echo '<td>' . esc_html( wp_date( 'M j, Y g:i:s a', strtotime( $entry['created_at'] ) ) ) . '</td>';
			echo '<td>' . esc_html( $source_label );
			if ( '' !== $user_name ) {
				echo '<br><span style="color:#666;font-size:11px;">' . esc_html( $user_name ) . '</span>';
			}
			echo '</td>';
			echo '<td>';
			if ( ! empty( $changed_labels ) ) {
				echo esc_html( implode( ', ', $changed_labels ) );
			} else {
				echo '<span style="color:#888;">' . esc_html__( 'No changes', 'metamanager' ) . '</span>';
			}
			echo '</td>';
			echo '</tr>';

			$prev_values = $entry['meta_values'];
		}

		echo '</tbody></table>';

		// Inline script for diff AJAX.
		$js_msg_same = esc_js( __( 'Select two different versions to compare.', 'metamanager' ) );
		$js_nonce    = esc_js( $nonce );
		$js_post_id  = absint( $post_id );
		echo "<script>
		jQuery(function(\$){
			\$('#mm-meta-diff-btn').on('click', function(){
				var oldVal = \$('#mm-meta-diff-old').val();
				var newVal = \$('#mm-meta-diff-new').val();
				if (oldVal === newVal) {
					\$('#mm-meta-diff-result').html(
						'<p style=\"color:#666;font-size:13px;'>{$js_msg_same}</p>'
					).show();
					return;
				}
				var btn = \$(this);
				btn.prop('disabled', true);
				\$.post(ajaxurl, {
					action: 'mm_meta_diff',
					nonce: '{$js_nonce}',
					post_id: {$js_post_id},
					old_version: oldVal,
					new_version: newVal
				}, function(resp){
					btn.prop('disabled', false);
					if (resp.success) {
						\$('#mm-meta-diff-result').html(resp.data).show();
					} else {
						\$('#mm-meta-diff-result').html('<p style=\"color:#d63638;font-size:13px;\">' + (resp.data || 'Error.') + '</p>').show();
					}
				}, 'json');
			});
		});
		</script>";

		echo '</div></div>'; // .inside .postbox
	}

	/**
	 * AJAX handler for computing and returning a diff between two versions.
	 */
	public static function ajax_diff(): void {
		check_ajax_referer( 'mm_meta_diff_' . (int) $_POST['post_id'], 'nonce' );

		if ( ! current_user_can( 'edit_post', (int) $_POST['post_id'] ) ) {
			wp_send_json_error( __( 'Permission denied.', 'metamanager' ) );
		}

		$post_id     = (int) $_POST['post_id'];
		$old_version = (int) ( $_POST['old_version'] ?? 0 );
		$new_version = (int) ( $_POST['new_version'] ?? 0 );

		if ( 0 === $old_version ) {
			$old_values = array_fill_keys( self::TRACKED_KEYS, '' );
		} else {
			$old_snap = self::get_version( $post_id, $old_version );
			if ( ! $old_snap ) {
				wp_send_json_error( __( 'Version not found.', 'metamanager' ) );
			}
			$old_values = $old_snap['meta_values'];
		}

		if ( 0 === $new_version ) {
			$new_values = self::read_current_values( $post_id );
		} else {
			$new_snap = self::get_version( $post_id, $new_version );
			if ( ! $new_snap ) {
				wp_send_json_error( __( 'Version not found.', 'metamanager' ) );
			}
			$new_values = $new_snap['meta_values'];
		}

		$diff = self::compute_diff( $old_values, $new_values );

		if ( empty( $diff ) ) {
			wp_send_json_success( '<p style="color:#00a32a;font-size:13px;">' . esc_html__( 'No differences between selected versions.', 'metamanager' ) . '</p>' );
		}

		$html = '<table class="widefat" style="font-size:13px;">';
		$html .= '<thead><tr>'
			. '<th style="width:25%;">' . esc_html__( 'Field', 'metamanager' ) . '</th>'
			. '<th style="width:37.5%;">' . esc_html__( 'Old Value', 'metamanager' ) . '</th>'
			. '<th style="width:37.5%;">' . esc_html__( 'New Value', 'metamanager' ) . '</th>'
			. '</tr></thead><tbody>';

		foreach ( $diff as $change ) {
			$old_display = '' !== $change['old'] ? esc_html( $change['old'] ) : '<em style="color:#888;">' . esc_html__( '(empty)', 'metamanager' ) . '</em>';
			$new_display = '' !== $change['new'] ? esc_html( $change['new'] ) : '<em style="color:#888;">' . esc_html__( '(empty)', 'metamanager' ) . '</em>';

			$html .= '<tr>'
				. '<td><strong>' . esc_html( $change['label'] ) . '</strong></td>'
				. '<td style="background:#fce4e4;">' . $old_display . '</td>'
				. '<td style="background:#e8f5e9;">' . $new_display . '</td>'
				. '</tr>';
		}

		$html .= '</tbody></table>';

		wp_send_json_success( $html );
	}
}
