export default class AutocompleteDropdown {
    constructor({
        input,
        minChars = 1,
        debounceMs = 250,
        fetchSuggestions,
        renderLabel,
        onPick,

        transformQuery = (v) => v,
        shouldFetch = (v) => v.length >= minChars,

        wrapperClass = 'smarty-suggestions-wrapper',
        dropdownClass = 'smarty-suggestions-dropdown',
        itemClass = 'smarty-suggestion-item',
    }) {
        this.input = input;
        this.minChars = minChars;
        this.fetchSuggestions = fetchSuggestions;
        this.renderLabel = renderLabel;
        this.onPick = onPick;

        this.transformQuery = transformQuery;
        this.shouldFetch = shouldFetch;

        this.wrapper = null;
        this.dropdown = null;
        this.itemClass = itemClass;

        this._debounced = this._debounce(this._onInput.bind(this), debounceMs);

        if (this.input) {
            this._mount(wrapperClass, dropdownClass);
            this.input.addEventListener('input', this._debounced);

            document.addEventListener('click', (e) => {
                if (this.wrapper && !this.wrapper.contains(e.target)) this.hide();
            });
        }
    }

    hide() {
        if (this.dropdown) this.dropdown.style.display = 'none';
    }

    show() {
        if (this.dropdown) this.dropdown.style.display = 'block';
    }

    async _onInput() {
        const raw = (this.input.value || '').trim();
        const query = this.transformQuery(raw);

        if (!this.shouldFetch(query)) {
            this.hide();
            return;
        }

        let items = [];
        try {
            items = (await this.fetchSuggestions(query)) || [];
        } catch (_) {
            items = [];
        }

        if (!items.length) {
            this.hide();
            return;
        }

        this._render(items);
        this.show();
    }

    _render(items) {
        if (!this.dropdown) return;

        this.dropdown.innerHTML = '';

        items.forEach((item) => {
            const label = (this.renderLabel && this.renderLabel(item)) || '';
            if (!label) return;

            const row = document.createElement('div');
            row.className = this.itemClass;
            row.textContent = label;

            row.addEventListener('click', () => {
                if (this.onPick) this.onPick(item);
                this.hide();
            });

            this.dropdown.appendChild(row);
        });
    }

    _mount(wrapperClass, dropdownClass) {
        const wrapper = document.createElement('div');
        wrapper.className = wrapperClass;

        const parent = this.input.parentNode;
        parent.insertBefore(wrapper, this.input);
        wrapper.appendChild(this.input);

        const dropdown = document.createElement('div');
        dropdown.className = dropdownClass;
        wrapper.appendChild(dropdown);

        this.wrapper = wrapper;
        this.dropdown = dropdown;
    }

    _debounce(fn, delay) {
        let timeout;
        return (...args) => {
            clearTimeout(timeout);
            timeout = setTimeout(() => fn(...args), delay);
        };
    }
}
