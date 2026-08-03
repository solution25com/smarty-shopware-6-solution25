export default class SmartyAutocompleteApiService {
    constructor(options = {}) {
        this.zipUrl = options.zipUrl;
        this.streetUrl = options.streetUrl;
        this.reverseGeoUrl = options.reverseGeoUrl;
    }

    zip(payload) {
        return this._post(this.zipUrl, payload);
    }

    street(payload) {
        return this._post(this.streetUrl, payload);
    }

    reverseGeo(payload) {
        return this._post(this.reverseGeoUrl, payload);
    }

    async _post(url, payload) {
        if (!url) {
            return { success: true, suggestions: [] };
        }

        const response = await fetch(url, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                Accept: 'application/json',
                'Content-Type': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify(payload),
        });

        if (!response.ok) {
            return { success: true, suggestions: [] };
        }

        try {
            return await response.json();
        } catch {
            return { success: true, suggestions: [] };
        }
    }
}