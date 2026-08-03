const Plugin = window.PluginBaseClass;
import SmartyAutocompleteApiService from '../service/smarty-autocomplete-api.service';
import SmartyAutocompleteDropdownService from '../service/smarty-autocomplete-dropdown.service';
import SmartyAddressFieldNormalizer from '../service/smarty-address-field-normalizer.service';
import smartyAddressAutocompleteHelpers from './smarty-address-autocomplete.plugin.helpers';

class SmartyAddressAutocompletePlugin extends Plugin {
    init() {
        this.api = new SmartyAutocompleteApiService(this._options());
        this.dropdown = new SmartyAutocompleteDropdownService();
        this.normalizer = new SmartyAddressFieldNormalizer();
        this.debounceTimers = new WeakMap();
        this.requestTokens = new WeakMap();
        this.snapshots = new WeakMap();
        this.applyingForms = new WeakSet();
        this.applyFinalizeTimers = new WeakMap();
        this.boundFields = new WeakSet();
        this.debugEnabled = this._isDebugEnabled();
        this.formObserver = null;
        this.formObserverTimer = null;

        this._bindForms();
        this._observeAddressForms();
        document.addEventListener('keydown', (event) => this.dropdown.handleKeydown(event));
    }

    destroy() {
        this.formObserver?.disconnect();

        if (this.formObserverTimer) {
            window.clearTimeout(this.formObserverTimer);
        }
    }

    _bindForms() {
        const forms = this._addressForms();

        if (!forms.length) {
            this._debug('No address forms detected', {
                path: window.location.pathname,
            });
        }

        forms.forEach((form) => {
            if (form.dataset.smartyAutocompleteBound === 'true') {
                return;
            }

            const fields = this._fields(form);

            if (!fields.street && !fields.zipcode) {
                this._debug('Address form skipped because ZIP and Street inputs were not detected', {
                    form: this._formLabel(form),
                });
                return;
            }

            form.dataset.smartyAutocompleteBound = 'true';

            this._debug('Field detection', {
                form: this._formLabel(form),
                zipDetected: Boolean(fields.zipcode),
                streetDetected: Boolean(fields.street),
            });

            fields.zipcode?.addEventListener('input', () => this._onZipInput(form, fields.zipcode));
            fields.street?.addEventListener('input', () => this._onStreetInput(form, fields.street));

            ['street', 'zipcode', 'city', 'state'].forEach((key) => {
                fields[key]?.addEventListener('input', () => this._detectManualChange(form));
                fields[key]?.addEventListener('change', () => this._detectManualChange(form));
            });

            this._injectGeoButton(form, fields);
        });
    }

    _observeAddressForms() {
        if (this.formObserver || !document.body) {
            return;
        }

        this.formObserver = new MutationObserver((mutations) => {
            const hasNewFormFields = mutations.some((mutation) => {
                return Array.from(mutation.addedNodes).some((node) => {
                    if (!(node instanceof Element)) {
                        return false;
                    }

                    return node.matches?.('form, input, select')
                        || Boolean(node.querySelector?.('form, input, select'));
                });
            });

            if (!hasNewFormFields) {
                return;
            }

            if (this.formObserverTimer) {
                window.clearTimeout(this.formObserverTimer);
            }

            this.formObserverTimer = window.setTimeout(() => {
                this._debug('Rebinding address autocomplete after DOM update', {
                    path: window.location.pathname,
                });
                this._bindForms();
            }, 50);
        });

        this.formObserver.observe(document.body, {
            childList: true,
            subtree: true,
        });
    }

    _injectGeoButton(form, fields) {
        if (!('geolocation' in navigator) || !this.api?.reverseGeoUrl) {
            return;
        }

        if (form.querySelector('.smarty-geo-button')) {
            return;
        }

        const anchor = fields.street || fields.zipcode;

        if (!anchor) {
            return;
        }

        const button = document.createElement('button');
        button.type = 'button';
        button.className = 'btn btn-light btn-sm smarty-geo-button';
        button.innerHTML = '<span aria-hidden="true">📍</span> Use my location';
        button.addEventListener('click', () => this._runGeoAutofill(form, button));

        const group = anchor.closest('.form-group') || anchor;
        group.parentNode?.insertBefore(button, group);
    }

    _runGeoAutofill(form, button) {
        if (!('geolocation' in navigator) || !this.api?.reverseGeoUrl) {
            return;
        }

        const label = button.innerHTML;

        const setLoading = (text) => {
            button.disabled = true;
            button.classList.add('is-loading');
            button.innerHTML = `<span class="smarty-geo-spinner" aria-hidden="true"></span> ${text}`;
        };

        const restore = () => {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.innerHTML = label;
        };

        const notify = (message) => {
            button.disabled = false;
            button.classList.remove('is-loading');
            button.textContent = message;
            window.setTimeout(() => { button.innerHTML = label; }, 5000);
        };

        const fail = (error) => {
            this._debug('Geolocation denied or failed.', { code: error?.code });

            notify(error?.code === 1
                ? 'Location blocked — allow it in the address bar'
                : 'Location unavailable — enter address manually');
        };

        setLoading('Getting your location…');

        const request = (attempt) => {
            navigator.geolocation.getCurrentPosition(
                (position) => {
                    setLoading('Filling address…');
                    this._onGeolocationSuccess(form, position, { restore, notify });
                },
                (error) => {
                    if (attempt === 0 && error?.code !== 1) {
                        window.setTimeout(() => request(1), 800);
                        return;
                    }

                    fail(error);
                },
                { enableHighAccuracy: false, timeout: 12000, maximumAge: 60000 }
            );
        };

        request(0);
    }

    async _onGeolocationSuccess(form, position, ui) {
        const latitude = position?.coords?.latitude;
        const longitude = position?.coords?.longitude;

        if (typeof latitude !== 'number' || typeof longitude !== 'number') {
            ui.notify('Location unavailable — enter address manually');
            return;
        }

        try {
            const response = await this.api.reverseGeo({ latitude, longitude });
            const suggestions = response?.suggestions || [];

            this._debug('Reverse geo autofill response received', {
                suggestionCount: suggestions.length,
            });

            if (!suggestions.length) {
                ui.notify('No address found for your location');
                return;
            }

            this._applySuggestion(form, suggestions[0]);
            ui.restore();
        } catch (error) {
            this._debug('Reverse geo request failed.', { error: error?.message });
            ui.notify('Could not fetch address — try again');
        }
    }

    _onZipInput(form, field) {
        if (this.applyingForms.has(form)) {
            return;
        }

        const fields = this._fields(form);
        const value = this._digits(field?.value || fields.zipcode?.value || '').slice(0, 5);

        if (value.length < 3) {
            this.dropdown.close();
            return;
        }

        const requestField = field || fields.zipcode;
        const currentRequest = this._nextRequestToken(requestField);

        this._debounce(requestField, async () => {
            this.dropdown.showLoading(field || fields.zipcode);

            const payload = {
                zipcode: value,
                ...this._context(form),
            };

            this._debug('ZIP autocomplete request payload', payload);

            const response = await this.api.zip(payload);

            if (!this._isCurrentRequest(requestField, currentRequest)) {
                return;
            }

            this._debug('ZIP autocomplete response received', {
                suggestionCount: Array.isArray(response?.suggestions) ? response.suggestions.length : 0,
            });

            const suggestions = response.suggestions || [];
            this._debug('Dropdown render count', {
                type: 'zip',
                count: suggestions.length,
            });

            this.dropdown.show(field || fields.zipcode, suggestions, (suggestion) => {
                this._debug('ZIP autocomplete selected suggestion', suggestion);
                this._applySuggestion(form, suggestion);
            });
        });
    }

    _onStreetInput(form, field) {
        if (this.applyingForms.has(form)) {
            return;
        }

        const fields = this._fields(form);
        const value = String(field?.value || fields.street?.value || '').trim();

        if (value.length < 3) {
            this.dropdown.close();
            return;
        }

        const requestField = field || fields.street;
        const currentRequest = this._nextRequestToken(requestField);

        this._debounce(requestField, async () => {
            this.dropdown.showLoading(field || fields.street);

            const payload = {
                street: value,
                ...this._context(form),
            };

            this._debug('Street autocomplete request payload', payload);

            const response = await this.api.street(payload);

            if (!this._isCurrentRequest(requestField, currentRequest)) {
                return;
            }

            this._debug('Street autocomplete response received', {
                suggestionCount: Array.isArray(response?.suggestions) ? response.suggestions.length : 0,
            });

            const suggestions = response.suggestions || [];
            this._debug('Dropdown render count', {
                type: 'street',
                count: suggestions.length,
            });

            this.dropdown.show(field || fields.street, suggestions, (suggestion) => {
                this._debug('Street autocomplete selected suggestion', suggestion);
                this._applySuggestion(form, suggestion);
            });
        });
    }

    _applySuggestion(form, suggestion) {
        const fields = this._fields(form);
        const existingFinalizeTimer = this.applyFinalizeTimers.get(form);

        if (existingFinalizeTimer) {
            window.clearTimeout(existingFinalizeTimer);
        }

        this.applyingForms.add(form);

        this._setValue(fields.street, suggestion.street);
        this._setValue(fields.zipcode, suggestion.zipcode);
        this._setValue(fields.city, suggestion.city);
        const countryChanged = this._setCountry(fields.country, suggestion);
        this._setTracking(form, true, false, false);

        if (countryChanged) {
            window.setTimeout(() => this._setState(form, suggestion), 50);
        } else {
            this._setState(form, suggestion);
        }

        const finalizeTimer = window.setTimeout(() => this._finalizeSuggestionApplication(form), 3500);
        this.applyFinalizeTimers.set(form, finalizeTimer);
    }

    _finalizeSuggestionApplication(form) {
        if (!this.applyingForms.has(form)) {
            return;
        }

        const finalizeTimer = this.applyFinalizeTimers.get(form);

        if (finalizeTimer) {
            window.clearTimeout(finalizeTimer);
            this.applyFinalizeTimers.delete(form);
        }

        this._setTracking(form, true, false, false);
        this.snapshots.set(form, this._snapshot(form));
        this.applyingForms.delete(form);
    }

    _detectManualChange(form) {
        if (this.applyingForms.has(form)) {
            return;
        }

        const snapshot = this.snapshots.get(form);

        if (!snapshot) {
            return;
        }

        const current = this._snapshot(form);

        if (JSON.stringify(snapshot) !== JSON.stringify(current)) {
            this._setTracking(form, false, true, true);
        }
    }

    _context(form) {
        const fields = this._fields(form);

        return {
            city: this._cleanInput(fields.city?.value || ''),
            zipcode: this._digits(fields.zipcode?.value || ''),
            state: this.normalizer.stateValue(fields.state),
            country: this.normalizer.countryValue(fields.country),
        };
    }

    _fields(form) {
        return {
            street: this._find(form, [
                'input[name*="[street]"]',
                'input[name="street"]',
                'input[name*="street"]',
                'input[autocomplete="address-line1"]',
                'input[id*="street"]',
                'input[placeholder*="Street" i]',
            ]),
            zipcode: this._find(form, [
                'input[name*="[zipcode]"]',
                'input[name="zipcode"]',
                'input[name*="zipcode"]',
                'input[name*="[zipCode]"]',
                'input[id*="zipcode"]',
                'input[autocomplete="postal-code"]',
                'input[placeholder*="ZIP" i]',
                'input[placeholder*="Postal" i]',
            ]),
            city: this._find(form, [
                'input[name*="[city]"]',
                'input[name="city"]',
                'input[name*="city"]',
                'input[id*="city"]',
                'input[autocomplete="address-level2"]',
                'input[placeholder*="City" i]',
            ]),
            state: this._find(form, [
                'select.country-state-select',
                'select[name*="[countryStateId]"]',
                'select[name="countryStateId"]',
                'select[name*="[countryState]"]',
                'select[id*="countryState"]',
                'select[id*="state"]',
                'input[name*="[countryStateId]"]',
                'input[name="countryStateId"]',
                'input[name*="countryState"]',
                'input[name*="[state]"]',
                'input[id*="countryState"]',
                'input[id*="state"]',
                'select[autocomplete="address-level1"]',
                'input[placeholder*="State" i]',
                'input[placeholder*="Province" i]',
            ], true),
            country: this._find(form, [
                'select.country-select',
                'select[name*="countryId"]',
                'select[name*="[country]"]',
                'input[name*="[country]" i]',
                'select[autocomplete="country"]',
            ], true),
        };
    }

    _addressForms() {
        return Array.from(document.querySelectorAll('form')).filter((form) => {
            return this._find(form, [
                'input[name*="[street]"]',
                'input[placeholder*="Street" i]',
            ]) && this._find(form, [
                'input[name*="[zipcode]"]',
                'input[placeholder*="ZIP" i]',
            ]);
        });
    }

    _setValue(field, value) {
        if (!field || value === undefined || value === null || value === '') {
            return;
        }

        field.value = value;
        this._trigger(field);
    }

    _setState(form, suggestion, attempt = 0) {
        if (!suggestion?.state) {
            this._finalizeSuggestionApplication(form);
            return;
        }

        const fields = this._fields(form);
        const field = fields.state;
        const countryField = fields.country;

        if (!field) {
            this._debug('State field not detected yet; retrying.', {
                attempt,
                suggestion,
            });

            if (attempt < 30) {
                window.setTimeout(() => this._setState(form, suggestion, attempt + 1), 100);
                return;
            }

            this._finalizeSuggestionApplication(form);
            return;
        }

        this._debug('State selection attempt', {
            attempt,
            currentValue: field.value || '',
            suggestion,
        });

        const selected = this.normalizer.selectState(field, suggestion, this._trigger.bind(this));

        if (selected && this._stateMatches(field, suggestion)) {
            this._debug('State selected successfully', {
                attempt,
                selectedValue: field.value || '',
                suggestion,
            });
            this._finalizeSuggestionApplication(form);
            return;
        }

        if (attempt < 30) {
            window.setTimeout(() => this._setState(form, suggestion, attempt + 1), 100);
            return;
        }

        this._debug('State selection fallback to hydrated country-state options', {
            suggestion,
        });
        this._hydrateStateField(countryField, field, suggestion);
        this._finalizeSuggestionApplication(form);
    }

    async _hydrateStateField(countryField, stateField, suggestion) {
        if (!countryField || !stateField) {
            return;
        }

        const countryId = String(countryField.value || '');
        const endpoint = window.router?.['frontend.country.country-data'];

        if (!countryId || !endpoint) {
            return;
        }

        try {
            const response = await fetch(
                `${endpoint}?countryId=${encodeURIComponent(countryId)}`,
                {
                    headers: {
                        'X-Requested-With': 'XMLHttpRequest',
                        'Content-Type': 'application/json',
                    },
                }
            );

            if (!response.ok) {
                return;
            }

            const content = await response.json();
            const states = Array.isArray(content?.states) ? content.states : [];

            if (!states.length) {
                return;
            }

            this._replaceStateOptions(stateField, states, suggestion);
        } catch {
            return;
        }
    }

}

Object.assign(SmartyAddressAutocompletePlugin.prototype, smartyAddressAutocompleteHelpers);

export default SmartyAddressAutocompletePlugin;
