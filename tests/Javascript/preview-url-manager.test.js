import test from 'node:test';
import assert from 'node:assert/strict';
import {
    canPreviewLocally,
    getFileKey,
    getPreviewUrl,
    revokePreviewUrl,
    revokeAllPreviewUrls,
    getActivePreviewCount,
    previewUrlManager
} from '../../resources/js/preview-url-manager.js';

// Setup mock window and URL for node environment
const revokedUrls = [];
let nextUrlId = 1;

globalThis.window = {
    URL: {
        createObjectURL: (file) => `blob:http://localhost/mock-${nextUrlId++}-${file.name || 'blob'}`,
        revokeObjectURL: (url) => {
            revokedUrls.push(url);
        }
    }
};

test('canPreviewLocally identifies supported native browser images', () => {
    assert.equal(canPreviewLocally({ name: 'photo.jpg', type: 'image/jpeg' }), true);
    assert.equal(canPreviewLocally({ name: 'graphic.png', type: 'image/png' }), true);
    assert.equal(canPreviewLocally({ name: 'banner.webp', type: 'image/webp' }), true);
    assert.equal(canPreviewLocally({ name: 'animation.gif', type: 'image/gif' }), true);
    assert.equal(canPreviewLocally({ name: 'icon.svg', type: 'image/svg+xml' }), true);
    assert.equal(canPreviewLocally({ name: 'bitmap.bmp', type: 'image/bmp' }), true);

    // Fallback by extension when MIME is generic octet-stream
    assert.equal(canPreviewLocally({ name: 'photo.JPG', type: 'application/octet-stream' }), true);
    assert.equal(canPreviewLocally({ name: 'vector.SVG', type: '' }), true);
});

test('canPreviewLocally rejects non-browser-native formats and documents', () => {
    assert.equal(canPreviewLocally({ name: 'document.pdf', type: 'application/pdf' }), false);
    assert.equal(canPreviewLocally({ name: 'iphone.heic', type: 'image/heic' }), false);
    assert.equal(canPreviewLocally({ name: 'iphone.heif', type: 'image/heif' }), false);
    assert.equal(canPreviewLocally({ name: 'archive.zip', type: 'application/zip' }), false);
    assert.equal(canPreviewLocally(null), false);
    assert.equal(canPreviewLocally(undefined), false);
    assert.equal(canPreviewLocally('not a file'), false);
});

test('getFileKey generates stable identifier for a single file instance', () => {
    const fileA = { name: 'sample.png', size: 1024, lastModified: 1700000000 };
    const key1 = getFileKey(fileA);
    const key2 = getFileKey(fileA);
    assert.ok(key1 && typeof key1 === 'string');
    assert.equal(key1, key2);
});

test('getFileKey generates unique identities for two distinct files with identical metadata', () => {
    // Two distinct file instances that share identical name, size, and timestamp
    const file1 = { name: 'duplicate.jpg', size: 50000, lastModified: 123456789, type: 'image/jpeg' };
    const file2 = { name: 'duplicate.jpg', size: 50000, lastModified: 123456789, type: 'image/jpeg' };

    const key1 = getFileKey(file1);
    const key2 = getFileKey(file2);

    assert.notEqual(key1, key2, 'Distinct file instances must have unique keys even with identical metadata');
});

test('getPreviewUrl creates and caches object URLs', () => {
    revokeAllPreviewUrls();
    revokedUrls.length = 0;

    const file = { name: 'img1.png', size: 5000, lastModified: 100, type: 'image/png' };
    const url1 = getPreviewUrl(file);
    assert.ok(url1 && url1.startsWith('blob:'));
    assert.equal(getActivePreviewCount(), 1);

    // Repeated call returns identical cached URL
    const url2 = getPreviewUrl(file);
    assert.equal(url1, url2);
    assert.equal(getActivePreviewCount(), 1);

    // Non-previewable file returns null
    const pdfFile = { name: 'test.pdf', size: 2000, type: 'application/pdf' };
    assert.equal(getPreviewUrl(pdfFile), null);
});

test('two distinct files with identical metadata maintain URL revocation isolation', () => {
    revokeAllPreviewUrls();
    revokedUrls.length = 0;

    const fileA = { name: 'photo.jpg', size: 2048, lastModified: 555, type: 'image/jpeg' };
    const fileB = { name: 'photo.jpg', size: 2048, lastModified: 555, type: 'image/jpeg' };

    const urlA = getPreviewUrl(fileA);
    const urlB = getPreviewUrl(fileB);

    assert.ok(urlA && urlB);
    assert.notEqual(urlA, urlB, 'Distinct file selections must have separate URLs');
    assert.equal(getActivePreviewCount(), 2);

    // Revoking file A must not revoke or remove file B
    revokePreviewUrl(fileA);
    assert.equal(getActivePreviewCount(), 1);
    assert.ok(revokedUrls.includes(urlA));
    assert.ok(!revokedUrls.includes(urlB), 'File B URL must remain active when File A is revoked');

    // File B's URL is still available
    assert.equal(getPreviewUrl(fileB), urlB);

    // Revoke file B
    revokePreviewUrl(fileB);
    assert.equal(getActivePreviewCount(), 0);
    assert.ok(revokedUrls.includes(urlB));
});

test('revokePreviewUrl revokes URL and is idempotent', () => {
    revokeAllPreviewUrls();
    revokedUrls.length = 0;

    const file = { name: 'temp.jpg', size: 4000, lastModified: 200, type: 'image/jpeg' };
    const url = getPreviewUrl(file);
    assert.equal(getActivePreviewCount(), 1);

    revokePreviewUrl(file);
    assert.equal(getActivePreviewCount(), 0);
    assert.ok(revokedUrls.includes(url));

    // Calling revoke again on same file must be safe and not throw
    assert.doesNotThrow(() => {
        revokePreviewUrl(file);
        revokePreviewUrl('non_existent_key');
        revokePreviewUrl(null);
    });
});

test('revokeAllPreviewUrls cleans up all active object URLs', () => {
    revokeAllPreviewUrls();
    revokedUrls.length = 0;

    const fileA = { name: 'a.png', size: 1000, lastModified: 1, type: 'image/png' };
    const fileB = { name: 'b.jpg', size: 2000, lastModified: 2, type: 'image/jpeg' };

    const urlA = getPreviewUrl(fileA);
    const urlB = getPreviewUrl(fileB);
    assert.equal(getActivePreviewCount(), 2);

    revokeAllPreviewUrls();
    assert.equal(getActivePreviewCount(), 0);
    assert.ok(revokedUrls.includes(urlA));
    assert.ok(revokedUrls.includes(urlB));

    // Repeated call safe
    assert.doesNotThrow(() => {
        revokeAllPreviewUrls();
    });
});

test('previewUrlManager Alpine component tracks, isolates, and destroys component-scoped URLs safely', () => {
    revokeAllPreviewUrls();
    revokedUrls.length = 0;

    const manager = previewUrlManager();
    const file1 = { name: 'comp1.png', size: 1200, lastModified: 10, type: 'image/png' };
    const file2 = { name: 'comp2.png', size: 1500, lastModified: 20, type: 'image/png' };

    const url1 = manager.getPreview(file1);
    const url2 = manager.getPreview(file2);
    assert.ok(url1 && url2);
    assert.equal(manager._urls.size, 2);

    // Revoke individual file via component
    manager.revoke(file1);
    assert.equal(manager._urls.size, 1);
    assert.ok(revokedUrls.includes(url1));

    // Destroy component cleans up remaining URLs
    manager.destroy();
    assert.equal(manager._urls.size, 0);
    assert.ok(revokedUrls.includes(url2));

    // Repeated destroy is safe and idempotent
    assert.doesNotThrow(() => {
        manager.destroy();
        manager.clearAll();
    });
});
