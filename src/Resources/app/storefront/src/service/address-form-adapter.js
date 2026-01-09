export default class AddressFormAdapter {
    constructor() {
        this._cache = new WeakMap();
    }

    readSingleAddress(form, type = 'billing') {
        const f = this._getFields(form, type);

        return {
            street: f.street?.value || '',
            city: f.city?.value || '',
            postalCode: f.zip?.value || '',
            countryIso: this.readCountryIso(form, type) || '',
        };
    }

    writeAddress(form, type, suggestion) {
        const f = this._getFields(form, type);

        this._setValue(f.street, suggestion.street);
        this._setValue(f.city, suggestion.city);
        this._setValue(f.zip, suggestion.postalCode);

        const normalizedState = this.normalizeStateForCountry(suggestion.countryIso, suggestion.state);
        this.applyCountryAndState(form, type, suggestion.countryIso, normalizedState);
    }

    readCountryIso(form, type = 'billing') {
        const f = this._getFields(form, type);
        const select = f.country;

        if (!select) return null;

        const opt = select.options[select.selectedIndex];
        if (!opt) return null;

        const iso = (
            opt.dataset.countryIso ||
            opt.dataset.countryIso2 ||
            opt.dataset.countryIso3 ||
            ''
        ).toUpperCase();

        if (iso) return iso;

        const label = (opt.textContent || '').trim().toLowerCase();
        if (label.includes('united states')) return 'US';

        return null;
    }

    applyCountryAndState(form, type, countryIso, stateName, countryOverride = null, stateOverride = null) {
        const f = this._getFields(form, type);

        const countrySelect = countryOverride || f.country;
        const stateSelect = stateOverride || f.state;

        const isoUpper = (countryIso || '').toUpperCase();
        const stateTrim = (stateName || '').trim();

        if (countrySelect && isoUpper) {
            const options = Array.from(countrySelect.querySelectorAll('option'));

            let countryOption = options.find((opt) => {
                const optIso = (
                    opt.dataset.countryIso ||
                    opt.dataset.countryIso2 ||
                    opt.dataset.countryIso3 ||
                    ''
                ).toUpperCase();

                return optIso === isoUpper;
            });

            if (!countryOption && isoUpper === 'US') {
                countryOption =
                    options.find((opt) => /united states of america/i.test((opt.textContent || '').trim())) ||
                    options.find((opt) => {
                        const label = (opt.textContent || '').trim().toLowerCase();
                        return label.includes('united states') && !label.includes('minor') && !label.includes('outlying');
                    });
            }

            if (countryOption && countrySelect.value !== countryOption.value) {
                countrySelect.value = countryOption.value;
                countrySelect.dispatchEvent(new Event('change', { bubbles: true }));
            }
        }

        if (!stateSelect || !stateTrim) return;

        const nameLower = stateTrim.toLowerCase();
        const codeUpper = stateTrim.toUpperCase();

        const tryApplyState = () => {
            let match = null;

            stateSelect.querySelectorAll('option').forEach((opt) => {
                if (match) return;

                const label = (opt.textContent || '').trim().toLowerCase();
                const shortCode = (
                    opt.dataset.countryStateShortCode ||
                    opt.dataset.countryStateShortcode ||
                    opt.dataset.shortCode ||
                    ''
                ).toUpperCase();

                if (!match && label === nameLower) match = opt;
                if (!match && shortCode && shortCode === codeUpper) match = opt;
                if (!match && label.includes(nameLower)) match = opt;
            });

            if (!match) return false;

            if (stateSelect.value !== match.value) {
                stateSelect.value = match.value;
                stateSelect.dispatchEvent(new Event('change', { bubbles: true }));
            }

            return true;
        };

        let attempts = 0;
        const interval = setInterval(() => {
            attempts += 1;
            if (tryApplyState() || attempts >= 10) clearInterval(interval);
        }, 250);
    }

    normalizeStateForCountry(countryIso, rawState) {
        const iso = (countryIso || '').toUpperCase();
        const trimmed = (rawState || '').trim();
        if (!trimmed) return trimmed;
        if (iso !== 'US') return trimmed;

        const map = US_STATE_MAP;
        const code = trimmed.toUpperCase();
        return map[code] || trimmed;
    }

    _getFields(form, type) {
        if (!form) return {};

        const cached = this._cache.get(form) || {};
        if (cached[type]) return cached[type];

        const selectors = type === 'shipping' ? shippingSelectors : billingSelectors;

        const fields = {
            street: form.querySelector(selectors.street),
            city: form.querySelector(selectors.city),
            zip: form.querySelector(selectors.zip),
            country: form.querySelector(selectors.country),
            state: form.querySelector(selectors.state),
        };

        const next = { ...cached, [type]: fields };
        this._cache.set(form, next);

        return fields;
    }

    _setValue(input, value) {
        if (!input) return;
        input.value = value || '';
        input.dispatchEvent(new Event('input', { bubbles: true }));
        input.dispatchEvent(new Event('change', { bubbles: true }));
    }
}

const billingSelectors = {
    street: '[name="billingAddress[street]"], [name="address[street]"], [name="street"]',
    city: '[name="billingAddress[city]"], [name="address[city]"], [name="city"]',
    zip: '[name="billingAddress[zipcode]"], [name="address[zipcode]"], [name="zipcode"]',
    country: 'select[name="billingAddress[countryId]"], select[name="address[countryId]"], select[name="countryId"]',
    state: 'select[name="billingAddress[countryStateId]"], select[name="address[countryStateId]"], select[name="countryStateId"]',
};

const shippingSelectors = {
    street: '[name="shippingAddress[street]"], [name="address[shipping][street]"], [name="shippingAddress[street1]"]',
    city: '[name="shippingAddress[city]"], [name="address[shipping][city]"], [name="shippingAddress[city]"]',
    zip: '[name="shippingAddress[zipcode]"], [name="address[shipping][zipcode]"], [name="shippingAddress[zipcode]"]',
    country: 'select[name="shippingAddress[countryId]"], select[name="address[shipping][countryId]"], select[name="shippingAddress[countryId]"]',
    state: 'select[name="shippingAddress[countryStateId]"], select[name="address[shipping][countryStateId]"], select[name="shippingAddress[countryStateId]"]',
};

const US_STATE_MAP = {
    AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas', CA: 'California', CO: 'Colorado',
    CT: 'Connecticut', DE: 'Delaware', FL: 'Florida', GA: 'Georgia', HI: 'Hawaii', ID: 'Idaho',
    IL: 'Illinois', IN: 'Indiana', IA: 'Iowa', KS: 'Kansas', KY: 'Kentucky', LA: 'Louisiana',
    ME: 'Maine', MD: 'Maryland', MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota', MS: 'Mississippi',
    MO: 'Missouri', MT: 'Montana', NE: 'Nebraska', NV: 'Nevada', NH: 'New Hampshire', NJ: 'New Jersey',
    NM: 'New Mexico', NY: 'New York', NC: 'North Carolina', ND: 'North Dakota', OH: 'Ohio', OK: 'Oklahoma',
    OR: 'Oregon', PA: 'Pennsylvania', RI: 'Rhode Island', SC: 'South Carolina', SD: 'South Dakota',
    TN: 'Tennessee', TX: 'Texas', UT: 'Utah', VT: 'Vermont', VA: 'Virginia', WA: 'Washington',
    WV: 'West Virginia', WI: 'Wisconsin', WY: 'Wyoming',
};
