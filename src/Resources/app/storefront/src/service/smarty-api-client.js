export default class SmartyApiClient {
  constructor(baseUrl = '') {
      this.baseUrl = baseUrl;
      this.headers = {
          'Content-Type': 'application/json',
          'X-Requested-With': 'XMLHttpRequest',
      };
  }

  validate(payload) {
      return this._post('/smarty/address/validate', payload);
  }

  suggest(payload) {
      return this._post('/smarty/address/suggest', payload);
  }

  suggestZip(payload) {
      return this._post('/smarty/address/suggest-zip', payload);
  }

  fromCoordinates(payload) {
      return this._post('/smarty/address/from-coordinates', payload);
  }

  async _post(path, payload) {
      const url = this.baseUrl + path;

      const res = await fetch(url, {
          method: 'POST',
          headers: this.headers,
          body: JSON.stringify(payload),
      });

      let json = {};
      try {
          json = await res.json();
      } catch (_) {
          json = {};
      }

      return { ok: res.ok, json };
  }
}
