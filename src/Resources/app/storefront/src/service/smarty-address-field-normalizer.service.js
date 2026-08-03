export default class SmartyAddressFieldNormalizer {
    constructor() {
        this.stateMap = {
            AL: 'Alabama', AK: 'Alaska', AZ: 'Arizona', AR: 'Arkansas',
            CA: 'California', CO: 'Colorado', CT: 'Connecticut', DE: 'Delaware',
            DC: 'District of Columbia', FL: 'Florida', GA: 'Georgia', HI: 'Hawaii',
            ID: 'Idaho', IL: 'Illinois', IN: 'Indiana', IA: 'Iowa',
            KS: 'Kansas', KY: 'Kentucky', LA: 'Louisiana', ME: 'Maine',
            MD: 'Maryland', MA: 'Massachusetts', MI: 'Michigan', MN: 'Minnesota',
            MS: 'Mississippi', MO: 'Missouri', MT: 'Montana', NE: 'Nebraska',
            NV: 'Nevada', NH: 'New Hampshire', NJ: 'New Jersey', NM: 'New Mexico',
            NY: 'New York', NC: 'North Carolina', ND: 'North Dakota', OH: 'Ohio',
            OK: 'Oklahoma', OR: 'Oregon', PA: 'Pennsylvania', RI: 'Rhode Island',
            SC: 'South Carolina', SD: 'South Dakota', TN: 'Tennessee', TX: 'Texas',
            UT: 'Utah', VT: 'Vermont', VA: 'Virginia', WA: 'Washington',
            WV: 'West Virginia', WI: 'Wisconsin', WY: 'Wyoming',
        };
    }

    countryValue(field) {
        const option = this.selectedOption(field);
        const candidates = [
            option?.dataset?.iso,
            option?.dataset?.countryIso,
            option?.getAttribute?.('data-iso'),
            option?.getAttribute?.('data-country-iso'),
            option?.textContent,
            field?.value,
        ];

        for (const candidate of candidates) {
            const normalized = this.normalizeCountry(candidate);

            if (normalized) {
                return normalized;
            }
        }

        return '';
    }

    stateValue(field) {
        const option = this.selectedOption(field);

        if (option && this.isPlaceholderOption(option)) {
            return '';
        }

        const candidates = [
            option?.dataset?.shortCode,
            option?.dataset?.stateCode,
            option?.getAttribute?.('data-short-code'),
            option?.getAttribute?.('data-state-code'),
            option?.textContent,
            field?.value,
        ];

        for (const candidate of candidates) {
            const normalized = this.normalizeState(candidate);

            if (normalized) {
                return normalized;
            }
        }

        return '';
    }

    selectState(field, suggestion, trigger) {
        if (!field || !suggestion?.state) {
            return false;
        }

        if (field.tagName.toLowerCase() !== 'select') {
            field.value = suggestion.state;
            trigger(field);
            return true;
        }

        const state = this.normalizeState(suggestion.state);
        const stateName = suggestion.stateName || this.stateMap[state] || '';
        const wantedCode = state.toLowerCase();
        const wantedCodeWithPrefix = `us-${wantedCode}`;
        const wantedName = String(stateName || '').trim().toLowerCase();

        const option = Array.from(field.options).find((item) => {
            if (this.isPlaceholderOption(item)) {
                return false;
            }

            const optionText = String(item.textContent || '').trim().toLowerCase();
            const optionTextCode = this.normalizeState(optionText).toLowerCase();
            const codeValues = [
                item.dataset.shortCode,
                item.dataset.stateCode,
                item.getAttribute('data-short-code'),
                item.getAttribute('data-state-code'),
            ].map((value) => String(value || '').trim().toLowerCase());

            const codeMatches = codeValues.some((value) => {
                return value === wantedCode || value === wantedCodeWithPrefix;
            });

            return codeMatches
                || optionText === wantedCode
                || optionText === wantedCodeWithPrefix
                || optionText === wantedName
                || optionTextCode === wantedCode;
        });

        if (!option) {
            return false;
        }

        Array.from(field.options).forEach((item) => {
            item.selected = false;
        });

        option.selected = true;
        field.value = option.value;
        trigger(field);
        this.refreshCustomSelect(field);

        return true;
    }

    selectedOption(field) {
        if (!field || field.tagName?.toLowerCase() !== 'select') {
            return null;
        }

        return field.options[field.selectedIndex] || null;
    }

    isPlaceholderOption(option) {
        if (!option) {
            return true;
        }

        const text = String(option.textContent || '').trim().toLowerCase();
        const value = String(option.value || '').trim();

        if (option.disabled || value === '') {
            return true;
        }

        return text === ''
            || text.includes('state/province')
            || text.includes('select')
            || text.includes('please choose')
            || text.includes('*');
    }

    normalizeCountry(value) {
        const clean = this.clean(value);
        const upper = clean.toUpperCase();
        const lower = clean.toLowerCase();

        if (!clean) {
            return '';
        }

        if (upper === 'US' || upper === 'USA') {
            return 'US';
        }

        if (lower.includes('united states')) {
            return 'US';
        }

        return '';
    }

    normalizeState(value) {
        const clean = this.clean(value);

        if (!clean) {
            return '';
        }

        let upper = clean.toUpperCase();

        if (upper.includes('-')) {
            upper = upper.split('-').pop();
        }

        if (/^[A-Z]{2}$/.test(upper)) {
            return upper;
        }

        const match = Object.entries(this.stateMap).find(([, name]) => {
            return name.toLowerCase() === clean.toLowerCase();
        });

        return match ? match[0] : '';
    }

    clean(value) {
        const clean = String(value || '').trim();

        if (!clean || clean.includes('*')) {
            return '';
        }

        const lower = clean.toLowerCase();

        if (
            lower.includes('state/province')
            || lower.includes('select')
            || lower.includes('placeholder')
            || lower.includes('please choose')
        ) {
            return '';
        }

        return clean;
    }

    refreshCustomSelect(field) {
        field.dispatchEvent(new Event('change', { bubbles: true }));

        const wrapper = field.closest('.form-group, .sw-field, .custom-select, .select-field');
        const visible = wrapper?.querySelector('.choices__item, .select-selected, .ts-control');

        if (visible) {
            visible.dispatchEvent(new Event('change', { bubbles: true }));
        }

        const eventTarget = field.closest('.form-select, .country-state-form-group, .select-field');
        if (eventTarget) {
            eventTarget.dispatchEvent(new Event('change', { bubbles: true }));
        }
    }
}
