# CHANGELOG.md

## [2.0.0] - 2026-08-03

- Added live address autocomplete as customers type, powered by Smarty's Autocomplete Pro API.
- Added ZIP code lookup that auto-fills city and state, resolving partial ZIP entries locally without an API call and full ZIP codes through Smarty's API.
- Added reverse geocoding through a "Use my location" button that pre-fills the address form from the customer's GPS coordinates.
- Added a suggestion modal so customers can review and accept a standardized address when it differs from what they entered.
- Added periodic re-validation so addresses are automatically flagged for re-checking once they pass a configurable age threshold.
- Added address validation inside the Shopware Administration customer address form, with a new review page under Customers.
- Added a persistent, queryable validation log and a local ZIP prefix lookup table shipped with sample data.
- Added tracking fields to record whether autocomplete was used and whether a customer overrode or declined a suggested address.
- Added an automatic migration path from an earlier internal build of this plugin, carrying over existing tables, settings, and custom field data on install.
