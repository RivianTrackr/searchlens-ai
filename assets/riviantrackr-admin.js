(function($) {
    $(document).ready(function() {
        var adminData = window.RivianTrackrAdmin || {};

        // --- Settings page: CSS modal / reset ---
        var modal = $('#riviantrackr-default-css-modal');
        var textarea = $('#riviantrackr-custom-css');

        $('#riviantrackr-reset-css').on('click', function() {
            if (confirm('Reset custom CSS? This will clear all your custom styles.')) {
                textarea.val('');
            }
        });

        $('#riviantrackr-view-default-css').on('click', function() {
            modal.addClass('riviantrackr-modal-open');
        });

        $('#riviantrackr-close-modal').on('click', function() {
            modal.removeClass('riviantrackr-modal-open');
        });

        modal.on('click', function(e) {
            if (e.target === this) {
                modal.removeClass('riviantrackr-modal-open');
            }
        });

        $(document).on('keydown', function(e) {
            if (e.key === 'Escape' && modal.hasClass('riviantrackr-modal-open')) {
                modal.removeClass('riviantrackr-modal-open');
            }
        });

        // --- Settings page: Advanced toggle ---
        $('#riviantrackr-advanced-toggle').on('click', function() {
            var $settings = $('#riviantrackr-advanced-settings');
            var isHidden = $settings.is(':hidden');
            if (isHidden) {
                $settings.slideDown(200);
                $(this).text('Hide Advanced Settings');
            } else {
                $settings.slideUp(200);
                $(this).text('Show Advanced Settings');
            }
        });

        // --- Settings page: Test API Key ---
        $('#riviantrackr-test-key-btn').on('click', function() {
            var btn = $(this);
            var useConstant = adminData.useApiKeyConstant || false;
            var apiKey = useConstant ? '__USE_CONSTANT__' : $('#riviantrackr-api-key').val().trim();
            var resultDiv = $('#riviantrackr-test-result');

            if (!useConstant && !apiKey) {
                resultDiv.html('<div class="riviantrackr-test-result error"><p>Please enter an API key first.</p></div>');
                return;
            }

            btn.prop('disabled', true).text('Testing...');
            resultDiv.html('<div class="riviantrackr-test-result info"><p>Testing API key...</p></div>');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'riviantrackr_test_api_key',
                    api_key: apiKey,
                    nonce: adminData.testKeyNonce || ''
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Test Connection');

                    if (response.success) {
                        var msg = '<strong>\u2713 ' + response.data.message + '</strong>';
                        if (response.data.model_count) {
                            msg += '<br>Available models: ' + response.data.model_count + ' (Chat models: ' + response.data.chat_models + ')';
                        }
                        resultDiv.html('<div class="riviantrackr-test-result success"><p>' + msg + '</p></div>');
                    } else {
                        resultDiv.html('<div class="riviantrackr-test-result error"><p><strong>\u2717 Test failed:</strong> ' + response.data.message + '</p></div>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Test Connection');
                    resultDiv.html('<div class="riviantrackr-test-result error"><p>Request failed. Please try again.</p></div>');
                }
            });
        });

        // --- Settings page: Refresh Models ---
        $('#riviantrackr-refresh-models-btn').on('click', function() {
            var btn = $(this);
            var resultSpan = $('#riviantrackr-refresh-models-result');
            var nonce = btn.data('nonce');

            btn.prop('disabled', true).text('Refreshing...');
            resultSpan.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'riviantrackr_refresh_models',
                    nonce: nonce
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Refresh Models');
                    if (response.success) {
                        resultSpan.html('<span style="color: #fba919;">\u2713 ' + response.data.message + '</span>');
                        setTimeout(function() { location.reload(); }, 1000);
                    } else {
                        resultSpan.html('<span style="color: #ef4444;">\u2717 ' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Refresh Models');
                    resultSpan.html('<span style="color: #ef4444;">\u2717 Request failed. Please try again.</span>');
                }
            });
        });

        // --- Settings page: GDPR Purge ---
        $('#riviantrackr-gdpr-purge-btn').on('click', function() {
            if (!confirm('This will permanently replace all stored search query text with SHA-256 hashes. This cannot be undone. Continue?')) return;

            var btn = $(this);
            var resultSpan = $('#riviantrackr-gdpr-purge-result');
            btn.prop('disabled', true).text('Anonymizing...');
            resultSpan.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'riviantrackr_gdpr_purge_queries',
                    nonce: adminData.gdprPurgeNonce || ''
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Anonymize Existing Queries');
                    if (response.success) {
                        resultSpan.html('<span style="color: #10b981;">' + response.data.message + '</span>');
                    } else {
                        resultSpan.html('<span style="color: #ef4444;">' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Anonymize Existing Queries');
                    resultSpan.html('<span style="color: #ef4444;">Request failed. Please try again.</span>');
                }
            });
        });

        // --- Settings page: Clear Cache ---
        $('#riviantrackr-clear-cache-btn').on('click', function() {
            var btn = $(this);
            var resultSpan = $('#riviantrackr-clear-cache-result');
            var nonce = btn.data('nonce');

            btn.prop('disabled', true).text('Clearing...');
            resultSpan.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'riviantrackr_clear_cache',
                    nonce: nonce
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Clear Cache Now');
                    if (response.success) {
                        resultSpan.html('<span style="color: #fba919;">\u2713 ' + response.data.message + '</span>');
                    } else {
                        resultSpan.html('<span style="color: #ef4444;">\u2717 ' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Clear Cache Now');
                    resultSpan.html('<span style="color: #ef4444;">\u2717 Request failed. Please try again.</span>');
                }
            });
        });

        // --- Analytics page: Purge Spam ---
        $('#riviantrackr-purge-spam-btn').on('click', function() {
            var btn = $(this);
            var resultSpan = $('#riviantrackr-purge-spam-result');
            var nonce = btn.data('nonce');

            if (!confirm('This will scan all log entries and permanently delete those matching spam patterns. Continue?')) {
                return;
            }

            btn.prop('disabled', true).text('Scanning...');
            resultSpan.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'riviantrackr_purge_spam',
                    nonce: nonce
                },
                success: function(response) {
                    btn.prop('disabled', false).text('Scan & Remove Spam');
                    if (response.success) {
                        var color = response.data.deleted > 0 ? '#10b981' : '#6e6e73';
                        resultSpan.html('<span style="color: ' + color + ';">' + response.data.message + '</span>');
                        if (response.data.deleted > 0) {
                            setTimeout(function() { location.reload(); }, 2000);
                        }
                    } else {
                        resultSpan.html('<span style="color: #ef4444;">' + response.data.message + '</span>');
                    }
                },
                error: function() {
                    btn.prop('disabled', false).text('Scan & Remove Spam');
                    resultSpan.html('<span style="color: #ef4444;">Request failed. Please try again.</span>');
                }
            });
        });

        // --- Analytics page: Bulk delete ---
        var $selectAll = $('#riviantrackr-select-all');
        var $deleteBtn = $('#riviantrackr-bulk-delete-btn');
        var $resultSpan = $('#riviantrackr-bulk-delete-result');

        function updateDeleteBtn() {
            var checked = $('.riviantrackr-row-check:checked').length;
            $deleteBtn.toggle(checked > 0).text('Delete Selected (' + checked + ')');
        }

        $selectAll.on('change', function() {
            $('.riviantrackr-row-check').prop('checked', this.checked);
            updateDeleteBtn();
        });

        $(document).on('change', '.riviantrackr-row-check', function() {
            var total = $('.riviantrackr-row-check').length;
            var checked = $('.riviantrackr-row-check:checked').length;
            $selectAll.prop('checked', total === checked);
            updateDeleteBtn();
        });

        $deleteBtn.on('click', function() {
            var ids = $('.riviantrackr-row-check:checked').map(function() { return this.value; }).get();
            if (!ids.length) return;

            if (!confirm('Delete ' + ids.length + ' selected log entries? This cannot be undone.')) return;

            $deleteBtn.prop('disabled', true).text('Deleting...');
            $resultSpan.html('');

            $.ajax({
                url: ajaxurl,
                type: 'POST',
                data: {
                    action: 'riviantrackr_bulk_delete_logs',
                    nonce: adminData.bulkDeleteNonce || '',
                    ids: ids.join(',')
                },
                success: function(response) {
                    $deleteBtn.prop('disabled', false);
                    if (response.success) {
                        $resultSpan.html('<span style="color: #10b981;">' + response.data.message + '</span>');
                        setTimeout(function() { location.reload(); }, 1500);
                    } else {
                        $resultSpan.html('<span style="color: #ef4444;">' + response.data.message + '</span>');
                        updateDeleteBtn();
                    }
                },
                error: function() {
                    $deleteBtn.prop('disabled', false);
                    $resultSpan.html('<span style="color: #ef4444;">Request failed. Please try again.</span>');
                    updateDeleteBtn();
                }
            });
        });
    });
})(jQuery);
