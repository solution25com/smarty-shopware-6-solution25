import template from './sw-customer-address-form.html.twig';
import './sw-customer-address-form.scss';

const { Component } = Shopware;
const { Criteria } = Shopware.Data;

const DEBOUNCE_MS = 300;
const MIN_QUERY_LENGTH = 3;
const MAX_ZIP_LENGTH = 5;

Component.override('sw-customer-address-form', {
    template,

    inject: ['smartyAdminApiService'],

    data() {
        return {
            smartyStreetSuggestions: [],
            smartyZipSuggestions: [],
            smartyStreetLoading: false,
            smartyZipLoading: false,
            smartyApplying: false,
        };
    },

    watch: {
        'address.street'(value) {
            if (this.smartyApplying) {
                return;
            }

            this.smartyDebounce('street', () => this.smartySearchStreet(value));
        },

        'address.zipcode'(value) {
            if (this.smartyApplying) {
                return;
            }

            this.smartyDebounce('zip', () => this.smartySearchZip(value));
        },
    },

    created() {
        this.smartyTimers = {};
        this.smartyRequestTokens = { street: 0, zip: 0 };
        this.smartyOutsideHandler = this.smartyHandleOutsideClick.bind(this);
        document.addEventListener('click', this.smartyOutsideHandler);
    },

    unmounted() {
        document.removeEventListener('click', this.smartyOutsideHandler);
        Object.values(this.smartyTimers || {}).forEach((timer) => window.clearTimeout(timer));
    },


    methods: {
        smartyDebounce(key, callback) {
            if (this.smartyTimers[key]) {
                window.clearTimeout(this.smartyTimers[key]);
            }

            this.smartyTimers[key] = window.setTimeout(callback, DEBOUNCE_MS);
        },

        smartyContext() {
            return {
                city: this.smartyClean(this.address.city),
                country: this.country?.iso || 'US',
            };
        },

        async smartySearchZip(rawValue) {
            const zipcode = String(rawValue || '').replace(/\D+/g, '').slice(0, MAX_ZIP_LENGTH);

            if (zipcode.length < MIN_QUERY_LENGTH) {
                this.smartyZipSuggestions = [];
                this.smartyZipLoading = false;
                return;
            }

            const token = (this.smartyRequestTokens.zip += 1);
            this.smartyZipLoading = true;
            this.$nextTick(() => this.smartyPositionDropdowns());

            const suggestions = await this.smartyFetch('autocompleteZip', {
                zipcode,
                ...this.smartyContext(),
            });

            if (token !== this.smartyRequestTokens.zip) {
                return;
            }

            this.smartyZipSuggestions = suggestions;
            this.smartyZipLoading = false;
            this.$nextTick(() => this.smartyPositionDropdowns());
        },

        async smartySearchStreet(rawValue) {
            const street = String(rawValue || '').trim();

            if (street.length < MIN_QUERY_LENGTH) {
                this.smartyStreetSuggestions = [];
                this.smartyStreetLoading = false;
                return;
            }

            const token = (this.smartyRequestTokens.street += 1);
            this.smartyStreetLoading = true;
            this.$nextTick(() => this.smartyPositionDropdowns());

            const suggestions = await this.smartyFetch('autocompleteStreet', {
                street,
                zipcode: String(this.address.zipcode || '').replace(/\D+/g, ''),
                ...this.smartyContext(),
            });

            if (token !== this.smartyRequestTokens.street) {
                return;
            }

            this.smartyStreetSuggestions = suggestions;
            this.smartyStreetLoading = false;
            this.$nextTick(() => this.smartyPositionDropdowns());
        },

        async smartyFetch(method, payload) {
            try {
                const response = await this.smartyAdminApiService[method](payload);

                return Array.isArray(response?.suggestions) ? response.suggestions : [];
            } catch {
                return [];
            }
        },

        async applySmartySuggestion(suggestion) {
            this.smartyApplying = true;
            this.closeSmartyDropdowns();

            try {
                if (suggestion.street) {
                    this.address.street = suggestion.street;
                }

                if (suggestion.city) {
                    this.address.city = suggestion.city;
                }

                if (suggestion.zipcode) {
                    this.address.zipcode = suggestion.zipcode;
                }

                if (suggestion.country === 'US') {
                    const countryId = await this.resolveSmartyCountryId('US');

                    if (countryId && this.address.countryId !== countryId) {
                        this.countryId = countryId;
                    }

                    if (suggestion.state) {
                        const stateId = await this.resolveSmartyStateId(this.address.countryId, suggestion.state);

                        if (stateId) {
                            this.address.countryStateId = stateId;
                        }
                    }
                }
            } finally {
                this.$nextTick(() => {
                    this.smartyApplying = false;
                });
            }
        },

        async resolveSmartyCountryId(iso) {
            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('iso', iso));

            const result = await this.countryRepository.search(criteria);

            return result.first()?.id ?? null;
        },

        async resolveSmartyStateId(countryId, stateCode) {
            if (!countryId || !stateCode) {
                return null;
            }

            const shortCode = String(stateCode).toUpperCase().replace('US-', '');

            const criteria = new Criteria(1, 1);
            criteria.addFilter(Criteria.equals('countryId', countryId));
            criteria.addFilter(Criteria.equalsAny('shortCode', [shortCode, `US-${shortCode}`]));

            const result = await this.countryStateRepository.search(criteria);

            return result.first()?.id ?? null;
        },

        smartyPositionDropdowns() {
            const wraps = this.$el?.querySelectorAll?.('.smarty-ac-wrap') || [];

            wraps.forEach((wrap) => {
                const dropdown = wrap.querySelector('.smarty-ac-dropdown');
                const input = wrap.querySelector('input');

                if (!dropdown || !input) {
                    return;
                }

                const wrapTop = wrap.getBoundingClientRect().top;
                const inputBottom = input.getBoundingClientRect().bottom;

                dropdown.style.top = `${Math.round(inputBottom - wrapTop) + 2}px`;
            });
        },

        closeSmartyDropdowns() {
            this.smartyStreetSuggestions = [];
            this.smartyZipSuggestions = [];
            this.smartyStreetLoading = false;
            this.smartyZipLoading = false;
        },

        smartyHandleOutsideClick(event) {
            if (!event.target.closest('.smarty-ac-wrap')) {
                this.closeSmartyDropdowns();
            }
        },

        smartyClean(value) {
            const clean = String(value || '').trim();

            return clean.includes('*') ? '' : clean;
        },
    },
});
