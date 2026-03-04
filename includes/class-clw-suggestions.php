<?php
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class CLW_Suggestions {

	/**
	 * Main pipeline: embed draft → find similar posts → get AI anchor text suggestions.
	 *
	 * @param string $draft_content  The HTML content of the draft post.
	 * @param int    $current_post_id The post being edited.
	 * @return array|WP_Error
	 */
	public static function get_suggestions( $draft_content, $current_post_id ) {
		// 1. Prepare and embed the draft content.
		$plain_text = wp_strip_all_tags( $draft_content );
		$plain_text = html_entity_decode( $plain_text, ENT_QUOTES, 'UTF-8' );
		$plain_text = preg_replace( '/\s+/', ' ', trim( $plain_text ) );

		$words = explode( ' ', $plain_text );
		if ( count( $words ) > 2000 ) {
			$plain_text = implode( ' ', array_slice( $words, 0, 2000 ) );
		}

		$draft_embedding = CLW_Embeddings::call_embedding_api( $plain_text, 'RETRIEVAL_QUERY' );
		if ( is_wp_error( $draft_embedding ) ) {
			return $draft_embedding;
		}

		// 2. Find similar posts.
		$similar_posts = CLW_Embeddings::find_similar_posts( $draft_embedding, $current_post_id, 15 );

		if ( empty( $similar_posts ) ) {
			return new WP_Error( 'no_similar_posts', 'No indexed posts found. Please index your posts first from the settings page.' );
		}

		// 3. Enrich with post data.
		$post_list = self::build_post_list( $similar_posts );

		if ( empty( $post_list ) ) {
			return new WP_Error( 'no_posts', 'Could not load post data for similar posts.' );
		}

		// 4. Call Gemini generative API for anchor text selection.
		$prompt          = self::build_prompt( $draft_content, $post_list );
		$raw_suggestions = self::call_generative_api( $prompt );

		if ( is_wp_error( $raw_suggestions ) ) {
			return $raw_suggestions;
		}

		if ( ! is_array( $raw_suggestions ) ) {
			return new WP_Error( 'invalid_response', 'API returned a non-array response.' );
		}

		// 5. Enrich suggestions with title, url, and similarity score.
		return self::enrich_suggestions( $raw_suggestions, $post_list );
	}

	/**
	 * Build the enriched post list for the prompt.
	 */
	private static function build_post_list( $similar_posts ) {
		$post_list = array();

		foreach ( $similar_posts as $item ) {
			$post = get_post( $item['post_id'] );
			if ( ! $post ) {
				continue;
			}

			$excerpt = wp_trim_words( wp_strip_all_tags( $post->post_content ), 200, '...' );

			$post_list[] = array(
				'id'         => $post->ID,
				'title'      => $post->post_title,
				'url'        => get_permalink( $post->ID ),
				'excerpt'    => $excerpt,
				'similarity' => round( $item['similarity'], 4 ),
			);
		}

		return $post_list;
	}

	/**
	 * Build the Gemini prompt with only the shortlisted posts.
	 */
	private static function build_prompt( $draft_content, $post_list ) {
		$post_list_json = wp_json_encode( $post_list );

		return "You are an expert SEO specialist building an internal link graph for a blog. Your task is to analyze the draft article and suggest internal links.

Here is a JSON list of the most semantically similar articles to link to (including their 'id', 'title', 'url', and a short 'excerpt'):
{$post_list_json}

Here is the content of the new draft article:
---
{$draft_content}
---

Follow these rules STRICTLY:
1. The 'anchor_text' MUST be a phrase that exists verbatim within the draft article's content. Do NOT invent or summarize phrases.
2. The 'anchor_text' MUST be between 4 and 6 words long. This is a strict range.
3. The selected 'anchor_text' should be a self-contained, natural-sounding phrase. Avoid selecting awkward sentence fragments.
4. Find up to 5 of the best possible linking opportunities, then find the single most contextually relevant article from the JSON list for each one.
5. NEVER use the title of an article from the JSON list as the 'anchor_text' unless that exact phrase also appears in the draft article.
6. Each suggestion must link to a DIFFERENT article. Do not suggest two links to the same article.
7. Use only straight quotes (not curly/smart quotes) in the 'anchor_text'.

Return your answer ONLY as a valid JSON array of objects. Each object must have these keys:
- 'anchor_text': the exact phrase from the draft (using straight quotes only)
- 'post_id_to_link': the id of the article to link to
- 'reasoning': a brief explanation of why this link is relevant

If you cannot find any good matches that follow all the rules, return an empty array [].";
	}

	/**
	 * Call the Gemini generative API for anchor text suggestions.
	 */
	private static function call_generative_api( $prompt ) {
		$api_key = get_option( 'clw_gemini_api_key' );
		if ( empty( $api_key ) ) {
			return new WP_Error( 'api_key_missing', 'Gemini API key is not set.' );
		}

		$model_id = 'gemini-3-flash-preview';
		$api_url  = "https://generativelanguage.googleapis.com/v1beta/models/{$model_id}:generateContent?key={$api_key}";

		$request_body = array(
			'contents'         => array(
				array(
					'role'  => 'user',
					'parts' => array( array( 'text' => $prompt ) ),
				),
			),
			'generationConfig' => array(
				'responseMimeType' => 'application/json',
			),
		);

		$response = wp_remote_post( $api_url, array(
			'headers' => array( 'Content-Type' => 'application/json' ),
			'body'    => wp_json_encode( $request_body ),
			'timeout' => 60,
		) );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( $code !== 200 ) {
			$body = wp_remote_retrieve_body( $response );
			return new WP_Error( 'generative_api_error', "Gemini API returned status $code", $body );
		}

		$body           = json_decode( wp_remote_retrieve_body( $response ), true );
		$generated_text = $body['candidates'][0]['content']['parts'][0]['text'] ?? null;

		if ( ! $generated_text ) {
			return new WP_Error( 'invalid_response', 'Could not find generated text in API response.' );
		}

		$parsed = json_decode( $generated_text, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'json_parse_error', 'Failed to parse JSON from Gemini response.' );
		}

		return $parsed;
	}

	/**
	 * Enrich raw suggestions with post title, URL, and similarity score.
	 */
	private static function enrich_suggestions( $raw_suggestions, $post_list ) {
		$enriched = array();

		foreach ( $raw_suggestions as $suggestion ) {
			foreach ( $post_list as $post ) {
				if ( $post['id'] == $suggestion['post_id_to_link'] ) {
					$suggestion['title']      = $post['title'];
					$suggestion['url']        = $post['url'];
					$suggestion['similarity'] = $post['similarity'];
					$enriched[]               = $suggestion;
					break;
				}
			}
		}

		return $enriched;
	}
}
