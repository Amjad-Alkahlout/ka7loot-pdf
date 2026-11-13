/**
 * Progressive PDF Page Preview & High-Resolution Inspection
 *
 * Provides lazy loading with IntersectionObserver, retry backoff,
 * adjacent page prefetching, and an on-demand high-resolution inspection modal.
 */

function signedUrlNeedsRefresh(url) {
    if (!url || typeof URL === 'undefined') return false;

    try {
        const expires = Number(new URL(url, window.location?.href).searchParams.get('expires'));
        return Number.isFinite(expires) && expires > 0 && expires * 1000 <= Date.now() + 30000;
    } catch {
        return false;
    }
}

export function pdfProgressivePreview(config = {}) {
    const isString = typeof config === 'string';
    const url = isString ? config : (config?.url || '');
    const highResUrl = isString ? '' : (config?.highResUrl || '');
    const page = isString ? 1 : (config?.page || 1);
    const refreshUrl = isString ? null : config?.refreshUrl;
    const refreshHighResUrl = isString ? null : config?.refreshHighResUrl;

    return {
        url: url,
        highResUrl: highResUrl,
        refreshUrl,
        refreshHighResUrl,
        page: page,
        loaded: false,
        loading: false,
        error: false,
        retries: 0,
        maxRetries: 2,
        observer: null,
        refreshAttempted: false,

        init() {
            if (!this.url) {
                return;
            }

            if (typeof window === 'undefined' || typeof window.IntersectionObserver === 'undefined') {
                this.load();
                return;
            }

            this.observer = new window.IntersectionObserver((entries) => {
                entries.forEach((entry) => {
                    if (entry.isIntersecting) {
                        this.load();
                        if (this.observer) {
                            this.observer.disconnect();
                            this.observer = null;
                        }
                    }
                });
            }, {
                root: null,
                rootMargin: '200px 0px 200px 0px',
                threshold: 0.01,
            });

            this.$nextTick(() => {
                if (this.$el && this.observer) {
                    this.observer.observe(this.$el);
                }
            });
        },

        load() {
            if (this.loaded || this.loading || !this.url) {
                return;
            }

            this.loading = true;
            this.error = false;

            const img = new Image();

            img.onload = () => {
                this.loaded = true;
                this.loading = false;
                this.error = false;

                if (typeof this.$dispatch === 'function') {
                    this.$dispatch('page-preview-loaded', { page: this.page });
                }
            };

            img.onerror = () => {
                if (!this.refreshAttempted && typeof this.refreshUrl === 'function') {
                    this.refreshAttempted = true;
                    Promise.resolve(this.refreshUrl())
                        .then((freshUrl) => {
                            if (typeof freshUrl === 'string' && freshUrl && freshUrl !== this.url) {
                                this.url = freshUrl;
                                this.retries = 0;
                                this.loading = false;
                                this.error = false;
                                this.load();
                                return;
                            }
                            this.retryAfterLoadError();
                        })
                        .catch(() => this.retryAfterLoadError());
                    return;
                }

                this.retryAfterLoadError();
            };

            img.src = this.url;
        },

        retryAfterLoadError() {
            if (this.retries < this.maxRetries) {
                this.retries++;
                setTimeout(() => {
                    this.loading = false;
                    this.load();
                }, 500 * this.retries);
            } else {
                this.loading = false;
                this.error = true;
            }
        },

        retry() {
            this.retries = 0;
            this.refreshAttempted = false;
            this.load();
        },

        async openInspect() {
            let inspectUrl = this.highResUrl || this.url;
            if (typeof this.refreshHighResUrl === 'function' && signedUrlNeedsRefresh(inspectUrl)) {
                try {
                    const freshUrl = await this.refreshHighResUrl();
                    if (typeof freshUrl === 'string' && freshUrl) {
                        this.highResUrl = freshUrl;
                        inspectUrl = freshUrl;
                    }
                } catch {
                    // Use the already-rendered preview URL if the refresh request fails.
                }
            }

            if (!inspectUrl) return;

            window.dispatchEvent(new CustomEvent('open-pdf-inspect', {
                detail: {
                    page: this.page,
                    url: inspectUrl,
                }
            }));
        },

        destroy() {
            if (this.observer) {
                this.observer.disconnect();
                this.observer = null;
            }
        }
    };
}

export function pdfInspectModal() {
    return {
        isOpen: false,
        page: 1,
        url: '',
        title: '',
        zoom: 1,
        imageLoaded: false,
        loading: false,
        error: false,
        errorMessage: '',
        inspectImage: null,
        loadGeneration: 0,
        openEventHandler: null,

        init() {
            if (typeof window === 'undefined' || this.openEventHandler) {
                return;
            }

            this.openEventHandler = (e) => {
                if (e.detail) {
                    this.open(e.detail.page, e.detail.url, e.detail.title);
                }
            };
            window.addEventListener('open-pdf-inspect', this.openEventHandler);
            window.addEventListener('open-pdf-modal', this.openEventHandler);
        },

        open(page, url, title = '') {
            this.cancelActiveLoad();
            const generation = ++this.loadGeneration;
            this.page = page || 1;
            this.url = url || '';
            this.title = title || '';
            this.zoom = 1;
            this.imageLoaded = false;
            this.loading = Boolean(this.url);
            this.error = !this.url;
            this.errorMessage = this.url ? '' : 'No preview URL is available. Reopen the inspection or try again.';
            this.isOpen = true;

            if (!this.url) return;

            let img;
            try {
                img = new Image();
            } catch {
                this.loading = false;
                this.error = true;
                this.errorMessage = 'The PDF page preview could not be loaded. Close this window and try again.';
                return;
            }
            this.inspectImage = img;
            img.onload = () => {
                if (generation !== this.loadGeneration || !this.isOpen) return;
                this.inspectImage = null;
                this.imageLoaded = true;
                this.loading = false;
                this.error = false;
            };
            img.onerror = () => {
                if (generation !== this.loadGeneration || !this.isOpen) return;
                this.inspectImage = null;
                this.imageLoaded = false;
                this.loading = false;
                this.error = true;
                this.errorMessage = 'The PDF page preview could not be loaded. The link may have expired, or the PDF may be unavailable. Close this window and try again.';
            };
            try {
                img.src = this.url;
            } catch {
                img.onerror?.();
            }
        },

        close() {
            this.cancelActiveLoad();
            this.loadGeneration++;
            this.isOpen = false;
            this.url = '';
            this.title = '';
            this.zoom = 1;
            this.imageLoaded = false;
            this.loading = false;
            this.error = false;
            this.errorMessage = '';
        },

        cancelActiveLoad() {
            if (!this.inspectImage) return;
            this.inspectImage.onload = null;
            this.inspectImage.onerror = null;
            this.inspectImage.src = '';
            this.inspectImage = null;
        },

        zoomIn() {
            this.zoom = Math.min(2.5, +(this.zoom + 0.25).toFixed(2));
        },

        zoomOut() {
            this.zoom = Math.max(0.5, +(this.zoom - 0.25).toFixed(2));
        },

        resetZoom() {
            this.zoom = 1;
        },

        destroy() {
            this.cancelActiveLoad();
            this.loadGeneration++;
            if (typeof window !== 'undefined' && this.openEventHandler) {
                window.removeEventListener('open-pdf-inspect', this.openEventHandler);
                window.removeEventListener('open-pdf-modal', this.openEventHandler);
            }
            this.openEventHandler = null;
        }
    };
}
