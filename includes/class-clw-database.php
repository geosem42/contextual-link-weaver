<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLW_Database {

	/**
	 * Get the embeddings table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'clw_embeddings';
	}

	/**
	 * Create the embeddings table. Called on plugin activation.
	 */
	public static function create_table() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$table_name      = self::table_name();
		$charset_collate = $wpdb->get_charset_collate();

		$sql = "CREATE TABLE $table_name (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			post_id bigint(20) unsigned NOT NULL,
			embedding longtext NOT NULL,
			content_hash varchar(32) NOT NULL,
			updated_at datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
			PRIMARY KEY  (id),
			UNIQUE KEY post_id (post_id)
		) $charset_collate;";

		dbDelta( $sql );

		update_option( 'clw_db_version', CLW_VERSION );
	}

	/**
	 * Insert or update an embedding for a post.
	 */
	public static function upsert_embedding( $post_id, $embedding_json, $content_hash ) {
		global $wpdb;

		return $wpdb->replace(
			self::table_name(),
			array(
				'post_id'      => $post_id,
				'embedding'    => $embedding_json,
				'content_hash' => $content_hash,
				'updated_at'   => current_time( 'mysql' ),
			),
			array( '%d', '%s', '%s', '%s' )
		);
	}

	/**
	 * Get the embedding row for a single post.
	 */
	public static function get_embedding( $post_id ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_row(
			$wpdb->prepare( "SELECT post_id, embedding, content_hash, updated_at FROM $table WHERE post_id = %d", $post_id ),
			ARRAY_A
		);
	}

	/**
	 * Get all embeddings keyed by post_id.
	 */
	public static function get_all_embeddings() {
		global $wpdb;
		$table   = self::table_name();
		$results = $wpdb->get_results( "SELECT post_id, embedding, content_hash FROM $table", ARRAY_A );

		$indexed = array();
		foreach ( $results as $row ) {
			$indexed[ $row['post_id'] ] = $row;
		}
		return $indexed;
	}

	/**
	 * Delete the embedding for a post.
	 */
	public static function delete_embedding( $post_id ) {
		global $wpdb;

		return $wpdb->delete(
			self::table_name(),
			array( 'post_id' => $post_id ),
			array( '%d' )
		);
	}

	/**
	 * Get indexing statistics.
	 */
	public static function get_index_stats() {
		global $wpdb;
		$table = self::table_name();

		$indexed = (int) $wpdb->get_var( "SELECT COUNT(*) FROM $table" );
		$total   = (int) wp_count_posts( 'post' )->publish;

		return array(
			'indexed' => $indexed,
			'total'   => $total,
		);
	}

	/**
	 * Get post IDs that need embedding (not yet indexed or content changed).
	 */
	public static function get_unindexed_post_ids( $limit = 10 ) {
		global $wpdb;
		$table = self::table_name();

		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN $table e ON p.ID = e.post_id
				WHERE p.post_status = 'publish'
				  AND p.post_type = 'post'
				  AND (e.post_id IS NULL OR e.content_hash != MD5(CONCAT(p.post_title, p.post_content)))
				LIMIT %d",
				$limit
			)
		);
	}

	/**
	 * AJAX handler to return index status.
	 */
	public static function ajax_index_status() {
		check_ajax_referer( 'clw_bulk_index_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		wp_send_json_success( self::get_index_stats() );
	}
}
