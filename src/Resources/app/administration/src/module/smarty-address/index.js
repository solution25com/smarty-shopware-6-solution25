import './page/smarty-address-index';

Shopware.Module.register('smarty-address-validation', {
    type: 'plugin',
    name: 'smarty-address-validation',
    title: 'smarty-address.general.mainMenuItemGeneral',
    description: 'smarty-address.general.description',
    color: '#202020',
    icon: 'regular-checkmark-circle',

    routes: {
        index: {
            component: 'smarty-address-index',
            path: 'index',
        },
    },

    navigation: [{
        label: 'smarty-address.general.mainMenuItemGeneral',
        color: '#202020',
        path: 'smarty.address.validation.index',
        icon: 'regular-checkmark-circle',
        parent: 'sw-customer',
        position: 120,
        privilege: 'customer.viewer',
    }],
});