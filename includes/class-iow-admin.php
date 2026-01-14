<?php
/**
 * Admin Interface
 *
 * Handles the WordPress admin UI with toggle switches and action buttons.
 *
 * @package ImageOptimizationWebP
 */

// Exit if accessed directly
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin Class
 */
class IOW_Admin {

	/**
	 * Settings manager instance
	 *
	 * @var IOW_Settings_Manager
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
	 * @param IOW_Settings_Manager $settings Settings manager instance.
	 * @param array                $features Feature instances.
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
		add_action( 'wp_ajax_iow_toggle_feature', array( $this, 'handle_toggle' ) );
	}

	/**
	 * Add admin menu
	 */
	public function add_admin_menu() {
		add_menu_page(
			__( 'Image Optimization & WebP', 'image-optimization-webp' ),
			__( 'Image Optimization', 'image-optimization-webp' ),
			'manage_options',
			'image-optimization-webp',
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
		if ( 'toplevel_page_image-optimization-webp' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'iow-admin-style',
			IOW_PLUGIN_URL . 'assets/admin.css',
			array(),
			IOW_VERSION
		);

		wp_enqueue_script(
			'iow-admin-script',
			IOW_PLUGIN_URL . 'assets/admin.js',
			array( 'jquery' ),
			IOW_VERSION,
			true
		);

		wp_localize_script(
			'iow-admin-script',
			'iowAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'toggle'              => wp_create_nonce( 'iow_toggle_feature' ),
					'convert_existing'    => wp_create_nonce( 'iow_convert_existing_webp' ),
					'replace_urls'        => wp_create_nonce( 'iow_replace_image_urls' ),
				),
				'strings' => array(
					'confirmConvert' => __( 'This will convert all existing JPEG/PNG images to WebP. This action cannot be undone automatically. Continue?', 'image-optimization-webp' ),
					'confirmReplace' => __( 'This will replace image URLs throughout your site. This action cannot be undone automatically. Continue?', 'image-optimization-webp' ),
					'processing'     => __( 'Processing...', 'image-optimization-webp' ),
					'error'          => __( 'An error occurred. Please try again.', 'image-optimization-webp' ),
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
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'image-optimization-webp' ) ) );
		}

		check_ajax_referer( 'iow_toggle_feature', 'nonce' );

		// WordPress.org compliance: wp_unslash() before sanitization
		$feature = isset( $_POST['feature'] ) ? sanitize_text_field( wp_unslash( $_POST['feature'] ) ) : '';
		// Plugin Check: wp_unslash() before sanitization for all $_POST access.
		$enabled = isset( $_POST['enabled'] ) ? sanitize_text_field( wp_unslash( $_POST['enabled'] ) ) : '';
		// Preserve original boolean behavior (AJAX sends 1/0).
		$enabled = ( '1' === $enabled || 'true' === $enabled );

		// Validate feature name
		$allowed_features = array( 'disable_image_sizes', 'auto_webp_convert' );
		if ( ! in_array( $feature, $allowed_features, true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid feature.', 'image-optimization-webp' ) ) );
		}

		// Update setting
		$this->settings->set( $feature, $enabled );

		wp_send_json_success( array(
			'message' => $enabled
				? __( 'Feature enabled.', 'image-optimization-webp' )
				: __( 'Feature disabled.', 'image-optimization-webp' ),
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
		<div class="wrap iow-admin-wrap">
			<h1><?php echo esc_html( get_admin_page_title() ); ?></h1>

			<div class="iow-admin-container">
				<?php $this->render_feature_card(
					'disable_image_sizes',
					__( 'Disable WordPress Default Image Sizes', 'image-optimization-webp' ),
					$this->features['disable_image_sizes']->get_description(),
					false // No action button
				); ?>

				<?php $this->render_feature_card(
					'auto_webp_convert',
					__( 'Auto Convert Newly Uploaded Images to WebP', 'image-optimization-webp' ),
					$this->features['auto_webp_convert']->get_description(),
					false // No action button
				); ?>

				<?php $this->render_action_card(
					'convert_existing',
					__( 'Convert Existing Media Library Images to WebP', 'image-optimization-webp' ),
					$this->features['convert_existing']->get_description(),
					'iow_convert_existing_webp',
					__( 'Convert Existing Images', 'image-optimization-webp' )
				); ?>

				<?php $this->render_action_card(
					'replace_urls',
					__( 'Replace Existing Image URLs with WebP', 'image-optimization-webp' ),
					$this->features['replace_urls']->get_description(),
					'iow_replace_image_urls',
					__( 'Replace Image URLs', 'image-optimization-webp' )
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
		<div class="iow-feature-card">
			<div class="iow-feature-header">
				<h2><?php echo esc_html( $title ); ?></h2>
				<label class="iow-toggle-switch">
					<input type="checkbox" 
					       class="iow-feature-toggle" 
					       data-feature="<?php echo esc_attr( $feature_key ); ?>"
					       <?php checked( $enabled, true ); ?>>
					<span class="iow-toggle-slider"></span>
				</label>
			</div>
			<div class="iow-feature-description">
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<?php if ( $has_action ) : ?>
				<div class="iow-feature-actions">
					<button type="button" class="button button-primary iow-action-button" data-action="<?php echo esc_attr( $feature_key ); ?>">
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
		<div class="iow-feature-card iow-action-card">
			<div class="iow-feature-header">
				<h2><?php echo esc_html( $title ); ?></h2>
			</div>
			<div class="iow-feature-description">
				<p><?php echo esc_html( $description ); ?></p>
			</div>
			<?php if ( $last_run ) : ?>
				<div class="iow-last-run">
					<strong><?php esc_html_e( 'Last run:', 'image-optimization-webp' ); ?></strong>
					<?php echo esc_html( $last_run ); ?>
				</div>
			<?php endif; ?>
			<div class="iow-feature-actions">
				<button type="button" 
				        class="button button-primary iow-action-button" 
				        data-action="<?php echo esc_attr( $action_key ); ?>"
				        data-nonce-action="<?php echo esc_attr( $nonce_action ); ?>">
					<?php echo esc_html( $button_text ); ?>
				</button>
				<div class="iow-action-status" id="iow-status-<?php echo esc_attr( $action_key ); ?>"></div>
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
		<div class="iow-precaution-note">
			<p>
				<strong><?php esc_html_e( 'Note:', 'image-optimization-webp' ); ?></strong>
				<?php esc_html_e( 'Some actions on this page modify existing media files or database content. As a precaution, it\'s recommended to take a full website backup before running one-time actions.', 'image-optimization-webp' ); ?>
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
		<div class="iow-support-section">
			<div class="iow-support-content">
				<h3><?php esc_html_e( 'Support This Plugin', 'image-optimization-webp' ); ?></h3>
				<p><?php esc_html_e( 'This plugin is completely free. If it has been helpful, you\'re welcome to support its ongoing maintenance and improvements.', 'image-optimization-webp' ); ?></p>
				<p class="iow-support-button-wrapper">
					<a href="https://www.paypal.com/ncp/payment/2C3DDKHKMPMLC" 
					   target="_blank" 
					   rel="noopener noreferrer" 
					   class="button iow-support-button">
						<?php esc_html_e( 'Support Development', 'image-optimization-webp' ); ?>
					</a>
				</p>
				<p class="iow-author-credit">
					<?php
					/* translators: %s: Author name with link */
					printf(
						/* translators: %s: Author name with link */
						esc_html__( 'Created by %s', 'image-optimization-webp' ),
						'<a href="' . esc_url( 'https://github.com/majedtalal' ) . '" target="_blank" rel="noopener noreferrer">' . esc_html( 'Majed Talal' ) . '</a>'
					);
					?>
				</p>
			</div>
		</div>
		<?php
	}
}
