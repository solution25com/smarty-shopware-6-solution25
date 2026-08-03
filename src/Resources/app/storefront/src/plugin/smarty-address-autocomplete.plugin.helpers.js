export default {
    _replaceStateOptions(stateField, states, suggestion) {
        const currentOptions = Array.from(stateField.options);
        const placeholder = currentOptions.find((option) => {
            return option.dataset?.placeholderOption === 'true' || String(option.value || '') === '';
        });

        while (stateField.options.length > 0) {
            stateField.remove(0);
        }

        if (placeholder) {
            stateField.appendChild(placeholder.cloneNode(true));
        }

        const wantedCode = this.normalizer.normalizeState(suggestion.state);
        const wantedCodeLower = wantedCode.toLowerCase();
        const wantedCodeWithPrefix = `us-${wantedCodeLower}`;
        const wantedName = String(suggestion.stateName || '').trim().toLowerCase();
        let selectedValue = '';

        states.forEach((state) => {
            if (!state || typeof state !== 'object') {
                return;
            }

            const option = document.createElement('option');
            option.value = String(state.id || '');
            option.textContent = String(state?.translated?.name || state.name || '');

            const optionText = String(option.textContent || '').trim().toLowerCase();
            const optionTextCode = this.normalizer.normalizeState(optionText).toLowerCase();
            const codeValues = [
                state.shortCode,
                state.short_code,
                state.abbreviation,
                state.code,
            ].map((value) => String(value || '').trim().toLowerCase());

            const codeMatches = codeValues.some((value) => {
                return value === wantedCodeLower || value === wantedCodeWithPrefix;
            });

            const isMatch = codeMatches
                || optionText === wantedCodeLower
                || optionText === wantedCodeWithPrefix
                || optionText === wantedName
                || optionTextCode === wantedCodeLower;

            if (isMatch && selectedValue === '') {
                selectedValue = option.value;
            }

            stateField.appendChild(option);
        });

        if (selectedValue !== '') {
            stateField.value = selectedValue;
            this._trigger(stateField);
            this.normalizer.refreshCustomSelect(stateField);
            this._debug('State option list replaced and selected', {
                selectedValue,
                suggestion,
                stateCount: states.length,
            });
        }
    },

    _stateMatches(field, suggestion) {
        if (!field || !suggestion?.state) {
            return false;
        }

        const selected = field.options?.[field.selectedIndex] || null;
        if (!selected) {
            return false;
        }

        if (this.normalizer.isPlaceholderOption(selected)) {
            return false;
        }

        const expectedCode = this.normalizer.normalizeState(suggestion.state).toLowerCase();
        const expectedCodeWithPrefix = `us-${expectedCode}`;
        const expectedName = String(suggestion.stateName || '').trim().toLowerCase();
        const selectedText = String(selected.textContent || '').trim().toLowerCase();
        const selectedTextCode = this.normalizer.normalizeState(selectedText).toLowerCase();

        const codeValues = [
            selected.dataset?.shortCode,
            selected.dataset?.stateCode,
            selected.getAttribute?.('data-short-code'),
            selected.getAttribute?.('data-state-code'),
        ].map((value) => String(value || '').trim().toLowerCase());

        const codeMatches = codeValues.some((value) => {
            return value === expectedCode || value === expectedCodeWithPrefix;
        });

        return codeMatches
            || selectedText === expectedCode
            || selectedText === expectedCodeWithPrefix
            || selectedText === expectedName
            || selectedTextCode === expectedCode;
    },

    _setCountry(field, suggestion) {
        if (!field || suggestion.country !== 'US') {
            return false;
        }

        if (field.tagName.toLowerCase() !== 'select') {
            return false;
        }

        if (this.normalizer.countryValue(field) === 'US') {
            return false;
        }

        const option = Array.from(field.options).find((item) => {
            const text = String(item.textContent || '').toLowerCase();
            const value = String(item.value || '').toLowerCase();

            return text.includes('united states') || value === 'us' || value === 'usa';
        });

        if (option) {
            if (field.value === option.value) {
                return false;
            }

            field.value = option.value;
            this._trigger(field);
            return true;
        }

        return false;
    },

    _setTracking(form, used, changed, declined = changed) {
        const prefix = this._addressPrefix(form);

        Object.entries({
            autocomplete_used_flag: used ? '1' : '0',
            user_changed_autocomplete_suggestion_flag: changed ? '1' : '0',
            suggested_address_declined_flag: declined ? '1' : '0',
        }).forEach(([name, value]) => {
            this._trackingInput(form, prefix, name).value = value;
        });
    },

    _trackingInput(form, prefix, name) {
        const inputName = prefix
            ? `${prefix}[customFields][${name}]`
            : `customFields[${name}]`;

        let input = form.querySelector(`input[name="${CSS.escape(inputName)}"]`);

        if (!input) {
            input = document.createElement('input');
            input.type = 'hidden';
            input.name = inputName;
            form.appendChild(input);
        }

        return input;
    },

    _addressPrefix(form) {
        const street = this._fields(form).street;
        const match = String(street?.name || '').match(/^(.+)\[street]$/);

        return match ? match[1] : '';
    },

    _snapshot(form) {
        const fields = this._fields(form);

        return {
            street: fields.street?.value || '',
            zipcode: fields.zipcode?.value || '',
            city: fields.city?.value || '',
            state: this.normalizer.stateValue(fields.state),
        };
    },

    _find(form, selectors, preferVisible = false) {
        const matches = [];

        for (const selector of selectors) {
            matches.push(...Array.from(form.querySelectorAll(selector)));
        }

        if (!matches.length) {
            return null;
        }

        if (!preferVisible) {
            return matches[0];
        }

        const visible = matches.find((field) => !this._isHiddenField(field));

        return visible || matches[0];
    },

    _debounce(field, callback) {
        if (!field) {
            window.setTimeout(callback, 300);
            return;
        }

        const existing = this.debounceTimers.get(field);

        if (existing) {
            window.clearTimeout(existing);
        }

        const timer = window.setTimeout(callback, 300);
        this.debounceTimers.set(field, timer);
    },

    _nextRequestToken(field) {
        if (!field) {
            return Date.now();
        }

        const next = (this.requestTokens.get(field) || 0) + 1;
        this.requestTokens.set(field, next);

        return next;
    },

    _isCurrentRequest(field, token) {
        if (!field) {
            return true;
        }

        return this.requestTokens.get(field) === token;
    },

    _trigger(field) {
        field.dispatchEvent(new Event('input', { bubbles: true }));
        field.dispatchEvent(new Event('change', { bubbles: true }));
    },

    _digits(value) {
        return String(value || '').replace(/\D+/g, '');
    },

    _cleanInput(value) {
        const clean = String(value || '').trim();

        return clean.includes('*') ? '' : clean;
    },

    _options() {
        const dataset = this.el.dataset;

        return {
            zipUrl: dataset.smartyAutocompleteZipUrl,
            streetUrl: dataset.smartyAutocompleteStreetUrl,
            reverseGeoUrl: dataset.smartyReverseGeoUrl,
            debugEnabled: dataset.smartyDebugEnabled === 'true',
        };
    },

    _isDebugEnabled() {
        return this.el.dataset.smartyDebugEnabled === 'true' || this.el.dataset.smartyDebugEnabled === '1';
    },

    _debug(message, context = {}) {
        void message;
        void context;

        if (!this.debugEnabled || typeof console === 'undefined' || typeof console.warn !== 'function') {
            return;
        }
    },

    _formLabel(form) {
        return form?.getAttribute('name')
            || form?.id
            || form?.getAttribute('action')
            || 'form';
    },

    _isHiddenField(field) {
        if (!field) {
            return true;
        }

        if (field.type === 'hidden' || field.hidden || field.disabled) {
            return true;
        }

        if (field.closest('[hidden], .d-none, .is-hidden')) {
            return true;
        }

        return field.offsetParent === null;
    },
};
