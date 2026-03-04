<?php
/**
 * Plugin Name:       Contextual Link Weaver
 * Plugin URI:        https://github.com/geosem42/contextual-link-weaver
 * Description:       Uses Gemini AI and semantic embeddings to provide intelligent, context-aware internal linking suggestions.
 * Version:           2.0.0
 * Author:            George Semaan
 * Author URI:        https://logicvoid.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       contextual-link-weaver
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'CLW_VERSION', '2.0.0' );
define( 'CLW_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'CLW_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'CLW_PLUGIN_FILE', __FILE__ );

// Load class files.
require_once CLW_PLUGIN_DIR . 'includes/class-clw-database.php';
require_once CLW_PLUGIN_DIR . 'includes/class-clw-embeddings.php';
require_once CLW_PLUGIN_DIR . 'includes/class-clw-suggestions.php';
require_once CLW_PLUGIN_DIR . 'includes/class-clw-rest-api.php';

/*
|--------------------------------------------------------------------------
| Activation
|--------------------------------------------------------------------------
*/

register_activation_hook( __FILE__, array( 'CLW_Database', 'create_table' ) );

/*
|--------------------------------------------------------------------------
| Admin Settings Page
|--------------------------------------------------------------------------
*/

add_action( 'admin_menu', 'clw_add_admin_menu' );
add_action( 'admin_init', 'clw_settings_init' );

function clw_add_admin_menu() {
	add_options_page(
		'Contextual Link Weaver Settings',
		'Link Weaver',
		'manage_options',
		'contextual-link-weaver',
		'clw_settings_page_html'
	);
}

function clw_settings_init() {
	register_setting( 'clw_settings_group', 'clw_gemini_api_key', array(
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	) );

	add_settings_section(
		'clw_api_settings_section',
		'API Settings',
		function () {
			echo '<p>Enter your Google Gemini API key below. This key is used for both generating embeddings and AI-powered link suggestions.</p>';
		},
		'contextual-link-weaver'
	);

	add_settings_field(
		'clw_gemini_api_key_field',
		'Gemini API Key',
		function () {
			$api_key = get_option( 'clw_gemini_api_key' );
			printf(
				'<input type="password" name="clw_gemini_api_key" value="%s" size="50" />',
				esc_attr( $api_key )
			);
		},
		'contextual-link-weaver',
		'clw_api_settings_section'
	);
}

function clw_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stats = CLW_Database::get_index_stats();
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

		<hr />

		<h2>Embedding Index</h2>
		<p>
			The embedding index allows Link Weaver to find semantically related posts without
			sending your entire post catalog to the AI on every request.
		</p>

		<table class="form-table">
			<tr>
				<th scope="row">Index Status</th>
				<td>
					<span id="clw-indexed-count"><?php echo esc_html( $stats['indexed'] ); ?></span>
					of
					<span id="clw-total-count"><?php echo esc_html( $stats['total'] ); ?></span>
					posts indexed
				</td>
			</tr>
			<tr>
				<th scope="row">Actions</th>
				<td>
					<button type="button" id="clw-index-all-btn" class="button button-secondary">
						Index All Posts
					</button>
					<span id="clw-index-status-text" style="margin-left: 10px;"></span>

					<div id="clw-progress-bar-container" style="display: none; margin-top: 10px; width: 400px; background: #e0e0e0; border-radius: 3px;">
						<div id="clw-progress-bar" style="width: 0%; height: 24px; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
					</div>
				</td>
			</tr>
		</table>
	</div>
	<?php
}

/*
|--------------------------------------------------------------------------
| Admin Assets (settings page only)
|--------------------------------------------------------------------------
*/

add_action( 'admin_enqueue_scripts', 'clw_enqueue_admin_assets' );

function clw_enqueue_admin_assets( $hook_suffix ) {
	if ( $hook_suffix !== 'settings_page_contextual-link-weaver' ) {
		return;
	}

	wp_enqueue_script(
		'clw-admin-script',
		CLW_PLUGIN_URL . 'assets/admin.js',
		array(),
		CLW_VERSION,
		true
	);

	wp_localize_script( 'clw-admin-script', 'clwAdmin', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'clw_bulk_index_nonce' ),
	) );
}

/*
|--------------------------------------------------------------------------
| AJAX Handlers
|--------------------------------------------------------------------------
*/

add_action( 'wp_ajax_clw_bulk_index', array( 'CLW_Embeddings', 'ajax_bulk_index' ) );
add_action( 'wp_ajax_clw_index_status', array( 'CLW_Database', 'ajax_index_status' ) );

/*
|--------------------------------------------------------------------------
| Gutenberg Editor Assets
|--------------------------------------------------------------------------
*/

add_action( 'enqueue_block_editor_assets', 'clw_enqueue_editor_assets' );

function clw_enqueue_editor_assets() {
	$asset_file_path = CLW_PLUGIN_DIR . 'build/index.asset.php';

	if ( ! file_exists( $asset_file_path ) ) {
		return;
	}

	$asset_file = include $asset_file_path;

	wp_enqueue_script(
		'contextual-link-weaver-editor-script',
		CLW_PLUGIN_URL . 'build/index.js',
		$asset_file['dependencies'],
		$asset_file['version']
	);
}

/*
|--------------------------------------------------------------------------
| Auto-Index on Post Save
|--------------------------------------------------------------------------
*/

add_action( 'save_post', array( 'CLW_Embeddings', 'on_save_post' ), 10, 3 );

/*
|--------------------------------------------------------------------------
| REST API
|--------------------------------------------------------------------------
*/

add_action( 'rest_api_init', array( 'CLW_Rest_API', 'register_routes' ) );
