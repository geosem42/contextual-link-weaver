<?php
/**
 * Plugin Name:       Pagelace Internal Links
 * Plugin URI:        https://github.com/geosem42/contextual-link-weaver
 * Description:       Uses Gemini AI and semantic embeddings to provide intelligent, context-aware internal linking suggestions.
 * Version:           2.0.0
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            George Semaan
 * Author URI:        https://logicvoid.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       pagelace-internal-links
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'PAGELACE_VERSION', '2.0.0' );
define( 'PAGELACE_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'PAGELACE_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'PAGELACE_PLUGIN_FILE', __FILE__ );

// Load class files.
require_once PAGELACE_PLUGIN_DIR . 'includes/class-pagelace-database.php';
require_once PAGELACE_PLUGIN_DIR . 'includes/class-pagelace-embeddings.php';
require_once PAGELACE_PLUGIN_DIR . 'includes/class-pagelace-suggestions.php';
require_once PAGELACE_PLUGIN_DIR . 'includes/class-pagelace-rest-api.php';

/*
|--------------------------------------------------------------------------
| Activation
|--------------------------------------------------------------------------
*/

register_activation_hook( __FILE__, array( 'Pagelace_Database', 'create_table' ) );

/*
|--------------------------------------------------------------------------
| Admin Settings Page
|--------------------------------------------------------------------------
*/

add_action( 'admin_menu', 'pagelace_add_admin_menu' );
add_action( 'admin_init', 'pagelace_settings_init' );

function pagelace_add_admin_menu() {
	add_options_page(
		__( 'Pagelace Internal Links Settings', 'pagelace-internal-links' ),
		__( 'Pagelace', 'pagelace-internal-links' ),
		'manage_options',
		'pagelace-internal-links',
		'pagelace_settings_page_html'
	);
}

function pagelace_settings_init() {
	register_setting( 'pagelace_settings_group', 'pagelace_gemini_api_key', array(
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	) );

	add_settings_section(
		'pagelace_api_settings_section',
		__( 'API Settings', 'pagelace-internal-links' ),
		function () {
			echo '<p>' . esc_html__( 'Enter your Google Gemini API key below. This key is used for both generating embeddings and AI-powered link suggestions.', 'pagelace-internal-links' ) . '</p>';
		},
		'pagelace-internal-links'
	);

	add_settings_field(
		'pagelace_gemini_api_key_field',
		__( 'Gemini API Key', 'pagelace-internal-links' ),
		function () {
			$api_key = get_option( 'pagelace_gemini_api_key' );
			printf(
				'<input type="password" name="pagelace_gemini_api_key" value="%s" size="50" autocomplete="off" />',
				esc_attr( $api_key )
			);
		},
		'pagelace-internal-links',
		'pagelace_api_settings_section'
	);
}

function pagelace_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stats = Pagelace_Database::get_index_stats();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'pagelace_settings_group' );
			do_settings_sections( 'pagelace-internal-links' );
			submit_button( __( 'Save Settings', 'pagelace-internal-links' ) );
			?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Embedding Index', 'pagelace-internal-links' ); ?></h2>
		<p>
			<?php esc_html_e( 'The embedding index allows Pagelace to find semantically related posts without sending your entire post catalog to the AI on every request.', 'pagelace-internal-links' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Index Status', 'pagelace-internal-links' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: number of indexed posts, 2: total number of published posts. */
						esc_html__( '%1$s of %2$s posts indexed', 'pagelace-internal-links' ),
						'<span id="pagelace-indexed-count">' . esc_html( $stats['indexed'] ) . '</span>',
						'<span id="pagelace-total-count">' . esc_html( $stats['total'] ) . '</span>'
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actions', 'pagelace-internal-links' ); ?></th>
				<td>
					<button type="button" id="pagelace-index-all-btn" class="button button-secondary">
						<?php esc_html_e( 'Index All Posts', 'pagelace-internal-links' ); ?>
					</button>
					<span id="pagelace-index-status-text" style="margin-left: 10px;"></span>

					<div id="pagelace-progress-bar-container" style="display: none; margin-top: 10px; width: 400px; background: #e0e0e0; border-radius: 3px;">
						<div id="pagelace-progress-bar" style="width: 0%; height: 24px; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
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

add_action( 'admin_enqueue_scripts', 'pagelace_enqueue_admin_assets' );

function pagelace_enqueue_admin_assets( $hook_suffix ) {
	if ( $hook_suffix !== 'settings_page_pagelace-internal-links' ) {
		return;
	}

	wp_enqueue_script(
		'pagelace-admin-script',
		PAGELACE_PLUGIN_URL . 'assets/admin.js',
		array(),
		PAGELACE_VERSION,
		true
	);

	wp_localize_script( 'pagelace-admin-script', 'pagelaceAdmin', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'pagelace_bulk_index_nonce' ),
		'i18n'     => array(
			'starting'     => __( 'Starting...', 'pagelace-internal-links' ),
			'complete'     => __( 'Indexing complete!', 'pagelace-internal-links' ),
			/* translators: 1: indexed count, 2: total count, 3: percentage. */
			'progress'     => __( 'Indexed %1$s of %2$s (%3$s%%)', 'pagelace-internal-links' ),
			/* translators: %s: number of errors. */
			'batchErrors'  => __( '%s error(s) in this batch', 'pagelace-internal-links' ),
			'error'        => __( 'Error:', 'pagelace-internal-links' ),
			'networkError' => __( 'Network error:', 'pagelace-internal-links' ),
			'unknownError' => __( 'Unknown error', 'pagelace-internal-links' ),
		),
	) );
}

/*
|--------------------------------------------------------------------------
| AJAX Handlers
|--------------------------------------------------------------------------
*/

add_action( 'wp_ajax_pagelace_bulk_index', array( 'Pagelace_Embeddings', 'ajax_bulk_index' ) );
add_action( 'wp_ajax_pagelace_index_status', array( 'Pagelace_Database', 'ajax_index_status' ) );

/*
|--------------------------------------------------------------------------
| Gutenberg Editor Assets
|--------------------------------------------------------------------------
*/

add_action( 'enqueue_block_editor_assets', 'pagelace_enqueue_editor_assets' );

function pagelace_enqueue_editor_assets() {
	$asset_file_path = PAGELACE_PLUGIN_DIR . 'build/index.asset.php';

	if ( ! file_exists( $asset_file_path ) ) {
		return;
	}

	$asset_file = include $asset_file_path;

	wp_enqueue_script(
		'pagelace-internal-links-editor-script',
		PAGELACE_PLUGIN_URL . 'build/index.js',
		$asset_file['dependencies'],
		$asset_file['version'],
		array( 'in_footer' => true )
	);

	wp_set_script_translations( 'pagelace-internal-links-editor-script', 'pagelace-internal-links' );
}

/*
|--------------------------------------------------------------------------
| Auto-Index on Post Save
|--------------------------------------------------------------------------
*/

add_action( 'save_post', array( 'Pagelace_Embeddings', 'on_save_post' ), 10, 3 );

/*
|--------------------------------------------------------------------------
| REST API
|--------------------------------------------------------------------------
*/

add_action( 'rest_api_init', array( 'Pagelace_Rest_API', 'register_routes' ) );
