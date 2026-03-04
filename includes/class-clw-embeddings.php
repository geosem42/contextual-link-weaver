<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLW_Embeddings {

	/**
	 * Compute MD5 hash of a post's title + content for change detection.
	 */
	public static function get_content_hash( $post ) {
		return md5( $post->post_title . $post->post_content );
	}

	/**
	 * Prepare post text for embedding: strip HTML, decode entities, collapse whitespace, limit words.
	 */
	public static function prepare_text_for_embedding( $post ) {
		$text = $post->post_title . "\n\n" . $post->post_content;
		$text = wp_strip_all_tags( $text );
		$text = html_entity_decode( $text, ENT_QUOTES, 'UTF-8' );
		$text = preg_replace( '/\s+/', ' ', $text );
		$text = trim( $text );

		// Limit to first 2000 words to stay within embedding model token limits.
		$words = explode( ' ', $text );
		if ( count( $words ) > 2000 ) {
			$text = implode( ' ', array_slice( $words, 0, 2000 ) );
		}

		return $text;
	}

	/**
	 * Call the Gemini gemini-embedding-001 API.
	 *
	 * @param string $text      The text to embed.
	 * @param string $task_type RETRIEVAL_DOCUMENT or RETRIEVAL_QUERY.
	 * @return array|WP_Error   Array of 768 floats on success.
	 */
	public static function call_embedding_api( $text, $task_type = 'RETRIEVAL_DOCUMENT' ) {
		$api_key = get_option( 'clw_gemini_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Gemini API key is not set.' );
		}

		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/gemini-embedding-001:embedContent?key=' . $api_key;

		$request_body = array(
			'model'   => 'models/gemini-embedding-001',
			'content' => array(
				'parts' => array( array( 'text' => $text ) ),
			),
			'taskType' => $task_type,
		);

		$response = wp_remote_post( $api_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $request_body ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'embedding_api_error', "Embedding API returned status $code", $body );
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$values = $body['embedding']['values'] ?? null;

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'embedding_parse_error', 'Could not parse embedding values from API response.' );
		}

		return $values;
	}

	/**
	 * Embed a single post and store the result.
	 *
	 * @return true|WP_Error
	 */
	public static function embed_post( $post_id ) {
		$post = get_post( $post_id );
		if ( ! $post || $post->post_status !== 'publish' || $post->post_type !== 'post' ) {
			return new WP_Error( 'invalid_post', 'Post is not a published post.' );
		}

		$content_hash = self::get_content_hash( $post );

		// Skip if content hasn't changed.
		$existing = CLW_Database::get_embedding( $post_id );
		if ( $existing && $existing['content_hash'] === $content_hash ) {
			return true;
		}

		$text      = self::prepare_text_for_embedding( $post );
		$embedding = self::call_embedding_api( $text, 'RETRIEVAL_DOCUMENT' );

		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		CLW_Database::upsert_embedding( $post_id, wp_json_encode( $embedding ), $content_hash );

		return true;
	}

	/**
	 * Hook handler for save_post. Auto-embeds published posts.
	 */
	public static function on_save_post( $post_id, $post, $update ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}
		if ( $post->post_type !== 'post' ) {
			return;
		}

		// If post is no longer published, remove its embedding.
		if ( $post->post_status !== 'publish' ) {
			CLW_Database::delete_embedding( $post_id );
			return;
		}

		// Only embed if API key is configured.
		if ( empty( get_option( 'clw_gemini_api_key' ) ) ) {
			return;
		}

		self::embed_post( $post_id );
	}

	/**
	 * AJAX handler for bulk indexing. Processes a batch of unindexed posts.
	 */
	public static function ajax_bulk_index() {
		check_ajax_referer( 'clw_bulk_index_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$post_ids  = CLW_Database::get_unindexed_post_ids( 10 );
		$processed = 0;
		$errors    = array();

		foreach ( $post_ids as $post_id ) {
			$result = self::embed_post( (int) $post_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				);
			} else {
				$processed++;
			}
		}

		$stats = CLW_Database::get_index_stats();

		wp_send_json_success( array(
			'processed' => $processed,
			'errors'    => $errors,
			'indexed'   => $stats['indexed'],
			'total'     => $stats['total'],
			'remaining' => $stats['total'] - $stats['indexed'],
		) );
	}

	/**
	 * Compute cosine similarity between two vectors.
	 */
	public static function cosine_similarity( $vec_a, $vec_b ) {
		$dot   = 0.0;
		$mag_a = 0.0;
		$mag_b = 0.0;
		$len   = count( $vec_a );

		for ( $i = 0; $i < $len; $i++ ) {
			$dot   += $vec_a[ $i ] * $vec_b[ $i ];
			$mag_a += $vec_a[ $i ] * $vec_a[ $i ];
			$mag_b += $vec_b[ $i ] * $vec_b[ $i ];
		}

		$mag_a = sqrt( $mag_a );
		$mag_b = sqrt( $mag_b );

		if ( $mag_a == 0 || $mag_b == 0 ) {
			return 0.0;
		}

		return $dot / ( $mag_a * $mag_b );
	}

	/**
	 * Find the most similar posts to a query embedding.
	 *
	 * @param array $query_embedding  768-float vector for the draft content.
	 * @param int   $current_post_id  Post ID to exclude.
	 * @param int   $top_n            Number of results to return.
	 * @return array Array of [ 'post_id' => int, 'similarity' => float ].
	 */
	public static function find_similar_posts( $query_embedding, $current_post_id, $top_n = 15 ) {
		$all    = CLW_Database::get_all_embeddings();
		$scores = array();

		foreach ( $all as $post_id => $row ) {
			if ( (int) $post_id === (int) $current_post_id ) {
				continue;
			}

			$stored = json_decode( $row['embedding'], true );
			if ( ! is_array( $stored ) ) {
				continue;
			}

			$score    = self::cosine_similarity( $query_embedding, $stored );
			$scores[] = array(
				'post_id'    => (int) $post_id,
				'similarity' => $score,
			);
		}

		usort( $scores, function ( $a, $b ) {
			return $b['similarity'] <=> $a['similarity'];
		} );

		return array_slice( $scores, 0, $top_n );
	}
}
