/**
 * Live PDF Edit Preview Helper
 *
 * Provides instantaneous 0ms client-side preview updates for text editing,
 * font scaling, auto-fit, color picking, and RTL detection without waiting
 * for Livewire server roundtrips.
 */

export function computeEffectiveFontSize(baseFontSize, targetText, replacementText, autoFit) {
    const parsed = parseFloat(baseFontSize);
    const base = Number.isFinite(parsed) ? parsed : 12.0;

    if (!autoFit) {
        return Math.max(6.0, base);
    }
    const targetLen = (targetText || '').length;
    const replLen = (replacementText || '').length;
    if (targetLen > 0 && replLen > targetLen) {
        const ratio = targetLen / replLen;
        if (ratio < 0.9) {
            return Math.max(6.0, Math.round(base * ratio * 10) / 10);
        }
    }
    return Math.max(6.0, base);
}

export function isRtlText(text, direction) {
    if (direction === 'rtl') {
        return true;
    }
    return /[\u0590-\u05FF\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF\uFB50-\uFDFF\uFE70-\uFEFF]/u.test(text || '');
}

export function pdfEditorPreview(config = {}) {
    return {
        localText: config.initialText || '',
        localFontSize: parseFloat(config.initialFontSize) || 12.0,
        localColor: config.initialColor || '#000000',
        localAutoFit: config.initialAutoFit !== undefined ? !!config.initialAutoFit : true,
        origText: config.origText || '',
        baseFontSize: parseFloat(config.baseFontSize) || 12.0,
        direction: config.direction || null,
        zoomScale: parseFloat(config.zoomScale) || 1.0,

        init() {
            this.syncFromLivewire();

            if (this.$wire) {
                this.$watch('$wire.editText', (val) => {
                    if (val !== undefined && val !== this.localText) {
                        this.localText = val || '';
                    }
                });
                this.$watch('$wire.editFontSize', (val) => {
                    if (val !== undefined && val !== null && parseFloat(val) !== this.localFontSize) {
                        this.localFontSize = parseFloat(val) || this.baseFontSize;
                    }
                });
                this.$watch('$wire.editColor', (val) => {
                    if (val && val !== this.localColor) {
                        this.localColor = val;
                    }
                });
                this.$watch('$wire.autoFit', (val) => {
                    if (val !== undefined) {
                        this.localAutoFit = !!val;
                    }
                });
                this.$watch('$wire.zoomLevel', (val) => {
                    if (val) {
                        this.zoomScale = parseFloat(val) / 100;
                    }
                });
                this.$watch('$wire.selectedGroupId', () => this.syncFromLivewire());
                this.$watch('$wire.selectedObjectId', () => this.syncFromLivewire());
            }
        },

        syncFromLivewire() {
            if (!this.$wire) return;
            const activeItem = this.$wire.selectedGroup || this.$wire.selectedObject;
            if (activeItem) {
                this.origText = activeItem.text || '';
                this.baseFontSize = parseFloat(activeItem.font_size) || 12.0;
                this.direction = activeItem.direction || null;
            } else {
                this.origText = '';
                this.baseFontSize = 12.0;
                this.direction = null;
            }
            this.localText = this.$wire.editText || '';
            this.localFontSize = parseFloat(this.$wire.editFontSize) || this.baseFontSize;
            this.localColor = this.$wire.editColor || '#000000';
            this.localAutoFit = this.$wire.autoFit !== undefined ? !!this.$wire.autoFit : true;
            this.zoomScale = (parseFloat(this.$wire.zoomLevel) || 100) / 100;
        },

        get isRtl() {
            return isRtlText(this.localText, this.direction);
        },

        get effectiveFontSizePx() {
            const eff = computeEffectiveFontSize(
                this.localFontSize || this.baseFontSize,
                this.origText,
                this.localText,
                this.localAutoFit
            );
            return Math.max(5, eff * this.zoomScale);
        },

        get charsCount() {
            return (this.localText || '').length;
        },

        get activePreviewStyle() {
            const sizePx = this.effectiveFontSizePx;
            const color = this.localColor || '#000000';
            const rtl = this.isRtl;
            const fontFamily = rtl ? "'Cairo', 'Amiri', 'Arial', sans-serif" : "'Arial', 'Helvetica', sans-serif";
            const direction = rtl ? 'rtl' : 'ltr';
            const overflow = this.localAutoFit
                ? 'overflow: hidden; text-overflow: ellipsis; white-space: nowrap;'
                : 'overflow: visible; white-space: nowrap;';

            return `font-size: ${sizePx}px; color: ${color}; font-family: ${fontFamily}; direction: ${direction}; ${overflow}`;
        }
    };
}
