<?php
/**
 * Plugin Name:       Contextual Link Weaver
 * Plugin URI:        https://github.com/geosem42/contextual-link-weaver
 * Description:       Uses Gemini AI and semantic embeddings to provide intelligent, context-aware internal linking suggestions.
 * Version:           2.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
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
		__( 'Contextual Link Weaver Settings', 'contextual-link-weaver' ),
		__( 'Link Weaver', 'contextual-link-weaver' ),
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
		__( 'API Settings', 'contextual-link-weaver' ),
		function () {
			echo '<p>' . esc_html__( 'Enter your Google Gemini API key below. This key is used for both generating embeddings and AI-powered link suggestions.', 'contextual-link-weaver' ) . '</p>';
		},
		'contextual-link-weaver'
	);

	add_settings_field(
		'clw_gemini_api_key_field',
		__( 'Gemini API Key', 'contextual-link-weaver' ),
		function () {
			$api_key = get_option( 'clw_gemini_api_key' );
			printf(
				'<input type="password" name="clw_gemini_api_key" value="%s" size="50" autocomplete="off" />',
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
			submit_button( __( 'Save Settings', 'contextual-link-weaver' ) );
			?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Embedding Index', 'contextual-link-weaver' ); ?></h2>
		<p>
			<?php esc_html_e( 'The embedding index allows Link Weaver to find semantically related posts without sending your entire post catalog to the AI on every request.', 'contextual-link-weaver' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Index Status', 'contextual-link-weaver' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: number of indexed posts, 2: total number of published posts. */
						esc_html__( '%1$s of %2$s posts indexed', 'contextual-link-weaver' ),
						'<span id="clw-indexed-count">' . esc_html( $stats['indexed'] ) . '</span>',
						'<span id="clw-total-count">' . esc_html( $stats['total'] ) . '</span>'
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actions', 'contextual-link-weaver' ); ?></th>
				<td>
					<button type="button" id="clw-index-all-btn" class="button button-secondary">
						<?php esc_html_e( 'Index All Posts', 'contextual-link-weaver' ); ?>
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
		'i18n'     => array(
			'starting'     => __( 'Starting...', 'contextual-link-weaver' ),
			'complete'     => __( 'Indexing complete!', 'contextual-link-weaver' ),
			/* translators: 1: indexed count, 2: total count, 3: percentage. */
			'progress'     => __( 'Indexed %1$s of %2$s (%3$s%%)', 'contextual-link-weaver' ),
			/* translators: %s: number of errors. */
			'batchErrors'  => __( '%s error(s) in this batch', 'contextual-link-weaver' ),
			'error'        => __( 'Error:', 'contextual-link-weaver' ),
			'networkError' => __( 'Network error:', 'contextual-link-weaver' ),
			'unknownError' => __( 'Unknown error', 'contextual-link-weaver' ),
		),
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

	wp_set_script_translations( 'contextual-link-weaver-editor-script', 'contextual-link-weaver' );
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
