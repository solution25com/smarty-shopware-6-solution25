export default class SmartyAutocompleteDropdownService {
    constructor() {
        this.dropdown = null;
        this.anchor = null;
        this.items = [];
        this.activeIndex = -1;
        this.onSelect = null;
        this.statusNode = null;

        this._boundOutsideClick = this._handleOutsideClick.bind(this);
        this._boundPosition = this._position.bind(this);

        document.addEventListener('click', this._boundOutsideClick);
        window.addEventListener('resize', this._boundPosition);
        window.addEventListener('scroll', this._boundPosition, true);
    }

    showLoading(anchor) {
        this.anchor = anchor;
        this.items = [];
        this.activeIndex = -1;
        this.onSelect = null;

        this._ensure();
        this._clear();
        this.dropdown.setAttribute('aria-busy', 'true');
        this.statusNode = document.createElement('div');
        this.statusNode.className = 'smarty-autocomplete__loading';
        this.statusNode.setAttribute('role', 'status');
        this.statusNode.setAttribute('aria-live', 'polite');
        this.statusNode.textContent = 'Loading suggestions...';
        this.dropdown.appendChild(this.statusNode);
        this._open();
    }

    show(anchor, items, onSelect) {
        this.anchor = anchor;
        this.items = items || [];
        this.activeIndex = -1;
        this.onSelect = onSelect;

        if (!this.items.length) {
            this.close();
            return;
        }

        this._ensure();
        this._clear();
        this.dropdown.setAttribute('aria-busy', 'false');

        this.items.forEach((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.className = 'smarty-autocomplete__item';
            button.dataset.smartyAutocompleteIndex = String(index);
            button.setAttribute('role', 'option');
            button.setAttribute('aria-selected', 'false');
            button.textContent = String(item.label || '');
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
                this._select(Number(button.dataset.smartyAutocompleteIndex));
            });

            this.dropdown.appendChild(button);
        });

        this._position();

        this._open();
    }

    close() {
        if (!this.dropdown) {
            return;
        }

        this.dropdown.remove();
        this.dropdown = null;
        this.anchor = null;
        this.items = [];
        this.activeIndex = -1;
        this.onSelect = null;
        this.statusNode = null;
    }

    handleKeydown(event) {
        if (!this.dropdown || !this.items.length) {
            return false;
        }

        if (event.key === 'ArrowDown') {
            event.preventDefault();
            this._activate(Math.min(this.activeIndex + 1, this.items.length - 1));
            return true;
        }

        if (event.key === 'ArrowUp') {
            event.preventDefault();
            this._activate(Math.max(this.activeIndex - 1, 0));
            return true;
        }

        if (event.key === 'Enter' && this.activeIndex >= 0) {
            event.preventDefault();
            this._select(this.activeIndex);
            return true;
        }

        if (event.key === 'Escape') {
            event.preventDefault();
            this.close();
            return true;
        }

        return false;
    }

    _ensure() {
        if (this.dropdown) {
            return;
        }

        this.dropdown = document.createElement('div');
        this.dropdown.className = 'smarty-autocomplete';
        this.dropdown.setAttribute('role', 'listbox');
        this.dropdown.setAttribute('aria-live', 'polite');
        document.body.appendChild(this.dropdown);
    }

    _open() {
        this.dropdown.classList.add('is-open');
        this._position();
    }

    _position() {
        if (!this.dropdown || !this.anchor) {
            return;
        }

        const rect = this.anchor.getBoundingClientRect();

        this.dropdown.style.left = `${rect.left + window.scrollX}px`;
        this.dropdown.style.top = `${rect.bottom + window.scrollY + 4}px`;
        this.dropdown.style.width = `${rect.width}px`;
    }

    _activate(index) {
        this.activeIndex = index;

        this.dropdown.querySelectorAll('.smarty-autocomplete__item').forEach((item, itemIndex) => {
            const active = itemIndex === index;
            item.classList.toggle('is-active', active);
            item.setAttribute('aria-selected', active ? 'true' : 'false');
        });
    }

    _select(index) {
        const item = this.items[index];

        if (!item || !this.onSelect) {
            return;
        }

        this.onSelect(item);
        this.close();
    }

    _handleOutsideClick(event) {
        if (!this.dropdown || !this.anchor) {
            return;
        }

        if (this.dropdown.contains(event.target) || this.anchor.contains(event.target)) {
            return;
        }

        this.close();
    }

    _clear() {
        this.dropdown.replaceChildren();
    }
}
