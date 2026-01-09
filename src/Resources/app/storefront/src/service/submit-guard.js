export default class SubmitGuard {
    constructor() {
        this._busy = false;
    }

    isBusy() {
        return this._busy;
    }

    async run(fn) {
        if (this._busy) return;
        this._busy = true;

        try {
            await fn();
        } finally {
            this._busy = false;
        }
    }
}
