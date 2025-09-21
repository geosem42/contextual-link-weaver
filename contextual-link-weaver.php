<?php
/**
 * Plugin Name:       Contextual Link Weaver
 * Plugin URI:        https://github.com/geosem42/contextual-link-weaver
 * Description:       Uses the Gemini API to provide intelligent, context-aware internal linking suggestions.
 * Version:           1.0.0
 * Author:            George Semaan
 * Author URI:        https://logicvoid.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contextual-link-weaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Include the separate file that handles the Gemini API communication.
require_once plugin_dir_path( __FILE__ ) . 'includes/gemini-api.php';

/*
|--------------------------------------------------------------------------
| Admin Settings Page
|--------------------------------------------------------------------------
|
| This section creates the settings page in the WordPress admin area
| where the user can enter their Gemini API key.
|
*/

/**
 * Adds the settings page to the admin menu.
 */
function clw_add_admin_menu() {
	add_options_page(
		'Contextual Link Weaver Settings',
		'Link Weaver',
		'manage_options',
		'contextual-link-weaver',
		'clw_settings_page_html'
	);
}
add_action( 'admin_menu', 'clw_add_admin_menu' );

/**
 * Initializes the settings, sections, and fields for the admin page.
 */
function clw_settings_init() {
	register_setting( 'clw_settings_group', 'clw_gemini_api_key', [
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	] );

	add_settings_section(
		'clw_api_settings_section',
		'API Settings',
		'clw_api_settings_section_callback',
		'contextual-link-weaver'
	);

	add_settings_field(
		'clw_gemini_api_key_field',
		'Gemini API Key',
		'clw_api_key_field_callback',
		'contextual-link-weaver',
		'clw_api_settings_section'
	);
}
add_action( 'admin_init', 'clw_settings_init' );

/**
 * Renders the description for the API settings section.
 */
function clw_api_settings_section_callback() {
	echo '<p>Enter your Google Gemini API key below to enable link suggestions.</p>';
}

/**
 * Renders the input field for the API key.
 */
function clw_api_key_field_callback() {
	$api_key = get_option( 'clw_gemini_api_key' );
	printf(
		'<input type="password" name="clw_gemini_api_key" value="%s" size="50" />',
		esc_attr( $api_key )
	);
}

/**
 * Renders the HTML for the main settings page.
 */
function clw_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>
		<form action="options.php" method="post">
			<?php
			settings_fields( 'clw_settings_group' );
			do_settings_sections( 'contextual-link-weaver' );
			submit_button( 'Save Settings' );
			?>
		</form>
	</div>
	<?php
}

/*
|--------------------------------------------------------------------------
| Gutenberg Editor Integration
|--------------------------------------------------------------------------
|
| This section handles loading the JavaScript for the editor sidebar
| and creating the REST API endpoint for communication.
|
*/

/**
 * Enqueues the JavaScript assets for the block editor.
 */
function clw_enqueue_editor_assets() {
	$asset_file_path = plugin_dir_path( __FILE__ ) . 'build/index.asset.php';

	if ( ! file_exists( $asset_file_path ) ) {
		return;
	}

	$asset_file = include $asset_file_path;

	wp_enqueue_script(
		'contextual-link-weaver-editor-script',
		plugin_dir_url( __FILE__ ) . 'build/index.js',
		$asset_file['dependencies'],
		$asset_file['version']
	);
}
add_action( 'enqueue_block_editor_assets', 'clw_enqueue_editor_assets' );

/**
 * Registers the custom REST API route for getting suggestions.
 */
function clw_register_rest_route() {
	register_rest_route(
		'contextual-link-weaver/v1',
		'/suggestions',
		[
			'methods'             => 'POST',
			'callback'            => 'clw_handle_suggestions_request',
			'permission_callback' => function () {
				return current_user_can( 'edit_posts' );
			},
		]
	);
}
add_action( 'rest_api_init', 'clw_register_rest_route' );

/**
 * Handles the incoming request from the editor to generate link suggestions.
 *
 * @param WP_REST_Request $request The request object from the REST API.
 * @return WP_REST_Response The response containing suggestions or an error.
 */
function clw_handle_suggestions_request( WP_REST_Request $request ) {
	$post_content = $request->get_param( 'content' );
	if ( empty( $post_content ) ) {
		return new WP_REST_Response( [ 'error' => 'Content is empty.' ], 400 );
	}

	$posts = get_posts( [
		'numberposts' => -1,
		'post_status' => 'publish',
		'post_type'   => 'post',
	] );

	$post_list       = [];
	$current_post_id = $request->get_param( 'post_id' );

	foreach ( $posts as $post ) {
		if ( $post->ID == $current_post_id ) {
			continue;
		}
		$post_list[] = [
			'id'    => $post->ID,
			'title' => $post->post_title,
			'url'   => get_permalink( $post->ID ),
		];
	}

	if ( empty( $post_list ) ) {
		return new WP_REST_Response( [ 'error' => 'No other published posts available to link to.' ], 400 );
	}

	$post_list_json = wp_json_encode( $post_list );

	$prompt = "You are an expert SEO who is building an internal link graph for a blog. Your task is to analyze the draft article and suggest internal links.

    Here is a JSON list of all available articles to link to (including their 'id', 'title', and 'url'):
    {$post_list_json}

    Here is the content of the new draft article:
    ---
    {$post_content}
    ---

    Follow these rules STRICTLY:
    1.  The 'anchor_text' MUST be a phrase that exists verbatim within the draft article's content. Do NOT invent or summarize phrases.
    2.  The 'anchor_text' MUST be between 4 and 6 words long. This is a strict range.
    3.  The selected 'anchor_text' should be a self-contained, natural-sounding phrase. Avoid selecting awkward sentence fragments.
    4.  Find up to 5 of the best possible linking opportunities, then find the single most contextually relevant article from the JSON list for each one.
    5.  NEVER use the title of an article from the JSON list as the 'anchor_text' unless that exact phrase also appears in the draft article.

    Return your answer ONLY as a valid JSON array of objects. Each object must have three keys: 'anchor_text', 'post_id_to_link', and 'reasoning'. If you cannot find any good matches that follow all the rules, return an empty array [].";

	$suggestions_from_api = get_gemini_linking_suggestions( $prompt );

	if ( is_wp_error( $suggestions_from_api ) ) {
		return new WP_REST_Response( [ 'error' => $suggestions_from_api->get_error_message() ], 500 );
	}

	if ( ! is_array( $suggestions_from_api ) ) {
		return new WP_REST_Response( [ 'error' => 'API returned a non-array response.' ], 500 );
	}

	$final_suggestions = [];
	foreach ( $suggestions_from_api as $suggestion ) {
		foreach ( $post_list as $post ) {
			if ( $post['id'] == $suggestion['post_id_to_link'] ) {
				$suggestion['title']       = $post['title'];
				$suggestion['url']         = $post['url'];
				$final_suggestions[] = $suggestion;
				break;
			}
		}
	}

	return new WP_REST_Response( $final_suggestions, 200 );
}
