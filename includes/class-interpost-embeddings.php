<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Interpost_Embeddings {

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
	 * The embedding model this site uses.
	 *
	 * Changing it makes every stored vector incomparable with every new one,
	 * so anything that offers the choice has to re-index the whole site.
	 *
	 * @return string
	 */
	public static function embedding_model() {
		$model = apply_filters( 'interpost_embedding_model', 'gemini-embedding-001' );
		$model = is_string( $model ) ? trim( $model ) : '';

		return '' === $model ? 'gemini-embedding-001' : $model;
	}

	/**
	 * How many floats each embedding should have, or null for the model default.
	 *
	 * @return int|null
	 */
	public static function embedding_dimensions() {
		$dimensions = apply_filters( 'interpost_embedding_dimensions', null );

		if ( null === $dimensions ) {
			return null;
		}

		$dimensions = (int) $dimensions;

		return $dimensions > 0 ? $dimensions : null;
	}

	/**
	 * Call the Gemini embedding API.
	 *
	 * @param string $text      The text to embed.
	 * @param string $task_type RETRIEVAL_DOCUMENT or RETRIEVAL_QUERY.
	 * @return array|WP_Error   Array of floats on success, 3072 of them by default.
	 */
	public static function call_embedding_api( $text, $task_type = 'RETRIEVAL_DOCUMENT' ) {
		$api_key = get_option( 'interpost_gemini_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', __( 'Gemini API key is not set.', 'interpost' ) );
		}

		$model   = self::embedding_model();
		$api_url = 'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':embedContent';

		$request_body = array(
			'model'   => 'models/' . $model,
			'content' => array(
				'parts' => array( array( 'text' => $text ) ),
			),
			'taskType' => $task_type,
		);

		$dimensions = self::embedding_dimensions();

		if ( null !== $dimensions ) {
			$request_body['outputDimensionality'] = $dimensions;
		}

		$response = wp_remote_post( $api_url, array(
			'headers' => array(
				'Content-Type'   => 'application/json',
				'x-goog-api-key' => $api_key,
			),
			'body'    => wp_json_encode( $request_body ),
			'timeout' => 30,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );

			// The status goes in the error data as a number. Reading it back out
			// of the translated message would break on every non-English site.
			return new WP_Error(
				'embedding_api_error',
				/* translators: %d: HTTP status code. */
				sprintf( __( 'Embedding API returned status %d', 'interpost' ), $code ),
				array(
					'status' => (int) $code,
					'body'   => $body,
				)
			);
		}

		$body   = json_decode( wp_remote_retrieve_body( $response ), true );
		$values = $body['embedding']['values'] ?? null;

		if ( ! is_array( $values ) ) {
			return new WP_Error( 'embedding_parse_error', __( 'Could not parse embedding values from API response.', 'interpost' ) );
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

		if (
			! $post
			|| ! in_array( $post->post_status, Interpost_Database::indexed_post_statuses(), true )
			|| ! in_array( $post->post_type, Interpost_Database::indexed_post_types(), true )
		) {
			return new WP_Error( 'invalid_post', __( 'This post is not one of the types Interpost indexes.', 'interpost' ) );
		}

		$content_hash = self::get_content_hash( $post );

		// Skip if content hasn't changed.
		$existing = Interpost_Database::get_embedding( $post_id );
		if ( $existing && $existing['content_hash'] === $content_hash ) {
			return true;
		}

		$text      = self::prepare_text_for_embedding( $post );
		$embedding = self::call_embedding_api( $text, 'RETRIEVAL_DOCUMENT' );

		if ( is_wp_error( $embedding ) ) {
			return $embedding;
		}

		Interpost_Database::upsert_embedding( $post_id, wp_json_encode( $embedding ), $content_hash );

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
		if ( ! in_array( $post->post_type, Interpost_Database::indexed_post_types(), true ) ) {
			return;
		}

		// If the post no longer has an indexed status, remove its embedding.
		if ( ! in_array( $post->post_status, Interpost_Database::indexed_post_statuses(), true ) ) {
			Interpost_Database::delete_embedding( $post_id );
			return;
		}

		// Only embed if API key is configured.
		if ( empty( get_option( 'interpost_gemini_api_key' ) ) ) {
			return;
		}

		self::embed_post( $post_id );
	}

	/**
	 * AJAX handler for bulk indexing. Processes a batch of unindexed posts.
	 *
	 * The caller sends back the IDs that have already failed this run, and they
	 * are left out of the next batch. Without that, a post that can never be
	 * embedded is selected again on every call and the run never ends.
	 */
	public static function ajax_bulk_index() {
		check_ajax_referer( 'interpost_bulk_index_nonce', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( 'Unauthorized', 403 );
		}

		$failed = isset( $_POST['failed'] ) ? array_map( 'absint', (array) wp_unslash( $_POST['failed'] ) ) : array();
		$failed = array_values( array_filter( $failed ) );

		$post_ids  = Interpost_Database::get_unindexed_post_ids( 10, $failed );
		$attempted = count( $post_ids );
		$processed = 0;
		$errors    = array();

		foreach ( $post_ids as $post_id ) {
			$post_id = (int) $post_id;
			$result  = self::embed_post( $post_id );

			if ( is_wp_error( $result ) ) {
				$failed[] = $post_id;
				$errors[] = array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				);
			} else {
				$processed++;
			}
		}

		$stats = Interpost_Database::get_index_stats();

		wp_send_json_success( array(
			'attempted' => $attempted,
			'processed' => $processed,
			'errors'    => $errors,
			'failed'    => array_values( array_unique( $failed ) ),
			'indexed'   => $stats['indexed'],
			'total'     => $stats['total'],
			'remaining' => max( 0, $stats['total'] - $stats['indexed'] ),
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
	 * @param array $query_embedding  Vector for the draft content.
	 * @param int   $current_post_id  Post ID to exclude.
	 * @param int   $top_n            Number of results to return.
	 * @return array Array of [ 'post_id' => int, 'similarity' => float ].
	 */
	public static function find_similar_posts( $query_embedding, $current_post_id, $top_n = 15 ) {
		$all    = Interpost_Database::get_all_embeddings();
		$scores = array();
		$width  = count( $query_embedding );

		foreach ( $all as $post_id => $row ) {
			if ( (int) $post_id === (int) $current_post_id ) {
				continue;
			}

			$stored = json_decode( $row['embedding'], true );
			if ( ! is_array( $stored ) ) {
				continue;
			}

			// A row embedded at a different width cannot be compared with this
			// query. Scoring it anyway either floods the log with warnings or,
			// the other way round, quietly scores on a prefix and returns a
			// number that looks reasonable and means nothing.
			if ( count( $stored ) !== $width ) {
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

		$scores = array_slice( $scores, 0, $top_n );

		/**
		 * The shortlist of posts a draft may link to.
		 *
		 * @param array $scores          Each entry has post_id and similarity.
		 * @param int   $current_post_id The post being edited.
		 */
		return apply_filters( 'interpost_similar_posts', $scores, $current_post_id );
	}
}
