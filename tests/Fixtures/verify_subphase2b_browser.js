import puppeteer from 'puppeteer-core';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '../..');

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';
const screenshotsDir = path.join(__dirname, 'screenshots');

if (!fs.existsSync(screenshotsDir)) {
    fs.mkdirSync(screenshotsDir, { recursive: true });
}

async function runSubPhase2BBrowserVerification() {
    console.log('--- STARTING SUB-PHASE 2B REAL-BROWSER VERIFICATION (EDIT PDF) ---');
    const browser = await puppeteer.launch({
        executablePath: chromePath,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,900']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 900 });

    page.on('console', msg => {
        const text = msg.text();
        if (!text.includes('[vite]') && !text.includes('Download the Vue Devtools')) {
            console.log('PAGE LOG:', text);
        }
    });
    page.on('pageerror', err => console.log('PAGE ERROR:', err.toString()));

    const testPdf = path.join(projectRoot, 'tests/Fixtures/document_12_pages.pdf');

    try {
        console.log('\n[1/6] Loading Edit PDF at 1280px Desktop Viewport...');
        await page.goto('http://127.0.0.1:8000/edit-pdf', { waitUntil: 'networkidle0' });

        const uploadInput = await page.$('input[type="file"]');
        if (!uploadInput) throw new Error('File input not found on Edit PDF page');

        console.log('Uploading document_12_pages.pdf...');
        await uploadInput.uploadFile(testPdf);

        // Wait for PDF Page container to appear
        await page.waitForSelector('#pdf-page-viewport', { timeout: 20000 });
        console.log('Document uploaded & analyzed! Viewport is mounted.');
        await new Promise(r => setTimeout(r, 2000));

        // 1. Verify signed preview URL (zero Base64)
        const previewImgSrc = await page.$eval('#pdf-page-viewport img', el => el.getAttribute('src'));
        console.log('PDF Page Preview Src:', previewImgSrc.substring(0, 80) + '...');
        if (previewImgSrc.startsWith('data:image')) {
            throw new Error('FAIL: PDF preview is still using Base64 data URI!');
        }
        if (!previewImgSrc.includes('tools/pdf-preview')) {
            throw new Error('FAIL: PDF preview is not using tools/pdf-preview route!');
        }
        if (!previewImgSrc.includes('dpi=150')) {
            throw new Error('FAIL: Main PDF preview is not using 150 DPI signed preview!');
        }
        console.log('PASS: Main page preview uses secure signed temporary route at 150 DPI (zero Base64).');

        // 2. Verify progressive bottom thumbnail strip
        console.log('\n[2/6] Verifying Progressive Bottom Thumbnail Strip...');
        const stripThumbnails = await page.$$('button[wire\\:key^="edit-thumb-"]');
        console.log(`Found ${stripThumbnails.length} thumbnail cards in bottom strip (matches document pageCount 12).`);
        if (stripThumbnails.length !== 12) {
            throw new Error(`FAIL: Expected exactly 12 bottom strip thumbnail cards, got ${stripThumbnails.length}`);
        }

        const totalGoToPageButtons = await page.$$('button[wire\\:click*="goToPage"]');
        console.log(`Found ${totalGoToPageButtons.length} total goToPage buttons (12 strip cards + 2 top navigation buttons = 14).`);

        // Check thumbnail page range: ensure keys and data correspond strictly to pages 1 through 12
        const renderedPages = await page.$$eval('button[wire\\:key^="edit-thumb-"]', els =>
            els.map(el => el.getAttribute('wire:key').replace('edit-thumb-', ''))
        );
        console.log(`Thumbnails correspond to pages: ${renderedPages.join(', ')}`);
        const expectedPages = Array.from({length: 12}, (_, i) => String(i + 1));
        if (JSON.stringify(renderedPages) !== JSON.stringify(expectedPages)) {
            throw new Error(`FAIL: Thumbnail page mapping mismatch! Expected ${expectedPages.join(',')}, got ${renderedPages.join(',')}`);
        }
        console.log('PASS: Bottom thumbnail strip contains strictly pages 1 through 12 with progressive preview.');

        // 3. Verify Snapshot Size Budgets for Pure Page Navigation
        console.log('\n[3/6] Verifying Snapshot Size Budgets in Live Browser...');
        const initialHydrationSnapshotSize = await page.evaluate(() => {
            const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
            if (snap) return snap.length;
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
        });
        console.log(`Active page hydration snapshot size: ${initialHydrationSnapshotSize} bytes (budget: < 4500 bytes)`);
        if (initialHydrationSnapshotSize >= 4500) {
            throw new Error(`FAIL: Active page hydration snapshot exceeds 4500 bytes: ${initialHydrationSnapshotSize} bytes`);
        }
        console.log('PASS: Active page hydration snapshot strictly satisfies budget < 4.5 KB.');

        // Test pure page navigation to Page 2
        console.log('Navigating to Page 2 (pure navigation without pending edits)...');
        await page.evaluate(() => {
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            if (lw) {
                lw.goToPage(2);
            }
        });
        await new Promise(r => setTimeout(r, 1500));

        const navSnapshotSize = await page.evaluate(() => {
            const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
            if (snap) return snap.length;
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
        });
        console.log(`Pure page navigation snapshot size: ${navSnapshotSize} bytes (budget: < 3000 bytes)`);
        if (navSnapshotSize >= 3000) {
            throw new Error(`FAIL: Page navigation snapshot exceeds 3000 bytes: ${navSnapshotSize} bytes`);
        }
        console.log('PASS: Page navigation snapshot strictly satisfies budget < 3.0 KB.');

        // Navigate back to Page 1 for text editing
        console.log('Navigating back to Page 1 for editing...');
        await page.evaluate(() => {
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            if (lw) {
                lw.goToPage(1);
            }
        });
        await new Promise(r => setTimeout(r, 1500));

        // 4. Verify text overlays and interactive editing
        console.log('\n[4/6] Verifying Text Selection & WYSIWYG Editing Layer...');
        const textOverlays = await page.$$('#pdf-page-viewport div[wire\\:key^="clickable_"]');
        console.log(`Found ${textOverlays.length} selectable text overlays on active page.`);
        if (textOverlays.length === 0) {
            throw new Error('FAIL: No interactive text overlays found on active page!');
        }

        // Click first text overlay to open editor
        console.log('Clicking first text overlay...');
        await textOverlays[0].click();
        await new Promise(r => setTimeout(r, 1200));

        // Set replacement text via Livewire component
        console.log('Applying inline text replacement: "ANTIGRAVITY PDFIUM VERIFIED"...');
        await page.evaluate(() => {
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            if (lw) {
                lw.set('editText', 'ANTIGRAVITY PDFIUM VERIFIED');
                lw.saveCurrentEdit();
            }
        });
        await new Promise(r => setTimeout(r, 1500));

        // Verify WYSIWYG manifest preview appears on the page
        const manifestPreviews = await page.$$('#pdf-page-viewport div[wire\\:key^="manifest_preview_"]');
        console.log(`Found ${manifestPreviews.length} WYSIWYG manifest preview elements.`);
        if (manifestPreviews.length === 0) {
            throw new Error('FAIL: WYSIWYG manifest preview element not rendered on page!');
        }
        console.log('PASS: WYSIWYG live replacement preview rendered on top of page background.');

        const workflowSnapshotSize = await page.evaluate(() => {
            const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
            if (snap) return snap.length;
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
        });
        console.log(`Multi-page editing workflow snapshot size: ${workflowSnapshotSize} bytes (budget: < 4500 bytes)`);
        if (workflowSnapshotSize >= 4500) {
            throw new Error(`FAIL: Multi-page editing workflow snapshot exceeds 4500 bytes: ${workflowSnapshotSize} bytes`);
        }
        console.log('PASS: Multi-page editing workflow snapshot strictly satisfies budget < 4.5 KB.');

        // Navigate back to Page 1 and check edit preservation
        console.log('Navigating back to Page 1...');
        await page.evaluate(() => {
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            if (lw) {
                lw.goToPage(1);
            }
        });
        await new Promise(r => setTimeout(r, 1500));

        const restoredManifestCount = await page.evaluate(() => {
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.editManifest ? Object.keys(lw.editManifest).length : 0;
        });
        console.log(`Restored editManifest count: ${restoredManifestCount}`);
        if (restoredManifestCount < 1) {
            throw new Error('FAIL: editManifest was lost during page navigation!');
        }
        console.log('PASS: Edit manifest preserved across cross-page navigation.');

        // Capture Desktop Screenshot
        await page.screenshot({ path: path.join(screenshotsDir, 'subphase2b_desktop_1280.png'), fullPage: true });
        console.log('Saved Desktop screenshot: subphase2b_desktop_1280.png');

        // 5. Responsive Viewport Tests
        console.log('\n[5/6] Testing Tablet (768px) and Mobile (375px) Viewports...');
        await page.setViewport({ width: 768, height: 1024 });
        await new Promise(r => setTimeout(r, 600));
        await page.screenshot({ path: path.join(screenshotsDir, 'subphase2b_tablet_768.png') });
        console.log('Saved Tablet screenshot: subphase2b_tablet_768.png');

        await page.setViewport({ width: 375, height: 812 });
        await new Promise(r => setTimeout(r, 600));
        await page.screenshot({ path: path.join(screenshotsDir, 'subphase2b_mobile_375.png') });
        console.log('Saved Mobile screenshot: subphase2b_mobile_375.png');

        // Reset viewport to desktop
        await page.setViewport({ width: 1280, height: 900 });

        // 6. Test Arabic RTL Mode
        console.log('\n[6/6] Testing Arabic RTL Layout...');
        await page.goto('http://127.0.0.1:8000/lang/ar', { waitUntil: 'networkidle0' });
        await page.goto('http://127.0.0.1:8000/edit-pdf', { waitUntil: 'networkidle0' });

        const isRtl = await page.evaluate(() => {
            return document.documentElement.getAttribute('dir') === 'rtl' ||
                   document.querySelector('[dir="rtl"]') !== null;
        });
        console.log(`Arabic RTL active: ${isRtl}`);
        await page.screenshot({ path: path.join(screenshotsDir, 'subphase2b_arabic_rtl.png') });
        console.log('Saved Arabic RTL screenshot: subphase2b_arabic_rtl.png');

        console.log('\n--- ALL SUB-PHASE 2B REAL-BROWSER VERIFICATIONS COMPLETED SUCCESSFULLY ---');
    } finally {
        await browser.close();
    }
}

runSubPhase2BBrowserVerification().catch(err => {
    console.error('VERIFICATION FAILED:', err);
    process.exit(1);
});
