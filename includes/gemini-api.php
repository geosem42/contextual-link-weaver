<?php
// If this file is called directly, abort.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends a prompt to the Google Gemini API and returns the response.
 */
function get_gemini_linking_suggestions( $prompt_text ) {
    $api_key = get_option( 'clw_gemini_api_key' );
    if ( empty( $api_key ) ) {
        return new WP_Error( 'api_key_missing', 'Gemini API key is not set.' );
    }

    $model_id  = 'gemini-2.5-flash';
    $api_url   = "https://generativelanguage.googleapis.com/v1beta/models/{$model_id}:generateContent?key={$api_key}";

    $request_body = [
        'contents' => [ [ 'role'  => 'user', 'parts' => [ [ 'text' => $prompt_text ] ] ] ],
        'generationConfig' => [ 'responseMimeType' => 'application/json' ],
    ];

    $args = [
        'method'  => 'POST',
        'headers' => [ 'Content-Type' => 'application/json' ],
        'body'    => json_encode( $request_body ),
        'timeout' => 60,
    ];

    $response = wp_remote_post( $api_url, $args );

    if ( is_wp_error( $response ) ) {
        return $response;
    }

    $response_code = wp_remote_retrieve_response_code( $response );
    if ( $response_code !== 200 ) {
        $error_body = wp_remote_retrieve_body( $response );
        return new WP_Error( 'api_error', "API returned non-200 status code: {$response_code}", $error_body );
    }

    $response_body = wp_remote_retrieve_body( $response );
    $decoded_body = json_decode( $response_body, true );

    $generated_text = $decoded_body['candidates'][0]['content']['parts'][0]['text'] ?? null;
    
    if ( ! $generated_text ) {
        return new WP_Error( 'invalid_response', 'Could not find generated text in API response.', $response_body );
    }
    
    return json_decode( $generated_text, true );
}