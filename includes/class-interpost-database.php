<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Interpost_Database {

	/**
	 * Get the embeddings table name.
	 */
	public static function table_name() {
		global $wpdb;
		return $wpdb->prefix . 'interpost_embeddings';
	}

	/**
	 * The post types that get indexed.
	 *
	 * Defaults to posts alone, which is what this plugin has always indexed.
	 * A companion plugin can widen it, and whatever it returns is checked
	 * against the types actually registered on the site.
	 *
	 * @return array<int, string>
	 */
	public static function indexed_post_types() {
		$types = apply_filters( 'interpost_indexed_post_types', array( 'post' ) );

		if ( ! is_array( $types ) ) {
			$types = array( 'post' );
		}

		$types = array_values( array_unique( array_filter( array_map( 'strval', $types ), 'post_type_exists' ) ) );

		return empty( $types ) ? array( 'post' ) : $types;
	}

	/**
	 * The post statuses that get indexed.
	 *
	 * @return array<int, string>
	 */
	public static function indexed_post_statuses() {
		$statuses = apply_filters( 'interpost_indexed_post_statuses', array( 'publish' ) );

		if ( ! is_array( $statuses ) ) {
			$statuses = array( 'publish' );
		}

		$statuses = array_values( array_unique( array_filter( array_map( 'strval', $statuses ), 'get_post_status_object' ) ) );

		return empty( $statuses ) ? array( 'publish' ) : $statuses;
	}

	/**
	 * A run of placeholders, one per value, for an IN clause.
	 *
	 * Building the list this way keeps every value inside prepare() rather
	 * than interpolated into the query.
	 *
	 * @param array<int, string> $values
	 * @return string
	 */
	private static function placeholders( $values ) {
		return implode( ',', array_fill( 0, count( $values ), '%s' ) );
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

		update_option( 'interpost_db_version', INTERPOST_VERSION );
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
	 *
	 * Both numbers are counted against the same post types and statuses, so a
	 * table holding rows this install no longer indexes cannot report more
	 * indexed than there are posts.
	 */
	public static function get_index_stats() {
		global $wpdb;

		$table     = self::table_name();
		$types     = self::indexed_post_types();
		$statuses  = self::indexed_post_statuses();
		$type_in   = self::placeholders( $types );
		$status_in = self::placeholders( $statuses );
		$values    = array_merge( $statuses, $types );

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $type_in and $status_in hold runs of %s placeholders built from array_fill, never values. Every value goes through prepare() below.
		$indexed = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM $table e
				INNER JOIN {$wpdb->posts} p ON p.ID = e.post_id
				WHERE p.post_status IN ($status_in)
				  AND p.post_type IN ($type_in)",
				...$values
			)
		);

		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*)
				FROM {$wpdb->posts}
				WHERE post_status IN ($status_in)
				  AND post_type IN ($type_in)",
				...$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter

		return array(
			'indexed' => $indexed,
			'total'   => $total,
		);
	}

	/**
	 * Get post IDs that need embedding (not yet indexed or content changed).
	 *
	 * @param int                $limit  How many to return.
	 * @param array<int, int>    $skip   Post IDs to leave out, used to step past
	 *                                   posts that failed earlier in the same run.
	 * @return array<int, string>
	 */
	public static function get_unindexed_post_ids( $limit = 10, $skip = array() ) {
		global $wpdb;

		$table     = self::table_name();
		$types     = self::indexed_post_types();
		$statuses  = self::indexed_post_statuses();
		$type_in   = self::placeholders( $types );
		$status_in = self::placeholders( $statuses );

		$skip    = array_values( array_unique( array_map( 'absint', (array) $skip ) ) );
		$exclude = '';
		$values  = array_merge( $statuses, $types );

		if ( ! empty( $skip ) ) {
			$exclude = ' AND p.ID NOT IN (' . implode( ',', array_fill( 0, count( $skip ), '%d' ) ) . ')';
			$values  = array_merge( $values, $skip );
		}

		$values[] = (int) $limit;

		// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter -- $type_in and $status_in hold runs of %s placeholders built from array_fill, never values. Every value goes through prepare() below.
		return $wpdb->get_col(
			$wpdb->prepare(
				"SELECT p.ID
				FROM {$wpdb->posts} p
				LEFT JOIN $table e ON p.ID = e.post_id
				WHERE p.post_status IN ($status_in)
				  AND p.post_type IN ($type_in)
				  AND (e.post_id IS NULL OR e.content_hash != MD5(CONCAT(p.post_title, p.post_content)))
				  $exclude
				LIMIT %d",
				...$values
			)
		);
		// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,PluginCheck.Security.DirectDB.UnescapedDBParameter
	}

	/**
	 * AJAX handler to return index status.
	 */
	public static function ajax_index_status() {
		check_ajax_referer( 'interpost_bulk_index_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		wp_send_json_success( self::get_index_stats() );
	}
}
