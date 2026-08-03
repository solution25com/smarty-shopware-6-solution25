import template from './smarty-address-index.html.twig';

Shopware.Component.register('smarty-address-index', {
    template,

    inject: ['smartyAdminApiService'],

    data() {
        return {
            addressType: 'customer_address',
            addressId: '',
            loading: false,
            actionLoading: false,
            result: null,
            logs: [],
            error: null,
            success: null,
        };
    },

    computed: {
        suggestions() {
            return this.result?.suggestions || [];
        },

        resultJson() {
            return this.formatJson(this.result);
        },
    },

    methods: {
        async validateAddress() {
            this.resetMessages();
            this.loading = true;

            try {
                const response = await this.smartyAdminApiService.validate(this.payload());

                if (!response.success) {
                    this.error = response.error || 'Validation failed.';
                    return;
                }

                this.result = response.result;
                await this.loadLogs();
            } catch (error) {
                this.error = error.message || 'Validation failed.';
            } finally {
                this.loading = false;
            }
        },

        async applySuggestion(index) {
            this.resetMessages();
            this.actionLoading = true;

            try {
                const response = await this.smartyAdminApiService.applySuggestion({
                    ...this.payload(),
                    suggestionIndex: index,
                });

                if (!response.success) {
                    this.error = response.error || 'Could not apply suggestion.';
                    return;
                }

                this.success = response.message;
                await this.validateAddress();
            } catch (error) {
                this.error = error.message || 'Could not apply suggestion.';
            } finally {
                this.actionLoading = false;
            }
        },

        async loadLogs() {
            if (!this.addressId) {
                return;
            }

            const response = await this.smartyAdminApiService.logs(this.addressType, this.addressId);
            this.logs = response.logs || [];
        },

        payload() {
            return {
                addressType: this.addressType,
                addressId: this.addressId,
            };
        },

        resetMessages() {
            this.error = null;
            this.success = null;
        },

        formatJson(value) {
            return value ? JSON.stringify(value, null, 2) : '';
        },

        addressLabel(address) {
            if (!address) {
                return '';
            }

            return [
                address.street,
                address.city,
                address.countryState,
                address.zipcode,
            ].filter(Boolean).join(', ');
        },
    },
});
