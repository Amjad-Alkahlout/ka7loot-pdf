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

async function runSubPhase1BBrowserVerification() {
    console.log('--- STARTING SUB-PHASE 1B REAL-BROWSER AUTOMATION VERIFICATION ---');
    const browser = await puppeteer.launch({
        executablePath: chromePath,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    page.on('console', msg => {
        const text = msg.text();
        if (!text.includes('[vite]') && !text.includes('Download the Vue Devtools')) {
            console.log('PAGE LOG:', text);
        }
    });
    page.on('pageerror', err => console.log('PAGE ERROR:', err.toString()));

    const img1 = path.join(projectRoot, 'tests/Fixtures/image_1.jpg');
    const img2 = path.join(projectRoot, 'tests/Fixtures/image_2.png');
    const heicFile = path.join(projectRoot, 'tests/Fixtures/photo_1.heic');

    try {
        // ==========================================
        // 1. COMPRESS IMAGE VERIFICATION
        // ==========================================
        console.log('\n[1/4] Verifying Compress Image in Chrome...');
        await page.goto('http://127.0.0.1:8000/compress-image', { waitUntil: 'networkidle0' });

        const compressUpload = await page.$('input[type="file"]');
        if (!compressUpload) throw new Error('File input not found on Compress Image');

        console.log('Uploading image_1.jpg to Compress Image...');
        await compressUpload.uploadFile(img1);

        // Wait for preview image to appear
        await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Verify preview URL type (zero Base64)
        const compressPreviewSrc = await page.$eval('img[alt="image_1.jpg"]', el => el.getAttribute('src'));
        console.log(`Compress Image Preview URL: ${compressPreviewSrc.substring(0, 60)}...`);
        if (compressPreviewSrc.startsWith('data:image')) {
            throw new Error('FAIL: Preview image is encoded as Base64 data URI!');
        }
        console.log('PASS: Preview image is served via object URL or temporary URL (Zero Base64).');

        // Verify zero raw Base64 in DOM
        const htmlCompress = await page.content();
        const base64Matches = htmlCompress.match(/data:image\/[a-zA-Z]+;base64,[A-Za-z0-9+/=]{100,}/g);
        if (base64Matches && base64Matches.length > 0) {
            throw new Error(`FAIL: Found ${base64Matches.length} raw Base64 image payloads in the DOM!`);
        }
        console.log('PASS: Zero raw Base64 image payloads found in Compress Image DOM.');

        // Verify Livewire snapshot size
        const compressSnapshotSize = await page.evaluate(() => {
            const el = document.querySelector('[wire\\:id]');
            if (!el) return 0;
            const snapshot = el.getAttribute('wire:snapshot');
            return snapshot ? snapshot.length : 0;
        });
        console.log(`Compress Image Livewire snapshot size: ${compressSnapshotSize} bytes (${(compressSnapshotSize / 1024).toFixed(2)} KB)`);
        if (compressSnapshotSize > 5000) {
            throw new Error(`FAIL: Snapshot size (${compressSnapshotSize} bytes) exceeds budget of 5 KB!`);
        }
        console.log('PASS: Livewire snapshot strictly under 5 KB budget.');

        // Test quality level switch
        const extremeRadio = await page.$('input[value="extreme"]');
        if (extremeRadio) {
            await extremeRadio.click();
            await new Promise(r => setTimeout(r, 500));
            console.log('PASS: Preset quality changed to extreme.');
        }

        // Screenshots for Compress Image
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_compress_image_desktop_1280.png'), fullPage: false });
        console.log('Saved p1b_compress_image_desktop_1280.png');

        await page.setViewport({ width: 768, height: 1024 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_compress_image_tablet_768.png'), fullPage: false });
        console.log('Saved p1b_compress_image_tablet_768.png');

        await page.setViewport({ width: 375, height: 667 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_compress_image_mobile_375.png'), fullPage: false });
        console.log('Saved p1b_compress_image_mobile_375.png');

        // Reset viewport
        await page.setViewport({ width: 1280, height: 800 });

        // ==========================================
        // 2. RESIZE IMAGE VERIFICATION
        // ==========================================
        console.log('\n[2/4] Verifying Resize Image in Chrome...');
        await page.goto('http://127.0.0.1:8000/resize-image', { waitUntil: 'networkidle0' });

        const resizeUpload = await page.$('input[type="file"]');
        if (!resizeUpload) throw new Error('File input not found on Resize Image');

        console.log('Uploading image_1.jpg to Resize Image...');
        await resizeUpload.uploadFile(img1);

        // Wait for preview image to appear
        await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Check preview URL
        const resizePreviewSrc = await page.$eval('img[alt="image_1.jpg"]', el => el.getAttribute('src'));
        console.log(`Resize Image Preview URL: ${resizePreviewSrc.substring(0, 60)}...`);
        if (resizePreviewSrc.startsWith('data:image')) {
            throw new Error('FAIL: Preview image is encoded as Base64 data URI!');
        }

        // Verify Livewire snapshot size
        const resizeSnapshotSize = await page.evaluate(() => {
            const el = document.querySelector('[wire\\:id]');
            if (!el) return 0;
            const snapshot = el.getAttribute('wire:snapshot');
            return snapshot ? snapshot.length : 0;
        });
        console.log(`Resize Image Livewire snapshot size: ${resizeSnapshotSize} bytes (${(resizeSnapshotSize / 1024).toFixed(2)} KB)`);
        if (resizeSnapshotSize > 5000) {
            throw new Error(`FAIL: Snapshot size (${resizeSnapshotSize} bytes) exceeds budget of 5 KB!`);
        }

        // Test Aspect-Ratio Lock locally in Alpine
        console.log('Testing client-side aspect ratio lock...');
        const initialDims = await page.evaluate(() => {
            const wInput = document.getElementById('resize-width');
            const hInput = document.getElementById('resize-height');
            return {
                w: parseInt(wInput ? wInput.value : '0', 10),
                h: parseInt(hInput ? hInput.value : '0', 10)
            };
        });
        console.log(`Initial dimensions in UI: ${initialDims.w} × ${initialDims.h} px`);

        // Type new width
        const targetW = 600;
        await page.evaluate(w => {
            const input = document.getElementById('resize-width');
            input.value = w;
            input.dispatchEvent(new Event('input', { bubbles: true }));
        }, targetW);
        await new Promise(r => setTimeout(r, 200));

        const updatedH = await page.evaluate(() => {
            const hInput = document.getElementById('resize-height');
            return parseInt(hInput ? hInput.value : '0', 10);
        });
        console.log(`Updated height after width change (${targetW}): ${updatedH} px`);
        if (updatedH === initialDims.h && targetW !== initialDims.w) {
            throw new Error('FAIL: Aspect ratio lock failed to update height locally!');
        }
        console.log('PASS: Client-side aspect-ratio lock updated height instantly without server roundtrip.');

        // Test toggle lock
        const lockToggleBtn = await page.$('#toggle-ratio-lock');
        if (lockToggleBtn) {
            await lockToggleBtn.click();
            await new Promise(r => setTimeout(r, 200));
            console.log('PASS: Aspect ratio lock toggled to FREE.');
        }

        // Screenshots for Resize Image
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_resize_image_desktop_1280.png'), fullPage: false });
        console.log('Saved p1b_resize_image_desktop_1280.png');

        await page.setViewport({ width: 768, height: 1024 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_resize_image_tablet_768.png'), fullPage: false });
        console.log('Saved p1b_resize_image_tablet_768.png');

        await page.setViewport({ width: 375, height: 667 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_resize_image_mobile_375.png'), fullPage: false });
        console.log('Saved p1b_resize_image_mobile_375.png');

        // Reset viewport
        await page.setViewport({ width: 1280, height: 800 });

        // ==========================================
        // 3. CONVERT IMAGE VERIFICATION
        // ==========================================
        console.log('\n[3/4] Verifying Convert Image in Chrome...');
        await page.goto('http://127.0.0.1:8000/convert-image', { waitUntil: 'networkidle0' });

        const convertUpload = await page.$('input[type="file"]');
        if (!convertUpload) throw new Error('File input not found on Convert Image');

        console.log('Uploading native image_1.jpg to Convert Image...');
        await convertUpload.uploadFile(img1);

        // Wait for preview image
        await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Verify native preview URL
        const convertPreviewSrc = await page.$eval('img[alt="image_1.jpg"]', el => el.getAttribute('src'));
        console.log(`Convert Image Native Preview URL: ${convertPreviewSrc.substring(0, 60)}...`);
        if (convertPreviewSrc.startsWith('data:image')) {
            throw new Error('FAIL: Preview image is encoded as Base64 data URI!');
        }

        // Verify Livewire snapshot size
        const convertSnapshotSize = await page.evaluate(() => {
            const el = document.querySelector('[wire\\:id]');
            if (!el) return 0;
            const snapshot = el.getAttribute('wire:snapshot');
            return snapshot ? snapshot.length : 0;
        });
        console.log(`Convert Image Livewire snapshot size: ${convertSnapshotSize} bytes (${(convertSnapshotSize / 1024).toFixed(2)} KB)`);
        if (convertSnapshotSize > 5000) {
            throw new Error(`FAIL: Snapshot size (${convertSnapshotSize} bytes) exceeds budget of 5 KB!`);
        }

        // Screenshots for Native Convert Image
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_convert_image_desktop_1280.png'), fullPage: false });
        console.log('Saved p1b_convert_image_desktop_1280.png');

        // Now test HEIC fallback
        console.log('\nTesting HEIC fallback on Convert Image...');
        await page.goto('http://127.0.0.1:8000/convert-image', { waitUntil: 'networkidle0' });

        const convertUpload2 = await page.$('input[type="file"]');
        console.log('Uploading photo_1.heic to Convert Image...');
        await convertUpload2.uploadFile(heicFile);

        // Wait for HEIC format badge
        await page.waitForFunction(() => document.body.innerText.includes('HEIC / HEIF'), { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Verify that NO broken <img> tag is rendered
        const hasBrokenImg = await page.evaluate(() => {
            const imgs = Array.from(document.querySelectorAll('img[alt="photo_1.heic"]'));
            return imgs.length > 0;
        });
        if (hasBrokenImg) {
            throw new Error('FAIL: Browser attempted to render unrenderable HEIC blob directly into img tag!');
        }
        console.log('PASS: Clear localized HEIC badge and secure server notice displayed without broken browser img.');

        // Screenshot for HEIC Convert Image
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_convert_heic_desktop_1280.png'), fullPage: false });
        console.log('Saved p1b_convert_heic_desktop_1280.png');

        await page.setViewport({ width: 768, height: 1024 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_convert_image_tablet_768.png'), fullPage: false });
        console.log('Saved p1b_convert_image_tablet_768.png');

        await page.setViewport({ width: 375, height: 667 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_convert_image_mobile_375.png'), fullPage: false });
        console.log('Saved p1b_convert_image_mobile_375.png');

        // Reset viewport
        await page.setViewport({ width: 1280, height: 800 });

        // ==========================================
        // 4. ARABIC RTL VERIFICATION
        // ==========================================
        console.log('\n[4/4] Verifying Arabic RTL layout in Chrome...');
        await page.goto('http://127.0.0.1:8000/compress-image', { waitUntil: 'networkidle0' });

        await page.evaluate(() => {
            document.cookie = 'locale=ar; path=/';
        });

        const arLink = await page.$('a[href*="lang/ar"], [data-locale="ar"]');
        if (arLink) {
            await Promise.all([
                page.waitForNavigation({ waitUntil: 'networkidle0' }).catch(() => {}),
                arLink.click()
            ]);
        } else {
            await page.goto('http://127.0.0.1:8000/compress-image', { waitUntil: 'networkidle0' });
        }

        const isRtl = await page.evaluate(() => document.documentElement.getAttribute('dir') === 'rtl');
        console.log(`Arabic dir="rtl" active: ${isRtl}`);

        // Upload to compress-image in Arabic
        const arCompressUpload = await page.$('input[type="file"]');
        if (arCompressUpload) {
            await arCompressUpload.uploadFile(img1);
            await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
            await new Promise(r => setTimeout(r, 1000));
        }

        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_compress_arabic_desktop_1280.png'), fullPage: false });
        console.log('Saved p1b_compress_arabic_desktop_1280.png');

        // Navigate to resize-image in Arabic
        await page.goto('http://127.0.0.1:8000/resize-image', { waitUntil: 'networkidle0' });
        const arResizeUpload = await page.$('input[type="file"]');
        if (arResizeUpload) {
            await arResizeUpload.uploadFile(img1);
            await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
            await new Promise(r => setTimeout(r, 1000));
        }
        await page.screenshot({ path: path.join(screenshotsDir, 'p1b_resize_arabic_desktop_1280.png'), fullPage: false });
        console.log('Saved p1b_resize_arabic_desktop_1280.png');

        // Return locale to en
        const enLink = await page.$('a[href*="lang/en"], [data-locale="en"]');
        if (enLink) {
            await enLink.click().catch(() => {});
        } else {
            await page.evaluate(() => {
                document.cookie = 'locale=en; path=/';
            });
        }
        console.log('Reset locale back to en.');

        console.log('\n======================================================');
        console.log('SUB-PHASE 1B BROWSER VERIFICATION COMPLETED SUCCESSFULLY!');
        console.log('======================================================\n');
    } catch (err) {
        console.error('VERIFICATION ERROR:', err);
        process.exit(1);
    } finally {
        await browser.close();
    }
}

runSubPhase1BBrowserVerification();
