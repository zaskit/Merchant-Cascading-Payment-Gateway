(function ($) {
    'use strict';

    var config      = window.mcpg_cascade;
    var totalSteps  = parseInt(config.total_steps, 10);
    var currentStep = 0;
    var isFinished  = false;
    var isProcessing = false; // Mutex to prevent double AJAX calls

    // SVG icons
    var ICON_CHECK = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>';
    var ICON_X     = '<svg viewBox="0 0 24 24" width="24" height="24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
    var ICON_SPINNER = '<svg viewBox="0 0 24 24" width="18" height="18" style="animation:mcpg-spin 0.8s linear infinite"><circle cx="12" cy="12" r="10" fill="none" stroke="currentColor" stroke-width="3" opacity="0.25"/><path d="M12 2a10 10 0 0 1 10 10" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round"/></svg>';

    function init() {
        // Inject spin animation
        if (!document.getElementById('mcpg-spin-style')) {
            var style = document.createElement('style');
            style.id = 'mcpg-spin-style';
            style.textContent = '@keyframes mcpg-spin{from{transform:rotate(0)}to{transform:rotate(360deg)}}';
            document.head.appendChild(style);
        }

        // If resuming after 3DS return, mark prior steps as failed
        var startStep = parseInt(config.current_step, 10) || 0;
        for (var i = 0; i < startStep; i++) {
            setStepFailed(i, 'Declined');
        }

        // Start cascade after a brief delay
        setTimeout(function () {
            processStep(startStep);
        }, 800);
    }

    function processStep(step) {
        if (isFinished || step >= totalSteps) {
            if (!isFinished) showExhausted();
            return;
        }

        if (isProcessing) return; // Prevent double fire
        isProcessing = true;

        currentStep = step;
        setStepActive(step);
        updateProgress(step, totalSteps);
        setMessage('Attempting payment through secure route ' + (step + 1) + ' of ' + totalSteps + '...');

        $.ajax({
            url: config.ajax_url,
            method: 'POST',
            data: {
                action: 'mcpg_cascade_process',
                nonce: config.nonce,
                order_id: config.order_id,
                order_key: config.order_key
            },
            timeout: 120000, // 2 min per attempt
            success: function (response) {
                isProcessing = false;

                if (!response || !response.data) {
                    setStepFailed(step, 'Connection error');
                    nextStep(step);
                    return;
                }

                var data = response.data;

                switch (data.status) {
                    case 'approved':
                        setStepSuccess(step, 'Approved');
                        updateProgress(totalSteps, totalSteps);
                        showSuccess(data.redirect_url);
                        break;

                    case '3ds_redirect':
                        setStepStatus(step, 'Verifying with your bank...', 'mcpg-step-active');
                        setMessage('You will be redirected to your bank for verification...');
                        setTimeout(function () {
                            window.location.href = data.redirect_url;
                        }, 1500);
                        isFinished = true;
                        break;

                    case 'pending':
                        setStepStatus(step, 'Awaiting confirmation...', 'mcpg-step-active');
                        setMessage('Waiting for payment confirmation. This may take a moment...');
                        // Poll for completion
                        setTimeout(function () {
                            pollPending(step, 0);
                        }, 3000);
                        break;

                    case 'exhausted':
                        setStepFailed(step, 'Declined');
                        showExhausted();
                        break;

                    case 'failed':
                    default:
                        setStepFailed(step, 'Declined');
                        nextStep(step);
                        break;
                }
            },
            error: function () {
                isProcessing = false;
                setStepFailed(step, 'Connection error');
                nextStep(step);
            }
        });
    }

    function nextStep(step) {
        var next = step + 1;
        if (next >= totalSteps) {
            // Don't show exhausted here — the server already returned 'exhausted' or will on next call
            // But if we got here from a client-side error, show it
            showExhausted();
            return;
        }
        setTimeout(function () {
            processStep(next);
        }, parseInt(config.step_delay, 10) || 1500);
    }

    function pollPending(step, attempts) {
        if (isFinished || attempts > 30) {
            // Timeout — show pending message
            setMessage('Your payment is still being confirmed. You\'ll receive an email when it\'s done.');
            return;
        }

        $.ajax({
            url: config.ajax_url,
            method: 'POST',
            data: {
                action: 'mcpg_cascade_process',
                nonce: config.nonce,
                order_id: config.order_id,
                order_key: config.order_key
            },
            success: function (response) {
                if (!response || !response.data) {
                    setTimeout(function () { pollPending(step, attempts + 1); }, 3000);
                    return;
                }
                var data = response.data;
                if (data.status === 'approved') {
                    setStepSuccess(step, 'Approved');
                    updateProgress(totalSteps, totalSteps);
                    showSuccess(data.redirect_url);
                } else if (data.status === '3ds_redirect') {
                    setStepStatus(step, 'Verifying with your bank...', 'mcpg-step-active');
                    setMessage('You will be redirected to your bank for verification...');
                    isFinished = true;
                    setTimeout(function () {
                        window.location.href = data.redirect_url;
                    }, 1500);
                } else {
                    setTimeout(function () {
                        pollPending(step, attempts + 1);
                    }, 3000);
                }
            },
            error: function () {
                setTimeout(function () {
                    pollPending(step, attempts + 1);
                }, 5000);
            }
        });
    }

    /* ── UI Helpers ── */

    function setStepActive(step) {
        var el = $('#mcpg-step-' + step);
        el.removeClass('mcpg-step-failed mcpg-step-success').addClass('mcpg-step-active');
        $('#mcpg-step-icon-' + step).html(ICON_SPINNER);
        $('#mcpg-step-status-' + step).text('Processing...');
    }

    function setStepSuccess(step, text) {
        var el = $('#mcpg-step-' + step);
        el.removeClass('mcpg-step-active mcpg-step-failed').addClass('mcpg-step-success');
        $('#mcpg-step-icon-' + step).html(ICON_CHECK);
        $('#mcpg-step-status-' + step).text(text || 'Complete');
    }

    function setStepFailed(step, text) {
        var el = $('#mcpg-step-' + step);
        el.removeClass('mcpg-step-active mcpg-step-success').addClass('mcpg-step-failed');
        $('#mcpg-step-icon-' + step).html(ICON_X);
        $('#mcpg-step-status-' + step).text(text || 'Failed');
    }

    function setStepStatus(step, text, className) {
        var el = $('#mcpg-step-' + step);
        if (className) el.attr('class', 'mcpg-step ' + className);
        $('#mcpg-step-status-' + step).text(text);
    }

    function updateProgress(step, total) {
        var pct = total > 0 ? Math.min(((step + 1) / total) * 100, 100) : 0;
        $('#mcpg-progress-bar').css('width', pct + '%');
    }

    function setMessage(text) {
        $('#mcpg-message').text(text);
    }

    function showSuccess(redirectUrl) {
        isFinished = true;

        // Hide steps area and show result
        $('#mcpg-message').hide();
        $('.mcpg-cascade-warning').hide();

        var resultEl = $('#mcpg-result');
        $('#mcpg-result-icon')
            .addClass('mcpg-result-success')
            .html('<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><polyline points="20 6 9 17 4 12"/></svg>');
        $('#mcpg-result-title').text('Payment Successful!');
        $('#mcpg-result-message').text('Your payment has been processed securely. Redirecting you now...');
        $('#mcpg-result-button')
            .addClass('mcpg-btn-success')
            .text('Continue to Order')
            .attr('href', redirectUrl);
        resultEl.show();

        // Auto-redirect after a brief moment
        setTimeout(function () {
            window.location.href = redirectUrl;
        }, 2500);
    }

    function showExhausted() {
        isFinished = true;

        $('#mcpg-message').hide();
        $('.mcpg-cascade-warning').hide();
        updateProgress(totalSteps, totalSteps);

        var resultEl = $('#mcpg-result');
        $('#mcpg-result-icon')
            .addClass('mcpg-result-failure')
            .html('<svg viewBox="0 0 24 24" width="32" height="32" fill="none" stroke="currentColor" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><line x1="15" y1="9" x2="9" y2="15"/><line x1="9" y1="9" x2="15" y2="15"/></svg>');
        $('#mcpg-result-title').text('Payment Unsuccessful');
        $('#mcpg-result-message').text('We were unable to process your payment through any of our available channels. Your order has been placed on hold. Please try again with a different card or contact support.');
        $('#mcpg-result-button')
            .addClass('mcpg-btn-failure')
            .text('Return to Checkout')
            .attr('href', config.checkout_url);
        resultEl.show();

        // Change header
        $('.mcpg-cascade-title').text('Payment Could Not Be Processed');
        $('.mcpg-cascade-subtitle').text('All available payment routes have been attempted.');
    }

    // Start when DOM is ready
    $(document).ready(init);

})(jQuery);
