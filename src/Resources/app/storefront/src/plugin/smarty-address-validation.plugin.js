import SmartyApiClient from '../service/smarty-api-client';
import SubmitGuard from '../service/submit-guard';
import GeoService from '../service/geo.service';
import AddressFormAdapter from '../service/address-form-adapter';
import SuggestionModal from '../ui/suggestion-modal';
import AutocompleteDropdown from '../ui/autocomplete-dropdown';
import { showErrorToast } from '../ui/toast';

const PluginBaseClass = window.PluginBaseClass;

export default class SmartyAddressValidationPlugin extends PluginBaseClass {
    init() {
        this.api = new SmartyApiClient('');
        this.guard = new SubmitGuard();
        this.geo = new GeoService();
        this.formAdapter = new AddressFormAdapter();
        this.modal = new SuggestionModal();
        this.streetAutocompletes = new Map();
        this._onSubmit = this._onSubmit.bind(this);
        this._isValidating = false;
        this._toastShown = false;
        document.addEventListener('submit', this._onSubmit, true);
        this._isModalOpen = false;

        const submitBtn = this.el.querySelector('button[type="submit"]');
        if (submitBtn && !submitBtn.dataset.originalLabel) {
            submitBtn.dataset.originalLabel = submitBtn.innerHTML;
        }

        this._setupStreetAutocomplete();
        this._setupZipAutocomplete();
        this._setupCoordinateLookup();
    }

    async _onSubmit(event) {
        if (event.target !== this.el) return;
        event.preventDefault();
        event.stopImmediatePropagation();
        if (this._isModalOpen) return;

        await this.guard.run(async () => {
            const form = this.el;
            const submitBtn = form.querySelector('button[type="submit"]');

            this._toastShown = false;

            const different = form.querySelector('[name="differentShippingAddress"]')?.checked === true;

            const billing = this.formAdapter.readSingleAddress(form, 'billing');

            if (!billing.street || !billing.city || !billing.postalCode) {
                showErrorToast('Please fill in street, city and ZIP code.');
                this._resetSubmitButton(submitBtn);
                return;
            }

            let shipping = null;
            if (different) {
                shipping = this.formAdapter.readSingleAddress(form, 'shipping');

                if (!shipping.street || !shipping.city || !shipping.postalCode) {
                    showErrorToast('Please fill in shipping street, city and ZIP code.');
                    this._resetSubmitButton(submitBtn);
                    return;
                }
            }

            const coords = await this.geo.getBrowserCoordinates();

            let billRes;
            try {
                billRes = await this.api.validate({
                    street: billing.street,
                    city: billing.city,
                    postalCode: billing.postalCode,
                    countryIso: (billing.countryIso || '').toUpperCase(),
                    latitude: coords.latitude,
                    longitude: coords.longitude,
                });
            } catch (e) {
                console.error('[Smarty] billing validate request failed', e);
                showErrorToast('Address validation failed. Please try again.');
                this._resetSubmitButton(submitBtn);
                return;
            }

            const { ok: bOk, json: bJson } = billRes || { ok: false, json: {} };
            const bPayload = bJson?.data || {};
            const bSuccess = bJson?.success === true;
            const bValid = bPayload?.isValid === true;

            const bCoordsValid =
                typeof bPayload?.coordsValid !== 'undefined' ? bPayload.coordsValid : null;

            const bCountryMismatch = bPayload?.countryMismatch === true;

            console.warn('[Smarty] billing validate result', {
                ok: bOk,
                success: bSuccess,
                isValid: bValid,
                message: bJson?.message,
                payload: bPayload,
            });

            if (!bOk || !bSuccess || !bValid) {
                await this._handleInvalidAddress(
                    'billing',
                    {
                        street: billing.street,
                        city: billing.city,
                        postalCode: billing.postalCode,
                        countryIso: (billing.countryIso || '').toUpperCase(),
                    },
                    { countryMismatch: bCountryMismatch, payload: bPayload, json: bJson },
                    submitBtn
                );
                return;
            }

            if (bCoordsValid === false) {
                showErrorToast('Your location does not match the address you entered.');
                this._resetSubmitButton(submitBtn);
                return;
            }

            if (different && shipping) {
                let shipRes;
                try {
                    shipRes = await this.api.validate({
                        street: shipping.street,
                        city: shipping.city,
                        postalCode: shipping.postalCode,
                        countryIso: (shipping.countryIso || '').toUpperCase(),
                    });
                } catch (e) {
                    console.error('[Smarty] shipping validate request failed', e);
                    showErrorToast('Shipping address validation failed. Please try again.');
                    this._resetSubmitButton(submitBtn);
                    return;
                }

                const { ok: sOk, json: sJson } = shipRes || { ok: false, json: {} };
                const sPayload = sJson?.data || {};
                const sSuccess = sJson?.success === true;
                const sValid = sPayload?.isValid === true;
                const sCountryMismatch = sPayload?.countryMismatch === true;

                console.warn('[Smarty] shipping validate result', {
                    ok: sOk,
                    success: sSuccess,
                    isValid: sValid,
                    message: sJson?.message,
                    payload: sPayload,
                });

                if (!sOk || !sSuccess || !sValid) {
                    await this._handleInvalidAddress(
                        'shipping',
                        {
                            street: shipping.street,
                            city: shipping.city,
                            postalCode: shipping.postalCode,
                            countryIso: (shipping.countryIso || '').toUpperCase(),
                        },
                        { countryMismatch: sCountryMismatch, payload: sPayload, json: sJson },
                        submitBtn
                    );
                    return;
                }
            }

            this._finishAndSubmit(form, submitBtn);
        });
    }

    async _handleInvalidAddress(type, inputAddress, ctx, submitBtn) {
        const message = this._buildInvalidMessage(inputAddress, ctx);

        const suggestions = await this._suggestWithRetries(inputAddress);
        if (!suggestions.length) {
            showErrorToast(message);
            this._resetSubmitButton(submitBtn);
            return;
        }

        if (!this.modal.isReady()) {
            console.warn('[Smarty] modal not present in DOM (Twig include missing).');
            showErrorToast(message);
            this._resetSubmitButton(submitBtn);
            return;
        }

        this._resetSubmitButton(submitBtn);
        this._toastOnce(message);

        this._isModalOpen = true;

        this.modal.open({
            suggestions,
            originalAddress: inputAddress,

            onPick: (picked) => {
                this._isModalOpen = false;

                this.formAdapter.writeAddress(this.el, type, picked);

                this.modal.close(false);
                this._finishAndSubmit(this.el, submitBtn);
            },

            onCancel: () => {
                this._isModalOpen = false;
                this.modal.close(true);
                this._resetSubmitButton(submitBtn);
            }
        });
    }



    _buildInvalidMessage(original, ctx) {
        const { countryMismatch, payload, json } = ctx || {};

        if (countryMismatch) {
            const selected = payload?.inputCountryIso || original.countryIso || 'N/A';
            const detected = payload?.detectedCountryIso || 'N/A';
            return `The country you selected (${selected}) does not match the address (detected ${detected}).`;
        }

        if (typeof json?.message === 'string' && json.message.length) {
            return json.message;
        }

        return 'Invalid address. Please check street, city and ZIP code.';
    }

    _toastOnce(message) {
        if (this._toastShown) return;
        showErrorToast(message);
        this._toastShown = true;
    }

    async _suggestWithRetries(original) {
        let list = await this._suggest(original);
        if (list.length) return list;

        if (original.city && original.city.trim().length) {
            list = await this._suggest({ ...original, city: '' });
            if (list.length) return list;
        }

        if (original.street && original.street.length > 6) {
            const fuzzyStreet = original.street.slice(0, -2);
            list = await this._suggest({ ...original, street: fuzzyStreet });
            if (list.length) return list;
        }

        return [];
    }

    async _suggest(payload) {
        try {
            const { ok, json } = await this.api.suggest(payload);
            const list = json?.data?.suggestions;
            if (!ok || !Array.isArray(list)) return [];
            return list;
        } catch (e) {
            console.error('[Smarty] suggest failed', e);
            return [];
        }
    }

    _setupStreetAutocomplete() {
        const inputs = this.el.querySelectorAll(
            '[name="billingAddress[street]"], [name="shippingAddress[street]"], [name="address[street]"], [name="street"]'
        );
        if (!inputs.length) return;

        inputs.forEach((streetInput) => {
            if (streetInput.dataset.smartyStreetBound === '1') return;
            streetInput.dataset.smartyStreetBound = '1';

            const type = streetInput.name?.startsWith('shippingAddress') ? 'shipping' : 'billing';

            const autocomplete = new AutocompleteDropdown({
                input: streetInput,
                minChars: 1,
                debounceMs: 150,

                fetchSuggestions: async () => {
                    const a = this.formAdapter.readSingleAddress(this.el, type);

                    const { ok, json } = await this.api.suggest({
                        street: a.street,
                        city: a.city,
                        postalCode: a.postalCode,
                        countryIso: (a.countryIso || 'US').toUpperCase(),
                    });

                    const suggestions = json?.data?.suggestions;
                    if (!ok || !Array.isArray(suggestions)) return [];
                    return suggestions;
                },

                renderLabel: (s) => s.label || s.street || s.delivery_line_1 || '',

                onPick: (s) => {
                    const normalized = {
                        street: s.street || s.delivery_line_1 || '',
                        city: s.city || s.city_name || '',
                        postalCode: (s.postalCode || s.zipcode || '').replace(/\D/g, '').slice(0, 5),
                        state: s.state || s.state_abbreviation || '',
                        countryIso: (s.countryIso || 'US').toUpperCase(),
                    };

                    this.formAdapter.writeAddress(this.el, type, normalized);
                },

                wrapperClass: 'smarty-street-suggestions-wrapper',
                dropdownClass: 'smarty-street-suggestions-dropdown',
                itemClass: 'smarty-street-suggestion-item',
            });

            this.streetAutocompletes.set(type, autocomplete);
        });
    }

    _setupZipAutocomplete() {
        const inputs = this.el.querySelectorAll(
            '[name="billingAddress[zipcode]"], [name="shippingAddress[zipcode]"], [name="address[zipcode]"], [name="zipcode"]'
        );
        if (!inputs.length) return;

        inputs.forEach((zipInput) => {
            if (zipInput.dataset.smartyZipBound === '1') return;
            zipInput.dataset.smartyZipBound = '1';

            const type = zipInput.name?.startsWith('shippingAddress') ? 'shipping' : 'billing';

            new AutocompleteDropdown({
                input: zipInput,
                debounceMs: 150,

                transformQuery: (v) => (v || '').replace(/\D/g, ''),

                shouldFetch: (digits) => digits.length >= 2,

                fetchSuggestions: async (digits) => {
                    const { ok, json } = await this.api.suggestZip({ postalCode: digits });
                    const list = json?.data?.suggestions;
                    if (!ok || !Array.isArray(list)) return [];
                    return list;
                },

                renderLabel: (s) => {
                    const postalCode = s.postalCode || s.zipcode || '';
                    const city = s.city || '';
                    const state = s.state || s.state_abbreviation || '';
                    return s.label || `${postalCode} – ${city}${state ? ', ' + state : ''}`;
                },

                onPick: (s) => {
                    const current = this.formAdapter.readSingleAddress(this.el, type);

                    const normalized = {
                        street: '',
                        city: s.city || '',
                        postalCode: (s.postalCode || s.zipcode || '').replace(/\D/g, '').slice(0, 5),
                        state: s.state || s.state_abbreviation || '',
                        countryIso: (current.countryIso || 'US').toUpperCase(),
                    };

                    this.formAdapter.writeAddress(this.el, type, normalized);
                    this._openStreetSuggestionsFromZip(type, normalized);
                },

                wrapperClass: 'smarty-suggestions-wrapper',
                dropdownClass: 'smarty-suggestions-dropdown',
                itemClass: 'smarty-suggestion-item',
            });
        });
    }

    async _openStreetSuggestionsFromZip(type, zipSelection) {
        const autocomplete = this.streetAutocompletes.get(type);
        if (!autocomplete?.input) return;

        let suggestions = [];
        const selectedZip = (zipSelection.postalCode || '').replace(/\D/g, '').slice(0, 5);

        try {
            const { ok, json } = await this.api.suggest({
                street: zipSelection.city || zipSelection.postalCode || '',
                city: zipSelection.city || '',
                postalCode: zipSelection.postalCode || '',
                countryIso: (zipSelection.countryIso || 'US').toUpperCase(),
            });

            const list = json?.data?.suggestions;
            suggestions = ok && Array.isArray(list) ? list : [];
        } catch (e) {
            console.error('[Smarty] follow-up street suggest failed', e);
            return;
        }

        if (selectedZip) {
            suggestions = suggestions.filter((suggestion) => {
                const suggestionZip = (suggestion.postalCode || suggestion.zipcode || '')
                    .replace(/\D/g, '')
                    .slice(0, 5);

                return suggestionZip === selectedZip;
            });
        }

        if (!suggestions.length) return;

        autocomplete.input.focus();
        autocomplete.renderSuggestions(suggestions);
    }

    _setupCoordinateLookup() {
        const billingBtn = this.el.querySelector('#smarty-fill-from-coordinates');
        if (!billingBtn) return;

        billingBtn.addEventListener('click', async (e) => {
            e.preventDefault();

            const lat = this.el.querySelector('#smarty-latitude')?.value?.trim() || '';
            const lng = this.el.querySelector('#smarty-longitude')?.value?.trim() || '';

            const latitude = parseFloat(lat.replace(',', '.'));
            const longitude = parseFloat(lng.replace(',', '.'));

            if (!Number.isFinite(latitude) || !Number.isFinite(longitude)) return;

            let res;
            try {
                res = await this.api.fromCoordinates({ latitude, longitude });
            } catch (err) {
                console.error('[Smarty] fromCoordinates failed', err);
                showErrorToast('Failed to resolve address from coordinates.');
                return;
            }

            const { ok, json } = res || { ok: false, json: {} };

            if (!ok || !json?.success || !json?.data?.isValid) {
                showErrorToast(json?.message || 'Could not find an address for these coordinates.');
                return;
            }

            const addr = json.data;

            this.formAdapter.writeAddress(this.el, 'billing', {
                street: addr.street,
                city: addr.city,
                postalCode: addr.postalCode,
                state: addr.state,
                countryIso: addr.countryIso || 'US',
            });
        });
    }

    _finishAndSubmit(form, btn) {
        document.removeEventListener('submit', this._onSubmit, true);
        this._resetSubmitButton(btn);

        if (typeof form.requestSubmit === 'function') {
            form.requestSubmit();
        } else {
            form.submit();
        }
    }

    _resetSubmitButton(btn) {
        if (!btn) return;

        btn.disabled = false;
        btn.removeAttribute('aria-disabled');
        btn.classList.remove('is-loading', 'btn-loading');
        btn.removeAttribute('data-form-submit-loader');

        const loaderEl = btn.querySelector(
            '.loader, .icon-loading, .btn-loader, .icon-loading-circle'
        );
        if (loaderEl && loaderEl.parentNode) {
            loaderEl.parentNode.removeChild(loaderEl);
        }

        if (btn.dataset.originalLabel) {
            btn.innerHTML = btn.dataset.originalLabel;
        }
    }
}
