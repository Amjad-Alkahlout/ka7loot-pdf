import test from 'node:test';
import assert from 'node:assert/strict';
import { pdfProgressivePreview, pdfInspectModal } from '../../resources/js/pdf-progressive-preview.js';

test('pdfProgressivePreview initial state is not loaded', () => {
    const preview = pdfProgressivePreview({ url: '/test-page.jpg', page: 2 });
    assert.equal(preview.url, '/test-page.jpg');
    assert.equal(preview.page, 2);
    assert.equal(preview.loaded, false);
    assert.equal(preview.loading, false);
    assert.equal(preview.error, false);
    assert.equal(preview.retries, 0);
});

test('pdfProgressivePreview handles missing URL gracefully', () => {
    const preview = pdfProgressivePreview({});
    preview.init();
    assert.equal(preview.loaded, false);
    assert.equal(preview.loading, false);
});

test('pdfProgressivePreview uses IntersectionObserver when available', () => {
    let observedElement = null;
    let observerOptions = null;
    let observerCallback = null;

    globalThis.window = {
        IntersectionObserver: class {
            constructor(callback, options) {
                observerCallback = callback;
                observerOptions = options;
            }
            observe(el) {
                observedElement = el;
            }
            disconnect() {
                observedElement = null;
            }
        }
    };

    const preview = pdfProgressivePreview({ url: '/test-page.jpg', page: 1 });
    const mockEl = { id: 'mock-card' };
    preview.$el = mockEl;
    preview.$nextTick = (cb) => cb();

    preview.init();

    assert.equal(observedElement, mockEl);
    assert.equal(observerOptions.rootMargin, '200px 0px 200px 0px');

    delete globalThis.window;
});

test('pdfProgressivePreview triggers load on intersection', () => {
    let observerCallback = null;

    globalThis.window = {
        IntersectionObserver: class {
            constructor(callback) {
                observerCallback = callback;
            }
            observe() {}
            disconnect() {}
        }
    };

    let imageInstantiated = false;
    let imageSrc = null;

    globalThis.Image = class {
        constructor() {
            imageInstantiated = true;
        }
        set src(value) {
            imageSrc = value;
            if (this.onload) this.onload();
        }
    };

    const preview = pdfProgressivePreview({ url: '/test-page.jpg', page: 1 });
    preview.$el = {};
    preview.$nextTick = (cb) => cb();

    preview.init();
    assert.equal(preview.loaded, false);

    // Simulate viewport intersection
    observerCallback([{ isIntersecting: true }]);

    assert.equal(imageInstantiated, true);
    assert.equal(imageSrc, '/test-page.jpg');
    assert.equal(preview.loaded, true);
    assert.equal(preview.loading, false);
    assert.equal(preview.error, false);

    delete globalThis.window;
    delete globalThis.Image;
});

test('pdfProgressivePreview handles image load error and max retries', () => {
    globalThis.Image = class {
        set src(value) {
            if (this.onerror) this.onerror();
        }
    };

    const preview = pdfProgressivePreview({ url: '/failing-page.jpg', page: 1 });
    preview.maxRetries = 0; // Test immediate error
    preview.load();

    assert.equal(preview.loaded, false);
    assert.equal(preview.loading, false);
    assert.equal(preview.error, true);

    // Retry resets
    preview.retry();
    assert.equal(preview.error, true); // Still errors with mock
    assert.equal(preview.retries, 0);

    delete globalThis.Image;
});

test('pdfProgressivePreview refreshes an expired signed URL before retrying', async () => {
    const requestedUrls = [];
    globalThis.Image = class {
        set src(value) {
            requestedUrls.push(value);
            if (requestedUrls.length === 1) {
                this.onerror?.();
            } else {
                this.onload?.();
            }
        }
    };

    const preview = pdfProgressivePreview({
        url: '/preview?expires=1&signature=expired',
        refreshUrl: async () => '/preview?expires=9999999999&signature=fresh',
    });

    preview.load();
    await new Promise(resolve => setImmediate(resolve));

    assert.deepEqual(requestedUrls, [
        '/preview?expires=1&signature=expired',
        '/preview?expires=9999999999&signature=fresh',
    ]);
    assert.equal(preview.url, '/preview?expires=9999999999&signature=fresh');
    assert.equal(preview.loaded, true);
    assert.equal(preview.error, false);

    delete globalThis.Image;
});

test('pdfInspectModal controls zoom, open, and close correctly', () => {
    let inspectionImage;
    globalThis.Image = class {
        constructor() { inspectionImage = this; }
        set src(v) { this._src = v; }
    };

    const modal = pdfInspectModal();
    assert.equal(modal.isOpen, false);
    assert.equal(modal.zoom, 1);

    modal.open(3, '/highres-page-3.jpg');
    assert.equal(modal.isOpen, true);
    assert.equal(modal.page, 3);
    assert.equal(modal.url, '/highres-page-3.jpg');
    assert.equal(modal.loading, true);
    assert.equal(modal.imageLoaded, false);
    assert.equal(modal.error, false);
    // Successful high-resolution image response clears the loading state.
    // (The endpoint returns JPEG bytes; this modal does not use PDF.js/canvas.)
    inspectionImage.onload();
    assert.equal(modal.imageLoaded, true);
    assert.equal(modal.loading, false);

    modal.zoomIn();
    assert.equal(modal.zoom, 1.25);

    modal.zoomIn();
    assert.equal(modal.zoom, 1.5);

    modal.zoomOut();
    assert.equal(modal.zoom, 1.25);

    modal.resetZoom();
    assert.equal(modal.zoom, 1);

    modal.close();
    assert.equal(modal.isOpen, false);
    assert.equal(modal.url, '');

    delete globalThis.Image;
});

test('pdfInspectModal shows an actionable error and clears loading when preview fails', () => {
    globalThis.Image = class {
        set src(value) {
            if (value && this.onerror) this.onerror(new Error('HTTP 403'));
        }
    };

    const modal = pdfInspectModal();
    modal.open(1, '/tools/pdf-preview?expires=expired');

    assert.equal(modal.loading, false);
    assert.equal(modal.imageLoaded, false);
    assert.equal(modal.error, true);
    assert.match(modal.errorMessage, /expired|unavailable/i);
    assert.equal(modal.isOpen, true);

    delete globalThis.Image;
});

test('pdfInspectModal handles missing URL and image construction failure visibly', () => {
    const modal = pdfInspectModal();
    modal.open(1, '');
    assert.equal(modal.loading, false);
    assert.equal(modal.error, true);
    assert.match(modal.errorMessage, /URL/i);

    globalThis.Image = class { constructor() { throw new Error('image unavailable'); } };
    modal.open(2, '/invalid-response');
    assert.equal(modal.loading, false);
    assert.equal(modal.error, true);
    assert.equal(modal.imageLoaded, false);

    globalThis.Image = class {
        set src(value) {
            if (value) throw new Error('invalid image response');
        }
    };
    modal.open(3, '/unreadable-image');
    assert.equal(modal.loading, false);
    assert.equal(modal.error, true, 'decode/render setup failures use the visible error state');
    assert.equal(modal.imageLoaded, false);

    delete globalThis.Image;
});

test('pdfInspectModal ignores stale image callbacks after reopening or switching files', () => {
    const images = [];
    globalThis.Image = class {
        constructor() { images.push(this); }
        set src(value) { this._src = value; }
        get src() { return this._src; }
    };

    const modal = pdfInspectModal();
    modal.open(1, '/file-a-page-1.jpg', 'file-a.pdf');
    const oldImage = images[0];
    modal.open(2, '/file-b-page-2.jpg', 'file-b.pdf');
    const currentImage = images[1];

    assert.equal(oldImage.onload, null, 'old image handlers are cancelled');
    currentImage.onload();
    assert.equal(modal.title, 'file-b.pdf');
    assert.equal(modal.page, 2);
    assert.equal(modal.imageLoaded, true);
    assert.equal(modal.loading, false);

    modal.open(3, '/file-a-page-3.jpg', 'file-a.pdf');
    const reopenedImage = images[2];
    reopenedImage.onerror();
    assert.equal(modal.error, true);
    assert.equal(modal.loading, false);

    delete globalThis.Image;
});

test('pdfInspectModal closing during an active load cancels callbacks and resets state', () => {
    let activeImage;
    globalThis.Image = class {
        constructor() { activeImage = this; }
        set src(value) { this._src = value; }
        get src() { return this._src; }
    };

    const modal = pdfInspectModal();
    modal.open(4, '/slow-page.jpg');
    modal.close();

    assert.equal(modal.isOpen, false);
    assert.equal(modal.loading, false);
    assert.equal(modal.error, false);
    assert.equal(activeImage.onload, null);
    assert.equal(activeImage.onerror, null);

    delete globalThis.Image;
});

test('pdfInspectModal handles each global open event once and removes listeners on destroy', () => {
    const listeners = new Map();
    globalThis.window = {
        addEventListener(name, callback) {
            const callbacks = listeners.get(name) || new Set();
            callbacks.add(callback);
            listeners.set(name, callbacks);
        },
        removeEventListener(name, callback) {
            listeners.get(name)?.delete(callback);
        },
    };

    const modal = pdfInspectModal();
    let openCalls = 0;
    modal.open = () => { openCalls++; };
    modal.init();
    modal.init(); // Repeated initialization must not register duplicates.

    for (const callback of listeners.get('open-pdf-inspect')) {
        callback({ detail: { page: 2, url: '/page.jpg' } });
    }
    assert.equal(openCalls, 1);

    modal.destroy();
    assert.equal(listeners.get('open-pdf-inspect').size, 0);
    assert.equal(listeners.get('open-pdf-modal').size, 0);

    modal.init();
    for (const callback of listeners.get('open-pdf-inspect')) {
        callback({ detail: { page: 2, url: '/page.jpg' } });
    }
    assert.equal(openCalls, 2);
    modal.destroy();

    delete globalThis.window;
});
