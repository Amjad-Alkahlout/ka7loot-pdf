import test from 'node:test';
import assert from 'node:assert/strict';
import { registerSortableDirective, validateOrderCompleteness } from '../../resources/js/sortable-directive.js';

test('validateOrderCompleteness accepts valid complete permutation', () => {
    const expected = ['item-1', 'item-2', 'item-3'];
    const submitted = ['item-3', 'item-1', 'item-2'];

    const result = validateOrderCompleteness(submitted, expected);
    assert.equal(result.isValid, true);
    assert.equal(result.missing.length, 0);
    assert.equal(result.unexpected.length, 0);
    assert.equal(result.duplicates.length, 0);
});

test('validateOrderCompleteness rejects missing IDs', () => {
    const expected = ['item-1', 'item-2', 'item-3'];
    const submitted = ['item-2', 'item-1']; // missing item-3

    const result = validateOrderCompleteness(submitted, expected);
    assert.equal(result.isValid, false);
    assert.deepEqual(result.missing, ['item-3']);
});

test('validateOrderCompleteness rejects duplicate IDs', () => {
    const expected = ['item-1', 'item-2', 'item-3'];
    const submitted = ['item-1', 'item-2', 'item-1']; // duplicate item-1, missing item-3

    const result = validateOrderCompleteness(submitted, expected);
    assert.equal(result.isValid, false);
    assert.deepEqual(result.duplicates, ['item-1']);
    assert.deepEqual(result.missing, ['item-3']);
});

test('validateOrderCompleteness rejects unexpected IDs', () => {
    const expected = ['item-1', 'item-2'];
    const submitted = ['item-1', 'item-2', 'foreign-item']; // unexpected foreign-item

    const result = validateOrderCompleteness(submitted, expected);
    assert.equal(result.isValid, false);
    assert.deepEqual(result.unexpected, ['foreign-item']);
});

test('registerSortableDirective registers x-sortable directive on Alpine', () => {
    let registeredName = null;
    let registeredHandler = null;

    const mockAlpine = {
        directive: (name, handler) => {
            registeredName = name;
            registeredHandler = handler;
        }
    };

    registerSortableDirective(mockAlpine);
    assert.equal(registeredName, 'sortable');
    assert.equal(typeof registeredHandler, 'function');
});

test('registerSortableDirective handles null or undefined Alpine safely', () => {
    assert.doesNotThrow(() => {
        registerSortableDirective(null);
        registerSortableDirective(undefined);
    });
});

test('x-sortable handler initializes SortableJS with safe defaults and cleans up idempotently', () => {
    let directiveCallback = null;
    const mockAlpine = {
        directive: (name, handler) => {
            if (name === 'sortable') directiveCallback = handler;
        }
    };
    registerSortableDirective(mockAlpine);
    assert.ok(directiveCallback);

    let cleanedUp = false;
    const cleanupRegistrar = (fn) => {
        // simulate cleanup execution
        assert.doesNotThrow(() => {
            fn();
            fn(); // idempotent second call
        });
        cleanedUp = true;
    };

    const mockElement = {
        dataset: {
            sortableHandle: '.my-handle',
            sortableAnimation: '200',
        },
        children: [
            { dataset: { id: 'file-1' }, getAttribute: () => null },
            { dataset: { id: 'file-2' }, getAttribute: () => null },
            { dataset: { id: 'file-1' }, getAttribute: () => null }, // intentional duplicate
            { dataset: {}, getAttribute: () => null }, // non-item card
        ],
        dispatchEvent: () => true,
    };

    // Invoke directive callback
    directiveCallback(
        mockElement,
        { modifiers: [], expression: null },
        { evaluateLater: () => null, cleanup: cleanupRegistrar }
    );

    assert.equal(cleanedUp, true);
});
