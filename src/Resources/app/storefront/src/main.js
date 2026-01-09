import SmartyAddressValidationPlugin from './plugin/smarty-address-validation.plugin';
import SmartyCheckoutConfirmValidationPlugin from './plugin/smarty-checkout-confirm-validation.plugin';

const PluginManager = window.PluginManager;

PluginManager.register(
    'SmartyAddressValidation',
    SmartyAddressValidationPlugin,
    'form[action*="/account/register"]'
);

PluginManager.register(
    'SmartyAddressValidationAddressEdit',
    SmartyAddressValidationPlugin,
    'form[action*="/account/address"]'
);
PluginManager.register(
    'SmartyCheckoutConfirmValidation',
    SmartyCheckoutConfirmValidationPlugin,
    '#confirmOrderForm'
);

