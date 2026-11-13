import test from 'node:test';
import assert from 'node:assert/strict';
import { fileUploadHandler } from '../../resources/js/upload-progress.js';

function createMockFile(name, size, type = 'application/pdf') {
    return {
        name,
        size,
        type,
    };
}

function createMockEvent(files) {
    let stopped = false;
    let prevented = false;
    const target = {
        files: [...files],
        value: 'C:\\fakepath\\file.pdf',
    };

    return {
        target,
        stopImmediatePropagation() {
            stopped = true;
        },
        preventDefault() {
            prevented = true;
        },
        isStopped() {
            return stopped;
        },
        isPrevented() {
            return prevented;
        }
    };
}

test('oversized PDF is rejected before upload in English', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'application/pdf',
        fileType: 'pdf',
        maxSizeMb: 50,
        locale: 'en',
    });

    const file = createMockFile('large.pdf', 55 * 1024 * 1024, 'application/pdf');
    const event = createMockEvent([file]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true, 'Upload event must be stopped');
    assert.equal(event.isPrevented(), true, 'Default action must be prevented');
    assert.equal(event.target.value, '', 'File input value must be cleared');
    assert.equal(handler.uploadState, 'idle', 'Upload state must remain idle');
    assert.equal(handler.errorMessage, 'The file exceeds the maximum allowed size of 50 MB.');
    assert.equal(handler.rejectedFiles.length, 1);
    assert.equal(handler.rejectedFiles[0].name, 'large.pdf');
});

test('oversized PDF is rejected before upload in Arabic', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'application/pdf',
        fileType: 'pdf',
        maxSizeMb: 50,
        locale: 'ar',
    });

    const file = createMockFile('large_ar.pdf', 60 * 1024 * 1024, 'application/pdf');
    const event = createMockEvent([file]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true, 'Upload event must be stopped');
    assert.equal(event.target.value, '', 'File input value must be cleared');
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.errorMessage, 'حجم الملف يتجاوز الحد المسموح وهو 50 ميجابايت.');
    assert.equal(handler.rejectedFiles[0].name, 'large_ar.pdf');
});

test('oversized image is rejected before upload in English', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'image/jpeg,image/png,image/webp',
        fileType: 'image',
        maxSizeMb: 25,
        locale: 'en',
    });

    const file = createMockFile('photo.jpg', 30 * 1024 * 1024, 'image/jpeg');
    const event = createMockEvent([file]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true, 'Upload event must be stopped');
    assert.equal(event.target.value, '', 'File input value must be cleared');
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.errorMessage, 'The image exceeds the maximum allowed size of 25 MB.');
    assert.equal(handler.rejectedFiles.length, 1);
});

test('oversized image is rejected before upload in Arabic', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'image/jpeg,image/png,image/webp',
        fileType: 'image',
        maxSizeMb: 25,
        locale: 'ar',
    });

    const file = createMockFile('photo_ar.png', 28 * 1024 * 1024, 'image/png');
    const event = createMockEvent([file]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true, 'Upload event must be stopped');
    assert.equal(event.target.value, '', 'File input value must be cleared');
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.errorMessage, 'حجم الصورة يتجاوز الحد المسموح وهو 25 ميجابايت.');
    assert.equal(handler.rejectedFiles.length, 1);
});

test('valid PDF proceeds to upload without stopping event', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'application/pdf',
        fileType: 'pdf',
        maxSizeMb: 50,
        locale: 'en',
    });

    const file = createMockFile('valid.pdf', 10 * 1024 * 1024, 'application/pdf');
    const event = createMockEvent([file]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), false, 'Valid PDF should not stop event propagation');
    assert.equal(event.isPrevented(), false, 'Valid PDF should not prevent default');
    assert.equal(handler.errorMessage, null);
    assert.equal(handler.rejectedFiles.length, 0);
    assert.equal(handler.uploadState, 'selected');
    assert.equal(handler.fileCount, 1);
    assert.equal(handler.fileName, 'valid.pdf');
});

test('valid image proceeds to upload without stopping event', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'image/jpeg,image/png,image/webp',
        fileType: 'image',
        maxSizeMb: 25,
        locale: 'en',
    });

    const file = createMockFile('valid.png', 5 * 1024 * 1024, 'image/png');
    const event = createMockEvent([file]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), false, 'Valid image should not stop event propagation');
    assert.equal(handler.errorMessage, null);
    assert.equal(handler.rejectedFiles.length, 0);
    assert.equal(handler.uploadState, 'selected');
    assert.equal(handler.fileCount, 1);
    assert.equal(handler.fileName, 'valid.png');
});

test('multi-file selection rejects oversized files individually', () => {
    // Mock DataTransfer if not present in Node environment
    if (typeof globalThis.DataTransfer === 'undefined') {
        globalThis.DataTransfer = class {
            constructor() {
                this.items = {
                    files: [],
                    add(file) {
                        this.files.push(file);
                    }
                };
            }
            get files() {
                return this.items.files;
            }
        };
    }

    const handler = fileUploadHandler({
        propertyName: 'uploads',
        multiple: true,
        accept: 'application/pdf',
        fileType: 'pdf',
        maxSizeMb: 50,
        maxBatchFiles: 20,
        locale: 'en',
    });

    const valid1 = createMockFile('valid1.pdf', 5 * 1024 * 1024, 'application/pdf');
    const oversized = createMockFile('huge.pdf', 70 * 1024 * 1024, 'application/pdf');
    const valid2 = createMockFile('valid2.pdf', 8 * 1024 * 1024, 'application/pdf');

    const event = createMockEvent([valid1, oversized, valid2]);

    handler.handleFileSelect(event);

    // Because valid files remain, the event is allowed to continue for the valid files
    assert.equal(event.isStopped(), false, 'Should allow remaining valid files to proceed');
    assert.equal(handler.rejectedFiles.length, 1);
    assert.equal(handler.rejectedFiles[0].name, 'huge.pdf');
    assert.equal(handler.rejectedFiles[0].error, 'The file exceeds the maximum allowed size of 50 MB.');
    assert.equal(event.target.files.length, 2, 'Input files must only contain the valid files');
    assert.equal(event.target.files[0].name, 'valid1.pdf');
    assert.equal(event.target.files[1].name, 'valid2.pdf');
    assert.equal(handler.fileCount, 2);
});

test('multi-file selection where all files are oversized stops upload completely', () => {
    const handler = fileUploadHandler({
        propertyName: 'uploads',
        multiple: true,
        accept: 'application/pdf',
        fileType: 'pdf',
        maxSizeMb: 50,
        locale: 'en',
    });

    const oversized1 = createMockFile('huge1.pdf', 60 * 1024 * 1024, 'application/pdf');
    const oversized2 = createMockFile('huge2.pdf', 80 * 1024 * 1024, 'application/pdf');

    const event = createMockEvent([oversized1, oversized2]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true, 'Should stop upload completely when all files are invalid');
    assert.equal(event.target.value, '');
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.rejectedFiles.length, 2);
    assert.equal(handler.errorMessage, 'The file exceeds the maximum allowed size of 50 MB.');
});

test('zero-byte files are rejected', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'application/pdf',
        fileType: 'pdf',
        locale: 'en',
    });

    const emptyFile = createMockFile('empty.pdf', 0, 'application/pdf');
    const event = createMockEvent([emptyFile]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true);
    assert.equal(handler.errorMessage, 'The selected file is empty (0 bytes).');
});

test('unsupported file extension is rejected', () => {
    const handler = fileUploadHandler({
        propertyName: 'upload',
        multiple: false,
        accept: 'application/pdf',
        fileType: 'pdf',
        locale: 'en',
    });

    const exeFile = createMockFile('malicious.exe', 1024, 'application/x-msdownload');
    const event = createMockEvent([exeFile]);

    handler.handleFileSelect(event);

    assert.equal(event.isStopped(), true);
    assert.equal(handler.errorMessage, 'Unsupported file format.');
});

test('queued batches upload sequentially and isolate progress by batch', () => {
    const calls = [];
    const handler = fileUploadHandler({
        propertyName: 'newFiles',
        multiple: true,
        accept: '.heic,.heif',
        fileType: 'image',
        queueBatches: true,
        locale: 'en',
    });
    handler.$wire = {
        uploadMultiple(property, files, finish, error, progress) {
            calls.push({ property, files, finish, error, progress });
        },
    };

    const first = createMockEvent([
        createMockFile('IMG_7995.HEIC', 2 * 1024 * 1024, 'image/heic'),
    ]);
    handler.handleFileSelect(first);

    const second = createMockEvent([
        createMockFile('IMG_7996.HEIC', 2 * 1024 * 1024, 'image/heic'),
        createMockFile('IMG_7997.HEIC', 2 * 1024 * 1024, 'image/heic'),
    ]);
    handler.handleFileSelect(second);

    assert.equal(first.isStopped(), true);
    assert.equal(second.isStopped(), true);
    assert.equal(calls.length, 1);
    assert.equal(calls[0].files.length, 1);
    calls[0].progress({ detail: { progress: 50 } });
    assert.equal(handler.progress, 50);
    calls[0].finish();
    assert.equal(calls.length, 2);
    assert.equal(calls[1].files.length, 2);
    assert.equal(handler.progress, 0);
    calls[0].progress({ detail: { progress: 100 } });
    assert.equal(handler.progress, 0);
    calls[1].progress({ detail: { progress: 75 } });
    assert.equal(handler.progress, 75);
    calls[1].finish();
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.activeBatch, null);
    assert.equal(handler.uploadQueue.length, 0);
});

test('batch 2 selected after initial batch completes advances batch counter and completes', () => {
    const calls = [];
    const handler = fileUploadHandler({
        propertyName: 'newFiles',
        multiple: true,
        accept: '.heic,.heif',
        fileType: 'image',
        queueBatches: true,
        locale: 'en',
    });
    handler.$wire = {
        uploadMultiple(property, files, finish, error, progress) {
            calls.push({ property, files, finish, error, progress });
        },
    };

    // 1. First batch of 5 files uploaded from large dropzone
    const batch1Files = [
        createMockFile('IMG_1.HEIC', 1024, 'image/heic'),
        createMockFile('IMG_2.HEIC', 1024, 'image/heic'),
        createMockFile('IMG_3.HEIC', 1024, 'image/heic'),
        createMockFile('IMG_4.HEIC', 1024, 'image/heic'),
        createMockFile('IMG_5.HEIC', 1024, 'image/heic'),
    ];
    handler.handleFileSelect(createMockEvent(batch1Files));

    assert.equal(handler.activeBatchId, 1);
    assert.equal(handler.activeBatch, 1);
    assert.equal(handler.uploadState, 'uploading');
    assert.equal(calls.length, 1);

    // Complete batch 1
    calls[0].progress({ detail: { progress: 100 } });
    calls[0].finish();

    assert.equal(handler.uploadState, 'idle', 'Upload state resets to idle so compact bar can accept next batch');
    assert.equal(handler.activeBatch, null);

    // 2. Second batch of 3 files selected from compact bar
    const batch2Files = [
        createMockFile('IMG_6.HEIC', 1024, 'image/heic'),
        createMockFile('IMG_7.HEIC', 1024, 'image/heic'),
        createMockFile('IMG_8.HEIC', 1024, 'image/heic'),
    ];
    handler.handleFileSelect(createMockEvent(batch2Files));

    assert.equal(calls.length, 2, 'Second batch must trigger uploadMultiple');
    assert.equal(handler.activeBatchId, 2, 'Active batch counter must increment to 2');
    assert.equal(handler.activeBatch, 2);
    assert.equal(handler.uploadState, 'uploading');

    calls[1].progress({ detail: { progress: 45 } });
    assert.equal(handler.progress, 45);

    calls[1].finish();
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.activeBatch, null);
    assert.equal(handler.uploadQueue.length, 0);
});

test('sequential thumbnail queue pauses during active upload and resumes on finish', () => {
    class MockImage {
        constructor() {
            this._src = '';
            setTimeout(() => {
                if (this._src) this.onload?.();
            }, 5);
        }
        set src(v) {
            this._src = v;
        }
        get src() {
            return this._src;
        }
    }
    globalThis.Image = MockImage;

    const handler = fileUploadHandler({
        propertyName: 'newFiles',
        multiple: true,
        accept: '.heic,.heif',
        fileType: 'image',
        queueBatches: true,
        locale: 'en',
    });

    // Simulate active batch uploading
    handler.uploadState = 'uploading';
    handler.activeBatch = 1;

    let loadedCount = 0;
    handler.enqueueThumbnail('/preview/1.jpg', () => { loadedCount++; });
    handler.enqueueThumbnail('/preview/2.jpg', () => { loadedCount++; });

    // While uploading is active, thumbnail processing must NOT start
    assert.equal(handler.activeThumbnails, 0, 'Thumbnails must not start while upload is in-flight');
    assert.equal(handler.thumbnailQueue.length, 2);

    // Upload finishes
    handler.uploadState = 'idle';
    handler.activeBatch = null;
    handler.processThumbnailQueue();

    assert.equal(handler.activeThumbnails, 1, 'Only 1 thumbnail loads at a time');
});

test('batch failure or timeout enters error state and can be retried', () => {
    const calls = [];
    const handler = fileUploadHandler({
        propertyName: 'newFiles',
        multiple: true,
        accept: '.heic,.heif',
        fileType: 'image',
        queueBatches: true,
        locale: 'en',
    });
    handler.$wire = {
        uploadMultiple(property, files, finish, error, progress) {
            calls.push({ property, files, finish, error, progress });
        },
    };

    const files = [createMockFile('failed.heic', 1024, 'image/heic')];
    handler.handleFileSelect(createMockEvent(files));

    assert.equal(handler.activeBatch, 1);
    assert.equal(calls.length, 1);

    // Simulate error callback
    calls[0].error({ message: 'Network disconnected' });

    assert.equal(handler.uploadState, 'error');
    assert.equal(handler.errorMessage, 'Network disconnected');
    assert.notEqual(handler.failedBatch, null);
    assert.equal(handler.activeBatch, null);

    // Retry failed batch
    handler.retryFailedBatch();

    assert.equal(calls.length, 2, 'Retry must start a new upload call');
    assert.equal(handler.uploadState, 'uploading');
    assert.equal(handler.errorMessage, null);

    calls[1].finish();
    assert.equal(handler.uploadState, 'idle');
    assert.equal(handler.activeBatch, null);
});

test('compact add-more control stays visible after a non-queued upload completes', () => {
    const handler = fileUploadHandler({
        propertyName: 'newFiles',
        multiple: true,
        compact: true,
    });

    assert.equal(handler.showUploadControl(), true);
    handler.onUploadFinish({ detail: { property: 'newFiles' } });
    assert.equal(handler.uploadState, 'uploaded');
    assert.equal(handler.showUploadControl(), true);

    handler.prepareUpload([{ name: 'next.png', size: 10 }]);
    assert.equal(handler.showUploadControl(), true);
});
