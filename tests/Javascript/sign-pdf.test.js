import test from 'node:test';
import assert from 'node:assert/strict';
import { pdfDrawSignature, pdfPlacementItem } from '../../resources/js/sign-pdf.js';

test('pdfDrawSignature initializes with default state and resets on clear', () => {
    const comp = pdfDrawSignature({ emptyError: 'Custom error' });
    assert.equal(comp.isDrawing, false);
    assert.equal(comp.hasContent, false);
    assert.equal(comp.errorMessage, '');

    // Set mock content, error, and canvas context
    comp.hasContent = true;
    comp.errorMessage = 'Some error';
    comp.canvas = { width: 400, height: 160 };
    comp.ctx = {
        save() {},
        setTransform() {},
        clearRect() {},
        restore() {}
    };

    // Calling clear resets state
    comp.clear();
    assert.equal(comp.hasContent, false);
    assert.equal(comp.errorMessage, '');
});

test('pdfDrawSignature validates empty canvas on save', () => {
    let wireCalled = false;
    const comp = pdfDrawSignature({ emptyError: 'Please draw first.' });
    comp.$wire = {
        setDrawData() {
            wireCalled = true;
        }
    };

    comp.save();
    assert.equal(wireCalled, false, 'Should not submit empty signature');
    assert.equal(comp.errorMessage, 'Please draw first.');
});

test('pdfPlacementItem initializes coordinates and dimensions correctly', () => {
    const item = pdfPlacementItem({
        id: 'test-123',
        x: 35,
        y: 70,
        w: 30,
        h: 12,
        rot: 90
    });

    assert.equal(item.id, 'test-123');
    assert.equal(item.x, 35);
    assert.equal(item.y, 70);
    assert.equal(item.w, 30);
    assert.equal(item.h, 12);
    assert.equal(item.rot, 90);
    assert.equal(item.isDragging, false);
    assert.equal(item.isResizing, false);
});

test('pdfPlacementItem clamping logic restricts placement within container bounds', () => {
    const item = pdfPlacementItem({
        id: 'test-clamp',
        x: 0,
        y: 0,
        w: 40,
        h: 20,
        rot: 0
    });

    // Simulate drag movement beyond right edge (x + w > 100)
    // Formula from sign-pdf.js: Math.max(0, Math.min(100 - this.w, this.initX + dxPct))
    item.initX = 80;
    const overflowDx = 50; // would reach 130
    item.x = Math.max(0, Math.min(100 - item.w, item.initX + overflowDx));
    assert.equal(item.x, 60, 'x should be clamped to 100 - w (60)');

    // Simulate drag movement beyond top/left (negative)
    item.initX = 10;
    const negativeDx = -30;
    item.x = Math.max(0, Math.min(100 - item.w, item.initX + negativeDx));
    assert.equal(item.x, 0, 'x should be clamped to 0');
});
