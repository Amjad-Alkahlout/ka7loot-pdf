import test from 'node:test';
import assert from 'node:assert/strict';
import { computeEffectiveFontSize, isRtlText, pdfEditorPreview } from '../../resources/js/edit-pdf.js';

test('computeEffectiveFontSize preserves base font size when replacement is shorter or equal', () => {
    const size = computeEffectiveFontSize(14, 'HELLO', 'HI', true);
    assert.equal(size, 14);

    const same = computeEffectiveFontSize(14, 'HELLO', 'HELLO', true);
    assert.equal(same, 14);
});

test('computeEffectiveFontSize scales down font size when replacement exceeds target length with auto-fit', () => {
    // Target length 5, replacement length 15 -> ratio 5/15 = 0.333
    // 14 * 0.333 = 4.67, clamped to min 6.0
    const scaledClamped = computeEffectiveFontSize(14, 'SHORT', 'A MUCH LONGER REPLACEMENT TEXT', true);
    assert.equal(scaledClamped, 6.0);

    // Target length 10, replacement length 15 -> ratio 10/15 = 0.667
    // 15 * 0.667 = 10.0
    const scaledProportional = computeEffectiveFontSize(15, '1234567890', '123456789012345', true);
    assert.equal(scaledProportional, 10.0);
});

test('computeEffectiveFontSize ignores length difference when auto-fit is disabled', () => {
    const size = computeEffectiveFontSize(18, 'SHORT', 'A MUCH LONGER REPLACEMENT TEXT', false);
    assert.equal(size, 18);
});

test('computeEffectiveFontSize handles null or empty strings safely', () => {
    assert.equal(computeEffectiveFontSize(12, '', '', true), 12);
    assert.equal(computeEffectiveFontSize(12, null, null, true), 12);
    assert.equal(computeEffectiveFontSize(0, 'A', 'B', true), 6.0);
});

test('isRtlText accurately distinguishes Latin, Arabic, and Hebrew characters', () => {
    assert.equal(isRtlText('Hello World', null), false);
    assert.equal(isRtlText('John Doe', 'ltr'), false);
    assert.equal(isRtlText('محمد أحمد', null), true);
    assert.equal(isRtlText('مرحبا بك', null), true);
    assert.equal(isRtlText('שלום', null), true);
    assert.equal(isRtlText('Mixed English and عربي text', null), true);
    assert.equal(isRtlText('English with RTL override', 'rtl'), true);
});

test('pdfEditorPreview initializes with default and config values', () => {
    const preview = pdfEditorPreview({
        initialText: 'Initial Title',
        initialFontSize: 16,
        initialColor: '#1d4ed8',
        initialAutoFit: true,
        origText: 'Initial Title',
        baseFontSize: 16,
        direction: 'ltr',
        zoomScale: 1.5,
    });

    assert.equal(preview.localText, 'Initial Title');
    assert.equal(preview.localFontSize, 16);
    assert.equal(preview.localColor, '#1d4ed8');
    assert.equal(preview.localAutoFit, true);
    assert.equal(preview.charsCount, 13);
    assert.equal(preview.isRtl, false);
    assert.equal(preview.effectiveFontSizePx, 16 * 1.5);
});

test('pdfEditorPreview updates preview immediately on character-by-character input', () => {
    const preview = pdfEditorPreview({
        initialText: '',
        initialFontSize: 14,
        initialColor: '#000000',
        initialAutoFit: true,
        origText: 'HELLO',
        baseFontSize: 14,
        zoomScale: 1.0,
    });

    // Simulating typing "HELLO WORLD" character by character
    const typed = 'HELLO WORLD';
    for (let i = 1; i <= typed.length; i++) {
        preview.localText = typed.slice(0, i);
        assert.equal(preview.charsCount, i);
    }

    assert.equal(preview.localText, 'HELLO WORLD');
    assert.equal(preview.charsCount, 11);
    // 5 / 11 = 0.455 ratio -> 14 * 0.455 = 6.4pt
    assert.equal(preview.effectiveFontSizePx, 6.4);
    assert.match(preview.activePreviewStyle, /font-size: 6\.4px/);
});

test('pdfEditorPreview updates font size, color, auto-fit, and RTL reactively in memory', () => {
    const preview = pdfEditorPreview({
        initialText: 'Original Text',
        initialFontSize: 12,
        initialColor: '#000000',
        initialAutoFit: true,
        origText: 'Original Text',
        baseFontSize: 12,
        zoomScale: 1.0,
    });

    // 1. Changing color
    preview.localColor = '#dc2626';
    assert.match(preview.activePreviewStyle, /color: #dc2626/);

    // 2. Changing font size
    preview.localFontSize = 24;
    assert.equal(preview.effectiveFontSizePx, 24);
    assert.match(preview.activePreviewStyle, /font-size: 24px/);

    // 3. Toggling auto-fit off
    preview.localText = 'Original Text That Is Extremely Long And Expanded';
    preview.localAutoFit = false;
    assert.equal(preview.effectiveFontSizePx, 24); // Kept at 24 without shrinking
    assert.match(preview.activePreviewStyle, /overflow: visible/);

    // 4. Entering Arabic text switches direction and font-family immediately
    preview.localText = 'تعديل النص المباشر';
    assert.equal(preview.isRtl, true);
    assert.match(preview.activePreviewStyle, /direction: rtl/);
    assert.match(preview.activePreviewStyle, /Cairo/);
});
