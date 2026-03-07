(function () {
    'use strict';

    function formatCardNumber(input) {
        input.addEventListener('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 16);
            var parts = v.match(/.{1,4}/g);
            this.value = parts ? parts.join(' ') : v;
        });
    }

    function formatExpiry(input) {
        input.addEventListener('input', function () {
            var v = this.value.replace(/\D/g, '').substring(0, 4);
            if (v.length >= 3) {
                this.value = v.substring(0, 2) + ' / ' + v.substring(2);
            } else {
                this.value = v;
            }
        });
    }

    function limitNumeric(input, max) {
        input.addEventListener('input', function () {
            this.value = this.value.replace(/\D/g, '').substring(0, max);
        });
    }

    function init() {
        document.querySelectorAll('input[name="mcpg_card_number"]').forEach(formatCardNumber);
        document.querySelectorAll('input[name="mcpg_expiry"]').forEach(formatExpiry);
        document.querySelectorAll('input[name="mcpg_cvv"]').forEach(function (el) {
            limitNumeric(el, 4);
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

    // Re-init on WooCommerce checkout updates
    if (typeof jQuery !== 'undefined') {
        jQuery(document.body).on('updated_checkout payment_method_selected', function () {
            setTimeout(init, 200);
        });
    }
})();
