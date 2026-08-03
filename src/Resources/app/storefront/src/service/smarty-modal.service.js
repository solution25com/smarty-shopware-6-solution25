export default class SmartyModalService {
    constructor(renderer, callbacks = {}) {
        this.renderer = renderer;
        this.callbacks = callbacks;
        this.root = null;
        this.busy = false;
        this.lastResult = null;
        this.lastAddress = null;
        this.previousActiveElement = null;
        this.statePlaceholderObserver = null;
    }

    openLoading() {
        this._ensureRoot();
        this._open();
        this._setBody(this._loadingTemplate());
    }

    showInvalid(result, originalAddress) {
        this.lastResult = result;
        this.lastAddress = originalAddress;
        const displayOriginal = originalAddress || result.originalAddress;
        const suggestions = this._actionableSuggestions(result.suggestions || [], displayOriginal);
        const suggestionSection = suggestions.length
            ? `
                <div class="smarty-address-modal__section">
                    <h3>SUGGESTED ADDRESS</h3>
                    ${this._renderActionableSuggestions(suggestions)}
                </div>
            `
            : `
                <div class="smarty-address-modal__notice">
                    Smarty returned the same address details but did not confirm this as a valid deliverable address.
                </div>
            `;

        this._setBody(`
            ${this._header('WE COULDN’T VALIDATE YOUR ADDRESS')}
            <p class="smarty-address-modal__description">
                Please compare and choose the correct address:
            </p>

            <div class="smarty-address-modal__section">
                <h3>YOU ENTERED</h3>
                ${this.renderer.renderAddress(displayOriginal)}
            </div>

            ${suggestionSection}
            ${this._errorSlot()}
            ${this._footer([
                ['edit-address', 'EDIT ADDRESS', 'secondary'],
                ['use-original', 'USE MY ADDRESS', 'primary'],
            ])}
        `);
    }

    showValid(result, currentAddress) {
        this.lastResult = result;
        this.lastAddress = currentAddress;

        const displayAddress = result.standardizedAddress || currentAddress || result.originalAddress;

        this._setBody(`
            ${this._header('YOUR ADDRESS IS VALID')}
            <p class="smarty-address-modal__description">
                Please compare and choose the correct address:
            </p>

            <div class="smarty-address-modal__section">
                <h3>CURRENT ADDRESS</h3>
                ${this.renderer.renderAddress(displayAddress)}
            </div>

            ${this._errorSlot()}
            ${this._footer([
                ['edit-address', 'EDIT ADDRESS', 'secondary'],
                ['confirm-valid', 'CONTINUE', 'primary'],
            ])}
        `);
    }

    showError(result, originalAddress) {
        this.lastResult = result;
        this.lastAddress = originalAddress;

        this._setBody(`
            ${this._header('ADDRESS VALIDATION UNAVAILABLE')}
            <p class="smarty-address-modal__description">
                ${this._escape(result.message || 'We could not validate your address right now.')}
            </p>

            <div class="smarty-address-modal__notice">
                ${this._escape(result.error || 'Please try again later or keep your current address.')}
            </div>

            <div class="smarty-address-modal__section">
                <h3>YOU ENTERED</h3>
                ${this.renderer.renderAddress(originalAddress || result.originalAddress)}
            </div>

            ${this._errorSlot()}
            ${this._footer([
                ['edit-address', 'EDIT ADDRESS', 'secondary'],
                ['use-original', 'USE MY ADDRESS', 'primary'],
            ])}
        `);
    }

    showActionError(message) {
        const slot = this.root?.querySelector('[data-smarty-error-slot]');

        if (slot) {
            slot.innerHTML = `
                <div class="smarty-address-modal__error">
                    ${this._escape(message)}
                </div>
            `;
        }
    }

    showEditLoading() {
        this._ensureRoot();
        this._open();
        this.root?.classList.add('smarty-address-modal--editing');
        return this._setBody(`
            ${this._header('EDIT ADDRESS')}
            <div class="smarty-address-modal__loader" role="status">
                <span class="smarty-address-modal__spinner"></span>
                <p>Loading address form...</p>
            </div>
        `, true);
    }

    showEditForm(html) {
        this.root?.classList.add('smarty-address-modal--editing');
        return this._setBody(`
            <div class="smarty-address-modal__edit-form">
                ${html}
            </div>
            ${this._errorSlot()}
        `, true);
    }

    setBusy(isBusy) {
        this.busy = isBusy;

        if (!this.root) {
            return;
        }

        this.root.classList.toggle('is-busy', isBusy);
        this.root.querySelectorAll('button').forEach((button) => {
            button.disabled = isBusy;
        });
    }

    close() {
        if (!this.root) {
            return;
        }

        document.body.classList.remove('smarty-address-modal-open');
        this._disconnectStatePlaceholderObserver();
        this.root.remove();
        this.root = null;

        if (this.previousActiveElement) {
            this.previousActiveElement.focus();
        }
    }

    _ensureRoot() {
        if (this.root) {
            return;
        }

        this.root = document.createElement('div');
        this.root.className = 'smarty-address-modal';
        this.root.innerHTML = `
            <div class="smarty-address-modal__backdrop"></div>
            <div
                class="smarty-address-modal__dialog"
                role="dialog"
                aria-modal="true"
                aria-labelledby="smarty-address-modal-title">
                <button
                    class="smarty-address-modal__close"
                    type="button"
                    aria-label="Close"
                    data-smarty-action="close">×</button>
                <div class="smarty-address-modal__content" data-smarty-modal-content></div>
            </div>
        `;

        document.body.appendChild(this.root);
        this.root.addEventListener('click', this._onClick.bind(this));
        document.addEventListener('keydown', this._onKeydown.bind(this), { once: false });
    }

    _open() {
        this.previousActiveElement = document.activeElement;
        document.body.classList.add('smarty-address-modal-open');

        window.setTimeout(() => {
            this.root?.querySelector('[data-smarty-action="close"]')?.focus();
        }, 0);
    }

    _setBody(html, transition = false) {
        const content = this.root?.querySelector('[data-smarty-modal-content]');

        if (!content) {
            return Promise.resolve();
        }

        if (!transition) {
            content.innerHTML = html;
            return Promise.resolve();
        }

        content.classList.add('is-transitioning');

        return new Promise((resolve) => {
            window.setTimeout(() => {
                content.innerHTML = html;
                content.scrollTop = 0;
                content.classList.remove('is-transitioning');
                resolve();
            }, 140);
        });
    }

    _onClick(event) {
        const backLink = event.target.closest('.account-address-back');

        if (backLink && this.root?.classList.contains('smarty-address-modal--editing')) {
            event.preventDefault();
            this.close();
            return;
        }

        const actionElement = event.target.closest('[data-smarty-action]');

        if (!actionElement || this.busy) {
            return;
        }

        const action = actionElement.dataset.smartyAction;

        if (action === 'close') {
            this.close();
        }

        if (action === 'confirm-valid') {
            this.callbacks.onConfirmValid?.();
        }

        if (action === 'edit-address') {
            this.callbacks.onEditAddress?.();
        }

        if (action === 'use-original') {
            this.callbacks.onUseOriginal?.();
        }

        if (action === 'use-suggestion') {
            const index = Number(actionElement.dataset.smartyIndex || 0);
            const suggestion = this.lastResult?.suggestions?.[index] || null;

            this.callbacks.onUseSuggestion?.(index, suggestion);
        }
    }

    _onKeydown(event) {
        if (event.key === 'Escape' && this.root && !this.busy) {
            this.close();
        }
    }

    prepareEditForm() {
        const content = this.root?.querySelector('[data-smarty-modal-content]');

        if (!content) {
            return;
        }

        content.querySelectorAll('.account-address-form form').forEach((form) => {
            form.removeAttribute('data-form-handler');
            form.removeAttribute('novalidate');
        });

        this._syncStatePlaceholder(content);
    }

    getEditForm() {
        return this.root?.querySelector('.account-address-form form') || null;
    }

    showEditFormError(message) {
        this.showActionError(message);
    }

    _syncStatePlaceholder(content) {
        this._disconnectStatePlaceholderObserver();

        const stateSelect = content.querySelector('.country-state-select');

        if (!stateSelect) {
            return;
        }

        const sync = () => {
            const placeholder = stateSelect.querySelector('[data-placeholder-option="true"]');

            if (!placeholder) {
                return;
            }

            if (!placeholder.dataset.baseLabel) {
                placeholder.dataset.baseLabel = placeholder.textContent.trim().replace(/\s+\*$/, '');
            }

            const rules = (stateSelect.getAttribute('data-validation') || '')
                .split(',')
                .map((rule) => rule.trim())
                .filter(Boolean);

            const isRequired = stateSelect.hasAttribute('aria-required') || rules.includes('required');

            placeholder.textContent = isRequired
                ? `${placeholder.dataset.baseLabel} *`
                : placeholder.dataset.baseLabel;
        };

        sync();

        this.statePlaceholderObserver = new MutationObserver(sync);
        this.statePlaceholderObserver.observe(stateSelect, {
            attributes: true,
            attributeFilter: ['aria-required', 'data-validation'],
        });
    }

    _disconnectStatePlaceholderObserver() {
        if (!this.statePlaceholderObserver) {
            return;
        }

        this.statePlaceholderObserver.disconnect();
        this.statePlaceholderObserver = null;
    }

    _loadingTemplate() {
        return `
            ${this._header('CHECKING YOUR ADDRESS')}
            <div class="smarty-address-modal__loader" role="status">
                <span class="smarty-address-modal__spinner"></span>
                <p>Validating address...</p>
            </div>
        `;
    }

    _header(title) {
        return `<h2 id="smarty-address-modal-title">${this._escape(title)}</h2>`;
    }

    _errorSlot() {
        return '<div data-smarty-error-slot></div>';
    }

    _footer(buttons) {
        return `
            <div class="smarty-address-modal__footer">
                ${buttons.map(([action, label, type]) => `
                    <button
                        class="smarty-address-modal__button smarty-address-modal__button--${type}"
                        type="button"
                        data-smarty-action="${action}">
                        ${label}
                    </button>
                `).join('')}
            </div>
        `;
    }

    _actionableSuggestions(suggestions, originalAddress) {
        const originalKey = this._addressComparisonKey(originalAddress);

        return suggestions
            .map((suggestion, index) => ({ suggestion, index }))
            .filter((item) => {
                return this._addressComparisonKey(item.suggestion) !== originalKey;
            });
    }

    _renderActionableSuggestions(items) {
        return items
            .map((item) => this.renderer.renderSuggestionButton(item.suggestion, item.index))
            .join('');
    }

    _addressComparisonKey(address) {
        const normalized = this.renderer.normalizeAddress(address || {});

        return JSON.stringify({
            street: this._normalizeComparisonValue(normalized.street),
            city: this._normalizeComparisonValue(normalized.city),
            zipcode: this._normalizeZipcode(normalized.zipcode),
            country: this._normalizeCountry(normalized.country),
        });
    }

    _normalizeComparisonValue(value) {
        return String(value || '')
            .trim()
            .toLowerCase()
            .replace(/\s+/g, ' ');
    }

    _normalizeZipcode(value) {
        return String(value || '').replace(/\D+/g, '');
    }

    _normalizeCountry(value) {
        const normalized = this._normalizeComparisonValue(value);

        if (normalized === 'us' || normalized === 'usa' || normalized.includes('united states')) {
            return 'us';
        }

        return normalized;
    }

    _escape(value) {
        return String(value || '')
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }
}
