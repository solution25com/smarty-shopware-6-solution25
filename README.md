[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25com/smarty-shopware-6-solution25/blob/main/LICENSE)

# Smarty Address Validation for Shopware 6

## Introduction

The **Smarty Address Validation Plugin** connects your Shopware 6 store to the **Smarty US Address Verification APIs**, enabling real-time address validation, autocomplete suggestions, and ZIP code lookups during registration and checkout.

Smarty is a leading address intelligence platform used to validate and standardize US addresses. This plugin ensures that only valid, deliverable addresses are accepted in your store — reducing failed deliveries, improving data quality, and giving customers instant address suggestions as they type.

---

## Key Features

### Real-Time Address Validation
- Validates US addresses against the **Smarty Street Address API** during customer registration, address book updates, and order placement.
- Stores the outcome on the address itself, so every record carries its own validation state.

### Address Autocomplete
- As customers type their street address, the plugin fetches up to 10 **live address suggestions** from the Smarty Autocomplete Pro API.
- Suggestions are filtered and ranked by city, state, ZIP, and street number proximity to what the customer has entered.

### ZIP Code Lookup
- Auto-fills **city and state** when a customer enters a ZIP code, reducing manual input and errors.
- Partial ZIP entry (3–4 digits) is resolved from a **local ZIP prefix table** shipped with the plugin, so no API call is needed; full 5-digit ZIPs are looked up through the Smarty ZIP Code API.

### Reverse Geocoding
- A **"Use my location"** button resolves the customer's **GPS coordinates** (latitude/longitude) to a standardized US address using the Smarty Reverse Geocoding API, pre-filling the address form.

### Address Suggestion Modal
- Displays a **suggestion modal** when a validated address differs from what the customer entered, allowing them to accept the standardized version or keep their original input.

### Periodic Re-validation
- Addresses are re-checked once their last validation grows older than a configurable threshold (**6 months** by default), so long-lived customer records do not drift out of date.

### Admin Address Validation
- Validates addresses entered directly in the **Shopware Admin** (customer address form) using the same Smarty API, ensuring data quality across all entry points.
- Adds a **Smarty Address Validation** page under **Customers** in the Admin menu for reviewing validation activity.

### Multi-Environment Support
- Switch between the **Test** and **Live** Smarty environments from the plugin configuration, without code changes.

### Comprehensive Logging
- API calls, responses, and validation results are written to `var/log/SmartyAddressValidation-<env>.log` and to the `smarty_validation_log` table for easy troubleshooting and auditability.

---

## Compatibility
- ✅ Shopware 6.7.x
- ✅ PHP 8.2+
- ✅ US addresses only (Smarty US APIs)

---

## Get Started

### Installation & Activation

#### GitHub

1. Clone the plugin into your Shopware plugins directory:

```bash
git clone https://github.com/solution25com/smarty-shopware-6-solution25.git
```

2. **Install the Plugin in Shopware 6**

    - Log in to your Shopware 6 Administration panel.
    - Navigate to **Extensions > My Extensions**.
    - Locate the plugin and click **Install**.

3. **Activate the Plugin**

    - After installation, click **Activate** to enable the plugin.
    - Run the following commands from your Shopware root:

```bash
bin/console plugin:refresh
bin/console plugin:install --activate SmartyAddressValidation
bin/console cache:clear
```

4. **Build Storefront Assets**

```bash
bin/console bundle:dump
bin/build-storefront.sh
bin/console cache:clear
```

5. **Verify Installation**

    - After activation, you will see **Smarty Address Validation** in the list of installed plugins.

---

## Plugin Configuration

After installing the plugin, configure your **Smarty** credentials through the Shopware Administration panel.

### Accessing the Configuration

1. Go to **Extensions > My Extensions**, then open the plugin's **Configuration**
2. Select the **Sales Channel** you want to configure
3. Set the following fields:

### Configuration

| Field | Description |
|---|---|
| **Enable storefront validation** | Validates addresses entered by customers in the storefront, and drives the suggestion modal |
| **Enable admin validation** | Validates addresses entered in the Shopware Admin customer address form |
| **Enable automatic standardization** | Applies Smarty's standardized address automatically instead of asking the customer to confirm |
| **Enable geocoding** | Stores latitude/longitude on the address and enables the "Use my location" button |
| **Enable logging** | Writes API calls, responses, and validation results to the log file and log table |
| **Smarty environment** | Choose **Test** or **Live** |
| **Smarty Auth ID** | Your Smarty API Auth ID |
| **Smarty Website Key** | Your Smarty website key, used for the browser-facing autocomplete requests |
| **Smarty Auth Token** | Your Smarty API Auth Token |
| **Validation age threshold in months** | How long a validation stays valid before the address is re-checked (default **6**, minimum **1**) |

> **Note:** You can obtain your Auth ID and Auth Token from your [Smarty account dashboard](https://www.smarty.com).

---

## How It Works

### 1. Address Autocomplete at Registration & Account

As the customer types their street address, the plugin sends real-time requests to the **Smarty Autocomplete Pro API**. A dropdown of up to 10 matching US address suggestions appears, filtered and ranked by how closely they match the city, state, and ZIP the customer has entered. Selecting a suggestion auto-fills all address fields.

### 2. ZIP Code Lookup

When a customer enters a ZIP code, the plugin fills in the corresponding city and state. A partial ZIP (3–4 digits) is expanded from the plugin's local ZIP prefix table, while a full 5-digit ZIP is resolved through the **Smarty ZIP Code API**.

### 3. Address Validation on Save

When a customer saves an address (during registration, from their account address book, or via the Admin), the plugin validates it against the **Smarty Street Address API**. The result is stored in custom fields on the address — including a verified flag, the validation timestamp, geo coordinates, and the raw request and response for auditing.

### 4. Address Suggestion Modal

If the validated address returned by Smarty differs from what the customer entered (e.g. corrected street abbreviations or ZIP+4), a **suggestion modal** appears showing both versions. The customer can accept Smarty's standardized version, keep their original input, or edit the address.

### 5. Periodic Re-validation

On a storefront page outside of checkout, the plugin checks the customer's preferred address. If it has never been validated, or its last validation is older than the configured threshold, the suggestion modal is raised so the address can be re-confirmed.

### 6. Order Address Tracking

When an order is placed, the shipping address on the order is marked for validation and validated through the same Smarty API, so the address that reaches fulfillment carries its own validation state independent of the customer record.

### 7. Reverse Geocoding

If a customer's device location is available, the **"Use my location"** button resolves their GPS coordinates to a standardized US address using the **Smarty Reverse Geocoding API**, pre-filling the address form automatically.

---

## Uninstallation

```bash
bin/console plugin:deactivate SmartyAddressValidation
bin/console plugin:uninstall SmartyAddressValidation
bin/console cache:clear
```

Uninstalling removes the plugin's custom fields and drops the `smarty_validation_log` table, unless you choose to keep user data during uninstall.

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Support

For questions or issues, contact [Solution25](https://www.solution25.com/support).
