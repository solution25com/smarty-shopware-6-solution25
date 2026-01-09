import SmartyApiClient from '../service/smarty-api-client';
import SubmitGuard from '../service/submit-guard';
import GeoService from '../service/geo.service';
import SuggestionModal from '../ui/suggestion-modal';
import { showErrorToast } from '../ui/toast';

const PluginBaseClass = window.PluginBaseClass;

export default class SmartyCheckoutConfirmValidationPlugin extends PluginBaseClass {
    init() {
        this.api = new SmartyApiClient('');
        this.guard = new SubmitGuard();
        this.geo = new GeoService();
        this.modal = new SuggestionModal();

        this._onSubmit = this._onSubmit.bind(this);

        this._isModalOpen = false;
        this._toastShown = false;

        this.form = document.getElementById('confirmOrderForm');
        if (!this.form) {
            console.warn('[Smarty] confirmOrderForm not found');
            return;
        }

        this.submitBtn =
            document.getElementById('confirmFormSubmit') ||
            this.form.querySelector('button[type="submit"]');

        if (this.submitBtn && !this.submitBtn.dataset.originalLabel) {
            this.submitBtn.dataset.originalLabel = this.submitBtn.innerHTML;
        }

        this.isAddressTooOld = this.el.dataset.addressTooOld === '1';

        this.form.addEventListener('submit', this._onSubmit);
    }

    async _onSubmit(event) {
        event.preventDefault();

        if (this._isModalOpen) return;

        await this.guard.run(async () => {
            this._toastShown = false;

            const addr = this._readConfirmAddress();
            if (!addr.street || !addr.city || !addr.postalCode) {
                this._finishAndSubmit();
                return;
            }

            this._setLoading(true);

            const coords = await this.geo.getBrowserCoordinates();

            let res;
            try {
                res = await this.api.validate({
                    street: addr.street,
                    city: addr.city,
                    postalCode: addr.postalCode,
                    countryIso: (addr.countryIso || 'US').toUpperCase(),
                    latitude: coords.latitude,
                    longitude: coords.longitude,
                    addressTooOld: this.isAddressTooOld,
                });
            } catch (e) {
                console.error('[Smarty] validate request failed', e);
                await this._handleValidationFailure(input, submitBtn);

                return;
            }

            const { ok, json } = res || { ok: false, json: {} };

            const payload = json?.data || {};
            const success = json?.success === true;
            const isValid = payload?.isValid === true;

            const coordsValid =
                typeof payload?.coordsValid !== 'undefined' ? payload.coordsValid : null;

            const countryMismatch = payload?.countryMismatch === true;

            if (!ok || !success || !isValid) {
                await this._handleInvalidAddress(addr, { countryMismatch, payload, json });
                this._setLoading(false);
                return;
            }

            if (coordsValid === false) {
                showErrorToast('Your current location does not match the shipping address on this order.');
                this._setLoading(false);
                return;
            }

            this._finishAndSubmit();
        });
    }

    _readConfirmAddress() {
        const streetEl = document.getElementById('smarty-confirm-street');
        const cityEl = document.getElementById('smarty-confirm-city');
        const zipEl = document.getElementById('smarty-confirm-zip');
        const countryEl = document.getElementById('smarty-confirm-country');

        return {
            street: streetEl?.value?.trim() || '',
            city: cityEl?.value?.trim() || '',
            postalCode: zipEl?.value?.trim() || '',
            countryIso: (countryEl?.value || 'US').trim(),
        };
    }

    async _handleInvalidAddress(inputAddress, ctx) {
        const message = this._buildInvalidMessage(inputAddress, ctx);

        const suggestions = await this._suggestWithRetries(inputAddress);

        if (!suggestions.length) {
            this._toastOnce(message);
            return;
        }

        if (!this.modal.isReady()) {
            console.warn('[Smarty] modal not present in DOM (Twig include missing).');
            this._toastOnce(message);
            return;
        }

        this._toastOnce(message);

        this._isModalOpen = true;

        this.modal.open({
            suggestions,
            originalAddress: inputAddress,

            onPick: async (picked) => {
                this._isModalOpen = false;
                this.modal.close(false);
            
                this._setLoading(true);
            
                const coords = await this.geo.getBrowserCoordinates();
            
                let res;
                try {
                    res = await this.api.validate({
                        street: picked.street,
                        city: picked.city,
                        postalCode: picked.postalCode,
                        countryIso: (picked.countryIso || 'US').toUpperCase(),
                        latitude: coords.latitude,
                        longitude: coords.longitude,
                        addressTooOld: this.isAddressTooOld,
                    });
                } catch (e) {
                    console.error('[Smarty] validate (apply) failed', e);
                    showErrorToast('Failed to apply the suggested address. Please try again.');
                    this._setLoading(false);
                    return;
                }
            
                const { ok, json } = res || { ok: false, json: {} };
                const isValid = json?.success === true && json?.data?.isValid === true;
            
                if (!isValid || !ok) {
                    showErrorToast(json?.message || 'Could not apply the suggested address.');
                    this._setLoading(false);
                    return;
                }
            
                this._finishAndSubmit(); 
            }
            ,            

            onCancel: () => {
                this._isModalOpen = false;
                this.modal.close(true);
                this._setLoading(false);
            },
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

        return 'We could not validate your shipping address. Please check street, city and ZIP code.';
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

    _setLoading(isLoading) {
        const btn = this.submitBtn;
        if (!btn) return;

        if (isLoading) {
            btn.disabled = true;
            btn.setAttribute('aria-disabled', 'true');
            btn.classList.add('is-loading', 'btn-loading');
        } else {
            btn.disabled = false;
            btn.removeAttribute('aria-disabled');
            btn.classList.remove('is-loading', 'btn-loading');
            if (btn.dataset.originalLabel) btn.innerHTML = btn.dataset.originalLabel;
        }
    }

    _finishAndSubmit() {
        this.form.removeEventListener('submit', this._onSubmit);
        this._setLoading(false);
        this.form.submit();
    }
    async _handleValidationFailure(inputAddress, submitBtn) {
        const message = 'Address validation failed. Please pick the correct address suggestion.';
    
        const suggestions = await this._suggestWithRetries({
            street: inputAddress.street,
            city: inputAddress.city,
            postalCode: inputAddress.postalCode,
            countryIso: (inputAddress.countryIso || 'US').toUpperCase(),
        });
    
        if (!suggestions.length) {
            showErrorToast('Address validation failed. Please try again.');
            this._resetSubmitButton(submitBtn);
            return;
        }
    
        if (!this.modal.isReady()) {
            console.warn('[Smarty] modal not present in DOM (Twig include missing).');
            showErrorToast('Address validation failed. Please try again.');
            this._resetSubmitButton(submitBtn);
            return;
        }
    
        this._resetSubmitButton(submitBtn);
        this._toastOnce(message);
    
        this._isModalOpen = true;
    
        this.modal.open({
            suggestions,
            originalAddress: {
                street: inputAddress.street,
                city: inputAddress.city,
                postalCode: inputAddress.postalCode,
                countryIso: (inputAddress.countryIso || 'US').toUpperCase(),
            },
    
            onPick: (picked) => {
                this._isModalOpen = false;
    
                this.formAdapter.writeAddress(this.el, 'billing', picked);
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
    
}
