<?php

namespace App\Livewire\Tools;

use App\Actions\File\SanitizeUploadedFileAction;
use App\Enums\JobStatus;
use App\Enums\ToolType;
use App\Models\FileJob;
use App\Models\ProcessedFile;
use App\Models\ToolUsage;
use App\Services\File\FileStorageManager;
use App\Services\Pdf\PdfAnnotationApplierService;
use App\Services\Pdf\PdfiumTextEditorService;
use App\Services\Pdf\PdfThumbnailService;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Livewire\WithFileUploads;
use RuntimeException;
use Throwable;

class EditPdfComponent extends BaseToolComponent
{
    use WithFileUploads;

    public ?string $toolType = ToolType::EDIT_PDF->value;

    /**
     * Uploaded PDF file.
     */
    public $upload;

    public string $filename = '';
    public int $fileSizeBytes = 0;
    public string $fileSizeFormatted = '';
    public ?string $storedSourcePath = null;
    public ?string $jobId = null;
    public ?FileJob $completedJob = null;

    // Document inspection & capabilities
    public int $pageCount = 0;
    public int $currentPage = 1;
    public bool $isScanned = false;
    public bool $isSigned = false;
    public bool $isEncrypted = false;
    public bool $passwordRequired = false;
    public string $password = '';
    public ?string $passwordError = null;
    public int $totalTextObjects = 0;
    public int $totalTextGroups = 0;
    public array $pageDimensions = []; // ['width' => float, 'height' => float, 'rotation' => int]
    public array $pageNativeEditable = []; // page_number (1-based) => bool
    public array $pageHasType3 = [];       // page_number (1-based) => bool
    public array $pageWarnings = [];       // page_number (1-based) => string|null

    // Previews & navigation
    public array $pagePreviews = []; // page => base64
    public array $pageThumbnails = []; // page => base64
    public int $zoomLevel = 100; // 50, 75, 100, 125, 150

    // Text objects & active selection
    public array $allTextObjectsByPage = []; // page_index => text_objects
    public array $allTextGroupsByPage = [];  // page_index => text_groups
    public ?string $selectedObjectId = null;
    public ?array $selectedObject = null;
    public ?string $selectedGroupId = null;
    public ?array $selectedGroup = null;
    public string $editText = '';
    public bool $autoFit = true;
    public ?float $editFontSize = null;
    public string $editColor = '#000000';

    // In-memory manifests
    public array $editManifest = []; // id => [page, page_index, group_id, child_object_ids, object_id, target_text, replacement_text, auto_fit, bbox]
    public array $addedTexts = [];   // id => [page, text, x_ratio, y_ratio, font_size, color]
    public array $addedShapes = [];  // id => [page, type, x_ratio, y_ratio, width_ratio, height_ratio, stroke_color, fill_color]

    // UI tabs & states
    public string $activeTab = 'text'; // 'text', 'add_text', 'shapes', 'review'

    public function updatedUpload(): void
    {
        $maxPdfKb = config('fileprocessor.limits.max_pdf_size_kb', 51200);

        $this->validate([
            'upload' => "required|file|max:{$maxPdfKb}",
        ]);

        if ($this->upload) {
            $this->filename = $this->upload->getClientOriginalName();
            $this->fileSizeBytes = (int) $this->upload->getSize();
            $this->fileSizeFormatted = $this->formatFileSize($this->fileSizeBytes);

            $this->jobId = Str::uuid()->toString();
            $storage = app(FileStorageManager::class);
            $sanitizer = app(SanitizeUploadedFileAction::class);

            $sanitized = $sanitizer->execute($this->upload, ['application/pdf']);
            $storageResult = $storage->storeUploadedFile($this->upload, $this->jobId, $sanitized['safe_filename']);
            $this->storedSourcePath = $storageResult['absolute_path'];

            $this->analyzeDocument();
        }
    }

    public function analyzeDocument(?string $password = null): void
    {
        if (!$this->storedSourcePath || !file_exists($this->storedSourcePath)) {
            return;
        }

        $this->jobStatus = 'analyzing';
        $this->errorMessage = null;

        try {
            $pdfium = app(PdfiumTextEditorService::class);
            $inspection = $pdfium->inspectDocument($this->storedSourcePath, $password);

            $this->pageCount = $inspection['page_count'] ?? 1;
            $this->isScanned = $inspection['is_scanned'] ?? false;
            $this->isSigned = $inspection['is_signed'] ?? false;
            $this->totalTextObjects = $inspection['total_text_objects'] ?? 0;
            $this->totalTextGroups = $inspection['total_text_groups'] ?? 0;
            $this->isEncrypted = false;
            $this->passwordRequired = false;
            $this->passwordError = null;

            // Cache text objects and logical groups by page index
            $this->allTextObjectsByPage = [];
            $this->allTextGroupsByPage = [];
            $this->pageNativeEditable = [];
            $this->pageHasType3 = [];
            $this->pageWarnings = [];

            foreach ($inspection['pages'] ?? [] as $pData) {
                $pIdx = (int) $pData['page_index'];
                $pageNum = $pIdx + 1;
                $this->pageNativeEditable[$pageNum] = (bool) ($pData['native_editable'] ?? true);
                $this->pageHasType3[$pageNum] = (bool) ($pData['has_type3_fonts'] ?? false);
                $this->pageWarnings[$pageNum] = $pData['warning']['message'] ?? ($pData['editable_reason'] ?? null);
                $objs = [];
                foreach ($pData['text_objects'] ?? [] as $to) {
                    $to['object_id'] = $to['id'] ?? ($to['object_id'] ?? null);
                    $objs[] = $to;
                }
                $this->allTextObjectsByPage[$pIdx] = $objs;
                $this->allTextGroupsByPage[$pIdx] = $pData['text_groups'] ?? [];

                if ($pIdx === ($this->currentPage - 1)) {
                    $this->pageDimensions = [
                        'width'    => $pData['width'] ?? 595.28,
                        'height'   => $pData['height'] ?? 841.89,
                        'rotation' => $pData['rotation'] ?? 0,
                    ];
                }
            }

            $this->currentPage = 1;

            // Load high-res preview and thumbnails if poppler available
            $this->loadPagePreview(1);
            $this->loadThumbnails();

            $this->jobStatus = 'ready';
        } catch (RuntimeException $e) {
            $msg = $e->getMessage();
            if (str_contains(strtolower($msg), 'password')) {
                $this->isEncrypted = true;
                $this->passwordRequired = true;
                $this->passwordError = $password ? __('tools.unlock_pdf.error_invalid_password') : null;
                $this->jobStatus = 'ready';
            } else {
                $this->errorMessage = $msg;
                $this->jobStatus = 'failed';
            }
        } catch (Throwable $e) {
            Log::error("EditPdfComponent: inspection failed: " . $e->getMessage());
            $this->errorMessage = $e->getMessage();
            $this->jobStatus = 'failed';
        }
    }


    public function isCurrentPageNativeEditable(): bool
    {
        return $this->pageNativeEditable[$this->currentPage] ?? true;
    }

    public function currentPageWarning(): ?string
    {
        return $this->pageWarnings[$this->currentPage] ?? null;
    }

    public function unlockWithPassword(): void
    {
        $this->validate([
            'password' => 'required|string|min:1',
        ]);

        $this->analyzeDocument($this->password);
    }

    public function loadPagePreview(int $page): void
    {
        if (isset($this->pagePreviews[$page]) || !$this->storedSourcePath) {
            return;
        }

        try {
            $thumbService = app(PdfThumbnailService::class);
            $preview = $thumbService->generatePagePreview($this->storedSourcePath, $page, 150);
            if ($preview) {
                $this->pagePreviews[$page] = $preview;
            }
        } catch (Throwable $e) {
            Log::warning("EditPdfComponent: Could not generate page preview: " . $e->getMessage());
        }
    }

    public function loadThumbnails(): void
    {
        if (!empty($this->pageThumbnails) || !$this->storedSourcePath) {
            return;
        }

        try {
            $thumbService = app(PdfThumbnailService::class);
            $this->pageThumbnails = $thumbService->generateThumbnails($this->storedSourcePath, min(20, $this->pageCount), 150);
        } catch (Throwable $e) {
            Log::warning("EditPdfComponent: Thumbnail generation failed: " . $e->getMessage());
        }
    }

    public function goToPage(int $page): void
    {
        if ($page < 1 || $page > $this->pageCount) {
            return;
        }

        $this->currentPage = $page;
        $this->selectedGroupId = null;
        $this->selectedGroup = null;
        $this->selectedObjectId = null;
        $this->selectedObject = null;
        $this->editText = '';

        $pIdx = $page - 1;
        if (isset($this->allTextObjectsByPage[$pIdx])) {
            $pdfium = app(PdfiumTextEditorService::class);
            $inspection = $pdfium->inspectDocument($this->storedSourcePath, $this->password ?: null);
            $pageData = $inspection['pages'][$pIdx] ?? null;
            if ($pageData) {
                $this->pageDimensions = [
                    'width'    => $pageData['width'] ?? 595.28,
                    'height'   => $pageData['height'] ?? 841.89,
                    'rotation' => $pageData['rotation'] ?? 0,
                ];
            }
        }

        $this->loadPagePreview($page);
    }

    public function setZoom(int $zoom): void
    {
        $this->zoomLevel = max(50, min(200, $zoom));
    }

    public function setActiveTab(string $tab): void
    {
        if (in_array($tab, ['text', 'add_text', 'shapes', 'review'], true)) {
            $this->activeTab = $tab;
        }
    }

    /**
     * Select a logical text group for editing.
     */
    public function selectTextGroup(string $groupId): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        if ($this->hasUnsavedActiveEdit() && $this->selectedGroupId !== $groupId && $this->selectedObjectId !== $groupId) {
            $this->saveCurrentEdit();
        }

        $pIdx = $this->currentPage - 1;
        $groups = $this->allTextGroupsByPage[$pIdx] ?? [];

        foreach ($groups as $grp) {
            if ($grp['group_id'] === $groupId) {
                $this->selectedGroupId = $groupId;
                $this->selectedGroup = $grp;
                $this->selectedObjectId = null;
                $this->selectedObject = null;

                if (isset($this->editManifest[$groupId])) {
                    $this->editText = $this->editManifest[$groupId]['replacement_text'];
                    $this->autoFit = $this->editManifest[$groupId]['auto_fit'] ?? true;
                    $this->editFontSize = $this->editManifest[$groupId]['font_size'] ?? round((float) ($grp['font_size'] ?? 12), 1);
                    $this->editColor = $this->editManifest[$groupId]['color'] ?? '#000000';
                } else {
                    $this->editText = $grp['text'];
                    $this->autoFit = true;
                    $this->editFontSize = round((float) ($grp['font_size'] ?? 12), 1);
                    $this->editColor = '#000000';
                }
                $this->activeTab = 'text';
                break;
            }
        }
    }

    /**
     * Select individual text object (with group fallback).
     */
    public function selectTextObject(string $objectId): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        if ($this->hasUnsavedActiveEdit() && $this->selectedGroupId !== $objectId && $this->selectedObjectId !== $objectId) {
            $this->saveCurrentEdit();
        }

        $pIdx = $this->currentPage - 1;

        // If called with a group_id directly
        $groups = $this->allTextGroupsByPage[$pIdx] ?? [];
        foreach ($groups as $grp) {
            if ($grp['group_id'] === $objectId) {
                $this->selectTextGroup($objectId);
                return;
            }
        }

        // Direct object match
        $objects = $this->allTextObjectsByPage[$pIdx] ?? [];
        foreach ($objects as $obj) {
            if ($obj['object_id'] === $objectId) {
                $this->selectedGroupId = null;
                $this->selectedObjectId = $objectId;
                $this->selectedObject = $obj;

                // Associate matching group if available
                $this->selectedGroup = null;
                foreach ($groups as $grp) {
                    if (in_array($objectId, $grp['child_object_ids'] ?? [], true)) {
                        $this->selectedGroup = $grp;
                        break;
                    }
                }

                if (isset($this->editManifest[$objectId])) {
                    $this->editText = $this->editManifest[$objectId]['replacement_text'];
                    $this->autoFit = $this->editManifest[$objectId]['auto_fit'] ?? true;
                    $this->editFontSize = $this->editManifest[$objectId]['font_size'] ?? round((float) ($obj['font_size'] ?? 12), 1);
                    $this->editColor = $this->editManifest[$objectId]['color'] ?? '#000000';
                } else {
                    $this->editText = $obj['text'];
                    $this->autoFit = true;
                    $this->editFontSize = round((float) ($obj['font_size'] ?? 12), 1);
                    $this->editColor = '#000000';
                }
                $this->activeTab = 'text';
                break;
            }
        }
    }

    public function cancelEdit(): void
    {
        $this->selectedGroupId = null;
        $this->selectedGroup = null;
        $this->selectedObjectId = null;
        $this->selectedObject = null;
        $this->editText = '';
        $this->editFontSize = null;
        $this->editColor = '#000000';
        $this->autoFit = true;
    }

    public function saveCurrentEdit(): void
    {
        if (!$this->isCurrentPageNativeEditable()) {
            $this->addError('edit_text', __('tools.edit_pdf.error_type3_fonts'));
            return;
        }

        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        if ($this->selectedGroupId && $this->selectedGroup) {
            $original = (string) $this->selectedGroup['text'];
            $groupId = $this->selectedGroupId;

            $fontSize = $this->editFontSize ?? round((float) ($this->selectedGroup['font_size'] ?? 12), 1);
            $origFontSize = round((float) ($this->selectedGroup['font_size'] ?? 12), 1);
            $hasChanges = ($this->editText !== $original)
                || (abs($fontSize - $origFontSize) > 0.01)
                || ($this->editColor !== '#000000');

            if (!$hasChanges) {
                unset($this->editManifest[$groupId]);
            } else {
                $this->editManifest[$groupId] = [
                    'id'                   => $groupId,
                    'group_id'             => $groupId,
                    'object_id'            => $this->selectedGroup['child_object_ids'][0] ?? $groupId,
                    'page'                 => $this->currentPage,
                    'page_index'           => $this->currentPage - 1,
                    'child_object_ids'     => $this->selectedGroup['child_object_ids'] ?? [],
                    'child_object_indices' => $this->selectedGroup['child_object_indices'] ?? [],
                    'original_text'        => $original,
                    'target_text'          => $original,
                    'replacement_text'     => $this->editText,
                    'auto_fit'             => $this->autoFit,
                    'font_size'            => $fontSize,
                    'color'                => $this->editColor,
                    'direction'            => $this->selectedGroup['direction'] ?? null,
                    'bbox'                 => $this->selectedGroup['bounding_box'] ?? [],
                ];
            }

            $this->cancelEdit();
            return;
        }

        if ($this->selectedObjectId && $this->selectedObject) {
            $original = (string) $this->selectedObject['text'];
            $objId = $this->selectedObjectId;

            $fontSize = $this->editFontSize ?? round((float) ($this->selectedObject['font_size'] ?? 12), 1);
            $origFontSize = round((float) ($this->selectedObject['font_size'] ?? 12), 1);
            $hasChanges = ($this->editText !== $original)
                || (abs($fontSize - $origFontSize) > 0.01)
                || ($this->editColor !== '#000000');

            if (!$hasChanges) {
                unset($this->editManifest[$objId]);
            } else {
                $this->editManifest[$objId] = [
                    'id'                   => $objId,
                    'group_id'             => $this->selectedGroup['group_id'] ?? '',
                    'page'                 => $this->currentPage,
                    'page_index'           => $this->currentPage - 1,
                    'object_id'            => $objId,
                    'child_object_ids'     => $this->selectedGroup['child_object_ids'] ?? [$objId],
                    'child_object_indices' => $this->selectedGroup['child_object_indices'] ?? [],
                    'original_text'        => $original,
                    'target_text'          => $original,
                    'replacement_text'     => $this->editText,
                    'auto_fit'             => $this->autoFit,
                    'font_size'            => $fontSize,
                    'color'                => $this->editColor,
                    'direction'            => $this->selectedGroup['direction'] ?? null,
                    'bbox'                 => $this->selectedObject['bbox'] ?? [],
                ];
            }

            $this->cancelEdit();
        }
    }

    /**
     * Check whether there is an active text group/object modification in progress.
     */
    public function hasUnsavedActiveEdit(): bool
    {
        $activeItem = $this->selectedGroup ?? $this->selectedObject;
        if (!$activeItem) {
            return false;
        }

        $origText = (string) ($activeItem['text'] ?? '');
        $origFontSize = round((float) ($activeItem['font_size'] ?? 12), 1);
        $currentFontSize = $this->editFontSize ?? $origFontSize;

        return ($this->editText !== $origText)
            || (abs($currentFontSize - $origFontSize) > 0.01)
            || ($this->editColor !== '#000000');
    }

    /**
     * Total modifications count including active unsaved typing.
     */
    public function getTotalQueuedModificationsCountProperty(): int
    {
        $count = count($this->editManifest) + count($this->addedTexts) + count($this->addedShapes);
        if ($this->hasUnsavedActiveEdit()) {
            $count++;
        }
        return $count;
    }

    /**
     * Computes the effective font size for live visual preview, scaling down if auto-fit is enabled.
     */
    public function computeEffectiveFontSize(float $baseFontSize, string $targetText, string $replacementText, bool $autoFit): float
    {
        if (!$autoFit) {
            return max(6.0, $baseFontSize);
        }

        $targetLen = mb_strlen($targetText);
        $replLen = mb_strlen($replacementText);

        if ($targetLen > 0 && $replLen > $targetLen) {
            $ratio = $targetLen / $replLen;
            if ($ratio < 0.9) {
                return max(6.0, round($baseFontSize * $ratio, 1));
            }
        }

        return max(6.0, $baseFontSize);
    }

    /**
     * Determines whether text is predominantly RTL (e.g. Arabic, Hebrew).
     */
    public function isRtlText(string $text, ?string $groupDirection = null): bool
    {
        if ($groupDirection === 'rtl') {
            return true;
        }

        return (bool) preg_match('/[\x{0590}-\x{05FF}\x{0600}-\x{06FF}\x{0750}-\x{077F}\x{08A0}-\x{08FF}\x{FB50}-\x{FDFF}\x{FE70}-\x{FEFF}]/u', $text);
    }

    public function removeEdit(string $editId): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        unset($this->editManifest[$editId]);

        if ($this->selectedGroupId === $editId || $this->selectedObjectId === $editId) {
            $this->cancelEdit();
        }
    }

    // Additive Text Insertion
    public function addTextItem(): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        $id = 'txt_' . Str::random(8);
        $this->addedTexts[$id] = [
            'id'        => $id,
            'page'      => $this->currentPage,
            'text'      => 'Sample Text',
            'x_ratio'   => 0.25,
            'y_ratio'   => 0.25,
            'font_size' => 14,
            'color'     => '#000000',
        ];
        $this->activeTab = 'add_text';
    }

    public function updateTextItem(string $id, array $params): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        if (isset($this->addedTexts[$id])) {
            $this->addedTexts[$id] = array_merge($this->addedTexts[$id], $params);
        }
    }

    public function removeTextItem(string $id): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        unset($this->addedTexts[$id]);
    }

    // Basic Shapes Insertion
    public function addShape(string $type = 'rect'): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        $id = 'shp_' . Str::random(8);
        $this->addedShapes[$id] = [
            'id'           => $id,
            'page'         => $this->currentPage,
            'type'         => in_array($type, ['rect', 'line', 'highlight'], true) ? $type : 'rect',
            'x_ratio'      => 0.20,
            'y_ratio'      => 0.20,
            'width_ratio'  => 0.30,
            'height_ratio' => $type === 'line' ? 0.00 : 0.05,
            'stroke_color' => $type === 'highlight' ? '#eab308' : '#2563eb',
            'fill_color'   => $type === 'highlight' ? '#fde047' : 'none',
        ];
        $this->activeTab = 'shapes';
    }

    public function removeShape(string $id): void
    {
        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }

        unset($this->addedShapes[$id]);
    }

    public function clearAllEdits(): void
    {
        $this->editManifest = [];
        $this->addedTexts = [];
        $this->addedShapes = [];
        $this->cancelEdit();

        if ($this->jobStatus === 'completed') {
            $this->fileJobId = null;
            $this->completedJob = null;
            $this->jobStatus = 'ready';
        }
    }

    public function process(
        FileStorageManager $storage,
        PdfiumTextEditorService $pdfium,
        PdfAnnotationApplierService $annotationApplier
    ): void {
        if (!$this->storedSourcePath || !file_exists($this->storedSourcePath)) {
            $this->addError('upload', 'No valid PDF document uploaded.');
            return;
        }

        if ($this->hasUnsavedActiveEdit()) {
            $this->saveCurrentEdit();
        }

        if (empty($this->editManifest) && empty($this->addedTexts) && empty($this->addedShapes)) {
            $this->addError('manifest', 'No modifications queued to apply.');
            return;
        }

        // Concurrency lock to protect against simultaneous double-submissions for the same edit session
        $lockKey = 'edit_pdf_process:' . ($this->jobId ?? 'global');
        $lock = Cache::lock($lockKey, 120);

        if (!$lock->get()) {
            if ($this->fileJobId) {
                $existingJob = FileJob::find($this->fileJobId);
                if ($existingJob && $existingJob->status === JobStatus::COMPLETED) {
                    $this->completedJob = $existingJob;
                    $this->jobStatus = 'completed';
                }
            }
            return;
        }

        // Idempotency check
        if ($this->fileJobId) {
            $existingJob = FileJob::find($this->fileJobId);
            if ($existingJob && $existingJob->status === JobStatus::COMPLETED) {
                $this->completedJob = $existingJob;
                $this->jobStatus = 'completed';
                $this->releaseLockSafely($lock);
                return;
            }
        }

        $this->jobStatus = 'processing';
        $this->statusMessage = __('ui.status_processing');

        $startTime = hrtime(true);
        $job = null;

        try {
            // Security Validation: Verify that every group_id or object_id belongs to the currently inspected document
            $inspection = $pdfium->inspectDocument($this->storedSourcePath, $this->password ?: null);
            $validObjectsMap = [];
            $validGroupsMap = [];

            foreach ($inspection['pages'] as $pData) {
                $pIdx = (int) $pData['page_index'];
                $pageNum = $pIdx + 1;
                $this->pageNativeEditable[$pageNum] = (bool) ($pData['native_editable'] ?? true);
                $this->pageHasType3[$pageNum] = (bool) ($pData['has_type3_fonts'] ?? false);
                $this->pageWarnings[$pageNum] = $pData['warning']['message'] ?? ($pData['editable_reason'] ?? null);
                foreach ($pData['text_objects'] ?? [] as $to) {
                    $oid = $to['id'] ?? ($to['object_id'] ?? null);
                    if ($oid) {
                        $validObjectsMap[$oid] = [
                            'page_index' => $pIdx,
                            'text'       => (string) ($to['text'] ?? ''),
                        ];
                    }
                }
                foreach ($pData['text_groups'] ?? [] as $tg) {
                    $gid = $tg['group_id'] ?? null;
                    if ($gid) {
                        $validGroupsMap[$gid] = [
                            'page_index'       => $pIdx,
                            'text'             => (string) ($tg['text'] ?? ''),
                            'child_object_ids' => $tg['child_object_ids'] ?? [],
                        ];
                    }
                }
            }

            $uniqueManifest = [];
            foreach ($this->editManifest as $item) {
                if (!is_array($item)) continue;
                $key = $item['group_id'] ?? ($item['object_id'] ?? ($item['id'] ?? null));
                if ($key) {
                    $uniqueManifest[$key] = $item;
                }
            }

            foreach ($uniqueManifest as $item) {
                $hasGroup = !empty($item['group_id']) && isset($validGroupsMap[$item['group_id']]);
                $hasObject = !empty($item['object_id']) && isset($validObjectsMap[$item['object_id']]);

                if (!$hasGroup && !$hasObject) {
                    $ref = $item['group_id'] ?? ($item['object_id'] ?? ($item['id'] ?? 'unknown'));
                    throw new RuntimeException("Security violation: Invalid or tampered text reference: {$ref}");
                }

                if ($hasGroup) {
                    $pIdx = (int) ($item['page_index'] ?? 0);
                    if ($pIdx !== $validGroupsMap[$item['group_id']]['page_index']) {
                        throw new RuntimeException("Security violation: Page index mismatch for group: {$item['group_id']}");
                    }
                } elseif ($hasObject) {
                    $pIdx = (int) ($item['page_index'] ?? 0);
                    if ($pIdx !== $validObjectsMap[$item['object_id']]['page_index']) {
                        throw new RuntimeException("Security violation: Page index mismatch for text object: {$item['object_id']}");
                    }
                }
            }

            // Security Validation for additive elements
            $maxPages = $this->pageCount;
            foreach ($this->addedTexts as $txt) {
                $p = (int) ($txt['page'] ?? 1);
                if ($p < 1 || $p > $maxPages) {
                    throw new RuntimeException("Security violation: Target page out of range for added text: {$p}");
                }
                $x = (float) ($txt['x_ratio'] ?? 0);
                $y = (float) ($txt['y_ratio'] ?? 0);
                if ($x < -0.2 || $x > 1.2 || $y < -0.2 || $y > 1.2) {
                    throw new RuntimeException("Security violation: Coordinates out of bounds for added text.");
                }
            }

            foreach ($this->addedShapes as $shp) {
                $p = (int) ($shp['page'] ?? 1);
                if ($p < 1 || $p > $maxPages) {
                    throw new RuntimeException("Security violation: Target page out of range for added shape: {$p}");
                }
                $type = (string) ($shp['type'] ?? '');
                if (!in_array($type, ['rect', 'line', 'highlight'], true)) {
                    throw new RuntimeException("Security violation: Invalid shape type: {$type}");
                }
            }

            // Security Precondition: ensure no native edit targets an unsafe page (Single authoritative validation path)
            foreach ($this->editManifest as $item) {
                $p = (int) ($item['page'] ?? (($item['page_index'] ?? 0) + 1));
                if (!($this->pageNativeEditable[$p] ?? true)) {
                    throw new \App\Exceptions\UnsupportedPdfFontException(
                        "Cannot perform native text edit on page {$p}: page contains unsupported Type 3 embedded fonts.",
                        $p,
                        'Type3'
                    );
                }
            }

            $outputFilename = 'edited_' . pathinfo($this->filename, PATHINFO_FILENAME) . '.pdf';

            // Retry support
            $isRetry = false;
            if ($this->fileJobId) {
                $existingJob = FileJob::find($this->fileJobId);
                if ($existingJob && $existingJob->status === JobStatus::FAILED) {
                    $isRetry = true;
                    $job = $existingJob;
                    $job->update([
                        'status'                => JobStatus::PROCESSING,
                        'error_message'         => null,
                        'processing_started_at' => now(),
                    ]);
                    ProcessedFile::where('file_job_id', $job->id)->delete();
                    $jobDir = $storage->getJobDirectory($job->id);
                }
            }

            if (!$isRetry) {
                $newJobId = Str::uuid()->toString();
                $this->fileJobId = $newJobId;
                $jobDir = $storage->getJobDirectory($newJobId);
                $job = FileJob::create([
                    'id'                    => $newJobId,
                    'tool_type'             => ToolType::EDIT_PDF,
                    'status'                => JobStatus::PROCESSING,
                    'download_token'        => Str::random(48),
                    'input_metadata'        => [
                        'file'            => basename($this->storedSourcePath),
                        'clean_name'      => pathinfo($this->filename, PATHINFO_FILENAME),
                        'ip'              => request()->ip(),
                        'edits_count'     => count($this->editManifest),
                        'add_text_count'  => count($this->addedTexts),
                        'shapes_count'    => count($this->addedShapes),
                        'engine'          => 'pdfium',
                    ],
                    'expires_at'            => now()->addMinutes(config('fileprocessor.retention.job_retention_minutes', 60)),
                    'processing_started_at' => now(),
                ]);
            }

            $finalOutputPath = $jobDir . DIRECTORY_SEPARATOR . $outputFilename;
            $currentInputPath = $this->storedSourcePath;

            // Stage 1: True In-Place PDFium Text Object/Group Replacement
            if (!empty($this->editManifest)) {
                $uniqueEdits = $uniqueManifest;
                foreach ($this->editManifest as $item) {
                    $key = $item['group_id'] ?? ($item['object_id'] ?? $item['id']);
                    $uniqueEdits[$key] = $item;
                }

                $replacements = array_values(array_map(function ($item) {
                    return [
                        'page_index'       => (int) $item['page_index'],
                        'group_id'         => (string) ($item['group_id'] ?? ''),
                        'child_object_ids' => $item['child_object_ids'] ?? [],
                        'object_id'        => (string) ($item['object_id'] ?? $item['id'] ?? ''),
                        'target_text'      => (string) ($item['target_text'] ?? ''),
                        'replacement_text' => (string) ($item['replacement_text'] ?? ''),
                        'fit_strategy'     => !empty($item['auto_fit']) ? 'auto_fit' : 'preserve_origin',
                    ];
                }, $uniqueEdits));

                $pdfiumRes = $pdfium->replaceText($currentInputPath, $replacements, [
                    'password' => $this->password ?: null,
                ]);

                $currentInputPath = $pdfiumRes['output_path'];
            }

            // Stage 2: Additive Text & Shapes (if any)
            if (!empty($this->addedTexts) || !empty($this->addedShapes)) {
                $annotationApplier->apply(
                    $currentInputPath,
                    $finalOutputPath,
                    array_values($this->addedTexts),
                    array_values($this->addedShapes)
                );
            } else {
                if ($currentInputPath !== $finalOutputPath) {
                    copy($currentInputPath, $finalOutputPath);
                }
            }

            $elapsedMs = (int) round((hrtime(true) - $startTime) / 1e6);
            $processedSizeBytes = filesize($finalOutputPath);

            // Determine truthful engine attribution
            $hasUnicodeFallback = false;
            foreach ($this->editManifest as $item) {
                $repText = (string) ($item['replacement_text'] ?? '');
                if (preg_match('/[^\x00-\xFF]/u', $repText)) {
                    $hasUnicodeFallback = true;
                    break;
                }
            }

            $engineLabel = $hasUnicodeFallback
                ? 'Google PDFium (True Text Editing) + Fallback Font (Amiri) + Poppler'
                : 'Google PDFium (True Text Editing) + Poppler';

            // Complete FileJob
            $job->update([
                'status'                => JobStatus::COMPLETED,
                'input_metadata'        => array_merge($job->input_metadata ?? [], [
                    'output_filename' => $outputFilename,
                    'engine_label'    => $engineLabel,
                ]),
                'duration_ms'           => $elapsedMs,
                'completed_at'          => now(),
            ]);

            ProcessedFile::create([
                'id'                   => Str::uuid()->toString(),
                'file_job_id'          => $job->id,
                'original_name'        => $this->filename,
                'stored_filename'      => $outputFilename,
                'mime_type'            => 'application/pdf',
                'original_size_bytes'  => $this->fileSizeBytes,
                'processed_size_bytes' => $processedSizeBytes,
            ]);

            // Track Tool Usage telemetry
            ToolUsage::log(
                toolType: ToolType::EDIT_PDF->value,
                ip: request()->ip() ?? '127.0.0.1',
                successful: true,
                bytes: $processedSizeBytes,
                durationMs: $elapsedMs,
                fileJobId: $job->id
            );

            $this->completedJob = $job->fresh();
            $this->fileJobId = $job->id;
            $this->jobStatus = 'completed';
        } catch (Throwable $e) {
            Log::error("EditPdfComponent: processing failed: " . $e->getMessage(), ['trace' => $e->getTraceAsString()]);
            if (isset($job) && $job->exists) {
                $job->update([
                    'status'        => JobStatus::FAILED,
                    'error_message' => $e->getMessage(),
                ]);
            }
            $this->errorMessage = $e->getMessage();
            $this->jobStatus = 'failed';
        } finally {
            $this->releaseLockSafely($lock);
        }
    }

    protected function releaseLockSafely($lock): void
    {
        try {
            $lock->release();
        } catch (\Throwable $e) {
            // Lock release failure is non-fatal
        }
    }

    public function resetWorkflow(): void
    {
        $this->resetTool();
    }

    public function deleteJobFiles(FileStorageManager $storage): void
    {
        $this->deleteNow($storage);
    }

    public function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes . ' B';
        }
        if ($bytes < 1048576) {
            return round($bytes / 1024, 1) . ' KB';
        }
        return round($bytes / 1048576, 2) . ' MB';
    }

    public function resetTool(): void
    {
        if ($this->jobId) {
            try {
                app(FileStorageManager::class)->deleteJobDirectory($this->jobId);
            } catch (Throwable $e) {
                // Ignore failure during temp source dir cleanup
            }
        }

        $this->reset([
            'upload', 'filename', 'fileSizeBytes', 'fileSizeFormatted',
            'storedSourcePath', 'jobId', 'pageCount', 'currentPage',
            'isScanned', 'isSigned', 'isEncrypted', 'passwordRequired',
            'password', 'passwordError', 'totalTextObjects', 'totalTextGroups',
            'pageDimensions', 'pagePreviews', 'pageThumbnails',
            'allTextObjectsByPage', 'allTextGroupsByPage',
            'selectedObjectId', 'selectedObject', 'selectedGroupId', 'selectedGroup',
            'editText', 'editFontSize', 'editColor', 'autoFit', 'editManifest', 'addedTexts', 'addedShapes', 'completedJob'
        ]);
        $this->resetState();
    }

    public function render()
    {
        $pIdx = $this->currentPage - 1;
        $currentTextObjects = $this->allTextObjectsByPage[$pIdx] ?? [];
        $currentTextGroups = $this->allTextGroupsByPage[$pIdx] ?? [];

        return view('livewire.tools.edit-pdf-component', [
            'currentTextObjects' => $currentTextObjects,
            'currentTextGroups'  => $currentTextGroups,
        ])->layout('layouts.app', [
            'title' => __('tools.edit_pdf.title') . ' — ' . config('app.name'),
        ]);
    }
}

