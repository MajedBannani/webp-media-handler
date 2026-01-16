/**
 * Admin Scripts
 *
 * @package WebPMediaHandler
 */

(function($) {
	'use strict';

	/**
	 * Admin functionality
	 */
	var WPMHAdmin = {
	/**
	 * Initialize
	 */
	init: function() {
		this.bindEvents();
		this.initWatermarkToggle();
	},

	/**
	 * Initialize watermark settings section visibility based on toggle state (Issue 1 fix)
	 */
	initWatermarkToggle: function() {
		var $toggle = $('.wpmh-feature-toggle[data-feature="image_watermark"]');
		var $section = $('#wpmh-watermark-settings-section');
		
		if ($toggle.length && $section.length) {
			var enabled = $toggle.is(':checked');
			if (enabled) {
				$section.show().attr('aria-hidden', 'false');
			} else {
				$section.hide().attr('aria-hidden', 'true');
			}
		}
	},

		/**
		 * Bind events
		 */
		bindEvents: function() {
			// Feature toggles
			$(document).on('change', '.wpmh-feature-toggle', this.handleToggle);

			// Action buttons
			$(document).on('click', '.wpmh-action-button', this.handleAction);

			// Watermark image selection
			$(document).on('click', '.wpmh-select-watermark-image', this.selectWatermarkImage);
			$(document).on('click', '.wpmh-remove-watermark-image', this.removeWatermarkImage);
			
			// Target mode change
			$(document).on('change', 'input[name="wpmh-watermark-target-mode"]', this.handleTargetModeChange);
			
			// Select images button
			$(document).on('click', '.wpmh-select-images-btn', this.selectTargetImages);
			
			// Save watermark settings when changed
			// NEW DESIGN: Do not save watermark settings - they are runtime-only
		// $(document).on('change', '.wpmh-watermark-size-select, .wpmh-watermark-position-select, input[name="wpmh-watermark-target-mode"]', this.saveWatermarkSettings);
		},

		/**
		 * Handle feature toggle
		 */
		handleToggle: function(e) {
			var $toggle = $(this);
			var feature = $toggle.data('feature');
			var enabled = $toggle.is(':checked');

			// Toggle watermark settings section visibility immediately (Issue 1 fix)
			if (feature === 'image_watermark') {
				var $section = $('#wpmh-watermark-settings-section');
				if (enabled) {
					$section.show().attr('aria-hidden', 'false');
				} else {
					$section.hide().attr('aria-hidden', 'true');
				}
			}

			// Disable toggle during request
			$toggle.prop('disabled', true);

			$.ajax({
				url: wpmhAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: 'wpmh_toggle_feature',
					nonce: wpmhAdmin.nonces.toggle,
					feature: feature,
					enabled: enabled ? 1 : 0
				},
				success: function(response) {
					if (response.success) {
						// Show success message
						WPMHAdmin.showMessage($toggle.closest('.wpmh-feature-card'), response.data.message, 'success');
					} else {
						// Revert toggle
						$toggle.prop('checked', !enabled);
						// Revert visibility if toggle failed
						if (feature === 'image_watermark') {
							var $section = $('#wpmh-watermark-settings-section');
							$section.toggle().attr('aria-hidden', enabled ? 'true' : 'false');
						}
						WPMHAdmin.showMessage($toggle.closest('.wpmh-feature-card'), response.data.message || wpmhAdmin.strings.error, 'error');
					}
				},
				error: function() {
					// Revert toggle
					$toggle.prop('checked', !enabled);
					// Revert visibility if request failed
					if (feature === 'image_watermark') {
						var $section = $('#wpmh-watermark-settings-section');
						$section.toggle().attr('aria-hidden', enabled ? 'true' : 'false');
					}
					WPMHAdmin.showMessage($toggle.closest('.wpmh-feature-card'), wpmhAdmin.strings.error, 'error');
				},
				complete: function() {
					$toggle.prop('disabled', false);
				}
			});
		},

		/**
		 * Handle action button click
		 */
		handleAction: function(e) {
			e.preventDefault();

			var $button = $(this);
			var action = $button.data('action');
			var nonceAction = $button.data('nonce-action');
			var statusId = '#wpmh-status-' + action;
			var $status = $(statusId);

			// Confirm action
			var confirmMessage = '';
			if (action === 'convert_existing') {
				confirmMessage = wpmhAdmin.strings.confirmConvert;
			} else if (action === 'replace_urls') {
				confirmMessage = wpmhAdmin.strings.confirmReplace;
			} else if (action === 'apply_watermark') {
				// Validate watermark settings before confirmation
				var watermarkId = $('#wpmh-watermark-image-id').val();
				if (!watermarkId || watermarkId === '0') {
					alert(wpmhAdmin.strings.selectWatermark);
					return;
				}

				var targetMode = $('input[name="wpmh-watermark-target-mode"]:checked').val();
				if (targetMode === 'selected') {
					var selectedImages = WPMHAdmin.getSelectedImageIds();
					if (selectedImages.length === 0) {
						alert(wpmhAdmin.strings.selectImages);
						return;
					}
					confirmMessage = wpmhAdmin.strings.confirmWatermark;
				} else {
					confirmMessage = wpmhAdmin.strings.confirmWatermarkAll;
				}
			}

			if (confirmMessage && !confirm(confirmMessage)) {
				return;
			}

			// Disable button
			$button.prop('disabled', true);

			// Show processing status
			$status.removeClass('success error').addClass('show info').text(wpmhAdmin.strings.processing);

			// Start processing
			WPMHAdmin.processAction(action, nonceAction, 0);
		},

		/**
		 * Process action (with batching)
		 */
		processAction: function(action, nonceAction, offset) {
			var $button = $('.wpmh-action-button[data-action="' + action + '"]');
			var statusId = '#wpmh-status-' + action;
			var $status = $(statusId);

			// Get nonce
			var nonce = '';
			if (nonceAction === 'wpmh_convert_existing_webp') {
				nonce = wpmhAdmin.nonces.convert_existing;
			} else if (nonceAction === 'wpmh_replace_image_urls') {
				nonce = wpmhAdmin.nonces.replace_urls;
			} else if (nonceAction === 'wpmh_apply_watermark') {
				nonce = wpmhAdmin.nonces.apply_watermark;
			}

			// Prepare data
			var postData = {
				action: nonceAction,
				nonce: nonce,
				offset: offset
			};

			// Add watermark-specific data
			if (nonceAction === 'wpmh_apply_watermark') {
				postData.watermark_id = $('#wpmh-watermark-image-id').val();
				postData.watermark_size = $('#wpmh-watermark-size').val();
				postData.watermark_position = $('#wpmh-watermark-position').val();
				postData.target_mode = $('input[name="wpmh-watermark-target-mode"]:checked').val();
				
				if (postData.target_mode === 'selected') {
					postData.selected_images = WPMHAdmin.getSelectedImageIds();
				}
			}

			$.ajax({
				url: wpmhAdmin.ajaxUrl,
				type: 'POST',
				data: postData,
				success: function(response) {
					if (response.success) {
						// Update status div (for progress feedback during processing)
						$status.removeClass('info error').addClass('show success').text(response.data.message);

						// If not completed, continue processing
						if (!response.data.completed) {
							setTimeout(function() {
								WPMHAdmin.processAction(action, nonceAction, response.data.offset);
							}, 500);
						} else {
							// Re-enable button
							$button.prop('disabled', false);
							
							// SINGLE CONSUMPTION: Do NOT inject notice via JS
							// The flash message is set server-side and will be rendered on next page load
							// This prevents duplicate notices (JS injection + PHP flash message)
							// Status div shows immediate feedback, flash message shows on reload
						}
					} else {
						$status.removeClass('info success').addClass('show error').text(response.data.message || wpmhAdmin.strings.error);
						
						// SINGLE CONSUMPTION: Errors shown in status div only (no inline notice for errors)
						
						$button.prop('disabled', false);
					}
				},
				error: function() {
					$status.removeClass('info success').addClass('show error').text(wpmhAdmin.strings.error);
					
					// SINGLE CONSUMPTION: Errors shown in status div only (no inline notice for errors)
					
					$button.prop('disabled', false);
				}
			});
		},

		/**
		 * Show message
		 */
		showMessage: function($container, message, type) {
			var $message = $('<div class="wpmh-action-status show ' + type + '">' + message + '</div>');
			$container.find('.wpmh-action-status').remove();
			$container.find('.wpmh-feature-actions').append($message);

			// Auto-hide after 3 seconds
			setTimeout(function() {
				$message.fadeOut(function() {
					$(this).remove();
				});
			}, 3000);
		},

		/**
		 * Select watermark image from media library
		 */
		selectWatermarkImage: function(e) {
			e.preventDefault();

			var frame = wp.media({
				title: 'Select Watermark Image',
				button: {
					text: 'Use as Watermark'
				},
				multiple: false,
				library: {
					type: 'image'
				}
			});

			frame.on('select', function() {
				// NEW DESIGN: Only ONE image is selected - no persistence
				var selection = frame.state().get('selection');
				if (selection.length > 1) {
					// If multiple selected, reset to only first one
					var firstAttachment = selection.first();
					selection.reset([firstAttachment]);
				}
				var attachment = selection.first().toJSON();
				
				// Set watermark ID in hidden input for POST (runtime only, not saved)
				$('#wpmh-watermark-image-id').val(attachment.id);
				
				var $preview = $('#wpmh-watermark-preview');
				$preview.html('<img src="' + attachment.sizes.thumbnail.url + '" style="max-width: 150px; height: auto;">');
				$preview.show();
				$('.wpmh-remove-watermark-image').show();

				// Do NOT save to database - watermark settings are runtime-only
			});

			frame.open();
		},

		/**
		 * Remove watermark image
		 */
		removeWatermarkImage: function(e) {
			e.preventDefault();
			$('#wpmh-watermark-image-id').val(0);
			$('#wpmh-watermark-preview').hide().html('');
			$(this).hide();
			// Do NOT save to database - watermark settings are runtime-only
		},

		/**
		 * Handle target mode change
		 */
		handleTargetModeChange: function() {
			var mode = $(this).val();
			if (mode === 'selected') {
				$('#wpmh-selected-images-container').show();
			} else {
				$('#wpmh-selected-images-container').hide();
			}
		},

		/**
		 * Select target images from media library
		 */
		selectTargetImages: function(e) {
			e.preventDefault();

			var selectedIds = WPMHAdmin.getSelectedImageIds();

			var frame = wp.media({
				title: 'Select Images to Watermark',
				button: {
					text: 'Select Images'
				},
				multiple: true,
				library: {
					type: 'image'
				}
			});

			// Pre-select currently selected images
			if (selectedIds.length > 0) {
				var selection = frame.state().get('selection');
				selectedIds.forEach(function(id) {
					var attachment = wp.media.attachment(id);
					attachment.fetch();
					selection.add(attachment);
				});
			}

			frame.on('select', function() {
				var selected = frame.state().get('selection').map(function(attachment) {
					return attachment.toJSON();
				});

				WPMHAdmin.displaySelectedImages(selected);
			});

			frame.open();
		},

		/**
		 * Display selected images
		 */
		displaySelectedImages: function(images) {
			var $container = $('#wpmh-selected-images-list');
			
			if (images.length === 0) {
				$container.html('<p class="description">' + wpmhAdmin.strings.selectImages + '</p>');
				return;
			}

			var html = '<div class="wpmh-selected-images-grid">';
			html += '<p><strong>' + images.length + ' ' + (images.length === 1 ? 'image' : 'images') + ' selected.</strong></p>';
			images.forEach(function(image) {
				html += '<div class="wpmh-selected-image-item" data-id="' + image.id + '">';
				html += '<img src="' + (image.sizes.thumbnail ? image.sizes.thumbnail.url : image.url) + '" style="max-width: 80px; height: auto;">';
				html += '<button type="button" class="button-link wpmh-remove-selected-image" data-id="' + image.id + '">×</button>';
				html += '</div>';
			});
			html += '</div>';

			$container.html(html);
		},

		/**
		 * Get selected image IDs
		 */
		getSelectedImageIds: function() {
			var ids = [];
			$('.wpmh-selected-image-item').each(function() {
				ids.push(parseInt($(this).data('id'), 10));
			});
			return ids;
		},

		/**
		 * NEW DESIGN: Watermark settings are runtime-only, not persisted
		 * These functions are no longer used - watermark settings come from form POST only
		 */
		saveWatermarkImageId: function(imageId) {
			// No-op: Watermark settings are runtime-only
		},

		saveWatermarkSettings: function() {
			// No-op: Watermark settings are runtime-only
		}
	};

	// Handle remove selected image
	$(document).on('click', '.wpmh-remove-selected-image', function(e) {
		e.preventDefault();
		$(this).closest('.wpmh-selected-image-item').remove();
		
		// Update display if no images left
		if ($('.wpmh-selected-image-item').length === 0) {
			$('#wpmh-selected-images-list').html('<p class="description">' + wpmhAdmin.strings.selectImages + '</p>');
		}
	});

	// Initialize on document ready
	$(document).ready(function() {
		WPMHAdmin.init();
	});

})(jQuery);
