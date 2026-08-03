import enGB from './snippet/en-GB.json';
import deDE from './snippet/de-DE.json';

import './service/smarty-admin-api.service';
import './extension/sw-customer-address-form';

Shopware.Locale.extend('en-GB', enGB);
Shopware.Locale.extend('de-DE', deDE);
