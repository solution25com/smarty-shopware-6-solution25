const Plugin = window.PluginBaseClass;
import SmartyApiService from './service/smarty-api.service';
import SmartyModalService from './service/smarty-modal.service';
import SmartyAddressRenderer from './service/smarty-address-renderer';

export default class SmartyAddressValidationPlugin extends Plugin {
    init() {
        this.options = this._readOptions();
        this.renderer = new SmartyAddressRenderer();
        this.api = new SmartyApiService(this.options);
        this.modal = new SmartyModalService(this.renderer, {
            onUseSuggestion: this._useSuggestion.bind(this),
            onUseOriginal: this._useOriginal.bind(this),
            onEditAddress: this._editAddress.bind(this),
            onConfirmValid: this._confirmValid.bind(this),
        });

        this.addressId = null;
        this.statusAddress = null;
        this.validationResult = null;
        this.booted = false;
        this.editFormSubmitHandler = null;

        this._boot();
    }

    async _boot() {
        if (this.booted || this._isCheckoutPage()) {
            return;
        }

        this.booted = true;

        try {
            const status = await this.api.getStatus({
                currentPath: window.location.pathname,
                currentRoute: this.options.currentRoute,
            });

            if (!status || status.shouldValidate !== true || !status.addressId) {
                return;
            }

            this.addressId = status.addressId;
            this.statusAddress = status.address || null;
            this.modal.openLoading();

            const result = await this.api.validateAddress(this.addressId);
            this.validationResult = result;

            if (result.error) {
                this.modal.showError(result, this.statusAddress);
                return;
            }

            if (result.isValid) {
                this.modal.showValid(result, this.statusAddress);
                return;
            }

            this.modal.showInvalid(result, this.statusAddress);
        } catch (error) {
            if (this.addressId) {
                this.modal.showError({
                    error: error.message || 'Address validation failed.',
                    message: 'We could not validate your address right now.',
                }, this.statusAddress);
            }
        }
    }

    async _useSuggestion(index, suggestion) {
        if (!this.addressId) {
            return;
        }

        this.modal.setBusy(true);

        try {
            const response = await this.api.useSuggestion(this.addressId, index, suggestion);

            if (response.success) {
                this.modal.close();
                window.location.reload();
                return;
            }

            this.modal.showActionError(response.error || 'Could not apply the suggested address.');
        } catch (error) {
            this.modal.showActionError(error.message || 'Could not apply the suggested address.');
        } finally {
            this.modal.setBusy(false);
        }
    }

    async _useOriginal() {
        if (!this.addressId) {
            return;
        }

        this.modal.setBusy(true);

        try {
            const response = await this.api.useOriginal(this.addressId);

            if (response.success) {
                this.modal.close();
                window.location.reload();
                return;
            }

            this.modal.showActionError(response.error || 'Could not keep the original address.');
        } catch (error) {
            this.modal.showActionError(error.message || 'Could not keep the original address.');
        } finally {
            this.modal.setBusy(false);
        }
    }

    async _confirmValid() {
        if (!this.addressId) {
            return;
        }

        this.modal.setBusy(true);

        try {
            const response = await this.api.confirmValid(this.addressId);

            if (response.success) {
                this.modal.close();
                window.location.reload();
                return;
            }

            this.modal.showActionError(response.error || 'Could not confirm the address.');
        } catch (error) {
            this.modal.showActionError(error.message || 'Could not confirm the address.');
        } finally {
            this.modal.setBusy(false);
        }
    }

    async _editAddress() {
        if (!this.addressId) {
            return;
        }

        const template = this.options.editUrlTemplate || '/account/address/__ADDRESS_ID__';
        const url = template.replace('__ADDRESS_ID__', encodeURIComponent(this.addressId));

        await this.modal.showEditLoading();

        try {
            const { response, html } = await this._requestAddressEditPage(url);
            await this._renderAddressEditPage(response.url, html, 'EDIT ADDRESS');
        } catch (error) {
            this.modal.showEditFormError(error.message || 'Unable to load the address form right now.');
        }
    }

    async _requestAddressEditPage(url, options = {}) {
        const response = await fetch(url, {
            credentials: 'same-origin',
            ...options,
        });
        const html = await response.text();

        if (!response.ok) {
            throw new Error(`Address form request failed with status ${response.status}.`);
        }

        return { response, html };
    }

    async _renderAddressEditPage(responseUrl, html, fallbackTitle = 'EDIT ADDRESS') {
        if (this._isAddressListingPath(responseUrl)) {
            this.modal.close();
            window.location.reload();
            return;
        }

        const pageContent = this._extractAddressPageContent(html);

        await this.modal.showEditForm(pageContent.html || html, pageContent.title || fallbackTitle);
        this.modal.prepareEditForm();
        window.PluginManager.initializePlugins();
        this._attachEditFormSubmitHandler();
    }

    _attachEditFormSubmitHandler() {
        const form = this.modal.getEditForm();

        if (!form) {
            return;
        }

        this.editFormSubmitHandler = this._submitEditForm.bind(this);
        form.addEventListener('submit', this.editFormSubmitHandler, true);
    }

    async _submitEditForm(event) {
        const form = event.target.closest('.account-address-form form');

        if (!form || event.defaultPrevented) {
            return;
        }

        this._validatePhoneFields(form);

        if (!form.checkValidity()) {
            event.preventDefault();
            event.stopImmediatePropagation();
            event.stopPropagation();
            form.reportValidity();
            return;
        }

        event.preventDefault();
        event.stopImmediatePropagation();
        event.stopPropagation();

        this.modal.setBusy(true);

        try {
            const { response, html } = await this._requestAddressEditPage(form.action, {
                method: form.method || 'POST',
                body: new FormData(form),
            });

            form.dispatchEvent(new Event('removeLoader', { bubbles: true }));
            await this._renderAddressEditPage(response.url, html, 'EDIT ADDRESS');
        } catch (error) {
            this.modal.showEditFormError(error.message || 'Unable to save the address right now.');
            form.dispatchEvent(new Event('removeLoader', { bubbles: true }));
        } finally {
            this.modal.setBusy(false);
        }
    }

    _validatePhoneFields(form) {
        form.querySelectorAll('[data-phone-number-mask="true"][pattern]').forEach((field) => {
            const value = field.value.trim();
            const pattern = field.getAttribute('pattern');

            field.setCustomValidity('');

            if (!value || !pattern) {
                return;
            }

            try {
                if (new RegExp(`^(?:${pattern})$`).test(value)) {
                    return;
                }
            } catch {
                if (field.validity.valid) {
                    return;
                }
            }

            field.setCustomValidity(field.getAttribute('title') || 'Please match the requested phone number format.');
        });
    }

    _extractAddressPageContent(html) {
        const parsedDocument = new DOMParser().parseFromString(html, 'text/html');
        const mainContent = parsedDocument.querySelector('.account-content-main');
        const titleElement = mainContent?.querySelector('h1');

        return {
            html: mainContent ? mainContent.innerHTML : html,
            title: titleElement ? titleElement.textContent.trim() : '',
        };
    }

    _isAddressListingPath(value) {
        return /\/account\/address\/?$/.test(this._normalizePath(value));
    }

    _normalizePath(value) {
        try {
            const url = new URL(value, window.location.origin);
            return url.pathname.replace(/\/+$/, '') || '/';
        } catch {
            return '';
        }
    }

    _isCheckoutPage() {
        const route = String(this.options.currentRoute || '').toLowerCase();
        const path = String(window.location.pathname || '').toLowerCase();

        return route.includes('checkout') || path.includes('/checkout');
    }

    _readOptions() {
        const dataset = this.el.dataset;

        return {
            statusUrl: dataset.smartyStatusUrl,
            validateUrl: dataset.smartyValidateUrl,
            useSuggestionUrl: dataset.smartyUseSuggestionUrl,
            useOriginalUrl: dataset.smartyUseOriginalUrl,
            confirmValidUrl: dataset.smartyConfirmValidUrl,
            editUrlTemplate: dataset.smartyEditUrlTemplate,
            currentRoute: dataset.smartyCurrentRoute,
            currentPath: dataset.smartyCurrentPath,
        };
    }
}
