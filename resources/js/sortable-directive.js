import Sortable from 'sortablejs';

/**
 * Validate that a submitted order array is an exact, complete permutation of expected item IDs.
 * Identifies missing IDs, duplicate IDs, and unexpected IDs.
 *
 * @param {Array<string|number>} submittedItems
 * @param {Array<string|number>} expectedItems
 * @returns {{ isValid: boolean, missing: Array<string>, unexpected: Array<string>, duplicates: Array<string> }}
 */
export function validateOrderCompleteness(submittedItems, expectedItems) {
    const submitted = Array.isArray(submittedItems) ? submittedItems.map(String) : [];
    const expected = Array.isArray(expectedItems) ? expectedItems.map(String) : [];

    const expectedSet = new Set(expected);
    const seen = new Set();
    const duplicates = [];
    const unexpected = [];

    for (const item of submitted) {
        if (seen.has(item)) {
            if (!duplicates.includes(item)) {
                duplicates.push(item);
            }
        } else {
            seen.add(item);
        }

        if (!expectedSet.has(item)) {
            unexpected.push(item);
        }
    }

    const missing = expected.filter(item => !seen.has(item));

    return {
        isValid: missing.length === 0 && unexpected.length === 0 && duplicates.length === 0 && submitted.length === expected.length,
        missing: missing,
        unexpected: unexpected,
        duplicates: duplicates,
    };
}

/**
 * Alpine.js Directive: x-sortable
 * Enables drag-and-drop reordering with SortableJS, supporting CSS Grid/Flexbox
 * in both LTR and Arabic RTL layouts, Alpine expressions, and Livewire DOM morphing.
 *
 * Usage:
 *   <div x-sortable="onReorder" data-sortable-handle=".drag-handle">
 *       <div data-id="file-1">...</div>
 *       <div data-id="file-2">...</div>
 *   </div>
 *
 * Or listen to custom event:
 *   <div x-sortable x-on:sort-end="handleSort($event.detail)">...</div>
 *
 * @param {import('alpinejs').Alpine} Alpine
 */
export function registerSortableDirective(Alpine) {
    if (!Alpine) return;

    Alpine.directive('sortable', (el, { modifiers, expression }, { evaluateLater, cleanup }) => {
        const evaluateHandler = expression ? evaluateLater(expression) : null;

        const handle = el.dataset.sortableHandle !== undefined ? el.dataset.sortableHandle : '.drag-handle';
        const ghostClass = el.dataset.sortableGhost || 'sortable-ghost';
        const chosenClass = el.dataset.sortableChosen || 'sortable-chosen';
        const dragClass = el.dataset.sortableDrag || 'sortable-drag';
        const animation = parseInt(el.dataset.sortableAnimation || '180', 10);
        const filter = el.dataset.sortableFilter || null;
        const itemSelector = el.dataset.sortableItem || el.dataset.sortableDraggable || null;

        const sortableOptions = {
            animation: animation,
            ghostClass: ghostClass,
            chosenClass: chosenClass,
            dragClass: dragClass,
            onEnd: (evt) => {
                // If position did not change, avoid duplicate state updates
                if (evt.oldIndex === evt.newIndex) return;

                // Extract ordered item keys from child elements (checks data-id, data-key, wire:key)
                const candidateChildren = itemSelector
                    ? Array.from(el.querySelectorAll(itemSelector))
                    : Array.from(el.children);

                const rawItems = candidateChildren.map(child => {
                    return child.dataset.id || child.dataset.key || child.getAttribute('wire:key');
                }).filter(Boolean);

                // Deduplicate while preserving order
                const items = [...new Set(rawItems)];

                const eventDetail = {
                    items: items,
                    rawItems: rawItems,
                    oldIndex: evt.oldIndex,
                    newIndex: evt.newIndex,
                    itemEl: evt.item,
                };

                el.dispatchEvent(new CustomEvent('sort-end', {
                    bubbles: true,
                    detail: eventDetail,
                }));

                // Evaluate Alpine expression if provided
                if (evaluateHandler) {
                    evaluateHandler(fn => {
                        if (typeof fn === 'function') {
                            fn(items, eventDetail);
                        }
                    }, {
                        scope: {
                            $items: items,
                            $detail: eventDetail,
                            $oldIndex: evt.oldIndex,
                            $newIndex: evt.newIndex,
                            $event: evt,
                        }
                    });
                }
            }
        };

        // If explicit direction is requested, use it; otherwise SortableJS auto-detects grid/flex
        if (el.dataset.sortableDirection && ['vertical', 'horizontal'].includes(el.dataset.sortableDirection)) {
            sortableOptions.direction = el.dataset.sortableDirection;
        }

        if (handle && handle !== 'false') {
            sortableOptions.handle = handle;
        }

        if (filter) {
            sortableOptions.filter = filter;
        }

        if (itemSelector) {
            sortableOptions.draggable = itemSelector;
        }

        if (typeof HTMLElement !== 'undefined' && !(el instanceof HTMLElement)) {
            return;
        }

        let sortableInstance = null;
        try {
            sortableInstance = new Sortable(el, sortableOptions);
        } catch (e) {
            console.warn('x-sortable initialization failed:', e);
        }

        cleanup(() => {
            if (sortableInstance) {
                try {
                    sortableInstance.destroy();
                } catch {
                    // Idempotent cleanup
                }
                sortableInstance = null;
            }
        });
    });
}
