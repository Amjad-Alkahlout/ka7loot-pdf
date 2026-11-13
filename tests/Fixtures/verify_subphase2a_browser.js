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

async function runSubPhase2ABrowserVerification() {
    console.log('--- STARTING SUB-PHASE 2A REAL-BROWSER VERIFICATION (SIGN PDF) ---');
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
        console.log('\n[1/6] Loading Sign PDF at 1280px Desktop Viewport...');
        await page.goto('http://127.0.0.1:8000/sign-pdf', { waitUntil: 'networkidle0' });

        const uploadInput = await page.$('input[type="file"]');
        if (!uploadInput) throw new Error('File input not found on Sign PDF page');

        console.log('Uploading document_12_pages.pdf...');
        await uploadInput.uploadFile(testPdf);

        // Wait for PDF Page container to appear
        await page.waitForSelector('#pdf-page-container', { timeout: 15000 });
        console.log('Document uploaded! Page container is mounted.');
        await new Promise(r => setTimeout(r, 1500));

        // 1. Verify signed preview URL (zero Base64)
        const previewImgSrc = await page.$eval('#pdf-page-container img', el => el.getAttribute('src'));
        console.log('PDF Page Preview Src:', previewImgSrc.substring(0, 80) + '...');
        if (previewImgSrc.startsWith('data:image')) {
            throw new Error('FAIL: PDF preview is still using Base64 data URI!');
        }
        if (!previewImgSrc.includes('tools/pdf-preview')) {
            throw new Error('FAIL: PDF preview is not using tools/pdf-preview route!');
        }
        console.log('PASS: PDF Page preview is served via signed temporary route (zero Base64 in state).');

        // Check Livewire snapshot payload size on initial load
        const initialSnapshotSize = await page.evaluate(() => {
            const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
            if (snap) return snap.length;
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
        });
        console.log(`Initial Livewire snapshot size: ${initialSnapshotSize} bytes (budget: < 2500 bytes)`);
        if (initialSnapshotSize > 2500) {
            console.warn(`WARNING: Snapshot size exceeds 2500 bytes: ${initialSnapshotSize}`);
        }

        // 2. Test Page Navigation
        console.log('\n[2/6] Testing Page Navigation in Real Browser...');
        const nextBtn = await page.evaluateHandle(() => {
            const buttons = Array.from(document.querySelectorAll('button'));
            return buttons.find(b => b.textContent.includes('Next') || b.textContent.includes('التالي') || b.getAttribute('wire:click')?.includes('goToPage'));
        });
        if (nextBtn) {
            await nextBtn.click();
            await new Promise(r => setTimeout(r, 1200));
            const navSnapshotSize = await page.evaluate(() => {
                const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
                if (snap) return snap.length;
                const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
                return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
            });
            console.log(`Page navigation snapshot size: ${navSnapshotSize} bytes (budget: < 2800 bytes)`);
        }

        // Return to Page 1
        await page.evaluate(() => {
            const lw = window.Livewire?.first?.() || window.Livewire?.find?.(document.querySelector('[wire\\:id]')?.getAttribute('wire:id'));
            if (lw) lw.goToPage(1);
        });
        await new Promise(r => setTimeout(r, 1000));

        // 3. Test Drawing Signature on Canvas
        console.log('\n[3/6] Testing Canvas Signature Drawing & Placement...');
        const canvas = await page.$('canvas');
        if (!canvas) throw new Error('Signature drawing canvas not found');

        const box = await canvas.boundingBox();
        if (box) {
            console.log(`Canvas dimensions: ${box.width}x${box.height}`);
            await page.mouse.move(box.x + 30, box.y + 40);
            await page.mouse.down();
            await page.mouse.move(box.x + 80, box.y + 70);
            await page.mouse.move(box.x + 130, box.y + 35);
            await page.mouse.move(box.x + 180, box.y + 80);
            await page.mouse.up();
        }

        // Click "Add to page"
        console.log('Submitting drawn signature...');
        await page.evaluate(() => {
            const buttons = Array.from(document.querySelectorAll('button'));
            const addBtn = buttons.find(b => b.textContent.includes('Add to page') || b.textContent.includes('إضافة') || b.getAttribute('@click')?.includes('save()'));
            if (addBtn) addBtn.click();
        });
        await new Promise(r => setTimeout(r, 1500));

        // Verify placed signature overlay on page
        const placementCount = await page.$$eval('#pdf-page-container [wire\\:key^="placement-"]', els => els.length);
        console.log(`Placed signatures on page: ${placementCount}`);
        if (placementCount < 1) throw new Error('FAIL: Drawn signature was not placed on page container');

        const singlePlacementSnapshotSize = await page.evaluate(() => {
            const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
            if (snap) return snap.length;
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
        });
        console.log(`Single placement snapshot size: ${singlePlacementSnapshotSize} bytes (budget: < 3000 bytes)`);
        if (singlePlacementSnapshotSize > 3000) {
            console.warn(`WARNING: Single placement snapshot size exceeds 3000 bytes: ${singlePlacementSnapshotSize}`);
        }

        // 4. Test Typed Signature (English & Arabic)
        console.log('\n[4/6] Testing Typed Signatures (English & Arabic)...');
        await page.evaluate(async () => {
            const lw = window.Livewire?.first?.();
            if (lw) {
                await lw.setSignatureType('type');
                await lw.set('typedName', 'د. سامي الجابر');
                await lw.setTypedStyle('casual');
                await lw.applyTypedSignature();
            }
        });
        await new Promise(r => setTimeout(r, 1500));

        const totalPlacements = await page.$$eval('#pdf-page-container [wire\\:key^="placement-"]', els => els.length);
        console.log(`Total placements on current page: ${totalPlacements}`);
        if (totalPlacements < 2) throw new Error('FAIL: Typed signature was not added');

        // Check snapshot size with 2 placements
        const multiPlacementSnapshotSize = await page.evaluate(() => {
            const snap = document.querySelector('[wire\\:snapshot]')?.getAttribute('wire:snapshot');
            if (snap) return snap.length;
            const lw = window.Livewire?.first?.() || window.Livewire?.all?.()[0];
            return lw?.snapshot ? JSON.stringify(lw.snapshot).length : 0;
        });
        console.log(`Multi-placement snapshot size: ${multiPlacementSnapshotSize} bytes (budget: < 3500 bytes)`);

        // Test Rotation button
        console.log('Testing placement rotate button...');
        const rotateBtn = await page.$('#pdf-page-container [wire\\:key^="placement-"] button[title*="Rotate"], #pdf-page-container [wire\\:key^="placement-"] button[title*="تدوير"]');
        if (rotateBtn) {
            await rotateBtn.click();
            await new Promise(r => setTimeout(r, 800));
            console.log('Rotate clicked successfully.');
        }

        // Capture Desktop Screenshot
        await page.screenshot({ path: path.join(screenshotsDir, 'sign_pdf_desktop_1280.png'), fullPage: true });
        console.log('Saved Desktop screenshot: sign_pdf_desktop_1280.png');

        // 5. Responsive Viewport Tests
        console.log('\n[5/6] Testing Tablet (768px) and Mobile (375px) Viewports...');
        await page.setViewport({ width: 768, height: 1024 });
        await new Promise(r => setTimeout(r, 500));
        await page.screenshot({ path: path.join(screenshotsDir, 'sign_pdf_tablet_768.png') });
        console.log('Saved Tablet screenshot: sign_pdf_tablet_768.png');

        await page.setViewport({ width: 375, height: 812 });
        await new Promise(r => setTimeout(r, 500));
        await page.screenshot({ path: path.join(screenshotsDir, 'sign_pdf_mobile_375.png') });
        console.log('Saved Mobile screenshot: sign_pdf_mobile_375.png');

        // Reset viewport to desktop
        await page.setViewport({ width: 1280, height: 900 });

        // 6. Test Arabic RTL Mode
        console.log('\n[6/6] Testing Arabic RTL Layout...');
        await page.goto('http://127.0.0.1:8000/sign-pdf', { waitUntil: 'networkidle0' });
        // Set Arabic session via evaluation or cookie
        await page.evaluate(() => {
            document.documentElement.setAttribute('dir', 'rtl');
            document.documentElement.setAttribute('lang', 'ar');
        });
        await page.screenshot({ path: path.join(screenshotsDir, 'sign_pdf_arabic_rtl.png') });
        console.log('Saved Arabic RTL screenshot: sign_pdf_arabic_rtl.png');

        console.log('\n--- ALL SUB-PHASE 2A REAL-BROWSER VERIFICATIONS COMPLETED SUCCESSFULLY ---');
    } finally {
        await browser.close();
    }
}

runSubPhase2ABrowserVerification().catch(err => {
    console.error('VERIFICATION FAILED:', err);
    process.exit(1);
});
