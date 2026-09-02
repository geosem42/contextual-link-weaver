<?php
/**
 * Plugin Name:       Interpost AI Internal Links
 * Plugin URI:        https://logicvoid.dev/plugins/interpost
 * Description:       Uses Gemini AI and semantic embeddings to provide intelligent, context-aware internal linking suggestions.
 * Version:           2.1.1
 * Requires at least: 6.8
 * Requires PHP:      8.2
 * Author:            George Semaan
 * Author URI:        https://logicvoid.dev
 * License:           GPL v2 or later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain:       interpost
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'INTERPOST_VERSION', '2.1.1' );
define( 'INTERPOST_PLUGIN_DIR', plugin_dir_path( __FILE__ ) );
define( 'INTERPOST_PLUGIN_URL', plugin_dir_url( __FILE__ ) );
define( 'INTERPOST_PLUGIN_FILE', __FILE__ );

// Load class files.
require_once INTERPOST_PLUGIN_DIR . 'includes/class-interpost-database.php';
require_once INTERPOST_PLUGIN_DIR . 'includes/class-interpost-embeddings.php';
require_once INTERPOST_PLUGIN_DIR . 'includes/class-interpost-suggestions.php';
require_once INTERPOST_PLUGIN_DIR . 'includes/class-interpost-rest-api.php';

/*
|--------------------------------------------------------------------------
| Activation
|--------------------------------------------------------------------------
*/

register_activation_hook( __FILE__, array( 'Interpost_Database', 'create_table' ) );

/*
|--------------------------------------------------------------------------
| Admin Settings Page
|--------------------------------------------------------------------------
*/

add_action( 'admin_menu', 'interpost_add_admin_menu' );
add_action( 'admin_init', 'interpost_settings_init' );

function interpost_add_admin_menu() {
	add_options_page(
		__( 'Interpost Settings', 'interpost' ),
		__( 'Interpost', 'interpost' ),
		'manage_options',
		'interpost',
		'interpost_settings_page_html'
	);
}

function interpost_settings_init() {
	register_setting( 'interpost_settings_group', 'interpost_gemini_api_key', array(
		'sanitize_callback' => 'sanitize_text_field',
		'type'              => 'string',
	) );

	add_settings_section(
		'interpost_api_settings_section',
		__( 'API Settings', 'interpost' ),
		function () {
			echo '<p>' . esc_html__( 'Enter your Google Gemini API key below. This key is used for both generating embeddings and AI-powered link suggestions.', 'interpost' ) . '</p>';
		},
		'interpost'
	);

	add_settings_field(
		'interpost_gemini_api_key_field',
		__( 'Gemini API Key', 'interpost' ),
		function () {
			$api_key = get_option( 'interpost_gemini_api_key' );
			printf(
				'<input type="password" name="interpost_gemini_api_key" value="%s" size="50" autocomplete="off" />',
				esc_attr( $api_key )
			);
		},
		'interpost',
		'interpost_api_settings_section'
	);

	register_setting( 'interpost_settings_group', 'interpost_delete_data', array(
		'sanitize_callback' => 'interpost_sanitize_checkbox',
		'type'              => 'boolean',
		'default'           => 0,
	) );

	add_settings_section(
		'interpost_data_settings_section',
		__( 'Deleting this plugin', 'interpost' ),
		function () {
			echo '<p>' . esc_html__( 'Deactivating Interpost changes nothing. This only applies when you delete the plugin from the Plugins screen.', 'interpost' ) . '</p>';
		},
		'interpost'
	);

	add_settings_field(
		'interpost_delete_data_field',
		__( 'On deletion', 'interpost' ),
		function () {
			printf(
				'<label><input type="checkbox" name="interpost_delete_data" value="1" %s /> %s</label>',
				checked( (bool) get_option( 'interpost_delete_data' ), true, false ),
				esc_html__( 'Also remove the embedding index and the API key', 'interpost' )
			);

			echo '<p class="description" style="max-width: 40em;">'
				. esc_html__( 'Rebuilding the index means one API call for every post, so it costs time and whatever your provider charges. Leave this off if you might reinstall.', 'interpost' )
				. '</p>';
		},
		'interpost',
		'interpost_data_settings_section'
	);
}

/**
 * An unchecked box is not posted at all, so anything present means checked.
 *
 * @param mixed $value
 * @return int
 */
function interpost_sanitize_checkbox( $value ) {
	return empty( $value ) ? 0 : 1;
}

function interpost_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$stats = Interpost_Database::get_index_stats();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<form action="options.php" method="post">
			<?php
			settings_fields( 'interpost_settings_group' );
			do_settings_sections( 'interpost' );
			submit_button( __( 'Save Settings', 'interpost' ) );
			?>
		</form>

		<hr />

		<h2><?php esc_html_e( 'Embedding Index', 'interpost' ); ?></h2>
		<p>
			<?php esc_html_e( 'The embedding index allows Interpost to find semantically related posts without sending your entire post catalog to the AI on every request.', 'interpost' ); ?>
		</p>

		<table class="form-table">
			<tr>
				<th scope="row"><?php esc_html_e( 'Index Status', 'interpost' ); ?></th>
				<td>
					<?php
					printf(
						/* translators: 1: number of indexed posts, 2: total number of published posts. */
						esc_html__( '%1$s of %2$s posts indexed', 'interpost' ),
						'<span id="interpost-indexed-count">' . esc_html( $stats['indexed'] ) . '</span>',
						'<span id="interpost-total-count">' . esc_html( $stats['total'] ) . '</span>'
					);
					?>
				</td>
			</tr>
			<tr>
				<th scope="row"><?php esc_html_e( 'Actions', 'interpost' ); ?></th>
				<td>
					<button type="button" id="interpost-index-all-btn" class="button button-secondary">
						<?php esc_html_e( 'Index All Posts', 'interpost' ); ?>
					</button>
					<span id="interpost-index-status-text" style="margin-left: 10px;"></span>

					<div id="interpost-progress-bar-container" style="display: none; margin-top: 10px; width: 400px; background: #e0e0e0; border-radius: 3px;">
						<div id="interpost-progress-bar" style="width: 0%; height: 24px; background: #0073aa; border-radius: 3px; transition: width 0.3s;"></div>
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

add_action( 'admin_enqueue_scripts', 'interpost_enqueue_admin_assets' );

function interpost_enqueue_admin_assets( $hook_suffix ) {
	$screens = apply_filters( 'interpost_admin_screens', array( 'settings_page_interpost' ) );

	if ( ! is_array( $screens ) || ! in_array( $hook_suffix, $screens, true ) ) {
		return;
	}

	wp_enqueue_script(
		'interpost-admin-script',
		INTERPOST_PLUGIN_URL . 'assets/admin.js',
		array(),
		INTERPOST_VERSION,
		true
	);

	wp_localize_script( 'interpost-admin-script', 'interpostAdmin', array(
		'ajax_url' => admin_url( 'admin-ajax.php' ),
		'nonce'    => wp_create_nonce( 'interpost_bulk_index_nonce' ),
		'i18n'     => array(
			'starting'     => __( 'Starting...', 'interpost' ),
			'complete'     => __( 'Indexing complete!', 'interpost' ),
			/* translators: 1: indexed count, 2: total count, 3: percentage. The placeholders are replaced in JavaScript, so a literal percent sign is not escaped. */
			'progress'     => __( 'Indexed %1$s of %2$s (%3$s%)', 'interpost' ),
			/* translators: %s: number of errors. */
			'batchErrors'  => __( '%s error(s) in this batch', 'interpost' ),
			/* translators: %s: number of posts that could not be indexed. */
			'finishedWithErrors' => __( 'Indexing finished. %s post(s) could not be indexed.', 'interpost' ),
			/* translators: %s: number of posts that could not be indexed. */
			'stalled'      => __( 'Indexing stopped: nothing in the last batch could be indexed. %s post(s) failed. Check your API key and quota.', 'interpost' ),
			'error'        => __( 'Error:', 'interpost' ),
			'networkError' => __( 'Network error:', 'interpost' ),
			'unknownError' => __( 'Unknown error', 'interpost' ),
		),
	) );
}

/*
|--------------------------------------------------------------------------
| AJAX Handlers
|--------------------------------------------------------------------------
*/

add_action( 'wp_ajax_interpost_bulk_index', array( 'Interpost_Embeddings', 'ajax_bulk_index' ) );
add_action( 'wp_ajax_interpost_index_status', array( 'Interpost_Database', 'ajax_index_status' ) );

/*
|--------------------------------------------------------------------------
| Gutenberg Editor Assets
|--------------------------------------------------------------------------
*/

add_action( 'enqueue_block_editor_assets', 'interpost_enqueue_editor_assets' );

function interpost_enqueue_editor_assets() {
	$asset_file_path = INTERPOST_PLUGIN_DIR . 'build/index.asset.php';

	if ( ! file_exists( $asset_file_path ) ) {
		return;
	}

	$asset_file = include $asset_file_path;

	wp_enqueue_script(
		'interpost-editor-script',
		INTERPOST_PLUGIN_URL . 'build/index.js',
		$asset_file['dependencies'],
		$asset_file['version'],
		array( 'in_footer' => true )
	);

	wp_set_script_translations( 'interpost-editor-script', 'interpost' );
}

/*
|--------------------------------------------------------------------------
| Auto-Index on Post Save
|--------------------------------------------------------------------------
*/

add_action( 'save_post', array( 'Interpost_Embeddings', 'on_save_post' ), 10, 3 );

/*
|--------------------------------------------------------------------------
| Clean Up After Deleted Posts
|--------------------------------------------------------------------------
|
| Trashing a post goes through save_post, but deleting one for good does
| not. Without this the embedding outlives the post and keeps competing
| for a place in the suggestions.
|
*/

add_action( 'deleted_post', 'interpost_on_deleted_post' );

function interpost_on_deleted_post( $post_id ) {
	Interpost_Database::delete_embedding( (int) $post_id );
}

/*
|--------------------------------------------------------------------------
| REST API
|--------------------------------------------------------------------------
*/

add_action( 'rest_api_init', array( 'Interpost_Rest_API', 'register_routes' ) );
