/**
 * MCPG Block Checkout Integration
 * Registers the cascading payment gateway with WooCommerce Block Checkout.
 */
(function () {
    'use strict';

    const { registerPaymentMethod } = wc.wcBlocksRegistry;
    const { createElement, useState, useEffect, useCallback } = wp.element;
    const { decodeEntities } = wp.htmlEntities;

    const settings = wc.wcSettings.getSetting('mcpg_cascading_data', {});
    const title = decodeEntities(settings.title || 'Credit / Debit Card');
    const description = decodeEntities(settings.description || '');
    const iconHtml = settings.icon || '';

    /**
     * Format card number with spaces every 4 digits.
     */
    function formatCardNumber(value) {
        var v = value.replace(/\D/g, '').substring(0, 19);
        var parts = [];
        for (var i = 0; i < v.length; i += 4) {
            parts.push(v.substring(i, i + 4));
        }
        return parts.join(' ');
    }

    /**
     * Format expiry as MM / YY.
     */
    function formatExpiry(value) {
        var v = value.replace(/\D/g, '').substring(0, 4);
        if (v.length >= 3) {
            return v.substring(0, 2) + ' / ' + v.substring(2);
        }
        return v;
    }

    /**
     * Card form component rendered inside the block checkout payment area.
     */
    var CardForm = function (props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var onPaymentSetup = eventRegistration.onPaymentSetup;

        var _cardName = useState('');
        var cardName = _cardName[0];
        var setCardName = _cardName[1];

        var _cardNumber = useState('');
        var cardNumber = _cardNumber[0];
        var setCardNumber = _cardNumber[1];

        var _expiry = useState('');
        var expiry = _expiry[0];
        var setExpiry = _expiry[1];

        var _cvv = useState('');
        var cvv = _cvv[0];
        var setCvv = _cvv[1];

        useEffect(function () {
            var unsubscribe = onPaymentSetup(function () {
                var rawNumber = cardNumber.replace(/\D/g, '');
                var rawExpiry = expiry.replace(/\D/g, '');
                var rawCvv = cvv.replace(/\D/g, '');

                // Validate
                if (!cardName.trim()) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please enter the cardholder name.'
                    };
                }
                if (rawNumber.length < 13 || rawNumber.length > 19) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please enter a valid card number.'
                    };
                }
                if (rawExpiry.length !== 4) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please enter a valid expiry date (MM/YY).'
                    };
                }
                if (rawCvv.length < 3 || rawCvv.length > 4) {
                    return {
                        type: emitResponse.responseTypes.ERROR,
                        message: 'Please enter a valid CVC.'
                    };
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            mcpg_card_name: cardName.trim(),
                            mcpg_card_number: rawNumber,
                            mcpg_expiry: rawExpiry,
                            mcpg_cvv: rawCvv
                        }
                    }
                };
            });

            return unsubscribe;
        }, [onPaymentSetup, cardName, cardNumber, expiry, cvv, emitResponse.responseTypes]);

        return createElement('fieldset', { className: 'mcpg-card-form', id: 'mcpg-card-form' },
            description ? createElement('p', { className: 'mcpg-description' }, description) : null,
            createElement('div', { className: 'mcpg-field' },
                createElement('label', null, 'Cardholder Name ', createElement('span', { className: 'required' }, '*')),
                createElement('input', {
                    type: 'text',
                    value: cardName,
                    onChange: function (e) { setCardName(e.target.value); },
                    autoComplete: 'cc-name',
                    placeholder: 'Name on card'
                })
            ),
            createElement('div', { className: 'mcpg-field' },
                createElement('label', null, 'Card Number ', createElement('span', { className: 'required' }, '*')),
                createElement('input', {
                    type: 'text',
                    value: cardNumber,
                    onChange: function (e) { setCardNumber(formatCardNumber(e.target.value)); },
                    inputMode: 'numeric',
                    autoComplete: 'cc-number',
                    placeholder: '0000 0000 0000 0000',
                    maxLength: 23
                })
            ),
            createElement('div', { className: 'mcpg-row' },
                createElement('div', { className: 'mcpg-field' },
                    createElement('label', null, 'Expiry ', createElement('span', { className: 'required' }, '*')),
                    createElement('input', {
                        type: 'text',
                        value: expiry,
                        onChange: function (e) { setExpiry(formatExpiry(e.target.value)); },
                        inputMode: 'numeric',
                        autoComplete: 'cc-exp',
                        placeholder: 'MM / YY',
                        maxLength: 7
                    })
                ),
                createElement('div', { className: 'mcpg-field' },
                    createElement('label', null, 'CVC ', createElement('span', { className: 'required' }, '*')),
                    createElement('input', {
                        type: 'text',
                        value: cvv,
                        onChange: function (e) { setCvv(e.target.value.replace(/\D/g, '').substring(0, 4)); },
                        inputMode: 'numeric',
                        autoComplete: 'cc-csc',
                        placeholder: '\u2022\u2022\u2022',
                        maxLength: 4
                    })
                )
            ),
            createElement('div', { className: 'mcpg-secure-badge' },
                createElement('span', null, '\uD83D\uDD12 Secured with 256-bit encryption \u2014 your card details are never stored')
            )
        );
    };

    /**
     * Label component — shown in the payment method list.
     */
    var Label = function (props) {
        return createElement('span', null, title);
    };

    registerPaymentMethod({
        name: 'mcpg_cascading',
        label: createElement(Label, null),
        content: createElement(CardForm, null),
        edit: createElement(CardForm, null),
        canMakePayment: function () { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
