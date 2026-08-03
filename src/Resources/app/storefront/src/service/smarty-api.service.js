export default class SmartyApiService {
    constructor(options = {}) {
        this.options = options;
    }

    getStatus(params = {}) {
        const url = this._url(this.options.statusUrl);

        if (params.currentPath) {
            url.searchParams.set('currentPath', params.currentPath);
        }

        if (params.currentRoute) {
            url.searchParams.set('currentRoute', params.currentRoute);
        }

        return this._request(url.toString(), {
            method: 'GET',
        });
    }

    validateAddress(addressId) {
        return this._post(this.options.validateUrl, { addressId });
    }

    useSuggestion(addressId, suggestionIndex, suggestion) {
        return this._post(this.options.useSuggestionUrl, {
            addressId,
            suggestionIndex,
            suggestion,
        });
    }

    useOriginal(addressId) {
        return this._post(this.options.useOriginalUrl, { addressId });
    }

    confirmValid(addressId) {
        return this._post(this.options.confirmValidUrl, { addressId });
    }

    _post(url, payload) {
        return this._request(url, {
            method: 'POST',
            body: JSON.stringify(payload),
        });
    }

    async _request(url, config = {}) {
        if (!url) {
            throw new Error('Missing Smarty endpoint URL.');
        }

        const response = await fetch(url, {
            credentials: 'same-origin',
            ...config,
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
                ...(config.headers || {}),
            },
        });

        const json = await this._safeJson(response);

        if (!response.ok) {
            throw new Error(json.error || `Smarty request failed with status ${response.status}.`);
        }

        return json;
    }

    async _safeJson(response) {
        try {
            return await response.json();
        } catch {
            return {};
        }
    }

    _url(path) {
        return new URL(path, window.location.origin);
    }
}
