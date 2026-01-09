export default class SuggestionModal {
    constructor(selector = '[data-smarty-suggestion-modal]') {
        this.root = document.querySelector(selector);

        this.listEl = this.root ? this.root.querySelector('[data-smarty-suggestions]') : null;
        this.originalEl = this.root ? this.root.querySelector('[data-smarty-original-address]') : null;
        this.cancelBtn = this.root ? this.root.querySelector('[data-smarty-cancel]') : null;

        this._onPick = null;
        this._onCancel = null;

        if (!this.root) return;

        if (this.cancelBtn) {
            this.cancelBtn.addEventListener('click', () => this.close(true));
        }

        this.root.addEventListener('click', (e) => {
            if (e.target === this.root) this.close(true);
        });

        document.addEventListener('keydown', (e) => {
            if (e.key === 'Escape' && this.isOpen()) this.close(true);
        });
    }

    isReady() {
        return !!this.root && !!this.listEl;
    }

    isOpen() {
        return this.root?.classList.contains('is-open') || false;
    }

    open({ suggestions, originalAddress, onPick, onCancel }) {
        if (!this.isReady()) return;

        this._onPick = onPick || null;
        this._onCancel = onCancel || null;


        if (this.originalEl) {
            const orig = normalizeAddress(originalAddress || {});
            renderAddressList(this.originalEl, orig, { showEmpty: true });
        }

        this.listEl.innerHTML = '';

        const fallbackIso = (originalAddress?.countryIso || 'US').toUpperCase();

        suggestions.forEach((s) => {
            const normalized = normalizeSuggestion(s, fallbackIso);
            if (!normalized.street) return;

            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'smarty-modal-suggestion';

            renderAddressList(btn, normalized, { showEmpty: false });

            btn.addEventListener('click', () => {
                if (this._onPick) this._onPick(normalized);
            });

            this.listEl.appendChild(btn);
        });

        this.root.classList.add('is-open');
    }

    close(cancelled = false) {
        if (!this.root) return;

        this.root.classList.remove('is-open');

        if (cancelled && this._onCancel) {
            this._onCancel();
        }

        this._onPick = null;
        this._onCancel = null;
    }
}


function renderAddressList(containerEl, addr, { showEmpty = false } = {}) {

    containerEl.innerHTML = '';

    const wrap = document.createElement('div');
    wrap.className = 'smarty-address-list';

    const addRow = (label, value) => {
        const v = (value ?? '').toString().trim();
        if (!showEmpty && !v) return;

        const row = document.createElement('div');
        row.className = 'smarty-address-list__row';

        const k = document.createElement('span');
        k.className = 'smarty-address-list__key';
        k.textContent = `${label}:`;

        const val = document.createElement('span');
        val.className = 'smarty-address-list__value';
        val.textContent = v || '-';

        row.appendChild(k);
        row.appendChild(val);
        wrap.appendChild(row);
    };

    addRow('Street', addr.street);
    addRow('City', addr.city);
    addRow('State', addr.state);

    addRow('ZIP', formatZipUS(addr.postalCode));

    addRow('Country', countryLabel(addr.countryIso));

    containerEl.appendChild(wrap);
}

function normalizeAddress(a = {}) {
    return {
        street: (a.street || '').trim(),
        city: (a.city || '').trim(),
        postalCode: (a.postalCode || a.zipcode || '').toString().trim(),
        state: (a.state || a.state_abbreviation || '').trim(),
        countryIso: (a.countryIso || 'US').toUpperCase(),
    };
}

function normalizeSuggestion(s, fallbackCountryIso) {
    const street = (s.street || s.delivery_line_1 || '').trim();
    const city = (s.city || s.city_name || '').trim();
    const postalCode = (s.postalCode || s.zipcode || '').toString().trim();
    const state = (s.state || s.state_abbreviation || '').trim();
    const countryIso = (s.countryIso || fallbackCountryIso || 'US').toUpperCase();

    return { street, city, postalCode, state, countryIso };
}

function formatZipUS(zip) {
    const raw = (zip || '').toString();
    const digits = raw.replace(/\D/g, '');

    if (digits.length <= 5) return digits.slice(0, 5);

    return `${digits.slice(0, 5)}-${digits.slice(5, 9)}`;
}

function countryLabel(iso) {
    const up = (iso || '').toUpperCase();
    if (up === 'US') return 'United States (US)';
    return up ? `${up}` : 'N/A';
}
