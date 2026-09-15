#!/usr/bin/env node

/**
 * Production PDFium Text Editor Runner
 * Google Chromium PDFium (Apache-2.0)
 *
 * Provides:
 * - Direct native PDF text object inspection & logical grouping
 * - True in-place text modification without whiteout or visual masks
 * - Robust logical text grouping for characters/glyphs into natural spans
 * - Detection of unsupported Type 3 font structures per page
 * - Enforces safe editing preconditions (prevents content loss on Type 3 pages)
 * - Unicode & Arabic shaping support (via @hyoban/reshaper & bidi-js)
 * - Safe document saving via FPDF_SaveAsCopy
 */

const fs = require('fs');
const path = require('path');
const koffi = require('koffi');
const reshaper = require('arabic-persian-reshaper');
const bidi = require('bidi-js')();

// 1. Resolve PDFium Dynamic Library
function resolvePdfiumLibrary() {
    if (process.env.PDFIUM_PATH && fs.existsSync(process.env.PDFIUM_PATH)) {
        return process.env.PDFIUM_PATH;
    }
    const isWindows = process.platform === 'win32';
    const binaryName = isWindows ? 'pdfium.dll' : 'libpdfium.so';
    const subfolder = isWindows ? 'win-x64' : 'linux-x64/lib';
    const candidate = path.resolve(__dirname, 'pdfium', subfolder, binaryName);
    if (fs.existsSync(candidate)) {
        return candidate;
    }
    throw new Error(`PDFium library not found at ${candidate}`);
}

const libPath = resolvePdfiumLibrary();
const lib = koffi.load(libPath);

// 2. Declare PDFium Data Types
const FPDF_DOCUMENT = koffi.pointer('FPDF_DOCUMENT', koffi.opaque());
const FPDF_PAGE = koffi.pointer('FPDF_PAGE', koffi.opaque());
const FPDF_TEXTPAGE = koffi.pointer('FPDF_TEXTPAGE', koffi.opaque());
const FPDF_PAGEOBJECT = koffi.pointer('FPDF_PAGEOBJECT', koffi.opaque());
const FPDF_FONT = koffi.pointer('FPDF_FONT', koffi.opaque());

const FS_MATRIX = koffi.struct('FS_MATRIX', {
    a: 'float',
    b: 'float',
    c: 'float',
    d: 'float',
    e: 'float',
    f: 'float'
});

const WriteBlockCallback = koffi.proto('int WriteBlockCallback(void* pThis, const uint8_t* pData, unsigned long size)');
const FPDF_FILEWRITE = koffi.struct('FPDF_FILEWRITE', {
    version: 'int',
    WriteBlock: koffi.pointer(WriteBlockCallback)
});

// 3. Declare PDFium C API Functions
const FPDF_InitLibraryWithConfig = lib.func('void FPDF_InitLibraryWithConfig(void* config)');
const FPDF_DestroyLibrary = lib.func('void FPDF_DestroyLibrary()');
const FPDF_GetLastError = lib.func('unsigned long FPDF_GetLastError()');

const FPDF_LoadMemDocument64 = lib.func('FPDF_DOCUMENT FPDF_LoadMemDocument64(const uint8_t* data_buf, size_t size, str password)');
const FPDF_CloseDocument = lib.func('void FPDF_CloseDocument(FPDF_DOCUMENT document)');
const FPDF_GetPageCount = lib.func('int FPDF_GetPageCount(FPDF_DOCUMENT document)');

const FPDF_LoadPage = lib.func('FPDF_PAGE FPDF_LoadPage(FPDF_DOCUMENT document, int page_index)');
const FPDF_ClosePage = lib.func('void FPDF_ClosePage(FPDF_PAGE page)');
const FPDF_GetPageWidthF = lib.func('float FPDF_GetPageWidthF(FPDF_PAGE page)');
const FPDF_GetPageHeightF = lib.func('float FPDF_GetPageHeightF(FPDF_PAGE page)');
const FPDFPage_GetRotation = lib.func('int FPDFPage_GetRotation(FPDF_PAGE page)');

const FPDFText_LoadPage = lib.func('FPDF_TEXTPAGE FPDFText_LoadPage(FPDF_PAGE page)');
const FPDFText_ClosePage = lib.func('void FPDFText_ClosePage(FPDF_TEXTPAGE text_page)');

const FPDFPage_CountObjects = lib.func('int FPDFPage_CountObjects(FPDF_PAGE page)');
const FPDFPage_GetObject = lib.func('FPDF_PAGEOBJECT FPDFPage_GetObject(FPDF_PAGE page, int index)');
const FPDFPageObj_GetType = lib.func('int FPDFPageObj_GetType(FPDF_PAGEOBJECT page_object)');
const FPDFPageObj_GetBounds = lib.func('bool FPDFPageObj_GetBounds(FPDF_PAGEOBJECT page_object, _Out_ float* left, _Out_ float* bottom, _Out_ float* right, _Out_ float* top)');
const FPDFPageObj_GetMatrix = lib.func('bool FPDFPageObj_GetMatrix(FPDF_PAGEOBJECT page_object, _Out_ FS_MATRIX* matrix)');
const FPDFPageObj_SetMatrix = lib.func('bool FPDFPageObj_SetMatrix(FPDF_PAGEOBJECT page_object, const FS_MATRIX* matrix)');
const FPDFPageObj_Destroy = lib.func('void FPDFPageObj_Destroy(FPDF_PAGEOBJECT page_object)');

const FPDFTextObj_GetText = lib.func('unsigned long FPDFTextObj_GetText(FPDF_PAGEOBJECT text_object, FPDF_TEXTPAGE text_page, _Out_ uint16_t* buffer, unsigned long length)');
const FPDFText_SetText = lib.func('bool FPDFText_SetText(FPDF_PAGEOBJECT text_object, const uint8_t* text)');
const FPDFTextObj_GetFontSize = lib.func('bool FPDFTextObj_GetFontSize(FPDF_PAGEOBJECT text, _Out_ float* size)');
const FPDFTextObj_SetFontSize = lib.func('bool FPDFTextObj_SetFontSize(FPDF_PAGEOBJECT text, float size)');
const FPDFTextObj_GetFont = lib.func('FPDF_FONT FPDFTextObj_GetFont(FPDF_PAGEOBJECT text)');

const FPDFFont_GetFamilyName = lib.func('size_t FPDFFont_GetFamilyName(FPDF_FONT font, _Out_ char* buffer, size_t length)');
const FPDFFont_GetBaseFontName = lib.func('size_t FPDFFont_GetBaseFontName(FPDF_FONT font, _Out_ char* buffer, size_t length)');
const FPDFFont_GetIsEmbedded = lib.func('int FPDFFont_GetIsEmbedded(FPDF_FONT font)');
const FPDFFont_GetFontData = lib.func('bool FPDFFont_GetFontData(FPDF_FONT font, _Out_ uint8_t* buffer, size_t buflen, _Out_ size_t* out_buflen)');
const FPDFFont_GetFlags = lib.func('int FPDFFont_GetFlags(FPDF_FONT font)');

const FPDFText_LoadFont = lib.func('FPDF_FONT FPDFText_LoadFont(FPDF_DOCUMENT document, const uint8_t* data, uint32_t size, int font_type, bool cid)');
const FPDFPageObj_CreateTextObj = lib.func('FPDF_PAGEOBJECT FPDFPageObj_CreateTextObj(FPDF_DOCUMENT document, FPDF_FONT font, float font_size)');
const FPDFPage_InsertObjectAtIndex = lib.func('bool FPDFPage_InsertObjectAtIndex(FPDF_PAGE page, FPDF_PAGEOBJECT page_object, size_t index)');
const FPDFPage_RemoveObject = lib.func('bool FPDFPage_RemoveObject(FPDF_PAGE page, FPDF_PAGEOBJECT page_object)');

const FPDFPage_GenerateContent = lib.func('bool FPDFPage_GenerateContent(FPDF_PAGE page)');
const FPDF_SaveAsCopy = lib.func('bool FPDF_SaveAsCopy(FPDF_DOCUMENT document, _Inout_ FPDF_FILEWRITE* file_write, uint32_t flags)');
const FPDF_GetSignatureCount = lib.func('int FPDF_GetSignatureCount(FPDF_DOCUMENT document)');

const FPDF_PAGEOBJ_TEXT = 1;
const FPDF_FONT_TRUETYPE = 2;

// 4. Utility Functions
function toWideString(str) {
    return Buffer.from(str + '\0', 'utf16le');
}

function computeStableId(pageIndex, objIndex, text) {
    let hash = 0;
    const cleanText = text.trim();
    for (let i = 0; i < cleanText.length; i++) {
        hash = ((hash << 5) - hash) + cleanText.charCodeAt(i);
        hash |= 0;
    }
    const hexHash = Math.abs(hash).toString(16).padStart(8, '0');
    return `p${pageIndex}_o${objIndex}_${hexHash}`;
}

function computeGroupId(pageIndex, anchorId, text) {
    let hash = 0;
    const cleanText = text.trim();
    for (let i = 0; i < cleanText.length; i++) {
        hash = ((hash << 5) - hash) + cleanText.charCodeAt(i);
        hash |= 0;
    }
    const hexHash = Math.abs(hash).toString(16).padStart(8, '0');
    return `grp_p${pageIndex}_${anchorId}_${hexHash}`;
}

function processArabic(text) {
    if (!text) return '';
    if (!/[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(text)) {
        return text;
    }
    const reshaped = reshaper.ArabicShaper.convertArabic(text);
    const embeddingLevels = bidi.getEmbeddingLevels(reshaped, 'rtl');
    const charArray = reshaped.split('').map((char, i) => {
        return (embeddingLevels.levels[i] & 1) ? (bidi.getMirroredCharacter(char) || char) : char;
    });
    const flips = bidi.getReorderSegments(reshaped, embeddingLevels);
    flips.forEach(([start, end]) => {
        const slice = charArray.slice(start, end + 1).reverse();
        for (let i = start; i <= end; i++) {
            charArray[i] = slice[i - start];
        }
    });
    return charArray.join('');
}

function hasNonLatinCharacters(text) {
    return /[^\u0000-\u00FF]/.test(text);
}

/**
 * Detect whether a text object uses an unsupported Type 3 font.
 * PDFium's CPDF_PageContentGenerator drops all Type 3 font objects during FPDFPage_GenerateContent.
 */
function isType3Font(fontHandle) {
    if (!fontHandle) return false;
    try {
        const outLen = [0];
        FPDFFont_GetFontData(fontHandle, null, 0, outLen);
        const bBuf = Buffer.alloc(128);
        const bLen = FPDFFont_GetBaseFontName(fontHandle, bBuf, 128);
        const fBuf = Buffer.alloc(128);
        const fLen = FPDFFont_GetFamilyName(fontHandle, fBuf, 128);

        // PDFium Type 3 fonts return 0 font data bytes and have empty BaseFont / FamilyName
        if (outLen[0] === 0 && bLen <= 1 && fLen <= 1) {
            return true;
        }
    } catch (_) {}
    return false;
}

/**
 * Logical Text Grouping Algorithm
 */
function groupTextObjects(textObjects, pageIndex) {
    if (!textObjects || textObjects.length === 0) {
        return [];
    }

    const sortedObjects = [...textObjects].sort((a, b) => {
        const yDiff = b.bbox.bottom - a.bbox.bottom;
        if (Math.abs(yDiff) > 2.0) {
            return yDiff;
        }
        return a.bbox.left - b.bbox.left;
    });

    const lines = [];
    for (const obj of sortedObjects) {
        const objBottom = obj.bbox.bottom;
        const objHeight = Math.max(obj.bbox.height, obj.font_size || 12.0);

        let placed = false;
        for (const line of lines) {
            const lineBottom = line.baseline;
            const lineHeight = line.height;
            const tolerance = Math.min(lineHeight, objHeight) * 0.45;

            if (Math.abs(objBottom - lineBottom) <= tolerance) {
                line.objects.push(obj);
                line.baseline = (line.baseline * (line.objects.length - 1) + objBottom) / line.objects.length;
                line.height = Math.max(line.height, objHeight);
                placed = true;
                break;
            }
        }

        if (!placed) {
            const rot = (obj.matrix && (obj.matrix.b !== 0 || obj.matrix.c !== 0)) ? 1 : 0;
            lines.push({
                baseline: objBottom,
                height: objHeight,
                rotation: rot,
                objects: [obj]
            });
        }
    }

    lines.sort((a, b) => b.baseline - a.baseline);

    const groups = [];
    let readingOrder = 0;

    for (const line of lines) {
        line.objects.sort((a, b) => a.bbox.left - b.bbox.left);

        let currentCluster = [];
        for (let i = 0; i < line.objects.length; i++) {
            const obj = line.objects[i];

            if (currentCluster.length === 0) {
                currentCluster.push(obj);
                continue;
            }

            const prevObj = currentCluster[currentCluster.length - 1];
            const gap = obj.bbox.left - prevObj.bbox.right;
            const avgFontSize = ((prevObj.font_size || 12) + (obj.font_size || 12)) / 2;
            const maxGapForSameGroup = Math.max(8.0, avgFontSize * 1.2);
            const fontDiff = Math.abs((prevObj.font_size || 12) - (obj.font_size || 12));

            const isSignificantGap = gap > maxGapForSameGroup;
            const isDifferentFont = fontDiff > 2.5;

            if (isSignificantGap || isDifferentFont) {
                groups.push(buildTextGroup(currentCluster, pageIndex, line.rotation, readingOrder++));
                currentCluster = [obj];
            } else {
                currentCluster.push(obj);
            }
        }

        if (currentCluster.length > 0) {
            groups.push(buildTextGroup(currentCluster, pageIndex, line.rotation, readingOrder++));
        }
    }

    return groups;
}

function buildTextGroup(objects, pageIndex, rotation, readingOrder) {
    objects.sort((a, b) => a.bbox.left - b.bbox.left);

    const left = Math.min(...objects.map(o => o.bbox.left));
    const bottom = Math.min(...objects.map(o => o.bbox.bottom));
    const right = Math.max(...objects.map(o => o.bbox.right));
    const top = Math.max(...objects.map(o => o.bbox.top));

    let fullText = '';
    for (let i = 0; i < objects.length; i++) {
        const obj = objects[i];
        const rawText = obj.text || '';

        if (i === 0) {
            fullText += rawText;
            continue;
        }

        const prevObj = objects[i - 1];
        const gap = obj.bbox.left - prevObj.bbox.right;
        const avgFontSize = ((prevObj.font_size || 12) + (obj.font_size || 12)) / 2;
        const wordSpaceThreshold = Math.max(1.8, avgFontSize * 0.17);

        const prevEndsWithSpace = fullText.endsWith(' ');
        const currStartsWithSpace = rawText.startsWith(' ');

        if (!prevEndsWithSpace && !currStartsWithSpace && gap >= wordSpaceThreshold) {
            const prevChar = fullText.slice(-1);
            const currChar = rawText.slice(0, 1);
            const isDateNumber = (/\d/.test(prevChar) && /[\/\-\:\.]/.test(currChar)) ||
                                 (/[\/\-\:\.]/.test(prevChar) && /\d/.test(currChar));

            if (!isDateNumber || gap >= avgFontSize * 0.45) {
                fullText += ' ';
            }
        }

        fullText += rawText;
    }

    fullText = fullText.trim();
    if (fullText.length === 0 && objects.length > 0) {
        fullText = objects.map(o => o.text || '').join('').trim();
    }

    const primaryObj = objects[0];
    const anchorId = primaryObj.id || `o${primaryObj.index}`;
    const groupId = computeGroupId(pageIndex, anchorId, fullText);

    const isArabic = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(fullText);
    const fontName = primaryObj.font_name || 'Arial';
    const hasType3 = objects.some(o => o.is_type3);
    const nativeEditable = !hasType3;
    const editableReason = hasType3
        ? 'Contains unsupported Type 3 embedded font objects'
        : 'Supported native font';

    return {
        group_id: groupId,
        page_index: pageIndex,
        child_object_ids: objects.map(o => o.id),
        child_object_indices: objects.map(o => o.index),
        text: fullText,
        native_editable: nativeEditable,
        has_type3_fonts: hasType3,
        editable_reason: editableReason,
        bounding_box: {
            left: Math.round(left * 100) / 100,
            bottom: Math.round(bottom * 100) / 100,
            right: Math.round(right * 100) / 100,
            top: Math.round(top * 100) / 100,
            width: Math.round(Math.max(0, right - left) * 100) / 100,
            height: Math.round(Math.max(0, top - bottom) * 100) / 100
        },
        font_size: Math.round((primaryObj.font_size || 12.0) * 100) / 100,
        font_family: fontName,
        is_embedded: primaryObj.is_embedded || false,
        writing_mode: rotation !== 0 ? 'vertical' : 'horizontal',
        direction: isArabic ? 'rtl' : 'ltr',
        reading_order: readingOrder
    };
}

// 5. Inspect Action
function actionInspect(pdfPath, password) {
    const fileBytes = fs.readFileSync(pdfPath);
    const doc = FPDF_LoadMemDocument64(fileBytes, fileBytes.length, password || null);

    if (!doc) {
        const err = FPDF_GetLastError();
        return { success: false, error_code: err, error: `Failed to load PDF document (code ${err})` };
    }

    const pageCount = FPDF_GetPageCount(doc);
    const signatureCount = FPDF_GetSignatureCount(doc);
    const pages = [];
    let totalTextObjects = 0;
    let totalTextGroups = 0;
    let totalType3Objects = 0;

    for (let p = 0; p < pageCount; p++) {
        const page = FPDF_LoadPage(doc, p);
        const textPage = FPDFText_LoadPage(page);
        const width = FPDF_GetPageWidthF(page);
        const height = FPDF_GetPageHeightF(page);
        const rotation = FPDFPage_GetRotation(page);
        const objCount = FPDFPage_CountObjects(page);

        const textObjects = [];
        let hasType3OnPage = false;
        let type3CountOnPage = 0;

        for (let i = 0; i < objCount; i++) {
            const obj = FPDFPage_GetObject(page, i);
            if (FPDFPageObj_GetType(obj) === FPDF_PAGEOBJ_TEXT) {
                const reqLen = FPDFTextObj_GetText(obj, textPage, null, 0);
                if (reqLen > 0) {
                    const buf = new Uint16Array(reqLen);
                    FPDFTextObj_GetText(obj, textPage, buf, reqLen);
                    const rawStr = Buffer.from(buf.buffer, 0, (reqLen - 1) * 2).toString('utf16le');
                    const cleanStr = rawStr.replace(/\0/g, '').trimEnd();

                    const fontSizeHolder = [0.0];
                    FPDFTextObj_GetFontSize(obj, fontSizeHolder);
                    const fontSize = fontSizeHolder[0];

                    const l = [0], b = [0], r = [0], t = [0];
                    FPDFPageObj_GetBounds(obj, l, b, r, t);
                    const bbox = {
                        left: l[0],
                        bottom: b[0],
                        right: r[0],
                        top: t[0],
                        width: Math.max(0, r[0] - l[0]),
                        height: Math.max(0, t[0] - b[0])
                    };

                    const matrix = {};
                    FPDFPageObj_GetMatrix(obj, matrix);

                    let fontName = 'Unknown';
                    let isEmbedded = false;
                    let isType3 = false;
                    const fontHandle = FPDFTextObj_GetFont(obj);
                    if (fontHandle) {
                        try {
                            const nameBuf = Buffer.alloc(128);
                            const nameLen = FPDFFont_GetFamilyName(fontHandle, nameBuf, 128);
                            if (nameLen > 0) {
                                fontName = nameBuf.toString('utf8', 0, nameLen - 1);
                            } else {
                                const baseLen = FPDFFont_GetBaseFontName(fontHandle, nameBuf, 128);
                                if (baseLen > 0) fontName = nameBuf.toString('utf8', 0, baseLen - 1);
                            }
                            isEmbedded = FPDFFont_GetIsEmbedded(fontHandle) > 0;
                            isType3 = isType3Font(fontHandle);
                        } catch (_) {}
                    }

                    if (isType3) {
                        hasType3OnPage = true;
                        type3CountOnPage++;
                        totalType3Objects++;
                    }

                    const stableId = computeStableId(p, i, cleanStr);
                    textObjects.push({
                        id: stableId,
                        index: i,
                        text: cleanStr,
                        font_size: fontSize,
                        font_name: fontName,
                        is_embedded: isEmbedded,
                        is_type3: isType3,
                        is_native_editable: !isType3,
                        bbox: bbox,
                        matrix: matrix
                    });
                    totalTextObjects++;
                }
            }
        }

        const textGroups = groupTextObjects(textObjects, p);
        totalTextGroups += textGroups.length;

        const nativeEditable = !hasType3OnPage;
        const editableReason = hasType3OnPage
            ? 'Contains Type 3 embedded fonts which cannot be rewritten natively by PDFium without data loss.'
            : 'Supported native text fonts';

        const warning = hasType3OnPage ? {
            code: 'UNSUPPORTED_TYPE3_FONTS',
            message: 'This page contains Type 3 embedded fonts that PDFium cannot safely rewrite. Native text editing is disabled to protect the document.'
        } : null;

        pages.push({
            page_index: p,
            width: width,
            height: height,
            rotation: rotation,
            object_count: objCount,
            text_object_count: textObjects.length,
            text_group_count: textGroups.length,
            native_editable: nativeEditable,
            has_type3_fonts: hasType3OnPage,
            type3_font_count: type3CountOnPage,
            editable_reason: editableReason,
            warning: warning,
            text_objects: textObjects,
            text_groups: textGroups
        });

        FPDFText_ClosePage(textPage);
        FPDF_ClosePage(page);
    }

    FPDF_CloseDocument(doc);

    return {
        success: true,
        page_count: pageCount,
        is_encrypted: false,
        is_signed: signatureCount > 0,
        signature_count: signatureCount,
        is_scanned: totalTextObjects === 0,
        total_text_objects: totalTextObjects,
        total_text_groups: totalTextGroups,
        total_type3_objects: totalType3Objects,
        pages: pages
    };
}

// 6. Edit Action
function actionEdit(pdfPath, recipePath, outputPath) {
    const fileBytes = fs.readFileSync(pdfPath);
    const recipeRaw = fs.readFileSync(recipePath, 'utf8');
    const recipe = JSON.parse(recipeRaw);

    const doc = FPDF_LoadMemDocument64(fileBytes, fileBytes.length, recipe.password || null);
    if (!doc) {
        const err = FPDF_GetLastError();
        return { success: false, error_code: err, error: `Failed to open PDF document (code ${err})` };
    }

    let fallbackFont = null;
    if (recipe.fallback_font_path && fs.existsSync(recipe.fallback_font_path)) {
        try {
            const fontBytes = fs.readFileSync(recipe.fallback_font_path);
            fallbackFont = FPDFText_LoadFont(doc, fontBytes, fontBytes.length, FPDF_FONT_TRUETYPE, true);
        } catch (_) {}
    }

    const pageCount = FPDF_GetPageCount(doc);
    const replacements = recipe.replacements || [];
    let modifiedCount = 0;
    const warnings = [];

    // Group replacements by target page
    const byPage = new Map();
    for (const rep of replacements) {
        const pIndex = rep.page_index !== undefined ? rep.page_index : 0;
        if (pIndex < 0 || pIndex >= pageCount) {
            warnings.push(`Replacement page_index ${pIndex} out of range (0-${pageCount - 1})`);
            continue;
        }
        if (!byPage.has(pIndex)) {
            byPage.set(pIndex, []);
        }
        byPage.get(pIndex).push(rep);
    }

    // Process each target page
    for (const [pIndex, pageReplacements] of byPage.entries()) {
        const page = FPDF_LoadPage(doc, pIndex);
        const textPage = FPDFText_LoadPage(page);
        const objCount = FPDFPage_CountObjects(page);

        // Precondition Safety Check: Inspect page for unsupported Type 3 fonts
        let hasType3OnPage = false;
        for (let i = 0; i < objCount; i++) {
            const obj = FPDFPage_GetObject(page, i);
            if (FPDFPageObj_GetType(obj) === FPDF_PAGEOBJ_TEXT) {
                const fontHandle = FPDFTextObj_GetFont(obj);
                if (isType3Font(fontHandle)) {
                    hasType3OnPage = true;
                    break;
                }
            }
        }

        if (hasType3OnPage) {
            FPDFText_ClosePage(textPage);
            FPDF_ClosePage(page);
            FPDF_CloseDocument(doc);
            return {
                success: false,
                error_code: 'UNSUPPORTED_TYPE3_FONTS',
                error: `Cannot perform native text edit on page ${pIndex + 1}: page contains Type 3 embedded fonts which cannot be safely regenerated by PDFium.`
            };
        }

        const pageObjects = [];
        for (let i = 0; i < objCount; i++) {
            const obj = FPDFPage_GetObject(page, i);
            const isText = FPDFPageObj_GetType(obj) === FPDF_PAGEOBJ_TEXT;
            let text = '';
            let fontSize = 12.0;
            const l = [0], b = [0], r = [0], t = [0];

            if (isText) {
                const reqLen = FPDFTextObj_GetText(obj, textPage, null, 0);
                if (reqLen > 0) {
                    const buf = new Uint16Array(reqLen);
                    FPDFTextObj_GetText(obj, textPage, buf, reqLen);
                    text = Buffer.from(buf.buffer, 0, (reqLen - 1) * 2).toString('utf16le').replace(/\0/g, '').trimEnd();
                }
                const fsH = [0.0];
                FPDFTextObj_GetFontSize(obj, fsH);
                fontSize = fsH[0] || 12.0;
                FPDFPageObj_GetBounds(obj, l, b, r, t);
            }
            pageObjects.push({
                index: i,
                obj: obj,
                text: text,
                id: computeStableId(pIndex, i, text),
                font_size: fontSize,
                bbox: { left: l[0], bottom: b[0], right: r[0], top: t[0], width: Math.max(0, r[0] - l[0]), height: Math.max(0, t[0] - b[0]) }
            });
        }

        let pageNeedsRegenerate = false;

        for (const rep of pageReplacements) {
            const isGroupReplacement = Array.isArray(rep.child_object_ids) && rep.child_object_ids.length > 0;

            if (isGroupReplacement) {
                const handles = rep.child_object_ids.map(id => pageObjects.find(o => o.id === id)).filter(Boolean);
                if (handles.length === 0) {
                    warnings.push(`Group replacement targets not found on page ${pIndex}`);
                    continue;
                }

                handles.sort((a, b) => (a.bbox.left !== b.bbox.left ? a.bbox.left - b.bbox.left : a.index - b.index));
                const anchor = handles[0];
                const secondaries = handles.slice(1);

                const newText = rep.replacement_text !== undefined ? rep.replacement_text : '';
                const isArabic = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(newText);
                const needsUnicodeFont = hasNonLatinCharacters(newText);
                const fitStrategy = rep.fit_strategy || 'preserve_size';

                let fontSize = anchor.font_size || 12.0;
                if (fitStrategy === 'auto_fit' && newText.length > (rep.target_text?.length || 0)) {
                    const ratio = (rep.target_text?.length || 1) / newText.length;
                    if (ratio < 0.9) {
                        fontSize = Math.max(6.0, fontSize * ratio);
                    }
                }

                let textToEmit = newText;
                if (isArabic) {
                    textToEmit = processArabic(newText);
                }

                if (newText === '') {
                    for (const h of handles) {
                        FPDFPage_RemoveObject(page, h.obj);
                        FPDFPageObj_Destroy(h.obj);
                    }
                    pageNeedsRegenerate = true;
                    modifiedCount++;
                } else if ((isArabic || needsUnicodeFont) && fallbackFont) {
                    const matrix = {};
                    FPDFPageObj_GetMatrix(anchor.obj, matrix);

                    const newTextObj = FPDFPageObj_CreateTextObj(doc, fallbackFont, fontSize);
                    FPDFPageObj_SetMatrix(newTextObj, matrix);
                    FPDFText_SetText(newTextObj, toWideString(textToEmit));

                    FPDFPage_InsertObjectAtIndex(page, newTextObj, anchor.index);
                    for (const h of handles) {
                        FPDFPage_RemoveObject(page, h.obj);
                        FPDFPageObj_Destroy(h.obj);
                    }
                    anchor.obj = newTextObj;
                    pageNeedsRegenerate = true;
                    modifiedCount++;
                } else {
                    const success = FPDFText_SetText(anchor.obj, toWideString(textToEmit));
                    if (fontSize !== anchor.font_size) {
                        FPDFTextObj_SetFontSize(anchor.obj, fontSize);
                    }
                    for (const sec of secondaries) {
                        FPDFPage_RemoveObject(page, sec.obj);
                        FPDFPageObj_Destroy(sec.obj);
                    }
                    if (success) {
                        pageNeedsRegenerate = true;
                        modifiedCount++;
                    } else {
                        warnings.push(`FPDFText_SetText failed on anchor object ${anchor.index}`);
                    }
                }
            } else {
                let target = null;
                if (rep.object_id) {
                    target = pageObjects.find(o => o.id === rep.object_id);
                } else if (rep.object_index !== undefined) {
                    target = pageObjects.find(o => o.index === rep.object_index);
                } else if (rep.target_text) {
                    target = pageObjects.find(o => o.text.includes(rep.target_text));
                }

                if (!target || !target.obj) {
                    warnings.push(`Target text object not found for replacement: "${rep.target_text || rep.object_id}"`);
                    continue;
                }

                let newText = rep.replacement_text !== undefined ? rep.replacement_text : '';
                if (rep.target_text && target.text.includes(rep.target_text)) {
                    newText = target.text.replace(rep.target_text, rep.replacement_text !== undefined ? rep.replacement_text : '');
                }

                const isArabic = /[\u0600-\u06FF\u0750-\u077F\u08A0-\u08FF]/.test(newText);
                const needsUnicodeFont = hasNonLatinCharacters(newText);
                const fitStrategy = rep.fit_strategy || 'preserve_size';

                let fontSize = target.font_size || 12.0;
                if (fitStrategy === 'auto_fit' && target.text.length > 0 && newText.length > target.text.length) {
                    const ratio = target.text.length / newText.length;
                    if (ratio < 0.9) {
                        fontSize = Math.max(6.0, fontSize * ratio);
                    }
                }

                let textToEmit = newText;
                if (isArabic) {
                    textToEmit = processArabic(newText);
                }

                if (newText === '') {
                    FPDFPage_RemoveObject(page, target.obj);
                    FPDFPageObj_Destroy(target.obj);
                    pageNeedsRegenerate = true;
                    modifiedCount++;
                } else if ((isArabic || needsUnicodeFont) && fallbackFont) {
                    const matrix = {};
                    FPDFPageObj_GetMatrix(target.obj, matrix);

                    const newTextObj = FPDFPageObj_CreateTextObj(doc, fallbackFont, fontSize);
                    FPDFPageObj_SetMatrix(newTextObj, matrix);
                    FPDFText_SetText(newTextObj, toWideString(textToEmit));

                    FPDFPage_RemoveObject(page, target.obj);
                    FPDFPageObj_Destroy(target.obj);
                    FPDFPage_InsertObjectAtIndex(page, newTextObj, target.index);
                    target.obj = newTextObj;
                    pageNeedsRegenerate = true;
                    modifiedCount++;
                } else {
                    const success = FPDFText_SetText(target.obj, toWideString(textToEmit));
                    if (fontSize !== target.font_size) {
                        FPDFTextObj_SetFontSize(target.obj, fontSize);
                    }
                    if (success) {
                        pageNeedsRegenerate = true;
                        modifiedCount++;
                    } else {
                        warnings.push(`FPDFText_SetText failed on object ${target.index}`);
                    }
                }
            }
        }

        if (pageNeedsRegenerate) {
            FPDFPage_GenerateContent(page);
        }

        FPDFText_ClosePage(textPage);
        FPDF_ClosePage(page);
    }

    const chunks = [];
    const fileWrite = {
        version: 1,
        WriteBlock: koffi.register((pThis, pData, size) => {
            const buf = koffi.decode(pData, 'uint8_t', size);
            chunks.push(Buffer.from(buf));
            return 1;
        }, koffi.pointer(WriteBlockCallback))
    };

    const saveOk = FPDF_SaveAsCopy(doc, fileWrite, 0);
    if (!saveOk) {
        FPDF_CloseDocument(doc);
        return { success: false, error: 'FPDF_SaveAsCopy failed' };
    }

    const outBuffer = Buffer.concat(chunks);
    fs.writeFileSync(outputPath, outBuffer);
    FPDF_CloseDocument(doc);

    return {
        success: true,
        modified_count: modifiedCount,
        output_size: outBuffer.length,
        warnings: warnings
    };
}

function main() {
    FPDF_InitLibraryWithConfig(null);

    try {
        const args = process.argv.slice(2);
        const actionArg = args.find(a => a.startsWith('--action='));
        const inputArg = args.find(a => a.startsWith('--input='));
        const recipeArg = args.find(a => a.startsWith('--recipe='));
        const outputArg = args.find(a => a.startsWith('--output='));
        const passwordArg = args.find(a => a.startsWith('--password='));

        const action = actionArg ? actionArg.split('=')[1] : null;
        const input = inputArg ? inputArg.slice('--input='.length) : null;
        const recipe = recipeArg ? recipeArg.slice('--recipe='.length) : null;
        const output = outputArg ? outputArg.slice('--output='.length) : null;
        const password = passwordArg ? passwordArg.slice('--password='.length) : null;

        if (!action || !input) {
            console.error(JSON.stringify({ success: false, error: 'Missing required arguments: --action and --input' }));
            process.exit(1);
        }

        let result;
        if (action === 'inspect') {
            result = actionInspect(input, password);
        } else if (action === 'edit') {
            if (!recipe || !output) {
                console.error(JSON.stringify({ success: false, error: 'Edit action requires --recipe and --output' }));
                process.exit(1);
            }
            result = actionEdit(input, recipe, output);
        } else {
            console.error(JSON.stringify({ success: false, error: `Unknown action: ${action}` }));
            process.exit(1);
        }

        console.log(JSON.stringify(result));
    } catch (err) {
        console.error(JSON.stringify({ success: false, error: err.message, stack: err.stack }));
        process.exit(1);
    } finally {
        FPDF_DestroyLibrary();
    }
}

if (require.main === module) {
    main();
}
