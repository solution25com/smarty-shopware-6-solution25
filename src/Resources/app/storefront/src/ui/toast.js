const CONTAINER_ATTR = 'data-smarty-toast-container';

function ensureContainer() {
    let container = document.querySelector(`[${CONTAINER_ATTR}]`);

    if (!container) {
        container = document.createElement('div');
        container.setAttribute(CONTAINER_ATTR, 'true');
        container.className = 'smarty-toast-container';
        document.body.appendChild(container);
    }

    return container;
}

function createToast({ message, type = 'error', ttl = 8000 }) {
    const container = ensureContainer();

    const toast = document.createElement('div');
    toast.className = `smarty-toast smarty-toast--${type}`;
    toast.setAttribute('role', 'alert');
    toast.setAttribute('aria-live', 'assertive');
    toast.setAttribute('aria-atomic', 'true');

    toast.innerHTML = `
        <div class="smarty-toast__content">
            <div class="smarty-toast__title">${type === 'error' ? 'Error' : 'Info'}</div>
            <div class="smarty-toast__message"></div>
        </div>
        <button type="button" class="smarty-toast__close" aria-label="Close">×</button>
    `;

    toast.querySelector('.smarty-toast__message').textContent = message;

    const close = () => {
        toast.classList.add('is-leaving');
        window.setTimeout(() => toast.remove(), 180);
    };

    toast.querySelector('.smarty-toast__close').addEventListener('click', close);

    container.appendChild(toast);

    requestAnimationFrame(() => toast.classList.add('is-visible'));

    if (ttl > 0) {
        window.setTimeout(close, ttl);
    }
}

export function showErrorToast(message, ttl = 8000) {
    createToast({ message, type: 'error', ttl });
}

export function showInfoToast(message, ttl = 6000) {
    createToast({ message, type: 'info', ttl });
}
