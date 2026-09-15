export function pdfDrawSignature(config = {}) {
    return {
        isDrawing: false,
        canvas: null,
        ctx: null,
        hasContent: false,
        errorMessage: '',
        lastPos: null,
        init() {
            this.canvas = this.$refs.sigCanvas;
            this.$nextTick(() => {
                this.setupCanvas();
            });
            window.addEventListener('resize', () => this.handleResize());
        },
        setupCanvas() {
            if (!this.canvas) return;
            const rect = this.canvas.getBoundingClientRect();
            const dpr = window.devicePixelRatio || 1;
            const width = rect.width > 0 ? rect.width : 440;
            const height = 160;
            this.canvas.width = Math.round(width * dpr);
            this.canvas.height = Math.round(height * dpr);
            this.ctx = this.canvas.getContext('2d');
            this.ctx.scale(dpr, dpr);
            this.ctx.lineWidth = 2.5;
            this.ctx.lineCap = 'round';
            this.ctx.lineJoin = 'round';
            this.ctx.strokeStyle = '#0f172a';
        },
        handleResize() {
            if (this.hasContent) return;
            this.setupCanvas();
        },
        getPos(e) {
            const rect = this.canvas.getBoundingClientRect();
            return {
                x: e.clientX - rect.left,
                y: e.clientY - rect.top
            };
        },
        start(e) {
            if (e.button !== 0 && e.pointerType === 'mouse') return;
            this.isDrawing = true;
            this.errorMessage = '';
            try {
                if (this.canvas.setPointerCapture) {
                    this.canvas.setPointerCapture(e.pointerId);
                }
            } catch (err) {}
            const pos = this.getPos(e);
            this.ctx.beginPath();
            this.ctx.moveTo(pos.x, pos.y);
            this.lastPos = pos;
        },
        move(e) {
            if (!this.isDrawing) return;
            const pos = this.getPos(e);
            this.ctx.beginPath();
            this.ctx.moveTo(this.lastPos.x, this.lastPos.y);
            this.ctx.lineTo(pos.x, pos.y);
            this.ctx.stroke();
            this.lastPos = pos;
            this.hasContent = true;
        },
        end(e) {
            if (!this.isDrawing) return;
            this.isDrawing = false;
            try {
                if (this.canvas.hasPointerCapture && this.canvas.hasPointerCapture(e.pointerId)) {
                    this.canvas.releasePointerCapture(e.pointerId);
                }
            } catch (err) {}
        },
        clear() {
            if (!this.ctx || !this.canvas) return;
            this.ctx.save();
            this.ctx.setTransform(1, 0, 0, 1, 0, 0);
            this.ctx.clearRect(0, 0, this.canvas.width, this.canvas.height);
            this.ctx.restore();
            this.hasContent = false;
            this.errorMessage = '';
        },
        save() {
            const emptyMsg = config.emptyError || 'Please draw your signature before adding it.';
            if (!this.hasContent) {
                this.errorMessage = emptyMsg;
                return;
            }
            const imgData = this.ctx.getImageData(0, 0, this.canvas.width, this.canvas.height);
            let drawn = false;
            for (let i = 3; i < imgData.data.length; i += 4) {
                if (imgData.data[i] > 10) {
                    drawn = true;
                    break;
                }
            }
            if (!drawn) {
                this.hasContent = false;
                this.errorMessage = emptyMsg;
                return;
            }
            const dataUrl = this.canvas.toDataURL('image/png');
            const wire = this.$wire || window.Livewire?.find(this.$root.closest('[wire\\:id]')?.getAttribute('wire:id'));
            if (wire) {
                wire.setDrawData(dataUrl);
            }
            this.clear();
        }
    };
}

export function pdfPlacementItem(config = {}) {
    return {
        id: config.id,
        x: config.x || 0,
        y: config.y || 0,
        w: config.w || 30,
        h: config.h || 12,
        rot: config.rot || 0,
        isDragging: false,
        isResizing: false,
        startX: 0,
        startY: 0,
        initX: 0,
        initY: 0,
        initW: 0,
        initH: 0,
        getWire() {
            return this.$wire || window.Livewire?.find(this.$root.closest('[wire\\:id]')?.getAttribute('wire:id'));
        },
        startDrag(e) {
            if (e.target.closest('.action-control')) return;
            if (e.button !== 0 && e.pointerType === 'mouse') return;
            this.isDragging = true;
            this.startX = e.clientX;
            this.startY = e.clientY;
            this.initX = this.x;
            this.initY = this.y;
            const onMove = (ev) => {
                if (!this.isDragging) return;
                const container = document.getElementById('pdf-page-container');
                if (!container) return;
                const rect = container.getBoundingClientRect();
                const dxPct = ((ev.clientX - this.startX) / rect.width) * 100;
                const dyPct = ((ev.clientY - this.startY) / rect.height) * 100;
                this.x = Math.max(0, Math.min(100 - this.w, this.initX + dxPct));
                this.y = Math.max(0, Math.min(100 - this.h, this.initY + dyPct));
            };
            const onUp = () => {
                if (this.isDragging) {
                    this.isDragging = false;
                    const wire = this.getWire();
                    if (wire) {
                        wire.updatePlacement(this.id, this.x / 100, this.y / 100, this.w / 100, this.h / 100, this.rot);
                    }
                }
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                window.removeEventListener('pointercancel', onUp);
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onUp);
        },
        startResize(e) {
            e.stopPropagation();
            this.isResizing = true;
            this.startX = e.clientX;
            this.startY = e.clientY;
            this.initW = this.w;
            this.initH = this.h;
            const onMove = (ev) => {
                if (!this.isResizing) return;
                const container = document.getElementById('pdf-page-container');
                if (!container) return;
                const rect = container.getBoundingClientRect();
                const dxPct = ((ev.clientX - this.startX) / rect.width) * 100;
                const dyPct = ((ev.clientY - this.startY) / rect.height) * 100;
                this.w = Math.max(5, Math.min(100 - this.x, this.initW + dxPct));
                this.h = Math.max(2, Math.min(100 - this.y, this.initH + dyPct));
            };
            const onUp = () => {
                if (this.isResizing) {
                    this.isResizing = false;
                    const wire = this.getWire();
                    if (wire) {
                        wire.updatePlacement(this.id, this.x / 100, this.y / 100, this.w / 100, this.h / 100, this.rot);
                    }
                }
                window.removeEventListener('pointermove', onMove);
                window.removeEventListener('pointerup', onUp);
                window.removeEventListener('pointercancel', onUp);
            };
            window.addEventListener('pointermove', onMove);
            window.addEventListener('pointerup', onUp);
            window.addEventListener('pointercancel', onUp);
        }
    };
}
