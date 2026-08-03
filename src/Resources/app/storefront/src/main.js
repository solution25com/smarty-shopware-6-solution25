import SmartyAddressValidationPlugin from './smarty-address-validation.plugin.js';
import SmartyAddressAutocompletePlugin from './plugin/smarty-address-autocomplete.plugin.js';

const PluginManager = window.PluginManager;

PluginManager.register(
    'SmartyAddressValidation',
    SmartyAddressValidationPlugin,
    '[data-smarty-address-validation]'
);

PluginManager.register(
    'SmartyAddressAutocomplete',
    SmartyAddressAutocompletePlugin,
    '[data-smarty-address-autocomplete]'
);