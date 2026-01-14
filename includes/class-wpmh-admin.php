<?php
/**
 * Admin Interface
 *
 * Handles the WordPress admin UI with toggle switches and action buttons.
 *
 * @package WebPMediaHandler
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Class
 */
class WPMH_Admin {

	/**
	 * Settings manager instance
	 *
	 * @var WPMH_Settings_Manager
	 */
	private $settings;

	/**
	 * Feature instances
	 *
	 * @var array
	 */
	private $features;

	/**
	 * Constructor
	 *
	 * @param WPMH_Settings_Manager $settings Settings manager instance.
	 * @param array                  $features Feature instances.
	 */
	public function __construct( $settings, $features ) {
		$this->settings = $settings;
		$this->features = $features;
		$this->init_hooks();
	}

	/**
	 * Initialize hooks
	 */
	private function init_hooks() {
		add_action( 'admin_menu', array( $this, 'add_admin_menu' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
		add_action( 'wp_ajax_wpmh_toggle_feature', array( $this, 'handle_toggle' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'WebP Media Handler', 'webp-media-handler' ),
			__( 'WebP Media', 'webp-media-handler' ),
			'manage_options',
			'webp-media-handler',
			array( $this, 'render_admin_page' ),
			'dashicons-format-image',
			80
		);
	}

	/**
	 * Enqueue admin scripts and styles
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( 'toplevel_page_webp-media-handler' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wpmh-admin-style',
			WPMH_PLUGIN_URL . 'assets/admin.css',
			array(),
			WPMH_VERSION
		);

		wp_enqueue_script(
			'wpmh-admin-script',
			WPMH_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			WPMH_VERSION,
			true
		);

		wp_localize_script(
			'wpmh-admin-script',
			'wpmhAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'toggle'              => wp_create_nonce( 'wpmh_toggle_feature' ),
					'convert_existing'    => wp_create_nonce( 'wpmh_convert_existing_webp' ),
					'replace_urls'        => wp_create_nonce( 'wpmh_replace_image_urls' ),
				),
				'strings' => array(
					'confirmConvert' => __( 'This will convert all existing JPEG/PNG images to WebP. This action cannot be undone automatically. Continue?', 'webp-media-handler' ),
					'confirmReplace' => __( 'This will replace image URLs throughout your site. This action cannot be undone automatically. Continue?', 'webp-media-handler' ),
					'processing'     => __( 'Processing...', 'webp-media-handler' ),
					'error'          => __( 'An error occurred. Please try again.', 'webp-media-handler' ),
				),
			)
		);
	}

	/**
	 * Handle feature toggle
	 */
	public function handle_toggle() {
		// Security checks
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
		}

		check_ajax_referer( 'wpmh_toggle_feature', 'nonce' );

		// WordPress.org compliance: wp_unslash() before sanitization
		$feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';
		// Plugin Check: wp_unslash() before sanitization for all $_POST access.
		$enabled = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '';
		// Preserve original boolean behavior (AJAX sends 1/0).
		$enabled = ( '1' === $enabled || 'true' === $enabled );

		// Validate feature name
		$allowed_features = array( 'disable_image_sizes', 'auto_webp_convert' );
		if ( ! in_array( $feature, $allowed_features, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid feature.', 'webp-media-handler' ) ) );
		}

		// Update setting
		$this->settings->set( $feature, $enabled );

		wp_send_json_success( array(
			'message' => $enabled
				? __( 'Feature enabled.', 'webp-media-handler' )
				: __( 'Feature disabled.', 'webp-media-handler' ),
		) );
	}

	/**
	 * Render admin page
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		?>
		<div class="wrap wpmh-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="wpmh-admin-container">
				<?php $this->render_feature_card(
					'disable_image_sizes',
					__( 'Disable WordPress Default Image Sizes', 'webp-media-handler' ),
					$this->features['disable_image_sizes']->get_description(),
					false // No action button
				); ?>

				<?php $this->render_feature_card(
					'auto_webp_convert',
					__( 'Auto Convert Newly Uploaded Images to WebP', 'webp-media-handler' ),
					$this->features['auto_webp_convert']->get_description(),
					false // No action button
				); ?>

				<?php $this->render_action_card(
					'convert_existing',
					__( 'Convert Existing Media Library Images to WebP', 'webp-media-handler' ),
					$this->features['convert_existing']->get_description(),
					'wpmh_convert_existing_webp',
					__( 'Convert Existing Images', 'webp-media-handler' )
				); ?>

				<?php $this->render_action_card(
					'replace_urls',
					__( 'Replace Existing Image URLs with WebP', 'webp-media-handler' ),
					$this->features['replace_urls']->get_description(),
					'wpmh_replace_image_urls',
					__( 'Replace Image URLs', 'webp-media-handler' )
				); ?>
			</div>

			<?php $this->render_precaution_note(); ?>

			<?php $this->render_support_section(); ?>
		</div>
		<?php
	}

	/**
	 * Render feature card with toggle
	 *
	 * @param string $feature_key Feature key.
	 * @param string $title Feature title.
	 * @param string $description Feature description.
	 * @param bool   $has_action Whether this feature has an action button.
	 */
	private function render_feature_card( $feature_key, $title, $description, $has_action = false ) {
		$enabled = $this->settings->get( $feature_key, false );
		?>
		<div class="wpmh-feature-card">
			<div class="wpmh-feature-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<label class="wpmh-toggle-switch">
					<input type="checkbox" 
					       class="wpmh-feature-toggle" 
					       data-feature="<?php echo esc_attr( $feature_key ); ?>"
					       <?php checked( $enabled, true ); ?>>
					<span class="wpmh-toggle-slider"></span>
				</label>
			</div>
			<div class="wpmh-feature-description">
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<?php if ( $has_action ) : ?>
				<div class="wpmh-feature-actions">
					<button type="button" class="button button-primary wpmh-action-button" data-action="<?php echo esc_attr( $feature_key ); ?>">
						<?php echo esc_html( $title ); ?>
					</button>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render action card (no toggle, just action button)
	 *
	 * @param string $action_key Action key.
	 * @param string $title Action title.
	 * @param string $description Action description.
	 * @param string $nonce_action Nonce action name.
	 * @param string $button_text Button text.
	 */
	private function render_action_card( $action_key, $title, $description, $nonce_action, $button_text ) {
		$log = $this->settings->get_action_log( $action_key );
		$last_run = $log ? $log['timestamp'] : '';
		?>
		<div class="wpmh-feature-card wpmh-action-card">
			<div class="wpmh-feature-header">
				<h2><?php echo esc_html( $title ); ?></h2>
			</div>
			<div class="wpmh-feature-description">
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<?php if ( $last_run ) : ?>
				<div class="wpmh-last-run">
					<strong><?php esc_html_e( 'Last run:', 'webp-media-handler' ); ?></strong>
					<?php echo esc_html( $last_run ); ?>
				</div>
			<?php endif; ?>
			<div class="wpmh-feature-actions">
				<button type="button" 
				        class="button button-primary wpmh-action-button" 
				        data-action="<?php echo esc_attr( $action_key ); ?>"
				        data-nonce-action="<?php echo esc_attr( $nonce_action ); ?>">
					<?php echo esc_html( $button_text ); ?>
				</button>
				<div class="wpmh-action-status" id="wpmh-status-<?php echo esc_attr( $action_key ); ?>"></div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render precaution note
	 * 
	 * Displays informational backup recommendation between feature cards and support section.
	 * Neutral, informational tone - not a warning or disclaimer. Compliant with WordPress.org
	 * guidelines: informational only, does not block actions, non-alarming appearance.
	 */
	private function render_precaution_note() {
		?>
		<div class="wpmh-precaution-note">
			<p>
				<strong><?php esc_html_e( 'Note:', 'webp-media-handler' ); ?></strong>
				<?php esc_html_e( 'Some actions on this page modify existing media files or database content. As a precaution, it\'s recommended to take a full website backup before running one-time actions.', 'webp-media-handler' ); ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render support section
	 * 
	 * Displays optional support section at bottom of settings page.
	 * Compliant with WordPress.org guidelines: optional, non-intrusive, no upsells.
	 * Placement at bottom ensures it doesn't interfere with plugin functionality.
	 */
	private function render_support_section() {
		?>
		<div class="wpmh-support-section">
			<div class="wpmh-support-content">
				<h3><?php esc_html_e( 'Support This Plugin', 'webp-media-handler' ); ?></h3>
				<p><?php esc_html_e( 'This plugin is completely free. If it has been helpful, you\'re welcome to support its ongoing maintenance and improvements.', 'webp-media-handler' ); ?></p>
				<p class="wpmh-support-button-wrapper">
					<a href="https://www.paypal.com/ncp/payment/2C3DDKHKMPMLC" 
					   target="_blank" 
					   rel="noopener noreferrer" 
					   class="button wpmh-support-button">
						<?php esc_html_e( 'Support Development', 'webp-media-handler' ); ?>
					</a>
				</p>
				<p class="wpmh-author-credit">
					<?php
					/* translators: %s: Author name with link */
					printf(
						esc_html__( 'Created by %s', 'webp-media-handler' ),
						'<a href="' . esc_url( 'https://github.com/MajedBannani' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( 'Majed Talal' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
