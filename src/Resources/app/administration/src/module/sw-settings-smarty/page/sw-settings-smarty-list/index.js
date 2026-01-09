import template from './sw-settings-smarty-list.html.twig';
import '../../components/sw-settings-smarty-form/index';

const {Component, Mixin} = Shopware;

Component.register('sw-settings-smarty-list', {
    template,

    mixins: [
        Mixin.getByName('notification')
    ],

    data() {
        return {
            isSaving: false
        };
    },

    methods: {
        async onSave() {
            this.isSaving = true;

            try {
                await this.$refs.form.saveConfig();
            } finally {
                this.isSaving = false;
            }

        }

    }
})