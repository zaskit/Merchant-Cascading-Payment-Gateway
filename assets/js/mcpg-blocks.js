/**
 * MCPG Block Checkout Integration
 * Registers the cascading payment gateway with WooCommerce Block Checkout.
 */
(function () {
    'use strict';

    var registerPaymentMethod = wc.wcBlocksRegistry.registerPaymentMethod;
    var el = wp.element.createElement;
    var useState = wp.element.useState;
    var useEffect = wp.element.useEffect;
    var decodeEntities = wp.htmlEntities.decodeEntities;

    var settings = wc.wcSettings.getSetting('mcpg_cascading_data', {});
    var title = decodeEntities(settings.title || 'Credit / Debit Card');
    var description = decodeEntities(settings.description || '');
    var countries = settings.countries || [];
    var defaultCountry = settings.defaultCountry || '';

    function formatCardNumber(value) {
        var v = value.replace(/\D/g, '').substring(0, 19);
        var parts = [];
        for (var i = 0; i < v.length; i += 4) {
            parts.push(v.substring(i, i + 4));
        }
        return parts.join(' ');
    }

    function formatExpiry(value) {
        var v = value.replace(/\D/g, '').substring(0, 4);
        if (v.length >= 3) {
            return v.substring(0, 2) + ' / ' + v.substring(2);
        }
        return v;
    }

    var CardForm = function (props) {
        var eventRegistration = props.eventRegistration;
        var emitResponse = props.emitResponse;
        var onPaymentSetup = eventRegistration.onPaymentSetup;

        var _cardName = useState('');
        var cardName = _cardName[0]; var setCardName = _cardName[1];

        var _cardNumber = useState('');
        var cardNumber = _cardNumber[0]; var setCardNumber = _cardNumber[1];

        var _expiry = useState('');
        var expiry = _expiry[0]; var setExpiry = _expiry[1];

        var _cvv = useState('');
        var cvv = _cvv[0]; var setCvv = _cvv[1];

        var _street = useState('');
        var street = _street[0]; var setStreet = _street[1];

        var _city = useState('');
        var city = _city[0]; var setCity = _city[1];

        var _state = useState('');
        var state = _state[0]; var setState = _state[1];

        var _country = useState(defaultCountry);
        var country = _country[0]; var setCountry = _country[1];

        var _zip = useState('');
        var zip = _zip[0]; var setZip = _zip[1];

        useEffect(function () {
            var unsubscribe = onPaymentSetup(function () {
                var rawNumber = cardNumber.replace(/\D/g, '');
                var rawExpiry = expiry.replace(/\D/g, '');
                var rawCvv = cvv.replace(/\D/g, '');

                // Card validation
                if (!cardName.trim()) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Please enter the cardholder name.' };
                }
                if (rawNumber.length < 13 || rawNumber.length > 19) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Please enter a valid card number.' };
                }
                if (rawExpiry.length !== 4) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Please enter a valid expiry date (MM/YY).' };
                }
                var month = parseInt(rawExpiry.substring(0, 2), 10);
                var year = parseInt(rawExpiry.substring(2, 4), 10);
                if (month < 1 || month > 12) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Please enter a valid expiry month (01-12).' };
                }
                var now = new Date();
                var nowMonth = now.getMonth() + 1;
                var nowYear = now.getFullYear() % 100;
                if (year < nowYear || (year === nowYear && month < nowMonth)) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Your card has expired. Please use a valid card.' };
                }
                if (rawCvv.length < 3 || rawCvv.length > 4) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Please enter a valid CVC.' };
                }

                // Billing address validation
                if (!street.trim()) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Cardholder billing street address is required.' };
                }
                if (!city.trim()) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Cardholder billing city is required.' };
                }
                if (!state.trim()) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Cardholder billing state / province is required.' };
                }
                if (!country || country.length !== 2) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Please select a valid billing country.' };
                }
                if (!zip.trim()) {
                    return { type: emitResponse.responseTypes.ERROR, message: 'Cardholder billing ZIP / postal code is required.' };
                }

                return {
                    type: emitResponse.responseTypes.SUCCESS,
                    meta: {
                        paymentMethodData: {
                            mcpg_card_name: cardName.trim(),
                            mcpg_card_number: rawNumber,
                            mcpg_expiry: rawExpiry,
                            mcpg_cvv: rawCvv,
                            mcpg_billing_street: street.trim(),
                            mcpg_billing_city: city.trim(),
                            mcpg_billing_state: state.trim(),
                            mcpg_billing_country: country,
                            mcpg_billing_zip: zip.trim()
                        }
                    }
                };
            });

            return unsubscribe;
        }, [onPaymentSetup, cardName, cardNumber, expiry, cvv, street, city, state, country, zip, emitResponse.responseTypes]);

        // Build country options
        var countryOptions = [el('option', { key: '', value: '' }, 'Select country\u2026')];
        for (var i = 0; i < countries.length; i++) {
            countryOptions.push(
                el('option', { key: countries[i].code, value: countries[i].code }, countries[i].name)
            );
        }

        return el('fieldset', { className: 'mcpg-card-form', id: 'mcpg-card-form' },
            description ? el('p', { className: 'mcpg-description' }, description) : null,

            // Card fields
            el('div', { className: 'mcpg-field' },
                el('label', null, 'Cardholder Name ', el('span', { className: 'required' }, '*')),
                el('input', {
                    type: 'text', value: cardName,
                    onChange: function (e) { setCardName(e.target.value); },
                    autoComplete: 'cc-name', placeholder: 'Name on card'
                })
            ),
            el('div', { className: 'mcpg-field' },
                el('label', null, 'Card Number ', el('span', { className: 'required' }, '*')),
                el('input', {
                    type: 'text', value: cardNumber,
                    onChange: function (e) { setCardNumber(formatCardNumber(e.target.value)); },
                    inputMode: 'numeric', autoComplete: 'cc-number',
                    placeholder: '0000 0000 0000 0000', maxLength: 23
                })
            ),
            el('div', { className: 'mcpg-row' },
                el('div', { className: 'mcpg-field' },
                    el('label', null, 'Expiry ', el('span', { className: 'required' }, '*')),
                    el('input', {
                        type: 'text', value: expiry,
                        onChange: function (e) { setExpiry(formatExpiry(e.target.value)); },
                        inputMode: 'numeric', autoComplete: 'cc-exp',
                        placeholder: 'MM / YY', maxLength: 7
                    })
                ),
                el('div', { className: 'mcpg-field' },
                    el('label', null, 'CVC ', el('span', { className: 'required' }, '*')),
                    el('input', {
                        type: 'text', value: cvv,
                        onChange: function (e) { setCvv(e.target.value.replace(/\D/g, '').substring(0, 4)); },
                        inputMode: 'numeric', autoComplete: 'cc-csc',
                        placeholder: '\u2022\u2022\u2022', maxLength: 4
                    })
                )
            ),

            // Cardholder Billing Address
            el('div', { className: 'mcpg-billing-heading' }, 'Cardholder Billing Address'),
            el('div', { className: 'mcpg-field' },
                el('label', null, 'Street Address ', el('span', { className: 'required' }, '*')),
                el('input', {
                    type: 'text', value: street,
                    onChange: function (e) { setStreet(e.target.value); },
                    autoComplete: 'address-line1', placeholder: 'Street address'
                })
            ),
            el('div', { className: 'mcpg-row' },
                el('div', { className: 'mcpg-field' },
                    el('label', null, 'City ', el('span', { className: 'required' }, '*')),
                    el('input', {
                        type: 'text', value: city,
                        onChange: function (e) { setCity(e.target.value); },
                        autoComplete: 'address-level2', placeholder: 'City'
                    })
                ),
                el('div', { className: 'mcpg-field' },
                    el('label', null, 'State / Province ', el('span', { className: 'required' }, '*')),
                    el('input', {
                        type: 'text', value: state,
                        onChange: function (e) { setState(e.target.value); },
                        autoComplete: 'address-level1', placeholder: 'e.g. MO, NY',
                        maxLength: 50
                    })
                )
            ),
            el('div', { className: 'mcpg-row' },
                el('div', { className: 'mcpg-field' },
                    el('label', null, 'Country ', el('span', { className: 'required' }, '*')),
                    el('select', {
                        value: country,
                        onChange: function (e) { setCountry(e.target.value); },
                        autoComplete: 'country'
                    }, countryOptions)
                ),
                el('div', { className: 'mcpg-field' },
                    el('label', null, 'ZIP / Postal Code ', el('span', { className: 'required' }, '*')),
                    el('input', {
                        type: 'text', value: zip,
                        onChange: function (e) { setZip(e.target.value); },
                        autoComplete: 'postal-code', placeholder: 'ZIP / Postal',
                        maxLength: 10
                    })
                )
            ),

            el('div', { className: 'mcpg-secure-badge' },
                el('span', null, '\uD83D\uDD12 Secured with 256-bit encryption \u2014 your card details are never stored')
            )
        );
    };

    var Label = function (props) {
        return el('span', null, title);
    };

    registerPaymentMethod({
        name: 'mcpg_cascading',
        label: el(Label, null),
        content: el(CardForm, null),
        edit: el(CardForm, null),
        canMakePayment: function () { return true; },
        ariaLabel: title,
        supports: {
            features: settings.supports || ['products']
        }
    });
})();
