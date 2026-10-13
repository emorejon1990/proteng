import test from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import vm from 'node:vm';

const source = readFileSync(new URL('../../resources/js/weightscale.js', import.meta.url), 'utf8');

function scale({ filling = true, inputs = [], quality = false, qualityInputs = [], livewire = true } = {}) {
    const calls = [];
    const events = [];
    const legacyInput = { value: '', dispatchEvent: event => events.push(event) };
    const context = vm.createContext({
        console: { log() {} },
        navigator: {},
        Event: class { constructor(type, options) { this.type = type; this.bubbles = options.bubbles; } },
        window: { Livewire: livewire ? { find: id => ({ $call: (...args) => calls.push([id, ...args]) }) } : null },
        document: {
            getElementById: () => null,
            querySelectorAll: selector => selector === '[data-filling-weight]' ? inputs : selector === '[data-quality-weight]' ? qualityInputs : [],
            querySelector: selector => {
                if (selector === '#butConnect') return { addEventListener() {} };
                if (selector === '[data-filling-page]') return filling ? {} : null;
                if (selector === '[data-quality-page]') return quality ? {} : null;
                if (selector === '#product-weight') return legacyInput;
                return null;
            },
        },
    });
    vm.runInContext(source, context);
    return { deliver: weight => context.deliverScaleWeight(weight), calls, events, legacyInput };
}

function input(component, product, active) {
    return {
        dataset: { productId: String(product), scaleActive: String(active) },
        closest: selector => {
            assert.equal(selector, '[wire\\:id]');
            return { getAttribute: () => component };
        },
    };
}

test('routes a weight to the selected product in its Livewire component', () => {
    const state = scale({ inputs: [input('order-a', 12, false), input('order-b', 25, true)] });
    state.deliver(2500.25);
    assert.deepEqual(state.calls, [['order-b', 'receiveScaleWeight', 2500.25, 25]]);
    assert.equal(state.legacyInput.value, '');
});

test('ignores readings without a selection and with ambiguous selections', () => {
    for (const inputs of [[], [input('a', 1, false)], [input('a', 1, true), input('b', 2, true)]]) {
        const state = scale({ inputs });
        state.deliver(2500);
        assert.deepEqual(state.calls, []);
        assert.equal(state.legacyInput.value, '');
    }
});

test('ignores nonfinite measurements and unavailable Livewire', () => {
    const state = scale({ inputs: [input('a', 1, true)] });
    for (const weight of [NaN, Infinity, -Infinity]) state.deliver(weight);
    assert.deepEqual(state.calls, []);
    assert.doesNotThrow(() => scale({ inputs: [input('a', 1, true)], livewire: false }).deliver(2500));
});

test('preserves the existing quality weight input and input event', () => {
    const state = scale({ filling: false });
    state.deliver(2499);
    assert.equal(state.legacyInput.value, 2499);
    assert.equal(state.events.length, 1);
    assert.equal(state.events[0].type, 'input');
    assert.equal(state.events[0].bubbles, true);
    assert.deepEqual(state.calls, []);
});

test('routes quality weights only to the selected quality product', () => {
    const state = scale({ filling: false, quality: true, qualityInputs: [input('quality-a', 12, false), input('quality-b', 25, true)] });
    state.deliver(2499.5);
    assert.deepEqual(state.calls, [['quality-b', 'receiveScaleWeight', 2499.5, 25]]);
    assert.equal(state.legacyInput.value, '');
});

test('ignores quality readings while no product or more than one product is active', () => {
    for (const qualityInputs of [[], [input('a', 1, false)], [input('a', 1, true), input('b', 2, true)]]) {
        const state = scale({ filling: false, quality: true, qualityInputs });
        state.deliver(2499);
        assert.deepEqual(state.calls, []);
        assert.equal(state.legacyInput.value, '');
    }
});
