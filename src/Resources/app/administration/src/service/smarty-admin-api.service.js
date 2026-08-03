const { Application, Classes } = Shopware;
const ApiService = Classes.ApiService;

class SmartyAdminApiService extends ApiService {
    constructor(httpClient, loginService, apiEndpoint = 'smarty') {
        super(httpClient, loginService, apiEndpoint);
    }

    validate(payload) {
        return this.post('/_action/smarty/address/validate', payload);
    }

    applySuggestion(payload) {
        return this.post('/_action/smarty/address/apply-suggestion', payload);
    }

    autocompleteZip(payload) {
        return this.post('/_action/smarty/address/autocomplete/zip', payload);
    }

    autocompleteStreet(payload) {
        return this.post('/_action/smarty/address/autocomplete/street', payload);
    }

    logs(addressType, addressId) {
        return this.httpClient.get(
            `/_action/smarty/address/logs?addressType=${addressType}&addressId=${addressId}`,
            { headers: this.getBasicHeaders() }
        ).then((response) => ApiService.handleResponse(response));
    }

    post(url, payload) {
        return this.httpClient.post(url, payload, {
            headers: this.getBasicHeaders(),
        }).then((response) => ApiService.handleResponse(response));
    }
}

Application.addServiceProvider('smartyAdminApiService', (container) => {
    const initContainer = Application.getContainer('init');

    return new SmartyAdminApiService(
        initContainer.httpClient,
        container.loginService
    );
});
