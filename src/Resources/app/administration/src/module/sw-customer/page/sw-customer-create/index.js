const {Component, Mixin} = Shopware;

Component.override('sw-customer-create', {
    mixins: [
        Mixin.getByName('notification'),
    ],

    methods: {
        async onSave() {

            const address = this.customer?.addresses?.[0];

            if (!address) {
                return this.$super('onSave');
            }

            const street = address.street || '';
            const city = address.city || '';
            const postalCode = address.zipcode || '';
            const countryIso = address.country && address.country.iso
                ? address.country.iso
                : '';

            if (!street || !city || !postalCode || !countryIso) {
                return this.$super('onSave');
            }

            let isValid = true;
            let errorMessage = null;

            try {
                const loginService = Shopware.Service('loginService');
                const token = loginService.getToken();
                const apiPath = Shopware.Context.api.apiPath; // normally "/api"

                const response = await fetch(`${apiPath}/_action/smarty/validate-address`, {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                        'Accept': 'application/json',

                        'Authorization': `Bearer ${token}`,

                        'sw-language-id': Shopware.Context.api.languageId,
                    },
                    body: JSON.stringify({
                        street,
                        city,
                        postalCode,
                        countryIso,
                    }),
                });

                const data = await response.json();

                if (!response.ok) {
                    console.warn('Smarty validation request failed, skipping validation');
                    isValid = true;
                } else {
                    isValid = !!(data && data.data && data.data.isValid);
                    errorMessage = data && data.message
                        ? data.message
                        : 'Invalid address. Please check street, city and ZIP code.';
                }
            } catch (e) {
                console.error('Smarty admin validation error', e);
                isValid = true;
            }

            if (!isValid) {
                this.createNotificationError({
                    title: this.$tc('global.default.error'),
                    message: errorMessage,
                });

                return;
            }

            return this.$super('onSave');
        }
    },
});
