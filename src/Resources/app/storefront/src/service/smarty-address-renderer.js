export default class SmartyAddressRenderer {
    renderAddress(address = {}) {
        const normalized = this.normalizeAddress(address);

        return `
            <address class="smarty-address-modal__address">
                ${this._line(normalized.street)}
                ${this._line(normalized.additionalAddressLine1)}
                ${this._line(normalized.additionalAddressLine2)}
                ${this._line(this._cityLine(normalized))}
                ${this._line(normalized.country)}
            </address>
        `;
    }

    renderSuggestionButton(suggestion, index) {
        return `
            <button
                class="smarty-address-modal__suggestion"
                type="button"
                data-smarty-action="use-suggestion"
                data-smarty-index="${index}">
                ${this.renderAddress(suggestion)}
            </button>
        `;
    }

    renderSuggestions(suggestions = []) {
        if (!suggestions.length) {
            return `
                <p class="smarty-address-modal__muted">
                    No suggested address was returned.
                </p>
            `;
        }

        return suggestions
            .map((suggestion, index) => this.renderSuggestionButton(suggestion, index))
            .join('');
    }

    normalizeAddress(address = {}) {
        const state = this._state(address);
        const country = this._country(address);

        return {
            street: this._value(address.street),
            additionalAddressLine1: this._value(address.additionalAddressLine1),
            additionalAddressLine2: this._value(address.additionalAddressLine2),
            zipcode: this._value(address.zipcode),
            city: this._value(address.city),
            countryState: state,
            country,
        };
    }

    _cityLine(address) {
        const state = address.countryState.replace(/^US-/i, '');
        const parts = [address.city, state, address.zipcode].filter(Boolean);

        return parts.join(', ').replace(', ', ' ');
    }

    _state(address) {
        if (typeof address.countryState === 'string') {
            return address.countryState;
        }

        if (address.countryState && address.countryState.shortCode) {
            return address.countryState.shortCode;
        }

        if (address.countryState && address.countryState.name) {
            return address.countryState.name;
        }

        return this._value(address.state || address.region);
    }

    _country(address) {
        if (typeof address.country === 'string') {
            return address.country;
        }

        if (address.country && address.country.name) {
            return address.country.name;
        }

        if (address.country && address.country.iso) {
            return address.country.iso;
        }

        return '';
    }

    _line(value) {
        const clean = this._value(value);

        return clean ? `<span>${this._escape(clean)}</span>` : '';
    }

    _value(value) {
        if (value === null || value === undefined) {
            return '';
        }

        return String(value).trim();
    }

    _escape(value) {
        return this._value(value)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
}