/**
 * Bulk Plugin Installer - Upload & Queue Management UI
 *
 * Implements drag-and-drop upload zone, client-side ZIP filtering,
 * per-file upload progress, queue management, and accessible controls.
 *
 * @package Bulk_Plugin_Installer
 */
(function ($) {
	'use strict';

	/**
	 * Format bytes into a human-readable size string.
	 *
	 * @param {number} bytes File size in bytes.
	 * @return {string} Formatted size (e.g. "1.5 MB").
	 */
	function formatFileSize(bytes) {
		if (bytes === 0) return '0 B';
		var units = ['B', 'KB', 'MB', 'GB'];
		var i = Math.floor(Math.log(bytes) / Math.log(1024));
		if (i >= units.length) i = units.length - 1;
		return (bytes / Math.pow(1024, i)).toFixed(i === 0 ? 0 : 1) + ' ' + units[i];
	}

	/**
	 * Main BPI Upload App.
	 */
	var BPIUpload = {
		/** @type {Array} Queue of uploaded file data from server responses. */
		queue: [],

		/** @type {jQuery|null} Container element. */
		$app: null,

		/** @type {jQuery|null} ARIA live region for announcements. */
		$liveRegion: null,

		/**
		 * Initialize the upload UI.
		 */
		init: function () {
			this.$app = $('#bpi-bulk-upload-app');
			if (!this.$app.length) return;

			this.render();
			this.bindEvents();
		},

		/**
		 * Render the initial UI structure.
		 */
		render: function () {
			var t = this.i18n();
			var html = '';

			// ARIA live region for dynamic announcements.
			html += '<div id="bpi-live-region" class="bpi-sr-only" aria-live="polite" aria-atomic="true"></div>';

			// Upload zone.
			html += '<div id="bpi-upload-zone" class="bpi-upload-zone" role="button" tabindex="0" aria-label="' + this.escAttr(t.dropZoneLabel) + '">';
			html += '<span class="bpi-upload-zone__icon dashicons dashicons-upload"></span>';
			html += '<p class="bpi-upload-zone__text">' + this.esc(t.dropZoneText) + '</p>';
			html += '<p class="bpi-upload-zone__subtext">' + this.esc(t.dropZoneSubtext) + '</p>';
			html += '<input type="file" id="bpi-file-input" class="bpi-upload-zone__input" multiple accept=".zip" aria-label="' + this.escAttr(t.selectFilesLabel) + '" />';
			html += '</div>';

			// Notices area.
			html += '<div id="bpi-notices" role="alert"></div>';

			// Queue section (hidden initially).
			html += '<div id="bpi-queue-section" class="bpi-queue-section" style="display:none;">';
			html += '<div class="bpi-queue-header">';
			html += '<h2>' + this.esc(t.uploadQueue) + '</h2>';
			html += '<span id="bpi-queue-summary" class="bpi-queue-summary"></span>';
			html += '</div>';
			html += '<ul id="bpi-queue-list" class="bpi-queue-list" aria-label="' + this.escAttr(t.queuedFilesLabel) + '"></ul>';
			html += '<div class="bpi-actions">';
			html += '<button type="button" id="bpi-add-more" class="button button-secondary" aria-label="' + this.escAttr(t.addMoreLabel) + '">' + this.esc(t.addMoreFiles) + '</button>';
			html += '<button type="button" id="bpi-continue-preview" class="button button-primary" disabled aria-disabled="true" aria-label="' + this.escAttr(t.continuePreviewLabel) + '">' + this.esc(t.continueToPreview) + '</button>';
			html += '</div>';
			html += '</div>';

			this.$app.html(html);

			this.$liveRegion = $('#bpi-live-region');
		},

		/**
		 * Bind all event handlers.
		 */
		bindEvents: function () {
			var self = this;
			var $zone = $('#bpi-upload-zone');
			var $input = $('#bpi-file-input');

			// Drag-and-drop events.
			$zone.on('dragenter dragover', function (e) {
				e.preventDefault();
				e.stopPropagation();
				$zone.addClass('bpi-dragover');
			});

			$zone.on('dragleave drop', function (e) {
				e.preventDefault();
				e.stopPropagation();
				$zone.removeClass('bpi-dragover');
			});

			$zone.on('drop', function (e) {
				var files = e.originalEvent.dataTransfer.files;
				if (files && files.length) {
					self.handleFiles(files);
				}
			});

			// Click to browse.
			$zone.on('click', function (e) {
				if (e.target !== $input[0]) {
					$input.trigger('click');
				}
			});

			// Keyboard activation for the zone.
			$zone.on('keydown', function (e) {
				if (e.key === 'Enter' || e.key === ' ') {
					e.preventDefault();
					$input.trigger('click');
				}
			});

			// File input change.
			$input.on('change', function () {
				if (this.files && this.files.length) {
					self.handleFiles(this.files);
				}
				// Reset so the same file can be re-selected.
				this.value = '';
			});

			// Add More Files button.
			$(document).on('click', '#bpi-add-more', function () {
				$input.trigger('click');
			});

			// Remove item from queue.
			$(document).on('click', '.bpi-queue-item__remove', function () {
				var slug = $(this).data('slug');
				self.removeFromQueue(slug);
			});

			// Continue to Preview.
			$(document).on('click', '#bpi-continue-preview', function () {
				if (!$(this).prop('disabled')) {
					self.loadPreview();
				}
			});
		},

		/**
		 * Load preview data via AJAX and render the preview screen.
		 */
		loadPreview: function () {
			var self = this;
			var t = this.i18n();
			var $btn = $('#bpi-continue-preview');
			$btn.prop('disabled', true).text(t.loadingPreview);

			$.post(bpiAdmin.ajaxUrl, {
				action: 'bpi_preview',
				_wpnonce: bpiAdmin.previewNonce || bpiAdmin.nonce
			}, function (response) {
				if (response.success && response.data && response.data.plugins) {
					self.showPreviewScreen(response.data.plugins);
				} else {
					var msg = (response.data && response.data.message) ? response.data.message : t.failedLoadPreview;
					self.showNotice(msg, 'error');
					$btn.prop('disabled', false).text(t.continueToPreview);
				}
			}).fail(function () {
				self.showNotice(t.networkErrorPreview, 'error');
				$btn.prop('disabled', false).text(t.continueToPreview);
			});
		},

		/**
		 * Render the preview/confirmation screen.
		 *
		 * @param {Array} plugins Array of plugin preview data from server.
		 */
		showPreviewScreen: function (plugins) {
			var self = this;
			var t = this.i18n();
			this.previewPlugins = plugins;

			var html = '';

			// ARIA live region.
			html += '<div id="bpi-live-region" class="bpi-sr-only" aria-live="polite" aria-atomic="true"></div>';

			// Header with back button.
			html += '<div class="bpi-preview-header">';
			html += '<button type="button" id="bpi-back-to-queue" class="button button-secondary" aria-label="' + this.escAttr(t.backToQueueLabel) + '">&larr; ' + this.esc(t.backToQueue) + '</button>';
			html += '<h2>' + this.esc(t.previewConfirm) + '</h2>';
			html += '</div>';

			// Select All / Deselect All toggle.
			html += '<div class="bpi-preview-controls">';
			html += '<button type="button" id="bpi-select-all" class="button button-link" aria-label="' + this.escAttr(t.selectAllLabel) + '">' + this.esc(t.selectAll) + '</button>';
			html += '<span class="bpi-preview-controls__sep">|</span>';
			html += '<button type="button" id="bpi-deselect-all" class="button button-link" aria-label="' + this.escAttr(t.deselectAllLabel) + '">' + this.esc(t.deselectAll) + '</button>';
			html += '<span id="bpi-preview-count" class="bpi-preview-count"></span>';
			html += '</div>';

			// Plugin list.
			html += '<div id="bpi-preview-list" class="bpi-preview-list" role="list" aria-label="' + this.escAttr(t.pluginsListLabel) + '">';
			for (var i = 0; i < plugins.length; i++) {
				html += this.renderPreviewItem(plugins[i], i);
			}
			html += '</div>';

			// Action buttons.
			html += '<div class="bpi-preview-actions">';
			html += '<button type="button" id="bpi-install-selected" class="button button-primary" aria-label="' + this.escAttr(t.installSelectedLabel) + '">' + this.esc(t.installSelected) + '</button>';
			html += '<button type="button" id="bpi-dry-run" class="button button-secondary" aria-label="' + this.escAttr(t.dryRunLabel) + '">' + this.esc(t.dryRunBtn) + '</button>';
			html += '</div>';

			this.$app.html(html);
			this.$liveRegion = $('#bpi-live-region');

			// Bind preview events.
			this.bindPreviewEvents();
			this.updatePreviewState();

			// Focus management: move focus to the preview heading.
			this.$app.find('.bpi-preview-header h2').attr('tabindex', '-1').trigger('focus');
		},

		/**
		 * Render a single preview item.
		 *
		 * @param {Object} plugin Plugin preview data.
		 * @param {number} index  Index in the list.
		 * @return {string} HTML string.
		 */
		renderPreviewItem: function (plugin, index) {
			var t = this.i18n();
			var isUpdate = plugin.action === 'update';
			var hasIssues = !plugin.compatible;
			var itemClass = 'bpi-preview-item';
			if (hasIssues) itemClass += ' bpi-preview-item--incompatible';

			var html = '<div class="' + itemClass + '" role="listitem" data-index="' + index + '" data-slug="' + this.escAttr(plugin.slug) + '">';

			// Checkbox.
			html += '<div class="bpi-preview-item__check">';
			html += '<input type="checkbox" id="bpi-check-' + index + '" class="bpi-preview-checkbox" data-index="' + index + '"';
			html += ' aria-label="' + this.escAttr(t.selectPlugin.replace('%s', plugin.plugin_name)) + '"';
			if (plugin.checked) html += ' checked';
			html += ' />';
			html += '</div>';

			// Main info.
			html += '<div class="bpi-preview-item__info">';

			// Name + version badge + action label.
			html += '<div class="bpi-preview-item__title">';
			html += '<strong>' + this.esc(plugin.plugin_name) + '</strong>';
			html += ' <span class="bpi-version-badge">' + this.esc(plugin.plugin_version) + '</span>';
			if (isUpdate && plugin.update_type) {
				html += ' <span class="bpi-update-badge bpi-update-badge--' + this.escAttr(plugin.update_type) + '">' + this.esc(plugin.update_type) + '</span>';
			}
			html += ' <span class="bpi-action-label bpi-action-label--' + this.escAttr(plugin.action) + '">' + this.esc(plugin.action_label) + '</span>';
			html += '</div>';

			// Installed version for updates.
			if (isUpdate && plugin.installed_version) {
				html += '<div class="bpi-preview-item__versions">';
				html += this.esc(t.installed + ' ' + plugin.installed_version) + ' &rarr; ' + this.esc(plugin.plugin_version);
				html += '</div>';
			}

			// Author.
			if (plugin.plugin_author) {
				html += '<div class="bpi-preview-item__author">' + this.esc(t.by + ' ' + plugin.plugin_author) + '</div>';
			}

			// Description.
			if (plugin.plugin_description) {
				html += '<div class="bpi-preview-item__desc">' + this.esc(plugin.plugin_description) + '</div>';
			}

			// Last Updated and Tested up to.
			if (plugin.changelog && (plugin.changelog.last_updated || plugin.changelog.tested_up_to)) {
				html += '<div class="bpi-preview-item__meta">';
				if (plugin.changelog.last_updated) {
					html += '<span class="bpi-meta-tag">' + this.esc(t.lastUpdated + ' ' + plugin.changelog.last_updated) + '</span>';
				}
				if (plugin.changelog.tested_up_to) {
					html += '<span class="bpi-meta-tag">' + this.esc(t.testedUpTo + ' ' + plugin.changelog.tested_up_to) + '</span>';
				}
				html += '</div>';
			}

			// Compatibility warnings.
			if (hasIssues && plugin.compatibility_issues && plugin.compatibility_issues.length) {
				html += '<div class="bpi-preview-item__warnings">';
				for (var w = 0; w < plugin.compatibility_issues.length; w++) {
					var issue = plugin.compatibility_issues[w];
					html += '<div class="bpi-compat-warning" role="alert">';
					html += '<span class="dashicons dashicons-warning bpi-warning-icon" aria-hidden="true"></span>';
					html += '<span>' + this.esc(issue.message) + '</span>';
					html += '</div>';
				}
				html += '<button type="button" class="bpi-override-compat button button-link" data-index="' + index + '" aria-label="' + this.escAttr(t.overrideCompatLabel.replace('%s', plugin.plugin_name)) + '">';
				html += this.esc(t.installAnyway);
				html += '</button>';
				html += '</div>';
			}

			// Changelog section (collapsible, for updates only).
			if (isUpdate) {
				html += '<details class="bpi-changelog-section">';
				html += '<summary class="bpi-changelog-toggle" aria-label="' + this.escAttr(t.toggleChangelogLabel.replace('%s', plugin.plugin_name)) + '">' + this.esc(t.changelog) + '</summary>';
				if (plugin.changelog && plugin.changelog.entries && plugin.changelog.entries.length) {
					html += '<div class="bpi-changelog-content">';
					for (var c = 0; c < plugin.changelog.entries.length; c++) {
						var entry = plugin.changelog.entries[c];
						html += '<div class="bpi-changelog-entry">';
						html += '<strong>' + this.esc(entry.version);
						if (entry.date) html += ' — ' + this.esc(entry.date);
						html += '</strong>';
						if (entry.changes && entry.changes.length) {
							html += '<ul>';
							for (var ch = 0; ch < entry.changes.length; ch++) {
								html += '<li>' + this.esc(entry.changes[ch]) + '</li>';
							}
							html += '</ul>';
						}
						html += '</div>';
					}
					html += '</div>';
				} else {
					html += '<p class="bpi-changelog-empty">' + this.esc(t.noChangelog) + '</p>';
				}
				html += '</details>';
			}

			html += '</div>'; // .bpi-preview-item__info

			// Network Activate toggle (only in Network Admin context).
			if (bpiAdmin.isNetworkAdmin) {
				html += '<div class="bpi-preview-item__network-activate">';
				html += '<label class="bpi-toggle-label" for="bpi-network-activate-' + index + '">';
				html += '<input type="checkbox" id="bpi-network-activate-' + index + '" class="bpi-network-activate-toggle" data-index="' + index + '"';
				html += ' aria-label="' + this.escAttr(t.networkActivateLabel.replace('%s', plugin.plugin_name)) + '"';
				if (plugin.network_activate) html += ' checked';
				html += ' />';
				html += '<span class="bpi-toggle-text">' + this.esc(t.networkActivate) + '</span>';
				html += '</label>';
				html += '</div>';
			}

			// Activate after install toggle.
			html += '<div class="bpi-preview-item__activate">';
			html += '<label class="bpi-toggle-label" for="bpi-activate-' + index + '">';
			html += '<input type="checkbox" id="bpi-activate-' + index + '" class="bpi-activate-toggle" data-index="' + index + '"';
			html += ' aria-label="' + this.escAttr(t.activateAfterLabel.replace('%s', plugin.plugin_name)) + '" />';
			html += '<span class="bpi-toggle-text">' + this.esc(t.activate) + '</span>';
			html += '</label>';
			html += '</div>';

			html += '</div>'; // .bpi-preview-item
			return html;
		},

		/**
		 * Bind event handlers for the preview screen.
		 */
		bindPreviewEvents: function () {
			var self = this;
			var t = this.i18n();

			// Back to queue.
			$(document).on('click', '#bpi-back-to-queue', function () {
				self.render();
				self.bindEvents();
				self.renderQueue();
			});

			// Select All.
			$(document).on('click', '#bpi-select-all', function () {
				$('.bpi-preview-checkbox').prop('checked', true);
				self.updatePreviewState();
				self.announce(t.allSelected);
			});

			// Deselect All.
			$(document).on('click', '#bpi-deselect-all', function () {
				$('.bpi-preview-checkbox').prop('checked', false);
				self.updatePreviewState();
				self.announce(t.allDeselected);
			});

			// Individual checkbox change.
			$(document).on('change', '.bpi-preview-checkbox', function () {
				self.updatePreviewState();
			});

			// Override compatibility warning.
			$(document).on('click', '.bpi-override-compat', function () {
				var idx = $(this).data('index');
				var $item = $('[data-index="' + idx + '"].bpi-preview-item');
				$item.removeClass('bpi-preview-item--incompatible');
				$item.find('.bpi-preview-item__warnings').remove();
				$item.find('.bpi-preview-checkbox').prop('checked', true);
				self.updatePreviewState();
				self.announce(t.overrideApplied);
			});

			// Install Selected.
			$(document).on('click', '#bpi-install-selected', function () {
				if (!$(this).prop('disabled')) {
					self.startProcessing(false);
				}
			});

			// Dry Run.
			$(document).on('click', '#bpi-dry-run', function () {
				if (!$(this).prop('disabled')) {
					self.startProcessing(true);
				}
			});
		},

		/**
		 * Update the preview state: count checked plugins, toggle Install button.
		 */
		updatePreviewState: function () {
			var t = this.i18n();
			var checked = $('.bpi-preview-checkbox:checked').length;
			var total = $('.bpi-preview-checkbox').length;
			var $installBtn = $('#bpi-install-selected');
			var $dryRunBtn = $('#bpi-dry-run');
			var $count = $('#bpi-preview-count');

			$count.text(t.selectedCount.replace('%1$s', checked).replace('%2$s', total));

			if (checked === 0) {
				$installBtn.prop('disabled', true).attr('aria-disabled', 'true');
				$dryRunBtn.prop('disabled', true).attr('aria-disabled', 'true');
			} else {
				$installBtn.prop('disabled', false).attr('aria-disabled', 'false');
				$dryRunBtn.prop('disabled', false).attr('aria-disabled', 'false');
			}
		},

		/**
		 * Start processing selected plugins.
		 *
		 * Collects checked plugin slugs and activate toggles from the preview
		 * screen, renders the processing screen, and sends the AJAX request.
		 *
		 * @param {boolean} dryRun Whether to perform a dry run.
		 */
		startProcessing: function (dryRun) {
			var self = this;
			var plugins = [];

			$('.bpi-preview-item').each(function () {
				var $item = $(this);
				var idx = parseInt($item.data('index'), 10);
				var $checkbox = $item.find('.bpi-preview-checkbox');

				if (!$checkbox.prop('checked')) return;

				var previewData = self.previewPlugins[idx];
				if (!previewData) return;

				var activate = $item.find('.bpi-activate-toggle').prop('checked');
				var networkActivate = bpiAdmin.isNetworkAdmin ? $item.find('.bpi-network-activate-toggle').prop('checked') : false;

				plugins.push({
					slug: previewData.slug,
					plugin_name: previewData.plugin_name,
					plugin_version: previewData.plugin_version,
					action: previewData.action,
					action_label: previewData.action_label,
					installed_version: previewData.installed_version || '',
					file_path: previewData.file_path || '',
					plugin_file: previewData.plugin_file || '',
					activate: activate,
					network_activate: networkActivate
				});
			});

			if (!plugins.length) return;

			this.showProcessingScreen(plugins, dryRun);

			var postData = {
				action: 'bpi_process',
				_wpnonce: bpiAdmin.processNonce || bpiAdmin.nonce,
				selected_plugins: plugins,
				dry_run: dryRun ? 1 : 0
			};

			// Mark all as installing sequentially via single AJAX call.
			var slugMap = {};
			for (var i = 0; i < plugins.length; i++) {
				slugMap[plugins[i].slug] = i;
			}

			// Simulate sequential status updates.
			var currentIdx = 0;
			var statusInterval = setInterval(function () {
				if (currentIdx < plugins.length) {
					self.updatePluginStatus(plugins[currentIdx].slug, 'installing');
					self.announce(self.i18n().installingPlugin.replace('%s', plugins[currentIdx].plugin_name));
					currentIdx++;
				}
			}, 800);

			$.post(bpiAdmin.ajaxUrl, postData, function (response) {
				clearInterval(statusInterval);

				if (response.success && response.data) {
					var results = response.data.results || [];
					var summary = response.data.summary || {};
					var batchId = response.data.batch_id || '';

					// Update each plugin's final status.
					for (var r = 0; r < results.length; r++) {
						var res = results[r];
						self.updatePluginStatus(res.slug, res.status, res.messages);
					}

					// Show results screen after a brief delay.
					setTimeout(function () {
						self.showResultsScreen(results, summary, batchId, dryRun);
					}, 600);
				} else {
					var msg = (response.data && response.data.message) ? response.data.message : self.i18n().processingFailed;
					self.showProcessingError(msg);
				}
			}).fail(function () {
				clearInterval(statusInterval);
				self.showProcessingError(self.i18n().networkErrorProcessing);
			});
		},

		/**
		 * Render the processing screen with plugin status list.
		 *
		 * @param {Array}   plugins Array of plugin objects being processed.
		 * @param {boolean} dryRun  Whether this is a dry run.
		 */
		showProcessingScreen: function (plugins, dryRun) {
			var t = this.i18n();
			var html = '';

			// ARIA live region for status announcements.
			html += '<div id="bpi-live-region" class="bpi-sr-only" aria-live="polite" aria-atomic="true"></div>';

			// Header.
			html += '<div class="bpi-processing-header">';
			html += '<span class="bpi-processing-spinner dashicons dashicons-update bpi-spin" aria-hidden="true"></span>';
			html += '<h2>' + this.esc(dryRun ? t.dryRunInProgress : t.installingPlugins) + '</h2>';
			html += '</div>';

			// Plugin status list.
			html += '<div id="bpi-processing-list" class="bpi-processing-list" role="list" aria-label="' + this.escAttr(t.processingStatusLabel) + '">';
			for (var i = 0; i < plugins.length; i++) {
				var p = plugins[i];
				html += '<div class="bpi-processing-item bpi-processing-item--pending" role="listitem" data-slug="' + this.escAttr(p.slug) + '">';
				html += '<span class="bpi-processing-item__icon dashicons dashicons-clock" aria-hidden="true"></span>';
				html += '<div class="bpi-processing-item__info">';
				html += '<strong>' + this.esc(p.plugin_name) + '</strong>';
				html += ' <span class="bpi-version-badge">' + this.esc(p.plugin_version) + '</span>';
				html += ' <span class="bpi-action-label bpi-action-label--' + this.escAttr(p.action) + '">' + this.esc(p.action_label) + '</span>';
				html += '</div>';
				html += '<span class="bpi-processing-item__status">' + this.esc(t.pending) + '</span>';
				html += '</div>';
			}
			html += '</div>';

			// ARIA status region for real-time updates.
			html += '<div id="bpi-processing-status" class="bpi-sr-only" aria-live="assertive" aria-atomic="true"></div>';

			this.$app.html(html);
			this.$liveRegion = $('#bpi-live-region');

			// Focus management: move focus to the processing heading.
			this.$app.find('.bpi-processing-header h2').attr('tabindex', '-1').trigger('focus');
		},

		/**
		 * Update a plugin's status indicator on the processing screen.
		 *
		 * @param {string} slug     Plugin slug.
		 * @param {string} status   New status: 'pending', 'installing', 'success', 'failed'.
		 * @param {Array}  [messages] Optional messages array.
		 */
		updatePluginStatus: function (slug, status, messages) {
			var t = this.i18n();
			var $item = $('.bpi-processing-item[data-slug="' + slug + '"]');
			if (!$item.length) return;

			// Remove previous status classes.
			$item.removeClass('bpi-processing-item--pending bpi-processing-item--installing bpi-processing-item--success bpi-processing-item--failed');
			$item.addClass('bpi-processing-item--' + status);

			// Update icon.
			var $icon = $item.find('.bpi-processing-item__icon');
			$icon.removeClass('dashicons-clock dashicons-update dashicons-yes-alt dashicons-dismiss bpi-spin');

			var iconMap = {
				pending: 'dashicons-clock',
				installing: 'dashicons-update bpi-spin',
				success: 'dashicons-yes-alt',
				failed: 'dashicons-dismiss'
			};
			$icon.addClass(iconMap[status] || 'dashicons-clock');

			// Update status text.
			var statusLabels = {
				pending: t.pending,
				installing: t.installing,
				success: t.success,
				failed: t.failed
			};
			$item.find('.bpi-processing-item__status').text(statusLabels[status] || status);

			// Announce to screen readers.
			var pluginName = $item.find('strong').text();
			$('#bpi-processing-status').text(pluginName + ': ' + (statusLabels[status] || status));
		},

		/**
		 * Show an error message on the processing screen.
		 *
		 * @param {string} message Error message.
		 */
		showProcessingError: function (message) {
			var t = this.i18n();
			var $header = $('.bpi-processing-header');
			$header.find('.bpi-processing-spinner').removeClass('bpi-spin').addClass('dashicons-warning');
			$header.find('h2').text(t.processingError);

			var html = '<div class="bpi-notice bpi-notice--error" role="alert">' + this.esc(message) + '</div>';
			html += '<div class="bpi-results-actions">';
			html += '<button type="button" id="bpi-back-to-upload" class="button button-primary" aria-label="' + this.escAttr(t.backToUploadLabel) + '">' + this.esc(t.backToUpload) + '</button>';
			html += '</div>';

			this.$app.find('.bpi-processing-list').after(html);

			$(document).on('click', '#bpi-back-to-upload', function () {
				BPIUpload.render();
				BPIUpload.bindEvents();
				BPIUpload.renderQueue();
			});

			this.announce(message);
		},

		/**
		 * Render the results/summary screen after processing completes.
		 *
		 * @param {Array}   results Array of per-plugin result objects.
		 * @param {Object}  summary Batch summary with counts.
		 * @param {string}  batchId Batch ID from server.
		 * @param {boolean} dryRun  Whether this was a dry run.
		 */
		showResultsScreen: function (results, summary, batchId, dryRun) {
			var self = this;
			var t = this.i18n();
			var html = '';

			// ARIA live region.
			html += '<div id="bpi-live-region" class="bpi-sr-only" aria-live="polite" aria-atomic="true"></div>';

			// Header.
			html += '<div class="bpi-results-header">';
			if (dryRun) {
				html += '<span class="dashicons dashicons-visibility bpi-results-header__icon" aria-hidden="true"></span>';
				html += '<h2>' + this.esc(t.dryRunComplete) + '</h2>';
			} else {
				html += '<span class="dashicons dashicons-yes-alt bpi-results-header__icon bpi-results-header__icon--success" aria-hidden="true"></span>';
				html += '<h2>' + this.esc(t.processingComplete) + '</h2>';
			}
			html += '</div>';

			// Dry run notice.
			if (dryRun) {
				html += '<div class="bpi-notice bpi-notice--info" role="status">';
				html += this.esc(t.dryRunNotice);
				html += '</div>';
			}

			// Summary cards.
			html += '<div class="bpi-results-summary" role="status" aria-label="' + this.escAttr(t.batchSummaryLabel) + '">';
			html += '<div class="bpi-summary-card bpi-summary-card--installed">';
			html += '<span class="bpi-summary-card__count">' + (summary.installed || 0) + '</span>';
			html += '<span class="bpi-summary-card__label">' + this.esc(t.installedLabel) + '</span>';
			html += '</div>';
			html += '<div class="bpi-summary-card bpi-summary-card--updated">';
			html += '<span class="bpi-summary-card__count">' + (summary.updated || 0) + '</span>';
			html += '<span class="bpi-summary-card__label">' + this.esc(t.updatedLabel) + '</span>';
			html += '</div>';
			html += '<div class="bpi-summary-card bpi-summary-card--failed">';
			html += '<span class="bpi-summary-card__count">' + (summary.failed || 0) + '</span>';
			html += '<span class="bpi-summary-card__label">' + this.esc(t.failedLabel) + '</span>';
			html += '</div>';
			html += '</div>';

			// Per-plugin result list.
			html += '<div class="bpi-results-list" role="list" aria-label="' + this.escAttr(t.perPluginResultsLabel) + '">';
			for (var i = 0; i < results.length; i++) {
				var r = results[i];
				var statusClass = r.status === 'success' ? 'success' : 'failed';
				var statusIcon = r.status === 'success' ? 'dashicons-yes-alt' : 'dashicons-dismiss';
				var statusText = r.status === 'success' ? t.success : t.failed;

				html += '<div class="bpi-results-item bpi-results-item--' + statusClass + '" role="listitem">';
				html += '<span class="bpi-results-item__icon dashicons ' + statusIcon + '" aria-hidden="true"></span>';
				html += '<div class="bpi-results-item__info">';
				html += '<strong>' + this.esc(r.plugin_name || r.slug) + '</strong>';
				html += ' <span class="bpi-results-item__status-text">' + this.esc(statusText) + '</span>';
				if (r.messages && r.messages.length) {
					html += '<div class="bpi-results-item__messages">';
					for (var m = 0; m < r.messages.length; m++) {
						html += '<p>' + this.esc(r.messages[m]) + '</p>';
					}
					html += '</div>';
				}
				if (r.rolled_back) {
					html += '<span class="bpi-results-item__rolled-back">' + this.esc(t.rolledBack) + '</span>';
				}
				html += '</div>';
				html += '</div>';
			}
			html += '</div>';

			// Action buttons.
			html += '<div class="bpi-results-actions">';

			// Rollback Entire Batch button (only for non-dry-run with a batch_id).
			if (!dryRun && batchId) {
				html += '<button type="button" id="bpi-rollback-batch" class="button button-secondary" data-batch-id="' + this.escAttr(batchId) + '" aria-label="' + this.escAttr(t.rollbackBatchLabel) + '">';
				html += this.esc(t.rollbackBatch);
				html += '</button>';
			}

			// Save as Profile button (only if any successes and not dry run).
			var hasSuccess = (summary.installed || 0) + (summary.updated || 0) > 0;
			if (!dryRun && hasSuccess) {
				html += '<button type="button" id="bpi-save-profile" class="button button-secondary" aria-label="' + this.escAttr(t.saveProfileLabel) + '">';
				html += this.esc(t.saveAsProfile);
				html += '</button>';
			}

			html += '<button type="button" id="bpi-back-to-upload" class="button button-primary" aria-label="' + this.escAttr(t.backToUploadLabel) + '">' + this.esc(t.backToUpload) + '</button>';
			html += '</div>';

			this.$app.html(html);
			this.$liveRegion = $('#bpi-live-region');

			// Bind results screen events.
			$(document).on('click', '#bpi-back-to-upload', function () {
				self.queue = [];
				self.render();
				self.bindEvents();
			});

			// Focus management: move focus to the results heading.
			this.$app.find('.bpi-results-header h2').attr('tabindex', '-1').trigger('focus');

			// Announce summary to screen readers.
			var summaryText = t.summaryAnnounce.replace('%1$s', summary.installed || 0).replace('%2$s', summary.updated || 0).replace('%3$s', summary.failed || 0);
			if (dryRun) {
				summaryText = t.dryRunCompleteAnnounce + ' ' + summaryText;
			}
			this.announce(summaryText);
		},

		/**
		 * Handle selected/dropped files.
		 *
		 * Filters to .zip only, then uploads each file.
		 *
		 * @param {FileList} fileList Files from input or drop.
		 */
		handleFiles: function (fileList) {
			var self = this;
			var t = this.i18n();
			var validFiles = [];
			var rejectedNames = [];

			for (var i = 0; i < fileList.length; i++) {
				var file = fileList[i];
				if (file.name.toLowerCase().endsWith('.zip')) {
					validFiles.push(file);
				} else {
					rejectedNames.push(file.name);
				}
			}

			// Show rejection notices.
			if (rejectedNames.length) {
				this.showNotice(
					t.onlyZipAccepted + ' ' + rejectedNames.join(', '),
					'warning'
				);
			}

			if (!validFiles.length) return;

			// Upload each valid file.
			for (var j = 0; j < validFiles.length; j++) {
				self.uploadFile(validFiles[j]);
			}
		},

		/**
		 * Upload a single file via AJAX with progress tracking.
		 *
		 * @param {File} file The file to upload.
		 */
		uploadFile: function (file) {
			var self = this;

			// Create a temporary queue item for progress display.
			var tempId = 'temp-' + Date.now() + '-' + Math.random().toString(36).substr(2, 5);
			var tempItem = {
				_tempId: tempId,
				file_name: file.name,
				file_size: file.size,
				_uploading: true,
				_progress: 0
			};

			this.queue.push(tempItem);
			this.renderQueue();

			var formData = new FormData();
			formData.append('action', 'bpi_upload');
			formData.append('_wpnonce', bpiAdmin.uploadNonce || bpiAdmin.nonce);
			formData.append('plugin_zip', file);

			var xhr = new XMLHttpRequest();

			// Track upload progress.
			xhr.upload.addEventListener('progress', function (e) {
				if (e.lengthComputable) {
					var pct = Math.round((e.loaded / e.total) * 100);
					self.updateItemProgress(tempId, pct);
				}
			});

			xhr.addEventListener('load', function () {
				var t = self.i18n();
				var response;
				try {
					response = JSON.parse(xhr.responseText);
				} catch (e) {
					self.replaceTemp(tempId, null, t.uploadFailedInvalid);
					return;
				}

				if (response.success && response.data) {
					var data = response.data;
					self.replaceTemp(tempId, {
						slug: data.slug,
						file_name: data.file_name || file.name,
						file_size: data.file_size || file.size,
						plugin_name: (data.headers && data.headers.plugin_name) || data.file_name || file.name,
						_uploading: false,
						_progress: 100,
						_status: 'success'
					});
					self.announce(t.uploaded.replace('%s', data.file_name || file.name));
				} else {
					var msg = (response.data && response.data.message) ? response.data.message : t.uploadFailed;
					self.replaceTemp(tempId, null, msg);
				}
			});

			xhr.addEventListener('error', function () {
				self.replaceTemp(tempId, null, self.i18n().networkErrorUpload);
			});

			xhr.open('POST', bpiAdmin.ajaxUrl);
			xhr.send(formData);
		},

		/**
		 * Update the progress of a temporary upload item.
		 *
		 * @param {string} tempId Temporary item ID.
		 * @param {number} pct    Progress percentage (0-100).
		 */
		updateItemProgress: function (tempId, pct) {
			for (var i = 0; i < this.queue.length; i++) {
				if (this.queue[i]._tempId === tempId) {
					this.queue[i]._progress = pct;
					break;
				}
			}
			var $bar = $('#bpi-progress-' + tempId + ' .bpi-progress-bar__fill');
			if ($bar.length) {
				$bar.css('width', pct + '%');
				$bar.parent().attr('aria-valuenow', pct);
			}
			var $status = $('#bpi-status-' + tempId);
			if ($status.length) {
				$status.text(pct + '%');
			}
		},

		/**
		 * Replace a temporary upload item with the server response or an error.
		 *
		 * @param {string}      tempId   Temporary item ID.
		 * @param {Object|null} data     Server data on success, null on failure.
		 * @param {string}      [errMsg] Error message on failure.
		 */
		replaceTemp: function (tempId, data, errMsg) {
			for (var i = 0; i < this.queue.length; i++) {
				if (this.queue[i]._tempId === tempId) {
					if (data) {
						// Check for duplicate slug — replace existing.
						var existingIdx = -1;
						for (var j = 0; j < this.queue.length; j++) {
							if (j !== i && this.queue[j].slug === data.slug) {
								existingIdx = j;
								break;
							}
						}
						if (existingIdx >= 0) {
							this.queue.splice(existingIdx, 1);
							if (existingIdx < i) i--;
							this.showNotice(
								this.i18n().duplicateDetected.replace('%s', data.slug),
								'info'
							);
						}
						this.queue[i] = data;
					} else {
						// Remove failed item.
						this.queue.splice(i, 1);
						if (errMsg) {
							this.showNotice(errMsg, 'error');
						}
					}
					break;
				}
			}
			this.renderQueue();
		},

		/**
		 * Remove an item from the queue by slug.
		 *
		 * Sends AJAX to server and updates local state.
		 *
		 * @param {string} slug Plugin slug to remove.
		 */
		removeFromQueue: function (slug) {
			var self = this;
			var t = this.i18n();

			// Remove locally first for responsiveness.
			for (var i = 0; i < this.queue.length; i++) {
				if (this.queue[i].slug === slug) {
					this.queue.splice(i, 1);
					break;
				}
			}
			this.renderQueue();
			this.announce(t.removedFromQueue.replace('%s', slug));

			// Notify server.
			$.post(bpiAdmin.ajaxUrl, {
				action: 'bpi_queue_remove',
				_wpnonce: bpiAdmin.queueRemoveNonce || bpiAdmin.nonce,
				slug: slug
			});
		},

		/**
		 * Render the queue list UI.
		 */
		renderQueue: function () {
			var $section = $('#bpi-queue-section');
			var $list = $('#bpi-queue-list');
			var $summary = $('#bpi-queue-summary');
			var $continueBtn = $('#bpi-continue-preview');

			// Filter to completed (non-uploading or uploading) items.
			var completedItems = [];
			var totalSize = 0;

			for (var i = 0; i < this.queue.length; i++) {
				var item = this.queue[i];
				if (!item._uploading || item._tempId) {
					completedItems.push(item);
				}
				if (!item._uploading && item.file_size) {
					totalSize += item.file_size;
				}
			}

			if (!this.queue.length) {
				$section.hide();
				$continueBtn.prop('disabled', true).attr('aria-disabled', 'true');
				return;
			}

			$section.show();

			// Count only fully uploaded items.
			var readyCount = 0;
			for (var k = 0; k < this.queue.length; k++) {
				if (!this.queue[k]._uploading) readyCount++;
			}

			$summary.text(this.i18n().queueSummary.replace('%1$s', readyCount).replace('%2$s', formatFileSize(totalSize)));

			// Build list HTML.
			var html = '';
			for (var m = 0; m < this.queue.length; m++) {
				html += this.renderQueueItem(this.queue[m]);
			}
			$list.html(html);

			// Enable/disable continue button.
			if (readyCount > 0) {
				$continueBtn.prop('disabled', false).attr('aria-disabled', 'false');
			} else {
				$continueBtn.prop('disabled', true).attr('aria-disabled', 'true');
			}
		},

		/**
		 * Render a single queue item.
		 *
		 * @param {Object} item Queue item data.
		 * @return {string} HTML string.
		 */
		renderQueueItem: function (item) {
			var t = this.i18n();
			var itemLabel = this.escAttr(item.file_name || item.slug || 'file');
			var html = '<li class="bpi-queue-item" aria-label="' + itemLabel + '">';
			html += '<div class="bpi-queue-item__info">';
			html += '<div class="bpi-queue-item__name">' + this.esc(item.file_name || item.slug || '') + '</div>';
			html += '<div class="bpi-queue-item__size">' + formatFileSize(item.file_size || 0) + '</div>';

			// Progress bar for uploading items.
			if (item._uploading && item._tempId) {
				html += '<div class="bpi-progress-bar" id="bpi-progress-' + this.esc(item._tempId) + '" role="progressbar" aria-valuenow="' + (item._progress || 0) + '" aria-valuemin="0" aria-valuemax="100" aria-label="' + this.escAttr(t.uploadProgressLabel.replace('%s', item.file_name || '')) + '">';
				html += '<div class="bpi-progress-bar__fill" style="width:' + (item._progress || 0) + '%;"></div>';
				html += '</div>';
			}

			html += '</div>';

			// Status.
			if (item._uploading && item._tempId) {
				html += '<span id="bpi-status-' + this.esc(item._tempId) + '" class="bpi-queue-item__status bpi-status--uploading">' + (item._progress || 0) + '%</span>';
			} else if (item._status === 'success') {
				html += '<span class="bpi-queue-item__status bpi-status--success">&#10003; ' + this.esc(t.uploadedStatus) + '</span>';
			}

			// Remove button (only for completed uploads).
			if (!item._uploading && item.slug) {
				html += '<button type="button" class="bpi-queue-item__remove" data-slug="' + this.escAttr(item.slug) + '" aria-label="' + this.escAttr(t.removeFromQueueLabel.replace('%s', item.file_name || item.slug)) + '">' + this.esc(t.remove) + '</button>';
			}

			html += '</li>';
			return html;
		},

		/**
		 * Show a notice message.
		 *
		 * @param {string} message Notice text.
		 * @param {string} type    Notice type: 'error', 'warning', 'info'.
		 */
		showNotice: function (message, type) {
			var $notices = $('#bpi-notices');
			var $notice = $('<div class="bpi-notice bpi-notice--' + type + '" role="alert">' + this.esc(message) + '</div>');
			$notices.append($notice);

			// Auto-dismiss after 8 seconds.
			setTimeout(function () {
				$notice.fadeOut(300, function () {
					$(this).remove();
				});
			}, 8000);
		},

		/**
		 * Announce a message to screen readers via ARIA live region.
		 *
		 * @param {string} message Message to announce.
		 */
		announce: function (message) {
			if (this.$liveRegion) {
				this.$liveRegion.text(message);
			}
		},

		/**
		 * Get the i18n strings from the localized data.
		 *
		 * @return {Object} Localized strings object.
		 */
		i18n: function () {
			return (bpiAdmin && bpiAdmin.i18n) ? bpiAdmin.i18n : {};
		},

		/**
		 * Escape HTML entities for safe insertion.
		 *
		 * @param {string} str Input string.
		 * @return {string} Escaped string.
		 */
		esc: function (str) {
			var div = document.createElement('div');
			div.appendChild(document.createTextNode(str));
			return div.innerHTML;
		},

		/**
		 * Escape a string for use in an HTML attribute.
		 *
		 * @param {string} str Input string.
		 * @return {string} Escaped string.
		 */
		escAttr: function (str) {
			return this.esc(str).replace(/"/g, '&quot;').replace(/'/g, '&#39;');
		}
	};

	// Initialize on DOM ready.
	$(function () {
		BPIUpload.init();
	});

})(jQuery);
