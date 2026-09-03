<?php
/**
 * Plugin Name:       Interpost AI Internal Links
 * Plugin URI:        https://logicvoid.dev/plugins/interpost
 * Description:       Uses Gemini AI and semantic embeddings to provide intelligent, context-aware internal linking suggestions.
 * Version:           2.3.0
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

define( 'INTERPOST_VERSION', '2.3.0' );
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
				. esc_html__( 'Rebuilding the index means one Gemini API call for every post, so it costs time and API usage. Leave this off if you might reinstall.', 'interpost' )
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

/**
 * Whether the paid add-on is installed.
 *
 * @return bool
 */
function interpost_pro_installed() {
	return defined( 'INTERPOST_PRO_VERSION' );
}

/**
 * The tabs on the settings screen.
 *
 * The scanning tab is not shown once the add-on that does the scanning is
 * installed, because there is nothing left to explain at that point.
 *
 * @return array<string, string>
 */
function interpost_settings_tabs() {
	$tabs = array( 'settings' => __( 'Settings', 'interpost' ) );

	if ( ! interpost_pro_installed() ) {
		$tabs['scan'] = __( 'Site-wide linking', 'interpost' );
	}

	return $tabs;
}

/**
 * What the paid add-on does, on a page of its own.
 *
 * This is the one place in the plugin that mentions the add-on. It sits behind
 * a tab a person chooses to open, shows what the two reports look like, and
 * links out to a single address. The screens are still pictures built from
 * markup, not controls that have been switched off, and the features still
 * being built are grouped under their own heading and labelled.
 *
 * @return void
 */
function interpost_scan_tab_html() {
	$url = 'https://logicvoid.dev/plugins/interpost?ref=wporg-scan-tab';

	$available = array(
		array(
			'icon'  => 'admin-links',
			'title' => __( 'Orphaned posts', 'interpost' ),
			'body'  => __( 'A report of published posts that nothing else on the site links to. Readers reach them through the archives or not at all, and they are usually the quickest internal linking win on an established site.', 'interpost' ),
		),
		array(
			'icon'  => 'list-view',
			'title' => __( 'Internal link report', 'interpost' ),
			'body'  => __( 'Every internal link in your posts, one row for each place a link appears, with its anchor text and where it points. Broken internal links are visible in a single list.', 'interpost' ),
		),
		array(
			'icon'  => 'filter',
			'title' => __( 'Rules for what is included', 'interpost' ),
			'body'  => __( 'Choose which post types are covered, and include or exclude particular categories and tags. Which posts receive links and which may be linked to are kept as two separate questions.', 'interpost' ),
		),
		array(
			'icon'  => 'edit',
			'title' => __( 'Per-post exceptions', 'interpost' ),
			'body'  => __( 'Two checkboxes in the editor, for a post that should be left alone or held back from suggestions. A setting on the post takes precedence over the rules.', 'interpost' ),
		),
		array(
			'icon'  => 'editor-code',
			'title' => __( 'Command line access', 'interpost' ),
			'body'  => __( 'Build the link report and read both reports with WP-CLI, which helps on hosts where scheduled tasks are unreliable.', 'interpost' ),
		),
	);

	$planned = array(
		array(
			'icon'  => 'search',
			'title' => __( 'Scanning every post at once', 'interpost' ),
			'body'  => __( 'Read the whole site in the background and collect link suggestions for every post, rather than one draft at a time in the editor.', 'interpost' ),
		),
		array(
			'icon'  => 'yes-alt',
			'title' => __( 'A review queue', 'interpost' ),
			'body'  => __( 'Every suggestion waits in a list to be approved or discarded by hand, so a post changes once someone has agreed to it.', 'interpost' ),
		),
		array(
			'icon'  => 'clock',
			'title' => __( 'Scheduled re-scans', 'interpost' ),
			'body'  => __( 'Check the site again on a schedule and report where new posts have arrived with no links pointing to them.', 'interpost' ),
		),
	);

	// Example rows, so the shape of each report is visible. These are made up
	// on purpose and are not read from this site.
	$orphan_rows = array(
		array( __( 'How sleep consolidates memory', 'interpost' ), __( 'Post', 'interpost' ), 3 ),
		array( __( 'A short history of the compass', 'interpost' ), __( 'Post', 'interpost' ), 1 ),
		array( __( 'Pricing', 'interpost' ), __( 'Page', 'interpost' ), 0 ),
	);

	$link_rows = array(
		array( __( 'Why exercise helps focus', 'interpost' ), __( 'morning routine', 'interpost' ), __( 'Building a morning routine', 'interpost' ), false ),
		array( __( 'Building a morning routine', 'interpost' ), __( 'our guide to sleep', 'interpost' ), __( 'How sleep consolidates memory', 'interpost' ), false ),
		array( __( 'A short history of the compass', 'interpost' ), __( 'this old post', 'interpost' ), '/blog/navigation-basics/', true ),
	);
	?>
	<div class="interpost-pro-tab">

		<div class="interpost-pro-hero">
			<div class="interpost-pro-hero-inner">
				<div class="interpost-pro-hero-text">
					<span class="interpost-pro-eyebrow">
						<span class="dashicons dashicons-admin-site-alt3" aria-hidden="true"></span>
						<?php esc_html_e( 'Interpost Pro', 'interpost' ); ?>
					</span>

					<h2><?php esc_html_e( 'See how the whole site links together', 'interpost' ); ?></h2>

					<p>
						<?php esc_html_e( 'This plugin suggests links for the post you are editing, one draft at a time. Looking at a site as a whole is a different job: reading the links already in your posts, finding the ones nothing points at, and deciding which content should be covered.', 'interpost' ); ?>
					</p>
				</div>

				<div class="interpost-pro-hero-buy">
					<p class="interpost-pro-price">$59 <span><?php esc_html_e( 'per year', 'interpost' ); ?></span></p>
					<p class="interpost-pro-price-note"><?php esc_html_e( 'One site. Larger licences available.', 'interpost' ); ?></p>

					<a href="<?php echo esc_url( $url ); ?>" class="interpost-pro-cta" target="_blank" rel="noopener">
						<span class="dashicons dashicons-unlock" aria-hidden="true"></span>
						<?php esc_html_e( 'Upgrade to Pro', 'interpost' ); ?>
					</a>
				</div>
			</div>

			<div class="interpost-pro-split">
				<div class="interpost-pro-side">
					<h3><?php esc_html_e( 'This plugin', 'interpost' ); ?></h3>
					<p><?php esc_html_e( 'Suggestions in the editor for the post in front of you, with the anchor text taken from what you have written.', 'interpost' ); ?></p>
				</div>
				<div class="interpost-pro-side is-pro">
					<h3><?php esc_html_e( 'With the add-on', 'interpost' ); ?></h3>
					<p><?php esc_html_e( 'Reports across every published post, rules for what is covered, and a record of each link on the site.', 'interpost' ); ?></p>
				</div>
			</div>
		</div>

		<div class="interpost-pro-section">
			<h3><?php esc_html_e( 'The screens you get', 'interpost' ); ?></h3>
			<span class="interpost-pro-rule" aria-hidden="true"></span>
		</div>

		<p class="interpost-pro-lede">
			<?php esc_html_e( 'Both reports are added under a menu of their own. The examples below use made up posts to show the shape of each one.', 'interpost' ); ?>
		</p>

		<div class="interpost-pro-preview">
			<div class="interpost-pro-preview-head">
				<span class="dashicons dashicons-admin-links" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Orphaned posts', 'interpost' ); ?></strong>
				<span class="interpost-pro-where"><?php esc_html_e( 'Example', 'interpost' ); ?></span>
			</div>

			<div class="interpost-pro-shot" aria-hidden="true">
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'Post', 'interpost' ); ?></th>
							<th><?php esc_html_e( 'Type', 'interpost' ); ?></th>
							<th><?php esc_html_e( 'Incoming', 'interpost' ); ?></th>
							<th><?php esc_html_e( 'Outgoing', 'interpost' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $orphan_rows as $row ) : ?>
							<tr>
								<td><span class="interpost-pro-title"><?php echo esc_html( $row[0] ); ?></span></td>
								<td><span class="interpost-pro-chip"><?php echo esc_html( $row[1] ); ?></span></td>
								<td><span class="interpost-pro-zero">0</span></td>
								<td><span class="interpost-pro-count"><?php echo esc_html( (string) $row[2] ); ?></span></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="interpost-pro-lock">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<p>
						<?php esc_html_e( 'Run this report on your own posts with Interpost Pro', 'interpost' ); ?><br />
						<small><?php esc_html_e( 'Sortable, filterable by post type, and searchable.', 'interpost' ); ?></small>
					</p>
					<a href="<?php echo esc_url( $url ); ?>" class="interpost-pro-cta interpost-pro-cta-small" target="_blank" rel="noopener">
						<?php esc_html_e( 'Upgrade to Pro', 'interpost' ); ?>
					</a>
				</div>
			</div>
		</div>

		<div class="interpost-pro-preview">
			<div class="interpost-pro-preview-head">
				<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
				<strong><?php esc_html_e( 'Internal link report', 'interpost' ); ?></strong>
				<span class="interpost-pro-where"><?php esc_html_e( 'Example', 'interpost' ); ?></span>
			</div>

			<div class="interpost-pro-shot" aria-hidden="true">
				<table>
					<thead>
						<tr>
							<th><?php esc_html_e( 'From', 'interpost' ); ?></th>
							<th><?php esc_html_e( 'Anchor text', 'interpost' ); ?></th>
							<th><?php esc_html_e( 'To', 'interpost' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $link_rows as $row ) : ?>
							<tr>
								<td><span class="interpost-pro-title"><?php echo esc_html( $row[0] ); ?></span></td>
								<td><?php echo esc_html( $row[1] ); ?></td>
								<td>
									<?php if ( $row[3] ) : ?>
										<span class="interpost-pro-zero"><?php esc_html_e( 'Broken', 'interpost' ); ?></span>
										<code><?php echo esc_html( $row[2] ); ?></code>
									<?php else : ?>
										<?php echo esc_html( $row[2] ); ?>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>

				<div class="interpost-pro-lock">
					<span class="dashicons dashicons-lock" aria-hidden="true"></span>
					<p>
						<?php esc_html_e( 'See every internal link on your site with Interpost Pro', 'interpost' ); ?><br />
						<small><?php esc_html_e( 'Including the ones that point at pages which no longer exist.', 'interpost' ); ?></small>
					</p>
					<a href="<?php echo esc_url( $url ); ?>" class="interpost-pro-cta interpost-pro-cta-small" target="_blank" rel="noopener">
						<?php esc_html_e( 'Upgrade to Pro', 'interpost' ); ?>
					</a>
				</div>
			</div>
		</div>

		<div class="interpost-pro-section">
			<h3><?php esc_html_e( 'Everything included', 'interpost' ); ?></h3>
			<span class="interpost-pro-rule" aria-hidden="true"></span>
		</div>

		<div class="interpost-pro-grid">
			<?php foreach ( $available as $item ) : ?>
				<div class="interpost-pro-card">
					<div class="interpost-pro-card-top">
						<span class="interpost-pro-icon">
							<span class="dashicons dashicons-<?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
						</span>
						<h4><?php echo esc_html( $item['title'] ); ?></h4>
						<span class="interpost-pro-badge is-live">
							<span class="dashicons dashicons-yes" aria-hidden="true"></span>
							<?php esc_html_e( 'Included', 'interpost' ); ?>
						</span>
					</div>
					<p><?php echo esc_html( $item['body'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="interpost-pro-section">
			<h3><?php esc_html_e( 'Being built', 'interpost' ); ?></h3>
			<span class="interpost-pro-rule" aria-hidden="true"></span>
		</div>

		<p class="interpost-pro-lede">
			<?php esc_html_e( 'These are in development and are not part of the add-on today. They are listed so you can see where it is going.', 'interpost' ); ?>
		</p>

		<div class="interpost-pro-grid">
			<?php foreach ( $planned as $item ) : ?>
				<div class="interpost-pro-card is-planned">
					<div class="interpost-pro-card-top">
						<span class="interpost-pro-icon">
							<span class="dashicons dashicons-<?php echo esc_attr( $item['icon'] ); ?>" aria-hidden="true"></span>
						</span>
						<h4><?php echo esc_html( $item['title'] ); ?></h4>
						<span class="interpost-pro-badge is-soon">
							<?php esc_html_e( 'In development', 'interpost' ); ?>
						</span>
					</div>
					<p><?php echo esc_html( $item['body'] ); ?></p>
				</div>
			<?php endforeach; ?>
		</div>

		<div class="interpost-pro-close">
			<div>
				<h3><?php esc_html_e( 'A separate plugin, installed alongside this one', 'interpost' ); ?></h3>
				<p><?php esc_html_e( 'This plugin stays free and the add-on requires it, so nothing you have set up here is replaced. Licences cover one, five or twenty sites.', 'interpost' ); ?></p>
			</div>

			<a href="<?php echo esc_url( $url ); ?>" class="interpost-pro-cta" target="_blank" rel="noopener">
				<span class="dashicons dashicons-unlock" aria-hidden="true"></span>
				<?php esc_html_e( 'Upgrade to Pro', 'interpost' ); ?>
			</a>
		</div>

		<p class="interpost-pro-note">
			<?php esc_html_e( 'This tab is hidden once the add-on is installed.', 'interpost' ); ?>
		</p>
	</div>
	<?php
}

function interpost_settings_page_html() {
	if ( ! current_user_can( 'manage_options' ) ) {
		return;
	}

	$tabs = interpost_settings_tabs();

	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab to draw, not acting on it.
	$current = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';
	$current = isset( $tabs[ $current ] ) ? $current : 'settings';

	$stats = Interpost_Database::get_index_stats();
	?>
	<div class="wrap">
		<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

		<?php if ( count( $tabs ) > 1 ) : ?>
			<nav class="nav-tab-wrapper wp-clearfix" aria-label="<?php esc_attr_e( 'Secondary menu', 'interpost' ); ?>">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a
						href="<?php echo esc_url( add_query_arg( array( 'page' => 'interpost', 'tab' => $slug ), admin_url( 'options-general.php' ) ) ); ?>"
						class="nav-tab <?php echo $current === $slug ? 'nav-tab-active' : ''; ?>"
					><?php echo esc_html( $label ); ?></a>
				<?php endforeach; ?>
			</nav>
		<?php endif; ?>

		<?php
		if ( 'scan' === $current ) {
			interpost_scan_tab_html();
			echo '</div>';

			return;
		}
		?>

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

	/*
	 * The add-on tab carries its own layout, and the indexing script has
	 * nothing to do there. Load one or the other rather than both on both.
	 */
	// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- reading which tab is open, not acting on it.
	$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'settings';

	if ( 'scan' === $tab && ! interpost_pro_installed() ) {
		wp_enqueue_style(
			'interpost-pro-tab',
			INTERPOST_PLUGIN_URL . 'assets/pro-tab.css',
			array( 'dashicons' ),
			INTERPOST_VERSION
		);

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
	$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;

	// The sidebar has nothing to offer on a post type Interpost does not
	// index: it would search a corpus that cannot contain this post.
	if ( $screen && ! in_array( $screen->post_type, Interpost_Database::indexed_post_types(), true ) ) {
		return;
	}

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
