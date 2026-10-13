import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const view = readFileSync(new URL('../../resources/views/livewire/qr-scanner-modal.blade.php', import.meta.url), 'utf8');
const initializer = view.match(/x-init="([\s\S]*?)"/)[1];

function scanner() {
    let watch;
    const timers = [];
    const instances = [];
    const codes = [];
    const context = vm.createContext({
        isOpen: false,
        console: { error() {} },
        $watch: (name, callback) => { watch = callback; },
        setTimeout: callback => timers.push(callback),
        $wire: { handleScannedCode: code => codes.push(code) },
        Html5QrcodeSupportedFormats: { QR_CODE: 0, CODE_39: 1 },
        Html5Qrcode: class {
            constructor() { this.stops = 0; instances.push(this); }
            start(camera, config, callback) { this.decode = callback; return Promise.resolve(); }
            stop() { this.stops++; return Promise.resolve(); }
        },
    });
    vm.runInContext(`(${initializer})()`, context);
    return {
        instances, codes,
        change: open => { context.isOpen = open; watch(open); },
        tick: async () => { for (const timer of timers.splice(0)) timer(); for (let i = 0; i < 8; i++) await Promise.resolve(); },
    };
}

test('stops the camera and sends only the first repeated decoding to Livewire', async () => {
    const state = scanner();
    state.change(true);
    await state.tick();
    state.instances[0].decode('A0000001B');
    state.instances[0].decode('A0000001B');
    await state.tick();
    assert.deepEqual(state.codes, ['A0000001B']);
    assert.equal(state.instances[0].stops, 1);
});

test('stops a cancelled camera and allows a new scan session', async () => {
    const state = scanner();
    state.change(true);
    await state.tick();
    state.change(false);
    await state.tick();
    assert.equal(state.instances[0].stops, 1);
    assert.deepEqual(state.codes, []);
    state.change(true);
    await state.tick();
    assert.equal(state.instances.length, 2);
});

test('does not start the camera if its modal was closed before initialization', async () => {
    const state = scanner();
    state.change(true);
    state.change(false);
    await state.tick();
    assert.equal(state.instances.length, 0);
});
