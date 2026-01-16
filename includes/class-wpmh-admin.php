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
		add_action( 'wp_ajax_wpmh_save_watermark_settings', array( $this, 'handle_save_watermark_settings' ) );
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

		// Enqueue media library for watermark image selection
		wp_enqueue_media();

		wp_localize_script(
			'wpmh-admin-script',
			'wpmhAdmin',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonces'  => array(
					'toggle'              => wp_create_nonce( 'wpmh_toggle_feature' ),
					'convert_existing'    => wp_create_nonce( 'wpmh_convert_existing_webp' ),
					'replace_urls'        => wp_create_nonce( 'wpmh_replace_image_urls' ),
					'apply_watermark'     => wp_create_nonce( 'wpmh_apply_watermark' ),
				),
				'strings' => array(
					'confirmConvert' => __( 'This will convert all existing JPEG/PNG images to WebP. This action cannot be undone automatically. Continue?', 'webp-media-handler' ),
					'confirmReplace' => __( 'This will replace image URLs throughout your site. This action cannot be undone automatically. Continue?', 'webp-media-handler' ),
					'confirmWatermark' => __( 'This will apply watermarks to selected images. This action will modify your images and cannot be undone automatically. Continue?', 'webp-media-handler' ),
					'confirmWatermarkAll' => __( 'This will apply watermarks to ALL images in your media library. This action will modify your images and cannot be undone automatically. Are you sure you want to continue?', 'webp-media-handler' ),
					'processing'     => __( 'Processing...', 'webp-media-handler' ),
					'error'          => __( 'An error occurred. Please try again.', 'webp-media-handler' ),
					'selectWatermark' => __( 'Please select a watermark image.', 'webp-media-handler' ),
					'selectImages'   => __( 'Please select at least one image to watermark.', 'webp-media-handler' ),
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
		$allowed_features = array( 'disable_image_sizes', 'auto_webp_convert', 'image_watermark' );
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

				<?php $this->render_watermark_card(); ?>
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
	 * Render watermark feature card
	 */
	private function render_watermark_card() {
		// Issue 2: Defensive migration - normalize watermark_image_id to scalar
		$watermark_id_raw = $this->settings->get( 'watermark_image_id', 0 );
		if ( is_array( $watermark_id_raw ) ) {
			// If array, take the last element and save as scalar
			$watermark_id = ! empty( $watermark_id_raw ) ? absint( end( $watermark_id_raw ) ) : 0;
			$this->settings->set( 'watermark_image_id', $watermark_id );
		} else {
			$watermark_id = absint( $watermark_id_raw );
		}

		$enabled = $this->settings->get( 'image_watermark', false );
		$watermark_size = $this->settings->get( 'watermark_size', 100 );
		$watermark_position = $this->settings->get( 'watermark_position', 'bottom-right' );
		$target_mode = $this->settings->get( 'watermark_target_mode', 'selected' );
		
		$log = $this->settings->get_action_log( 'apply_watermark' );
		$last_run = $log ? $log['timestamp'] : '';

		// Issue 1: Read inline notice from transient
		$inline_notice = get_transient( 'wpmh_watermark_notice' );
		if ( $inline_notice && is_array( $inline_notice ) ) {
			delete_transient( 'wpmh_watermark_notice' );
		}
		?>
		<div class="wpmh-feature-card wpmh-watermark-card">
			<div class="wpmh-feature-header">
				<h2><?php esc_html_e( 'Image Watermarking', 'webp-media-handler' ); ?></h2>
				<label class="wpmh-toggle-switch">
					<input type="checkbox" 
					       class="wpmh-feature-toggle" 
					       data-feature="image_watermark"
					       <?php checked( $enabled, true ); ?>>
					<span class="wpmh-toggle-slider"></span>
				</label>
			</div>
			<div class="wpmh-feature-description">
				<p><?php echo esc_html( $this->features['image_watermark']->get_description() ); ?></p>
			</div>

			<div id="wpmh-watermark-settings-section" style="display: <?php echo $enabled ? 'block' : 'none'; ?>" aria-hidden="<?php echo $enabled ? 'false' : 'true'; ?>">
				<div class="wpmh-watermark-settings">
					<h3><?php esc_html_e( 'Watermark Settings', 'webp-media-handler' ); ?></h3>

					<div class="wpmh-watermark-field">
						<label for="wpmh-watermark-image">
							<strong><?php esc_html_e( 'Watermark Image:', 'webp-media-handler' ); ?></strong>
						</label>
						<div class="wpmh-watermark-image-selector">
							<button type="button" class="button wpmh-select-watermark-image" id="wpmh-select-watermark-btn">
								<?php esc_html_e( 'Select Watermark Image', 'webp-media-handler' ); ?>
							</button>
							<button type="button" class="button-link wpmh-remove-watermark-image" style="display: <?php echo $watermark_id ? 'inline' : 'none'; ?>;">
								<?php esc_html_e( 'Remove', 'webp-media-handler' ); ?>
							</button>
							<input type="hidden" id="wpmh-watermark-image-id" value="<?php echo esc_attr( $watermark_id ); ?>">
							<div class="wpmh-watermark-preview" id="wpmh-watermark-preview" style="display: <?php echo $watermark_id ? 'block' : 'none'; ?>;">
								<?php if ( $watermark_id ) : ?>
									<?php echo wp_get_attachment_image( $watermark_id, 'thumbnail', false, array( 'style' => 'max-width: 150px; height: auto;' ) ); ?>
								<?php endif; ?>
							</div>
							<p class="description">
								<?php esc_html_e( 'Select a PNG, JPG, or WebP image from your Media Library to use as a watermark.', 'webp-media-handler' ); ?>
							</p>
						</div>
					</div>

					<div class="wpmh-watermark-field">
						<label for="wpmh-watermark-size">
							<strong><?php esc_html_e( 'Watermark Size:', 'webp-media-handler' ); ?></strong>
						</label>
						<select id="wpmh-watermark-size" class="wpmh-watermark-size-select">
							<option value="50" <?php selected( $watermark_size, 50 ); ?>>50px</option>
							<option value="100" <?php selected( $watermark_size, 100 ); ?>>100px</option>
							<option value="200" <?php selected( $watermark_size, 200 ); ?>>200px</option>
							<option value="300" <?php selected( $watermark_size, 300 ); ?>>300px</option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Maximum width of the watermark. The watermark will be resized proportionally and will not be upscaled.', 'webp-media-handler' ); ?>
						</p>
					</div>

					<div class="wpmh-watermark-field">
						<label for="wpmh-watermark-position">
							<strong><?php esc_html_e( 'Watermark Position:', 'webp-media-handler' ); ?></strong>
						</label>
						<select id="wpmh-watermark-position" class="wpmh-watermark-position-select">
							<option value="top-left" <?php selected( $watermark_position, 'top-left' ); ?>><?php esc_html_e( 'Top Left', 'webp-media-handler' ); ?></option>
							<option value="top-right" <?php selected( $watermark_position, 'top-right' ); ?>><?php esc_html_e( 'Top Right', 'webp-media-handler' ); ?></option>
							<option value="bottom-left" <?php selected( $watermark_position, 'bottom-left' ); ?>><?php esc_html_e( 'Bottom Left', 'webp-media-handler' ); ?></option>
							<option value="bottom-right" <?php selected( $watermark_position, 'bottom-right' ); ?>><?php esc_html_e( 'Bottom Right', 'webp-media-handler' ); ?></option>
							<option value="center" <?php selected( $watermark_position, 'center' ); ?>><?php esc_html_e( 'Center', 'webp-media-handler' ); ?></option>
						</select>
						<p class="description">
							<?php esc_html_e( 'Position where the watermark will be placed. A minimum padding of 20px from edges will be maintained.', 'webp-media-handler' ); ?>
						</p>
					</div>

					<div class="wpmh-watermark-field">
						<label>
							<strong><?php esc_html_e( 'Target Images:', 'webp-media-handler' ); ?></strong>
						</label>
						<div class="wpmh-watermark-target-mode">
							<label>
								<input type="radio" name="wpmh-watermark-target-mode" value="selected" <?php checked( $target_mode, 'selected' ); ?>>
								<?php esc_html_e( 'Selected Images', 'webp-media-handler' ); ?>
							</label>
							<br>
							<label>
								<input type="radio" name="wpmh-watermark-target-mode" value="all" <?php checked( $target_mode, 'all' ); ?>>
								<?php esc_html_e( 'All Images', 'webp-media-handler' ); ?>
								<strong class="wpmh-warning-text"><?php esc_html_e( '(Applies to all images in Media Library)', 'webp-media-handler' ); ?></strong>
							</label>
						</div>
						<div class="wpmh-selected-images-container" id="wpmh-selected-images-container" style="display: <?php echo ( 'selected' === $target_mode ) ? 'block' : 'none'; ?>;">
							<button type="button" class="button wpmh-select-images-btn" id="wpmh-select-images-btn">
								<?php esc_html_e( 'Select Images from Media Library', 'webp-media-handler' ); ?>
							</button>
							<div class="wpmh-selected-images-list" id="wpmh-selected-images-list">
								<p class="description"><?php esc_html_e( 'No images selected. Click the button above to select images.', 'webp-media-handler' ); ?></p>
							</div>
						</div>
					</div>
				</div>

				<?php if ( $last_run ) : ?>
					<div class="wpmh-last-run">
						<strong><?php esc_html_e( 'Last run:', 'webp-media-handler' ); ?></strong>
						<?php echo esc_html( $last_run ); ?>
					</div>
				<?php endif; ?>

				<div class="wpmh-feature-actions">
					<button type="button" 
					        class="button button-primary wpmh-action-button wpmh-apply-watermark-btn" 
					        data-action="apply_watermark"
					        data-nonce-action="wpmh_apply_watermark">
						<?php esc_html_e( 'Apply Watermark', 'webp-media-handler' ); ?>
					</button>
					<div class="wpmh-action-status" id="wpmh-status-apply_watermark"></div>
					<?php if ( ! empty( $inline_notice ) && isset( $inline_notice['message'] ) ) : ?>
						<div id="wpmh-watermark-inline-notice" class="wpmh-inline-notice wpmh-inline-notice-<?php echo esc_attr( isset( $inline_notice['type'] ) ? $inline_notice['type'] : 'success' ); ?>">
							<?php echo esc_html( $inline_notice['message'] ); ?>
						</div>
					<?php else : ?>
						<div id="wpmh-watermark-inline-notice" class="wpmh-inline-notice" style="display: none;"></div>
					<?php endif; ?>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Handle save watermark settings AJAX request
	 */
	public function handle_save_watermark_settings() {
		// Security checks
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'webp-media-handler' ) ) );
		}

		check_ajax_referer( 'wpmh_toggle_feature', 'nonce' );

		// WordPress.org compliance: wp_unslash() before sanitization
		if ( isset( $_POST['setting'] ) && isset( $_POST['value'] ) ) {
			// Save single setting
			$setting = sanitize_text_field( wp_unslash( $_POST['setting'] ) );
			$value = wp_unslash( $_POST['value'] );
			
			// Validate and sanitize value based on setting
			if ( 'watermark_image_id' === $setting ) {
				// Issue 2: Ensure watermark_image_id is ALWAYS a scalar (single value), never an array
				if ( is_array( $value ) ) {
					// If array received, take last element and normalize to scalar
					$value = ! empty( $value ) ? absint( end( $value ) ) : 0;
				} else {
					$value = absint( $value );
				}
			}
			
			$this->settings->set( $setting, $value );
			wp_send_json_success();
		} elseif ( isset( $_POST['settings'] ) && is_array( $_POST['settings'] ) ) {
			// Save multiple settings
			$settings = array_map( 'wp_unslash', $_POST['settings'] );
			
			// Sanitize each setting
			if ( isset( $settings['watermark_size'] ) ) {
				$settings['watermark_size'] = absint( $settings['watermark_size'] );
			}
			if ( isset( $settings['watermark_position'] ) ) {
				$allowed_positions = array( 'top-left', 'top-right', 'bottom-left', 'bottom-right', 'center' );
				if ( ! in_array( $settings['watermark_position'], $allowed_positions, true ) ) {
					unset( $settings['watermark_position'] );
				}
			}
			if ( isset( $settings['watermark_target_mode'] ) ) {
				$allowed_modes = array( 'selected', 'all' );
				if ( ! in_array( $settings['watermark_target_mode'], $allowed_modes, true ) ) {
					unset( $settings['watermark_target_mode'] );
				}
			}
			// Issue 2: Ensure watermark_image_id is scalar before saving
			if ( isset( $settings['watermark_image_id'] ) ) {
				if ( is_array( $settings['watermark_image_id'] ) ) {
					$settings['watermark_image_id'] = ! empty( $settings['watermark_image_id'] ) ? absint( end( $settings['watermark_image_id'] ) ) : 0;
				} else {
					$settings['watermark_image_id'] = absint( $settings['watermark_image_id'] );
				}
			}
			foreach ( $settings as $key => $value ) {
				$this->settings->set( $key, $value );
			}
			wp_send_json_success();
		}

		wp_send_json_error( array( 'message' => __( 'Invalid request.', 'webp-media-handler' ) ) );
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
