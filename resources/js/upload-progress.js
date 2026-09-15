export function fileUploadHandler(config = {}) {
    const globalLimits = (typeof window !== 'undefined' && window.fileProcessorConfig?.limits) || {};
    const fileType = config.fileType || 'pdf';

    const maxSizeMb = config.maxSizeMb || (fileType === 'image'
        ? (globalLimits.maxImageSizeMb || 25)
        : (globalLimits.maxPdfSizeMb || 50));
    const maxSizeBytes = config.maxSizeBytes || (maxSizeMb * 1024 * 1024);
    const maxBatchFiles = config.maxBatchFiles || globalLimits.maxBatchFiles || 20;
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
        accept: config.accept || '',
        fileType: fileType,
        maxSizeMb: maxSizeMb,
        maxSizeBytes: maxSizeBytes,
        maxBatchFiles: maxBatchFiles,
        maxTotalBatchBytes: maxTotalBatchBytes,
        messages: messages,

        uploadState: 'idle', // 'idle' | 'selected' | 'uploading' | 'uploaded'
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
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.uploadState = 'uploading';
            this.startTime = Date.now();
            this.progress = 0;
            this.uploadedBytes = 0;
            this.speed = 0;
            this.remainingSeconds = null;
        },

        onUploadProgress(e) {
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.uploadState = 'uploading';
            const pct = Math.min(100, Math.max(0, parseInt(e.detail.progress, 10) || 0));
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
            if (e.detail && e.detail.property && e.detail.property !== this.propertyName) return;

            this.progress = 100;
            this.uploadedBytes = this.totalBytes;
            this.remainingSeconds = 0;
            this.uploadState = 'uploaded';
        },

        onUploadError(e) {
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
