export default class GeoService {
    async getBrowserCoordinates() {
        if (!('geolocation' in navigator)) {
            return { latitude: null, longitude: null };
        }

        return new Promise((resolve) => {
            navigator.geolocation.getCurrentPosition(
                (pos) => resolve({ latitude: pos.coords.latitude, longitude: pos.coords.longitude }),
                () => resolve({ latitude: null, longitude: null }),
                { enableHighAccuracy: false, timeout: 5000, maximumAge: 0 }
            );
        });
    }
}
