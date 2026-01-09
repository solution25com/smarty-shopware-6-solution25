import template from './sw-settings-smarty-form.html.twig';

const {Component, Mixin} = Shopware;

Component.register('sw-settings-smarty-form', {
    template,
    inject: ['systemConfigApiService'],
    mixins: [Mixin.getByName('notification')],

    data() {
        return {
            isLoading: false,
            isTesting: false,
            salesChannelId: null,
            form: {
                'SmartyIntegration.config.authId': '',
                'SmartyIntegration.config.authToken': '',
                'SmartyIntegration.config.sandbox': true,
                'SmartyIntegration.config.live': false
            }
        };
    },

    computed: {
        configDomain() {
            return 'SmartyIntegration.config';
        },

        environment() {
            return this.form['SmartyIntegration.config.sandbox'] ? 'test' : 'live';
        },
    },

    created() {
        this.salesChannelId = null;
        this.loadConfig();
    },

    methods: {
        async onSalesChannelChanged(salesChannelId) {
            this.salesChannelId = salesChannelId || null;
            await this.loadConfig();
        },

        async loadConfig() {
            this.isLoading = true;

            try {
                const values = await this.systemConfigApiService.getValues(
                    this.configDomain,
                    this.salesChannelId
                );

                this.form['SmartyIntegration.config.authId'] =
                    values['SmartyIntegration.config.authId'] ?? '';

                this.form['SmartyIntegration.config.authToken'] =
                    values['SmartyIntegration.config.authToken'] ?? '';

                this.form['SmartyIntegration.config.sandbox'] =
                    values['SmartyIntegration.config.sandbox'] ?? true;

                this.form['SmartyIntegration.config.live'] =
                    values['SmartyIntegration.config.live'] ?? false;

            } catch (e) {
                this.createNotificationError({
                    title: 'SmartyProject',
                    message: 'Failed to load configuration.'
                });
                throw e;
            } finally {
                this.isLoading = false;
            }
        },

        async saveConfig() {
            if (this.isLoading) {
                return false;
            }

            this.isLoading = true;

            if (
                !this.form['SmartyIntegration.config.sandbox'] &&
                !this.form['SmartyIntegration.config.live']
            ) {
                this.createNotificationError({
                    title: 'SmartyProject',
                    message: 'At least one environment (Sandbox or Live) must be enabled.'
                });
                this.isLoading = false;
                return false;
            }

            const payload = {
                'SmartyIntegration.config.authId': this.form['SmartyIntegration.config.authId'],
                'SmartyIntegration.config.authToken': this.form['SmartyIntegration.config.authToken'],
                'SmartyIntegration.config.sandbox': this.form['SmartyIntegration.config.sandbox'],
                'SmartyIntegration.config.live': this.form['SmartyIntegration.config.live'],
            };

            try {
                await this.systemConfigApiService.saveValues(
                    payload,
                    this.salesChannelId || null
                );

                this.createNotificationSuccess({
                    title: 'SmartyProject',
                    message: 'Configuration saved.'
                });

                this.$emit('saved');

                await this.loadConfig();

                return true;
            } catch (e) {
                this.createNotificationError({
                    title: 'SmartyProject',
                    message: 'Failed to save configuration.'
                });
                throw e;
            } finally {
                this.isLoading = false;
            }
        },

        async onClickTestConnection() {
            this.isTesting = true;

            const loginService = Shopware.Service('loginService');

            const token = loginService.getToken ? loginService.getToken() : null;

            const headers = {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
            };

            if (token) {
                headers['Authorization'] = `Bearer ${token}`;
            }

            let sessionState = null;
            try {
                if (Shopware.State && typeof Shopware.State.get === 'function') {
                    sessionState = Shopware.State.get('session');
                }
            } catch (e) {
                console.warn(e);
            }

            if (sessionState && sessionState.languageId) {
                headers['sw-language-id'] = sessionState.languageId;
            }

            if (sessionState && sessionState.contextToken) {
                headers['sw-context-token'] = sessionState.contextToken;
            }

            try {
                const response = await fetch('/api/_action/smarty/test-connection', {
                    method: 'POST',
                    headers,
                    body: JSON.stringify({
                        authId: this.form['SmartyIntegration.config.authId'],
                        authToken: this.form['SmartyIntegration.config.authToken'],
                        environment: this.environment,
                        salesChannelId: this.salesChannelId,
                    }),
                });

                const data = await response.json();

                if (data && data.success) {
                    this.createNotificationSuccess({
                        title: 'Smarty',
                        message: data.message || 'Connection successful.',
                    });
                } else {
                    this.createNotificationError({
                        title: 'Smarty',
                        message: (data && data.message) || 'Connection failed.',
                    });
                }
            } catch (error) {
                console.error('Smarty test connection error:', error);
                this.createNotificationError({
                    title: 'Smarty',
                    message: 'Error while testing connection.',
                });
            } finally {
                this.isTesting = false;
            }
        }

    },
});
