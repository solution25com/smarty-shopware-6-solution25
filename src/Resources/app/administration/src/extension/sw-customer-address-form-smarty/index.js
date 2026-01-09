import template from './sw-customer-address-form.html.twig';

const {Component, Mixin} = Shopware;
const {Criteria} = Shopware.Data;

Component.override('sw-customer-address-form', {
    template,

    inject: ['repositoryFactory'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            smartyIsApplying: false,
        };
    },

    mounted() {
        this.$nextTick(() => {
            this._ensureSmartyCustomFields();
            this.initSmartyZipAutocomplete();
            this.initSmartyStreetAutocomplete();
        });
    },

    watch: {
        address: {
            handler() {
                this._ensureSmartyCustomFields();
            },
            immediate: true,
            deep: true,
        },
    },

    beforeDestroy() {
        if (this._smartyZipInput && this._onSmartyZipInput) {
            this._smartyZipInput.removeEventListener('input', this._onSmartyZipInput);
        }

        if (this._smartyStreetInput && this._onSmartyStreetInput) {
            this._smartyStreetInput.removeEventListener('input', this._onSmartyStreetInput);
        }

        if (this._smartyOutsideClickHandler) {
            document.removeEventListener('click', this._smartyOutsideClickHandler);
        }

        if (this._smartyZipDropdown && this._smartyZipDropdown.parentNode) {
            this._smartyZipDropdown.parentNode.removeChild(this._smartyZipDropdown);
        }

        if (this._smartyStreetDropdown && this._smartyStreetDropdown.parentNode) {
            this._smartyStreetDropdown.parentNode.removeChild(this._smartyStreetDropdown);
        }
    },

    methods: {
        _ensureSmartyCustomFields() {
            const model = this._getSmartyAddressModel();

            if (!model) {
                return;
            }

            if (!model.customFields) {
                model.customFields = {};
            }

            if (typeof model.customFields.smarty_latitude === 'undefined') {
                model.customFields.smarty_latitude = null;
            }

            if (typeof model.customFields.smarty_longitude === 'undefined') {
                model.customFields.smarty_longitude = null;
            }
        },

        async onSmartyApplyCoords() {
            const model = this._getSmartyAddressModel();

            if (!model) {
                this.createNotificationError({
                    title: 'Smarty',
                    message: 'Could not resolve the address entity.',
                });
                return;
            }

            this._ensureSmartyCustomFields();

            const customFields = model.customFields || {};
            const latitude = customFields.smarty_latitude;
            const longitude = customFields.smarty_longitude;

            if (!latitude || !longitude) {
                this.createNotificationError({
                    title: 'Smarty',
                    message: 'Please enter both latitude and longitude.',
                });
                return;
            }

            this.smartyIsApplying = true;

            try {
                const response = await fetch('/smarty/address/from-coordinates', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        latitude,
                        longitude,
                    }),
                });

                const json = await response.json();
                const result = json && json.data ? json.data : null;

                if (!response.ok || !result || result.isValid === false) {
                    this.createNotificationError({
                        title: 'Smarty',
                        message: (result && result.message)
                            ? result.message
                            : 'Coordinates could not be resolved to a valid address.',
                    });
                    return;
                }

                const street = result.street || '';
                const city = result.city || '';
                const postalCode = result.postalCode || '';
                const countryIso = (result.countryIso || 'US').toUpperCase();
                const state = result.state || '';

                if (street) {
                    model.street = street;
                }
                if (city) {
                    model.city = city;
                }
                if (postalCode) {
                    model.zipcode = postalCode;
                }

                const root = this.$el;

                const streetInput = this._findInput(root, 'street', ['street', 'address']);
                if (streetInput && street) {
                    streetInput.value = street;
                    streetInput.dispatchEvent(new Event('change', {bubbles: true}));
                    streetInput.dispatchEvent(new Event('input', {bubbles: true}));
                }

                const zipInput = this._smartyZipInput || this._findInput(root, 'zipcode', ['postal code', 'postcode', 'zip']);
                if (zipInput && postalCode) {
                    zipInput.value = postalCode;
                    zipInput.dispatchEvent(new Event('change', {bubbles: true}));
                    zipInput.dispatchEvent(new Event('input', {bubbles: true}));
                }

                if (this._smartyCityInput && city) {
                    this._smartyCityInput.value = city;
                    this._smartyCityInput.dispatchEvent(new Event('change', {bubbles: true}));
                    this._smartyCityInput.dispatchEvent(new Event('input', {bubbles: true}));
                }

                await this._applySmartyCountryAndState(countryIso, state);

                this.createNotificationSuccess({
                    title: 'Smarty',
                    message: 'Address has been filled from coordinates.',
                });
            } catch (e) {
                console.error('Smarty admin: error while resolving coordinates', e);
                this.createNotificationError({
                    title: 'Smarty',
                    message: 'Unexpected error while resolving coordinates.',
                });
            } finally {
                this.smartyIsApplying = false;
            }
        },

        initSmartyZipAutocomplete() {
            const root = this.$el;

            this._smartyZipInput = this._findInput(root, 'zipcode', ['postal code', 'postcode', 'zip']);
            this._smartyCityInput = this._findInput(root, 'city', ['city']);
            this._smartyCountrySelect = this._findSelect(root, 'countryId', 'Country');
            this._smartyStateSelect = this._findSelect(root, 'countryStateId', 'State');

            if (!this._smartyZipInput) {
                console.warn('Smarty admin ZIP autocomplete: zipcode input not found');
                return;
            }

            this._smartyZipDropdown = this._createDropdown();

            this._onSmartyZipInput = this._debounce(this._handleSmartyZipInput.bind(this), 300);
            this._smartyZipInput.addEventListener('input', this._onSmartyZipInput);
            this._smartyOutsideClickHandler = (e) => {
                const target = e.target;

                const onZipInput = this._smartyZipInput && (target === this._smartyZipInput);
                const inZipDropdown = this._smartyZipDropdown && this._smartyZipDropdown.contains(target);

                const onStreetInput = this._smartyStreetInput && (target === this._smartyStreetInput);
                const inStreetDropdown = this._smartyStreetDropdown && this._smartyStreetDropdown.contains(target);

                if (!onZipInput && !inZipDropdown) {
                    this._hideSmartyZipSuggestions();
                }
                if (!onStreetInput && !inStreetDropdown) {
                    this._hideSmartyStreetSuggestions();
                }
            };

            document.addEventListener('click', this._smartyOutsideClickHandler);
        },

        _createDropdown() {
            const dropdown = document.createElement('div');

            dropdown.className = 'smarty-admin-zip-dropdown';
            dropdown.style.position = 'fixed';
            dropdown.style.left = '0';
            dropdown.style.top = '0';
            dropdown.style.zIndex = '9999';
            dropdown.style.background = '#fff';
            dropdown.style.border = '1px solid #ccc';
            dropdown.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
            dropdown.style.maxHeight = '220px';
            dropdown.style.overflowY = 'auto';
            dropdown.style.fontSize = '12px';
            dropdown.style.display = 'none';

            document.body.appendChild(dropdown);

            return dropdown;
        },

        _findInput(root, name, placeholders = []) {
            return (
                root.querySelector(`input[name="${name}"]`) ||
                root.querySelector(`input[name$="${name}"]`) ||
                root.querySelector(`input[id$="${name}"]`) ||
                Array.from(root.querySelectorAll('input')).find((el) => {
                    const ph = (el.placeholder || '').toLowerCase();
                    return placeholders.some(p => ph.includes(p));
                }) ||
                null
            );
        },

        _findSelect(root, name, ariaLabelContains) {
            return (
                root.querySelector(`select[name="${name}"]`) ||
                root.querySelector(`select[name$="${name}"]`) ||
                root.querySelector(`select[id$="${name}"]`) ||
                (ariaLabelContains
                        ? root.querySelector(`select[aria-label*="${ariaLabelContains}"]`)
                        : null
                ) ||
                null
            );
        },

        _debounce(fn, delay) {
            let timeout;
            return (...args) => {
                clearTimeout(timeout);
                timeout = setTimeout(() => fn.apply(this, args), delay);
            };
        },

        async _handleSmartyZipInput(event) {
            const value = event.target.value.replace(/\D/g, '');

            if (value.length !== 5) {
                this._hideSmartyZipSuggestions();
                return;
            }

            try {
                const response = await fetch('/smarty/address/suggest-zip', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({postalCode: value}),
                });

                const data = await response.json();

                let suggestions = [];
                if (data && data.data && Array.isArray(data.data.suggestions)) {
                    suggestions = data.data.suggestions;
                }

                if (!response.ok || !suggestions.length) {
                    this._hideSmartyZipSuggestions();
                    return;
                }

                this._renderSmartyZipSuggestions(suggestions);
            } catch (e) {
                console.error('Smarty admin ZIP autocomplete error', e);
                this._hideSmartyZipSuggestions();
            }
        },

        _renderSmartyZipSuggestions(suggestions) {
            const dropdown = this._smartyZipDropdown;
            if (!dropdown || !this._smartyZipInput) {
                console.warn('Smarty admin ZIP: dropdown or input missing');
                return;
            }

            dropdown.innerHTML = '';

            if (!suggestions.length) {
                dropdown.style.display = 'none';
                return;
            }

            const rect = this._smartyZipInput.getBoundingClientRect();
            dropdown.style.left = `${rect.left}px`;
            dropdown.style.top = `${rect.bottom + 2}px`;
            dropdown.style.width = `${rect.width}px`;

            suggestions.forEach((s) => {
                const postalCode = s.postalCode || s.zipcode || '';
                const city = s.city || '';
                const state = s.state || '';
                const countryIso = (s.countryIso || 'US').toUpperCase();

                const label = s.label || `${postalCode} – ${city}${state ? ', ' + state : ''}`;

                const item = document.createElement('div');
                item.className = 'smarty-admin-zip-item';
                item.style.padding = '6px 10px';
                item.style.cursor = 'pointer';
                item.innerText = label;

                item.addEventListener('mouseenter', () => {
                    item.style.background = '#f0f0f0';
                });
                item.addEventListener('mouseleave', () => {
                    item.style.background = '#fff';
                });

                item.addEventListener('click', () => {
                    if (this._smartyZipInput && postalCode) {
                        this._smartyZipInput.value = postalCode;
                        this._smartyZipInput.dispatchEvent(new Event('change', {bubbles: true}));
                        this._smartyZipInput.dispatchEvent(new Event('input', {bubbles: true}));
                    }

                    if (this._smartyCityInput && city) {
                        this._smartyCityInput.value = city;
                        this._smartyCityInput.dispatchEvent(new Event('change', {bubbles: true}));
                        this._smartyCityInput.dispatchEvent(new Event('input', {bubbles: true}));
                    }

                    this._applySmartyCountryAndState(countryIso, state);

                    this._hideSmartyZipSuggestions();
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
        },

        _hideSmartyZipSuggestions() {
            if (this._smartyZipDropdown) {
                this._smartyZipDropdown.style.display = 'none';
            }
        },

        initSmartyStreetAutocomplete() {
            const root = this.$el;

            this._smartyStreetInput = this._findInput(root, 'street', ['street', 'address']);

            if (!this._smartyStreetInput) {
                console.warn('Smarty admin street autocomplete: street input not found');
                return;
            }

            this._smartyStreetDropdown = this._createDropdown();

            this._onSmartyStreetInput = this._debounce(this._handleSmartyStreetInput.bind(this), 250);
            this._smartyStreetInput.addEventListener('input', this._onSmartyStreetInput);
        },

        async _handleSmartyStreetInput(event) {
            const value = (event.target.value || '').trim();

            if (!value.length) {
                this._hideSmartyStreetSuggestions();
                return;
            }

            const model = this._getSmartyAddressModel();
            const city = model?.city || '';
            const postalCode = model?.zipcode || '';

            const countryIso = model?.country && model.country.iso
                ? model.country.iso
                : 'US';

            try {
                const response = await fetch('/smarty/address/suggest', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'X-Requested-With': 'XMLHttpRequest',
                    },
                    body: JSON.stringify({
                        street: value,
                        city,
                        postalCode,
                        countryIso,
                    }),
                });

                const data = await response.json();

                let suggestions = [];
                if (data && data.data && Array.isArray(data.data.suggestions)) {
                    suggestions = data.data.suggestions;
                }

                if (!response.ok || !suggestions.length) {
                    this._hideSmartyStreetSuggestions();
                    return;
                }

                this._renderSmartyStreetSuggestions(suggestions);
            } catch (e) {
                console.error('Smarty admin street autocomplete error', e);
                this._hideSmartyStreetSuggestions();
            }
        },

        _renderSmartyStreetSuggestions(suggestions) {
            const dropdown = this._smartyStreetDropdown;
            if (!dropdown || !this._smartyStreetInput) {
                console.warn('Smarty admin STREET: dropdown or input missing');
                return;
            }

            dropdown.innerHTML = '';

            if (!suggestions.length) {
                dropdown.style.display = 'none';
                return;
            }

            const rect = this._smartyStreetInput.getBoundingClientRect();
            dropdown.style.left = `${rect.left}px`;
            dropdown.style.top = `${rect.bottom + 2}px`;
            dropdown.style.width = `${rect.width}px`;

            suggestions.forEach((s) => {
                const street = s.street || '';
                const city = s.city || '';
                const postalCode = s.postalCode || s.zipcode || '';
                const state = s.state || '';
                const countryIso = (s.countryIso || 'US').toUpperCase();

                const label = s.label || street;

                const item = document.createElement('div');
                item.className = 'smarty-admin-street-item';
                item.style.padding = '6px 10px';
                item.style.cursor = 'pointer';
                item.innerText = label;

                item.addEventListener('mouseenter', () => {
                    item.style.background = '#f0f0f0';
                });
                item.addEventListener('mouseleave', () => {
                    item.style.background = '#fff';
                });

                item.addEventListener('click', () => {
                    const model = this._getSmartyAddressModel();
                    if (model) {
                        if (street) model.street = street;
                        if (city) model.city = city;
                        if (postalCode) model.zipcode = postalCode;
                    }

                    if (this._smartyStreetInput && street) {
                        this._smartyStreetInput.value = street;
                        this._smartyStreetInput.dispatchEvent(new Event('change', {bubbles: true}));
                        this._smartyStreetInput.dispatchEvent(new Event('input', {bubbles: true}));
                    }

                    if (this._smartyZipInput && postalCode) {
                        this._smartyZipInput.value = postalCode;
                        this._smartyZipInput.dispatchEvent(new Event('change', {bubbles: true}));
                        this._smartyZipInput.dispatchEvent(new Event('input', {bubbles: true}));
                    }

                    if (this._smartyCityInput && city) {
                        this._smartyCityInput.value = city;
                        this._smartyCityInput.dispatchEvent(new Event('change', {bubbles: true}));
                        this._smartyCityInput.dispatchEvent(new Event('input', {bubbles: true}));
                    }

                    this._applySmartyCountryAndState(countryIso, state);

                    this._hideSmartyStreetSuggestions();
                });

                dropdown.appendChild(item);
            });

            dropdown.style.display = 'block';
        },

        _hideSmartyStreetSuggestions() {
            if (this._smartyStreetDropdown) {
                this._smartyStreetDropdown.style.display = 'none';
            }
        },

        _getSmartyAddressModel() {
            if (this.address) {
                return this.address;
            }

            if (this.customerAddress) {
                return this.customerAddress;
            }

            if (this.customer && Array.isArray(this.customer.addresses) && this.customer.addresses.length) {
                return this.customer.addresses[0];
            }

            console.warn('Smarty admin: cannot resolve address model');
            return null;
        },

        async _applySmartyCountryAndState(countryIso, stateName) {
            const isoUpper = (countryIso || '').toUpperCase();
            const addressModel = this._getSmartyAddressModel();

            if (!addressModel || !isoUpper) {
                return;
            }

            try {
                const countryRepo = this.repositoryFactory.create('country');

                const countryCriteria = new Criteria(1, 1);
                countryCriteria.addFilter(Criteria.equals('iso', isoUpper));

                let countries = await countryRepo.search(countryCriteria, Shopware.Context.api);

                if (!countries.length) {
                    const critIso3 = new Criteria(1, 1);
                    critIso3.addFilter(Criteria.equals('iso3', isoUpper));
                    countries = await countryRepo.search(critIso3, Shopware.Context.api);
                }

                const country = countries.first ? countries.first() : countries[0];

                if (country) {
                    addressModel.countryId = country.id;

                    if (this._smartyCountrySelect) {
                        this._smartyCountrySelect.value = country.id;
                        this._smartyCountrySelect.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                }
            } catch (e) {
                console.error('Smarty admin: failed to resolve country by ISO', e);
            }

            if (!stateName) {
                return;
            }

            try {
                const stateRepo = this.repositoryFactory.create('country_state');
                const stateCriteria = new Criteria(1, 1);

                stateCriteria.addFilter(Criteria.equals('country.iso', isoUpper));
                stateCriteria.addFilter(Criteria.equals('name', stateName));

                const states = await stateRepo.search(stateCriteria, Shopware.Context.api);
                const state = states.first ? states.first() : states[0];

                if (state) {
                    addressModel.countryStateId = state.id;

                    if (this._smartyStateSelect) {
                        this._smartyStateSelect.value = state.id;
                        this._smartyStateSelect.dispatchEvent(new Event('change', {bubbles: true}));
                    }
                }
            } catch (e) {
                console.error('Smarty admin: failed to resolve state', e);
            }
        },
    },
});
