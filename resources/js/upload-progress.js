export function fileUploadHandler(config = {}) {
    const globalLimits = (typeof window !== 'undefined' && window.fileProcessorConfig?.limits) || {};
    const fileType = config.fileType || 'pdf';

    const maxSizeMb = config.maxSizeMb || (fileType === 'image'
        ? (globalLimits.maxImageSizeMb || 25)
        : (globalLimits.maxPdfSizeMb || 50));
    const maxSizeBytes = config.maxSizeBytes || (maxSizeMb * 1024 * 1024);
    const maxBatchFiles = config.maxBatchFiles || globalLimits.maxBatchFiles || 50;
    const maxTotalBatchBytes = config.maxTotalBatchBytes || ((globalLimits.maxTotalBatchMb || 100) * 1024 * 1024);
    const maxTotalBatchMb = config.maxTotalBatchMb || globalLimits.maxTotalBatchMb || 100;

    const locale = config.locale || (typeof window !== 'undefined' && window.fileProcessorConfig?.locale) || 'en';
    const isAr = locale === 'ar';

    const defaultMessages = {
        sizeExceeded: fileType === 'image'
            ? (isAr ? `حجم الصورة يتجاوز الحد المسموح وهو ${maxSizeMb} ميجابايت.` : `The image exceeds the maximum allowed size of ${maxSizeMb} MB.`)
            : (isAr ? `حجم الملف يتجاوز الحد المسموح وهو ${maxSizeMb} ميجابايت.` : `The file exceeds the maximum allowed size of ${maxSizeMb} MB.`),
        emptyFile: isAr ? 'الملف المحدد فارغ (0 بايت).' : 'The selected file is empty (0 bytes).',
        unsupportedFormat: isAr ? 'صيغة الملف غير مدعومة.' : 'Unsupported file format.',
        tooManyFiles: isAr ? `الحد الأقصى المسموح به هو ${maxBatchFiles} ملفات لكل عملية رفع.` : `Maximum ${maxBatchFiles} files allowed per upload.`,
        batchTooLarge: isAr ? `الحجم الإجمالي للملفات يتجاوز الحد الأقصى وهو ${maxTotalBatchMb} ميجابايت.` : `Total upload size exceeds maximum limit of ${maxTotalBatchMb} MB.`,
    };

    const messages = { ...defaultMessages, ...(config.messages || {}) };

    return {
        propertyName: config.propertyName || 'upload',
        isMultiple: config.multiple || false,
        queueBatches: config.queueBatches || false,
        compact: config.compact || false,
        accept: config.accept || '',
        fileType: fileType,
        maxSizeMb: maxSizeMb,
        maxSizeBytes: maxSizeBytes,
        maxBatchFiles: maxBatchFiles,
        maxTotalBatchBytes: maxTotalBatchBytes,
        messages: messages,

        uploadState: 'idle', // 'idle' | 'selected' | 'uploading' | 'uploaded' | 'error'
        progress: 0,
        uploadedBytes: 0,
        totalBytes: 0,
        startTime: null,
        speed: 0,
        remainingSeconds: null,
        fileName: '',
        fileCount: 0,
        isDragging: false,
        errorMessage: null,
        rejectedFiles: [],
        uploadQueue: [],
        activeBatchId: 0,
        activeBatch: null,
        activeBatchFiles: null,
        failedBatch: null,
        batchTimeoutTimer: null,
        batchTimeoutMs: config.batchTimeoutMs || 45000,

        showUploadControl() {
            return this.compact || this.queueBatches || this.uploadState === 'idle';
        },

        // Sequential thumbnail loader state (concurrency limited to 1 so uploads are never blocked)
        thumbnailQueue: [],
        activeThumbnails: 0,
        maxThumbnailConcurrency: 1,
        thumbnailStatus: {},

        // Lightbox modal state
        activePreview: null,
        activeFileName: '',
        activeFileSize: '',

        validateFile(file) {
            if (!file) return null;

            // 1. Zero-byte check
            if (file.size === 0) {
                return this.messages.emptyFile;
            }

            // 2. Format / extension check
            if (this.accept) {
                const allowedPatterns = this.accept.split(',').map(s => s.trim().toLowerCase()).filter(Boolean);
                if (allowedPatterns.length > 0) {
                    const fileName = (file.name || '').toLowerCase();
                    const mime = (file.type || '').toLowerCase();
                    const ext = fileName.includes('.') ? '.' + fileName.split('.').pop() : '';

                    const isAllowed = allowedPatterns.some(pattern => {
                        if (pattern.startsWith('.')) {
                            return ext === pattern;
                        }
                        if (pattern.endsWith('/*')) {
                            const prefix = pattern.slice(0, -1);
                            return mime.startsWith(prefix);
                        }
                        return mime === pattern;
                    });

                    if (!isAllowed) {
                        return this.messages.unsupportedFormat;
                    }
                }
            }

            // 3. Size limit check
            if (file.size > this.maxSizeBytes) {
                return this.messages.sizeExceeded;
            }

            return null;
        },

        handleFileSelect(e) {
            const rawFiles = e.target.files;
            if (!rawFiles || rawFiles.length === 0) return;

            if (this.queueBatches) {
                e.preventDefault();
                e.stopImmediatePropagation();
                this.queueFiles(Array.from(rawFiles), e.target);
                return;
            }

            this.clearErrors();

            // Single-file mode
            if (!this.isMultiple) {
                const file = rawFiles[0];
                const error = this.validateFile(file);

                if (error) {
                    e.stopImmediatePropagation();
                    e.preventDefault();
                    e.target.value = '';

                    this.errorMessage = error;
                    this.rejectedFiles = [{ name: file.name, error }];
                    this.uploadState = 'idle';
                    return;
                }

                this.prepareUpload(rawFiles);
                return;
            }

            // Multiple-files mode
            const filesArray = Array.from(rawFiles);

            // Batch count check
            if (this.maxBatchFiles && filesArray.length > this.maxBatchFiles) {
                e.stopImmediatePropagation();
                e.preventDefault();
                e.target.value = '';

                this.errorMessage = this.messages.tooManyFiles;
                this.rejectedFiles = [];
                this.uploadState = 'idle';
                return;
            }

            const validDataTransfer = typeof DataTransfer !== 'undefined' ? new DataTransfer() : null;
            const rejected = [];
            let validTotalBytes = 0;

            for (const file of filesArray) {
                const err = this.validateFile(file);
                if (err) {
                    rejected.push({ name: file.name, error: err });
                } else {
                    if (validDataTransfer) {
                        validDataTransfer.items.add(file);
                    }
                    validTotalBytes += file.size;
                }
            }

            // Total batch size check on valid files
            if (this.maxTotalBatchBytes && validTotalBytes > this.maxTotalBatchBytes) {
                e.stopImmediatePropagation();
                e.preventDefault();
                e.target.value = '';

                this.errorMessage = this.messages.batchTooLarge;
                this.rejectedFiles = rejected;
                this.uploadState = 'idle';
                return;
            }

            if (rejected.length > 0) {
                this.rejectedFiles = rejected;

                if (rejected.length === filesArray.length || !validDataTransfer) {
                    // All files rejected
                    e.stopImmediatePropagation();
                    e.preventDefault();
                    e.target.value = '';

                    this.errorMessage = rejected[0].error;
                    this.uploadState = 'idle';
                    return;
                }

                // Subset of files valid: reject oversized files individually,
                // update target files with only the valid ones
                e.target.files = validDataTransfer.files;
                this.prepareUpload(validDataTransfer.files);
                return;
            }

            this.prepareUpload(rawFiles);
        },

        queueFiles(rawFiles, input) {
            this.clearErrors();
            const filesArray = this.isMultiple ? rawFiles : rawFiles.slice(0, 1);

            if (this.isMultiple && this.maxBatchFiles && filesArray.length > this.maxBatchFiles) {
                if (input) input.value = '';
                this.errorMessage = this.messages.tooManyFiles;
                this.rejectedFiles = [];
                return;
            }

            const validFiles = [];
            const rejected = [];

            for (const file of filesArray) {
                const error = this.validateFile(file);
                if (error) {
                    rejected.push({ name: file.name, error });
                } else {
                    validFiles.push(file);
                }
            }

            if (input) input.value = '';

            const validTotalBytes = validFiles.reduce((total, file) => total + file.size, 0);
            if (this.isMultiple && this.maxTotalBatchBytes && validTotalBytes > this.maxTotalBatchBytes) {
                this.rejectedFiles = rejected;
                this.errorMessage = this.messages.batchTooLarge;
                return;
            }

            if (rejected.length) {
                this.rejectedFiles = rejected;
                this.errorMessage = rejected[0].error;
            }

            if (!validFiles.length) return;

            this.uploadQueue.push(validFiles);
            this.startNextBatch();
        },

        startNextBatch() {
            if (this.activeBatch || this.uploadQueue.length === 0) return;

            const files = this.uploadQueue.shift();
            const batchId = ++this.activeBatchId;
            this.activeBatch = batchId;
            this.activeBatchFiles = files;
            this.prepareUpload(files);
            this.uploadState = 'uploading';
            this.errorMessage = null;

            if (!this.$wire || typeof this.$wire.uploadMultiple !== 'function') {
                this.activeBatch = null;
                this.activeBatchFiles = null;
                this.uploadState = 'idle';
                this.errorMessage = 'Upload is unavailable. Please try again.';
                return;
            }

            // Start timeout safeguard
            this.clearBatchTimeout();
            this.batchTimeoutTimer = setTimeout(() => {
                if (this.activeBatch === batchId) {
                    console.warn(`Upload batch ${batchId} timed out.`);
                    this.failBatch(batchId, this.messages.uploadTimedOut || 'Upload timed out. Please retry.');
                }
            }, this.batchTimeoutMs);
            if (typeof this.batchTimeoutTimer?.unref === 'function') {
                this.batchTimeoutTimer.unref();
            }

            this.$wire.uploadMultiple(
                this.propertyName,
                files,
                () => this.finishBatch(batchId),
                (err) => this.failBatch(batchId, err?.message || this.messages.uploadFailed || 'Upload failed. Please retry.'),
                (event) => this.progressBatch(batchId, event)
            );
        },

        progressBatch(batchId, event) {
            if (this.activeBatch !== batchId) return;
            const pct = typeof event === 'number' ? event : event?.detail?.progress ?? event?.progress;
            this.applyUploadProgress(pct);

            // Refresh timeout timer as long as progress is advancing
            if (this.batchTimeoutTimer) {
                clearTimeout(this.batchTimeoutTimer);
                this.batchTimeoutTimer = setTimeout(() => {
                    if (this.activeBatch === batchId) {
                        this.failBatch(batchId, this.messages.uploadTimedOut || 'Upload timed out. Please retry.');
                    }
                }, this.batchTimeoutMs);
                if (typeof this.batchTimeoutTimer?.unref === 'function') {
                    this.batchTimeoutTimer.unref();
                }
            }
        },

        finishBatch(batchId) {
            if (this.activeBatch !== batchId) return;
            this.clearBatchTimeout();
            this.applyUploadFinish();
            this.activeBatch = null;
            this.activeBatchFiles = null;
            this.failedBatch = null;

            if (this.uploadQueue.length > 0) {
                this.startNextBatch();
            } else {
                // All queued batches completed: return to idle so UI displays add more files
                this.uploadState = 'idle';
                this.progress = 0;
                // Resume thumbnail loading now that upload is complete
                this.processThumbnailQueue();
            }
        },

        failBatch(batchId, errorMsg) {
            if (this.activeBatch !== batchId) return;
            this.clearBatchTimeout();
            const failedFiles = this.activeBatchFiles || [];
            this.failedBatch = { id: batchId, files: failedFiles };
            this.activeBatch = null;
            this.activeBatchFiles = null;
            this.uploadState = 'error';
            this.errorMessage = errorMsg || this.messages.uploadFailed || 'Upload failed. Please retry.';
            this.progress = 0;
            // Resume thumbnail queue if uploads halted
            this.processThumbnailQueue();
        },

        retryFailedBatch() {
            if (!this.failedBatch || !this.failedBatch.files.length) return;
            const files = this.failedBatch.files;
            this.failedBatch = null;
            this.errorMessage = null;
            this.uploadState = 'idle';
            this.uploadQueue.unshift(files);
            this.startNextBatch();
        },

        clearBatchTimeout() {
            if (this.batchTimeoutTimer) {
                clearTimeout(this.batchTimeoutTimer);
                this.batchTimeoutTimer = null;
            }
        },

        // --- Sequential Thumbnail Loader ---
        enqueueThumbnail(url, onLoaded, onError) {
            if (!url) return;
            if (this.thumbnailStatus[url] === 'loaded') {
                onLoaded?.();
                return;
            }
            if (this.thumbnailStatus[url] === 'failed') {
                onError?.();
                return;
            }
            this.thumbnailQueue.push({ url, onLoaded, onError });
            this.processThumbnailQueue();
        },

        processThumbnailQueue() {
            // High priority: Never block active batch uploads!
            if (this.uploadState === 'uploading' && this.activeBatch) {
                return;
            }

            if (this.activeThumbnails >= this.maxThumbnailConcurrency || this.thumbnailQueue.length === 0) {
                return;
            }

            const item = this.thumbnailQueue.shift();
            this.activeThumbnails++;
            this.thumbnailStatus[item.url] = 'loading';

            const img = new Image();
            let settled = false;

            const cleanup = () => {
                settled = true;
                this.activeThumbnails--;
                // Process next thumbnail sequentially with a tiny yield to keep UI responsive
                setTimeout(() => this.processThumbnailQueue(), 25);
            };

            img.onload = () => {
                if (settled) return;
                this.thumbnailStatus[item.url] = 'loaded';
                item.onLoaded?.();
                cleanup();
            };

            img.onerror = () => {
                if (settled) return;
                this.thumbnailStatus[item.url] = 'failed';
                item.onError?.();
                cleanup();
            };

            // 15-second safety timeout per thumbnail
            const thumbTimer = setTimeout(() => {
                if (!settled) {
                    this.thumbnailStatus[item.url] = 'failed';
                    item.onError?.();
                    cleanup();
                }
            }, 15000);
            if (typeof thumbTimer?.unref === 'function') {
                thumbTimer.unref();
            }

            img.src = item.url;
        },

        // --- Lightbox Modal ---
        openPreview(url, name, size) {
            this.activePreview = url;
            this.activeFileName = name || '';
            this.activeFileSize = size || '';
        },

        closePreview() {
            this.activePreview = null;
            this.activeFileName = '';
            this.activeFileSize = '';
        },

        prepareUpload(files) {
            this.fileCount = files.length;
            this.fileName = files.length === 1 ? files[0].name : `${files.length} files`;
            this.totalBytes = Array.from(files).reduce((acc, f) => acc + f.size, 0);
            this.uploadedBytes = 0;
            this.progress = 0;
            this.speed = 0;
            this.remainingSeconds = null;
            this.startTime = Date.now();
            this.uploadState = 'selected';
        },

        clearErrors() {
            this.errorMessage = null;
            this.rejectedFiles = [];
        },

        onFileSelected(e) {
            // Legacy / direct event fallback
            if (e && e.target && e.target.files) {
                this.handleFileSelect(e);
            }
        },

        onFileDrop(e) {
            const droppedFiles = e.dataTransfer.files;
            if (!droppedFiles || droppedFiles.length === 0) return;

            this.clearErrors();

            const input = this.$refs.fileInput || this.$el.querySelector('input[type="file"]');
            if (!input) return;

            // Single file mode
            if (!this.isMultiple) {
                const file = droppedFiles[0];
                const error = this.validateFile(file);

                if (error) {
                    this.errorMessage = error;
                    this.rejectedFiles = [{ name: file.name, error }];
                    this.uploadState = 'idle';
                    return;
                }

                if (typeof DataTransfer !== 'undefined') {
                    const dt = new DataTransfer();
                    dt.items.add(file);
                    input.files = dt.files;
                } else {
                    input.files = droppedFiles;
                }
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }

            // Multiple files mode
            const filesArray = Array.from(droppedFiles);

            if (this.maxBatchFiles && filesArray.length > this.maxBatchFiles) {
                this.errorMessage = this.messages.tooManyFiles;
                this.uploadState = 'idle';
                return;
            }

            const validDataTransfer = typeof DataTransfer !== 'undefined' ? new DataTransfer() : null;
            const rejected = [];
            let validTotalBytes = 0;

            for (const file of filesArray) {
                const err = this.validateFile(file);
                if (err) {
                    rejected.push({ name: file.name, error: err });
                } else {
                    if (validDataTransfer) {
                        validDataTransfer.items.add(file);
                    }
                    validTotalBytes += file.size;
                }
            }

            if (this.maxTotalBatchBytes && validTotalBytes > this.maxTotalBatchBytes) {
                this.errorMessage = this.messages.batchTooLarge;
                this.rejectedFiles = rejected;
                this.uploadState = 'idle';
                return;
            }

            if (rejected.length > 0) {
                this.rejectedFiles = rejected;

                if (rejected.length === filesArray.length || !validDataTransfer) {
                    this.errorMessage = rejected[0].error;
                    this.uploadState = 'idle';
                    return;
                }

                input.files = validDataTransfer.files;
                input.dispatchEvent(new Event('change', { bubbles: true }));
                return;
            }

            input.files = droppedFiles;
            input.dispatchEvent(new Event('change', { bubbles: true }));
        },

        onUploadStart(e) {
            if (this.queueBatches) return;
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.uploadState = 'uploading';
            this.startTime = Date.now();
            this.progress = 0;
            this.uploadedBytes = 0;
            this.speed = 0;
            this.remainingSeconds = null;
        },

        onUploadProgress(e) {
            if (this.queueBatches) return;
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.applyUploadProgress(e.detail.progress);
        },

        applyUploadProgress(progress) {
            this.uploadState = 'uploading';
            const pct = Math.min(100, Math.max(0, parseInt(progress, 10) || 0));
            this.progress = pct;

            if (this.totalBytes > 0) {
                this.uploadedBytes = Math.round((pct / 100) * this.totalBytes);
            }

            const now = Date.now();
            const elapsed = (now - (this.startTime || now)) / 1000;

            if (elapsed > 0.2 && this.uploadedBytes > 0) {
                this.speed = Math.round(this.uploadedBytes / elapsed);
                if (this.speed > 0 && this.totalBytes > this.uploadedBytes) {
                    const remaining = this.totalBytes - this.uploadedBytes;
                    this.remainingSeconds = Math.max(0, Math.round(remaining / this.speed));
                } else if (pct >= 100) {
                    this.remainingSeconds = 0;
                }
            }
        },

        onUploadFinish(e) {
            if (this.queueBatches) return;
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.applyUploadFinish();
        },

        applyUploadFinish() {
            this.progress = 100;
            this.uploadedBytes = this.totalBytes;
            this.remainingSeconds = 0;
            this.uploadState = 'uploaded';
        },

        onUploadError(e) {
            if (this.queueBatches) return;
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.reset();
        },

        cancelUpload() {
            try {
                if (this.$wire && typeof this.$wire.cancelUpload === 'function') {
                    this.$wire.cancelUpload(this.propertyName);
                }
            } catch (err) {
                console.warn('Livewire cancelUpload error:', err);
            }

            this.reset();

            try {
                if (this.$wire && typeof this.$wire.cancelToolUpload === 'function') {
                    this.$wire.cancelToolUpload();
                } else if (this.$wire && typeof this.$wire.resetState === 'function') {
                    this.$wire.resetState();
                }
            } catch (err) {
                console.warn('Component reset error:', err);
            }
        },

        reset() {
            this.uploadState = 'idle';
            this.progress = 0;
            this.uploadedBytes = 0;
            this.totalBytes = 0;
            this.speed = 0;
            this.remainingSeconds = null;
            this.fileName = '';
            this.fileCount = 0;
            this.isDragging = false;
            this.errorMessage = null;
            this.rejectedFiles = [];
            this.uploadQueue = [];
            this.activeBatch = null;
            const input = this.$refs.fileInput || this.$el.querySelector('input[type="file"]');
            if (input) input.value = '';
        },

        formatBytes(bytes) {
            if (!bytes || bytes === 0) return '0 B';
            const k = 1024;
            const sizes = ['B', 'KB', 'MB', 'GB'];
            const i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
            return (bytes / Math.pow(k, i)).toFixed(1) + ' ' + sizes[i];
        },

        formatSpeed(bytesPerSec) {
            if (!bytesPerSec || bytesPerSec <= 0) return null;
            return this.formatBytes(bytesPerSec) + '/s';
        },

        formatTime(seconds, isRtl) {
            if (seconds === null || seconds === undefined) return null;
            if (seconds <= 0) return isRtl ? '0 ث' : '0s';
            if (seconds < 60) return `${seconds} ${isRtl ? 'ث' : 's'}`;
            const mins = Math.floor(seconds / 60);
            const secs = seconds % 60;
            return `${mins} ${isRtl ? 'د' : 'm'} ${secs} ${isRtl ? 'ث' : 's'}`;
        }
    };
}
