/**
 * SB FB Profile Importer - Admin JavaScript
 */
(function($) {
    'use strict';

    /**
     * Show result message
     * @param {jQuery} $element Target element
     * @param {string} message Message text
     * @param {string} type Message type (success, error, loading)
     */
    function showResult($element, message, type) {
        $element
            .removeClass('sb-fbpi-result-success sb-fbpi-result-error sb-fbpi-result-loading')
            .addClass('sb-fbpi-result-' + type)
            .text(message)
            .show();
    }

    /**
     * Validate token button handler
     */
    $('#sb-fbpi-validate-token').on('click', function() {
        var $button = $(this);
        var $result = $('#sb-fbpi-token-result');

        $button.prop('disabled', true);
        showResult($result, sbFbpiAdmin.strings.validating, 'loading');

        $.ajax({
            url: sbFbpiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sb_fbpi_validate_token',
                nonce: sbFbpiAdmin.validateNonce
            },
            success: function(response) {
                if (response.success) {
                    showResult($result, response.data.message, 'success');
                } else {
                    showResult($result, sbFbpiAdmin.strings.error + ' ' + response.data, 'error');
                }
            },
            error: function(xhr, status, error) {
                showResult($result, sbFbpiAdmin.strings.error + ' ' + error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    /**
     * Run import button handler
     */
    $('#sb-fbpi-run-import').on('click', function() {
        var $button = $(this);
        var $result = $('#sb-fbpi-import-result');

        if (!confirm(sbFbpiAdmin.strings.confirm)) {
            return;
        }

        $button.prop('disabled', true);
        showResult($result, sbFbpiAdmin.strings.importing, 'loading');

        $.ajax({
            url: sbFbpiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sb_fbpi_run_import',
                nonce: sbFbpiAdmin.importNonce
            },
            success: function(response) {
                if (response.success) {
                    showResult($result, response.data.message, 'success');
                    // Reload page after 2 seconds to show updated data
                    setTimeout(function() {
                        location.reload();
                    }, 2000);
                } else {
                    var message = response.data.message || response.data;
                    showResult($result, sbFbpiAdmin.strings.error + ' ' + message, 'error');
                }
            },
            error: function(xhr, status, error) {
                showResult($result, sbFbpiAdmin.strings.error + ' ' + error, 'error');
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    /**
     * Clear logs button handler
     */
    $('#sb-fbpi-clear-logs').on('click', function() {
        var $button = $(this);

        if (!confirm(sbFbpiAdmin.strings.confirm)) {
            return;
        }

        $button.prop('disabled', true);

        $.ajax({
            url: sbFbpiAdmin.ajaxUrl,
            type: 'POST',
            data: {
                action: 'sb_fbpi_clear_logs',
                nonce: sbFbpiAdmin.clearLogsNonce
            },
            success: function(response) {
                if (response.success) {
                    $('.sb-fbpi-logs').html('<p>' + response.data + '</p>');
                }
            },
            error: function(xhr, status, error) {
                alert(sbFbpiAdmin.strings.error + ' ' + error);
            },
            complete: function() {
                $button.prop('disabled', false);
            }
        });
    });

    /**
     * Source type change handler - show warning for profile
     */
    $('#sb_fbpi_source_type').on('change', function() {
        var $description = $(this).siblings('.description');
        
        if ($(this).val() === 'profile') {
            $description.addClass('sb-fbpi-warning');
        } else {
            $description.removeClass('sb-fbpi-warning');
        }
    }).trigger('change');

    /**
     * Token field - show/hide based on constant
     */
    if ($('#sb_fbpi_access_token').prop('disabled')) {
        $('#sb-fbpi-validate-token').text('Validate Stored Token');
    }

})(jQuery);
