import './page/sw-settings-smarty-list';
import './components/sw-settings-smarty-form';

const {Module} = Shopware;

Module.register('sw-settings-smarty', {
    type: 'plugin',
    name: 'Smarty',
    description: 'SmartyProject',
    version: '1.0.0',
    targetVersion: '1.0.0',
    icon: 'regular-cog',

    routes: {
        index: {
            component: 'sw-settings-smarty-list',
            path: 'index',
        }
    },

    settingsItem: {
        group: 'smarty',
        to: 'sw.settings.smarty.index',
        icon: 'regular-location-arrow',
        label: 'Smarty Integrations'
    }
});
