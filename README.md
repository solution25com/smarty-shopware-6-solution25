[![License: MIT](https://img.shields.io/badge/license-MIT-green.svg)](https://github.com/solution25com/smarty-shopware-6-solution25/blob/main/LICENSE)

# Smarty Integration for Shopware 6

## Introduction

The **Smarty Integration Plugin** connects your Shopware 6 store to the **Smarty US Address Verification APIs**, enabling real-time address validation, autocomplete suggestions, and ZIP code lookups during registration and checkout.

Smarty is a leading address intelligence platform used to validate and standardize US addresses. This plugin ensures that only valid, deliverable addresses are accepted in your store — reducing failed deliveries, improving data quality, and giving customers instant address suggestions as they type.

---

## Key Features

### Real-Time Address Validation
- Validates US addresses against the **Smarty Street Address API** during customer registration, address book updates, and checkout.
- Blocks submission of invalid or undeliverable addresses with a clear error message.

### Address Autocomplete
- As customers type their street address, the plugin fetches up to 10 **live address suggestions** from the Smarty Autocomplete Pro API.
- Suggestions are filtered and ranked by city, state, ZIP, and street number proximity to what the customer has entered.

### ZIP Code Lookup
- Auto-fills **city and state** when a customer enters a ZIP code, reducing manual input and errors.
- Supports partial ZIP entry (4 digits) by expanding across all possible completions.

### Reverse Geocoding
- Resolves a customer's **GPS coordinates** (latitude/longitude) to a standardized US address using the Smarty Reverse Geocoding API.

### Address Suggestion Modal
- Displays a **suggestion modal** when a validated address differs from what the customer entered, allowing them to accept the standardized version or keep their original input.

### Admin Address Validation
- Validates addresses entered directly in the **Shopware Admin** (customer address form) using the same Smarty API, ensuring data quality across all entry points.

### Checkout Confirmation Guard
- Runs a final address check on the **checkout confirmation page** before the order is placed, preventing invalid addresses from reaching fulfillment.

### Built-in Connection Test
- Test your Smarty API credentials directly from the **Admin settings page** with a single click, without leaving Shopware.

### Multi-Environment Support
- Switch between **Sandbox** and **Live** Smarty environments without code changes.

### Comprehensive Logging
- All API calls, responses, and validation results are logged for easy troubleshooting and auditability.

---

## Compatibility
- ✅ Shopware 6.7.x
- ✅ PHP 8.1+
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
bin/console plugin:install --activate SmartyIntegration
bin/console cache:clear
```

4. **Build Storefront Assets**

```bash
bin/console bundle:dump
bin/build-storefront.sh
bin/console cache:clear
```

5. **Verify Installation**

    - After activation, you will see **Smarty Integration – Address Validation** in the list of installed plugins.

---

## Plugin Configuration

After installing the plugin, configure your **Smarty** credentials through the Shopware Administration panel.

### Accessing the Configuration

1. Go to **Settings > Smarty Plugin**
2. Select the **Sales Channel** you want to configure
3. Set the following fields:

### Configuration

| Field | Description |
|---|---|
| **Auth ID** | Your Smarty API Auth ID |
| **Auth Token** | Your Smarty API Auth Token |
| **Sandbox Environment** | Enable to use the Smarty Sandbox environment for testing |
| **Live Environment** | Enable to use the Smarty Live/Production environment |
| **Test Connection** | Click to verify your credentials are working before saving |

> **Note:** You can obtain your Auth ID and Auth Token from your [Smarty account dashboard](https://www.smarty.com).

---

## How It Works

### 1. Address Autocomplete at Checkout & Registration

As the customer types their street address, the plugin sends real-time requests to the **Smarty Autocomplete Pro API**. A dropdown of up to 10 matching US address suggestions appears, filtered and ranked by how closely they match the city, state, and ZIP the customer has entered. Selecting a suggestion auto-fills all address fields.

### 2. ZIP Code Lookup

When a customer enters a ZIP code, the plugin queries the **Smarty ZIP Code API** and automatically fills in the corresponding city and state, reducing the amount of manual typing required.

### 3. Address Validation on Save

When a customer saves an address (during registration, from their account address book, or via the Admin), the plugin validates it against the **Smarty Street Address API**. If the address is not recognized as valid and deliverable, the save is blocked and an error is shown.

### 4. Address Suggestion Modal

If the validated address returned by Smarty differs from what the customer entered (e.g. corrected street abbreviations or ZIP+4), a **suggestion modal** appears showing both versions. The customer can accept Smarty's standardized version or keep their original input.

### 5. Checkout Confirmation Guard

Before the customer places their order on the checkout confirmation page, a final address check is run. This ensures the shipping address on the order is valid even if it was saved before the plugin was activated.

### 6. Reverse Geocoding

If a customer's device location is available, the plugin can resolve their GPS coordinates to a standardized US address using the **Smarty Reverse Geocoding API**, pre-filling the address form automatically.

---

## Uninstallation

```bash
bin/console plugin:deactivate SmartyIntegration
bin/console plugin:uninstall SmartyIntegration
bin/console cache:clear
```

---

## License

MIT — see [LICENSE](LICENSE) for details.

---

## Support

For questions or issues, please open a [GitHub Issue](https://github.com/solution25com/smarty-shopware-6-solution25/issues) or contact [Solution25](https://solution25.com).