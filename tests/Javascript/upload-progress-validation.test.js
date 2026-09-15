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
