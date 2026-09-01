<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class Interpost_Rest_API {

	/**
	 * Register all REST API routes.
	 */
	public static function register_routes() {
		register_rest_route( 'interpost/v1', '/suggestions', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_suggestions' ),
			'permission_callback' => function ( WP_REST_Request $request ) {
				// Params are not sanitized yet at permission_callback time.
				$post_id = absint( $request->get_param( 'post_id' ) );

				// An unsaved post has no ID to authorize against, so fall
				// back to the general capability.
				if ( 0 === $post_id ) {
					return current_user_can( 'edit_posts' );
				}

				// Object-level check: the caller must be able to edit this
				// specific post, not merely posts in general.
				return current_user_can( 'edit_post', $post_id );
			},
			'args'                => array(
				'content' => array(
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'wp_kses_post',
				),
				'post_id' => array(
					'required'          => true,
					'type'              => 'integer',
					'sanitize_callback' => 'absint',
				),
			),
		) );

		register_rest_route( 'interpost/v1', '/index', array(
			'methods'             => 'POST',
			'callback'            => array( __CLASS__, 'handle_index' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );

		register_rest_route( 'interpost/v1', '/index-status', array(
			'methods'             => 'GET',
			'callback'            => array( __CLASS__, 'handle_index_status' ),
			'permission_callback' => function () {
				return current_user_can( 'manage_options' );
			},
		) );
	}

	/**
	 * Handle the suggestion request from the editor.
	 */
	public static function handle_suggestions( WP_REST_Request $request ) {
		$content = $request->get_param( 'content' );
		$post_id = $request->get_param( 'post_id' );

		if ( empty( trim( $content ) ) ) {
			return new WP_REST_Response( array( 'error' => __( 'Content is empty.', 'interpost' ) ), 400 );
		}

		$suggestions = Interpost_Suggestions::get_suggestions( $content, $post_id );

		if ( is_wp_error( $suggestions ) ) {
			return new WP_REST_Response(
				array( 'error' => $suggestions->get_error_message() ),
				500
			);
		}

		return new WP_REST_Response( $suggestions, 200 );
	}

	/**
	 * Handle batch indexing via REST API.
	 */
	public static function handle_index( WP_REST_Request $request ) {
		$post_ids  = Interpost_Database::get_unindexed_post_ids( 10 );
		$processed = 0;
		$errors    = array();

		foreach ( $post_ids as $post_id ) {
			$result = Interpost_Embeddings::embed_post( (int) $post_id );
			if ( is_wp_error( $result ) ) {
				$errors[] = array(
					'post_id' => $post_id,
					'error'   => $result->get_error_message(),
				);
			} else {
				$processed++;
			}
		}

		$stats = Interpost_Database::get_index_stats();

		return new WP_REST_Response( array(
			'processed' => $processed,
			'errors'    => $errors,
			'indexed'   => $stats['indexed'],
			'total'     => $stats['total'],
			'remaining' => $stats['total'] - $stats['indexed'],
		), 200 );
	}

	/**
	 * Return current indexing status.
	 */
	public static function handle_index_status( WP_REST_Request $request ) {
		return new WP_REST_Response( Interpost_Database::get_index_stats(), 200 );
	}
}
