/**
 * Client Object URL Lifecycle Manager
 * Handles instant client-side image preview creation and safe revocation to prevent memory leaks.
 * Assigns collision-safe unique identities per file selection so distinct files never share URLs.
 */

const registry = new Map();
const fileIdentityMap = new WeakMap();
let uidCounter = 0;

/**
 * Generate a collision-safe unique identity for a File or Blob instance.
 *
 * @param {File|Blob} file
 * @returns {string}
 */
export function getFileKey(file) {
    if (!file || typeof file !== 'object') return '';

    if (fileIdentityMap.has(file)) {
        return fileIdentityMap.get(file);
    }

    if (file._previewUid) {
        return file._previewUid;
    }

    const uid = `f_${Date.now()}_${++uidCounter}_${Math.random().toString(36).slice(2, 9)}`;
    fileIdentityMap.set(file, uid);

    try {
        Object.defineProperty(file, '_previewUid', {
            value: uid,
            writable: false,
            enumerable: false,
            configurable: true,
        });
    } catch {
        // Ignored if object is frozen
    }

    return uid;
}

/**
 * Check if a file can be rendered directly by browser <img> tag.
 *
 * @param {File|Blob} file
 * @returns {boolean}
 */
export function canPreviewLocally(file) {
    if (!file || typeof file !== 'object') return false;
    const type = (file.type || '').toLowerCase();
    const name = (file.name || '').toLowerCase();

    // Standard native formats supported across all modern browsers
    const nativeMimes = [
        'image/jpeg',
        'image/png',
        'image/webp',
        'image/gif',
        'image/bmp',
        'image/svg+xml',
    ];

    if (nativeMimes.includes(type)) return true;

    // Extension fallback if mime is generic octet-stream
    const nativeExtensions = ['.jpg', '.jpeg', '.png', '.webp', '.gif', '.bmp', '.svg'];
    return nativeExtensions.some(ext => name.endsWith(ext));
}

/**
 * Get or create an object URL for a given File/Blob.
 *
 * @param {File|Blob} file
 * @returns {string|null}
 */
export function getPreviewUrl(file) {
    if (!file || !canPreviewLocally(file)) return null;
    if (typeof window === 'undefined' || !window.URL || !window.URL.createObjectURL) return null;

    const key = getFileKey(file);
    if (!key) return null;

    if (registry.has(key)) {
        return registry.get(key);
    }

    try {
        const url = window.URL.createObjectURL(file);
        registry.set(key, url);
        return url;
    } catch (e) {
        console.warn('previewUrlManager: Failed to create object URL', e);
        return null;
    }
}

/**
 * Revoke an object URL for a specific file or key. Safe and idempotent.
 *
 * @param {File|Blob|string} fileOrKey
 */
export function revokePreviewUrl(fileOrKey) {
    if (!fileOrKey) return;
    const key = typeof fileOrKey === 'string' ? fileOrKey : getFileKey(fileOrKey);
    if (!key) return;

    if (registry.has(key)) {
        const url = registry.get(key);
        try {
            if (typeof window !== 'undefined' && window.URL && window.URL.revokeObjectURL) {
                window.URL.revokeObjectURL(url);
            }
        } catch {
            // Ignored
        }
        registry.delete(key);
    }
}

/**
 * Revoke all active object URLs in the registry. Safe and idempotent.
 */
export function revokeAllPreviewUrls() {
    if (typeof window !== 'undefined' && window.URL && window.URL.revokeObjectURL) {
        for (const url of registry.values()) {
            try {
                window.URL.revokeObjectURL(url);
            } catch {
                // Ignored
            }
        }
    }
    registry.clear();
}

/**
 * Get count of active object URLs in registry (useful for testing and monitoring).
 *
 * @returns {number}
 */
export function getActivePreviewCount() {
    return registry.size;
}

/**
 * Alpine component helper factory for managing component-scoped preview URLs.
 * Automatically cleans up object URLs on component destroy. Safe and idempotent.
 */
export function previewUrlManager() {
    return {
        _urls: new Map(),

        getPreview(file) {
            if (!file) return null;
            const key = getFileKey(file);
            if (!key) return null;

            if (this._urls.has(key)) {
                return this._urls.get(key);
            }
            const url = getPreviewUrl(file);
            if (url) {
                this._urls.set(key, url);
            }
            return url;
        },

        revoke(file) {
            if (!file) return;
            const key = typeof file === 'string' ? file : getFileKey(file);
            if (!key) return;

            if (this._urls.has(key)) {
                revokePreviewUrl(key);
                this._urls.delete(key);
            } else {
                revokePreviewUrl(key);
            }
        },

        clearAll() {
            for (const key of this._urls.keys()) {
                revokePreviewUrl(key);
            }
            this._urls.clear();
        },

        destroy() {
            this.clearAll();
        }
    };
}
