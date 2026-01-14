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
		},

		/**
		 * Bind events
		 */
		bindEvents: function() {
			// Feature toggles
			$(document).on('change', '.wpmh-feature-toggle', this.handleToggle);

			// Action buttons
			$(document).on('click', '.wpmh-action-button', this.handleAction);
		},

		/**
		 * Handle feature toggle
		 */
		handleToggle: function(e) {
			var $toggle = $(this);
			var feature = $toggle.data('feature');
			var enabled = $toggle.is(':checked');

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
						WPMHAdmin.showMessage($toggle.closest('.wpmh-feature-card'), response.data.message || wpmhAdmin.strings.error, 'error');
					}
				},
				error: function() {
					// Revert toggle
					$toggle.prop('checked', !enabled);
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
			}

			$.ajax({
				url: wpmhAdmin.ajaxUrl,
				type: 'POST',
				data: {
					action: nonceAction,
					nonce: nonce,
					offset: offset
				},
				success: function(response) {
					if (response.success) {
						// Update status
						$status.removeClass('info error').addClass('show success').text(response.data.message);

						// If not completed, continue processing
						if (!response.data.completed) {
							setTimeout(function() {
								WPMHAdmin.processAction(action, nonceAction, response.data.offset);
							}, 500);
						} else {
							// Re-enable button
							$button.prop('disabled', false);
						}
					} else {
						$status.removeClass('info success').addClass('show error').text(response.data.message || wpmhAdmin.strings.error);
						$button.prop('disabled', false);
					}
				},
				error: function() {
					$status.removeClass('info success').addClass('show error').text(wpmhAdmin.strings.error);
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
		}
	};

	// Initialize on document ready
	$(document).ready(function() {
		WPMHAdmin.init();
	});

})(jQuery);
