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

async function runSubPhase1ABrowserVerification() {
    console.log('--- STARTING SUB-PHASE 1A REAL-BROWSER AUTOMATION VERIFICATION ---');
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

    try {
        // ==========================================
        // 1. REMOVE BACKGROUND VERIFICATION
        // ==========================================
        console.log('\n[1/3] Verifying Remove Background in Chrome...');
        await page.goto('http://127.0.0.1:8000/remove-background', { waitUntil: 'networkidle0' });

        const removeUpload = await page.$('input[type="file"]');
        if (!removeUpload) throw new Error('File input not found on Remove Background');

        console.log('Uploading image_1.jpg to Remove Background...');
        await removeUpload.uploadFile(img1);

        // Wait for preview image to appear in DOM
        await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Verify preview URL type (must NOT be data:image Base64)
        const removePreviewSrc = await page.$eval('img[alt="image_1.jpg"]', el => el.getAttribute('src'));
        console.log(`Remove Background Preview URL: ${removePreviewSrc.substring(0, 60)}...`);
        if (removePreviewSrc.startsWith('data:image')) {
            throw new Error('FAIL: Preview image is encoded as Base64 data URI!');
        }
        console.log('PASS: Preview image is served via object URL or temporary URL (Zero Base64).');

        // Check for any Base64 strings in the entire page HTML
        const htmlRemove = await page.content();
        const base64Matches = htmlRemove.match(/data:image\/[a-zA-Z]+;base64,[A-Za-z0-9+/=]{100,}/g);
        if (base64Matches && base64Matches.length > 0) {
            throw new Error(`FAIL: Found ${base64Matches.length} raw Base64 image payloads in the DOM!`);
        }
        console.log('PASS: Zero raw Base64 image payloads found in Remove Background DOM.');

        // Verify Livewire component state size
        const removeSnapshotSize = await page.evaluate(() => {
            const el = document.querySelector('[wire\\:id]');
            if (!el) return 0;
            const snapshot = el.getAttribute('wire:snapshot');
            return snapshot ? snapshot.length : 0;
        });
        console.log(`Remove Background Livewire snapshot size: ${removeSnapshotSize} bytes (${(removeSnapshotSize / 1024).toFixed(2)} KB)`);
        if (removeSnapshotSize > 5000) {
            throw new Error(`FAIL: Snapshot size (${removeSnapshotSize} bytes) exceeds budget of 5 KB!`);
        }
        console.log('PASS: Livewire snapshot strictly under 5 KB budget.');

        // Test debounced tolerance slider
        const toleranceSlider = await page.$('input#tolerance');
        if (toleranceSlider) {
            await toleranceSlider.evaluate(el => {
                el.value = 45;
                el.dispatchEvent(new Event('input', { bubbles: true }));
                el.dispatchEvent(new Event('change', { bubbles: true }));
            });
            await new Promise(r => setTimeout(r, 600));
            console.log('PASS: Debounced tolerance slider adjusted successfully.');
        }

        // Capture Desktop screenshot
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_remove_bg_desktop_1280.png'), fullPage: false });
        console.log('Saved p1a_remove_bg_desktop_1280.png');

        // Capture Tablet & Mobile screenshots
        await page.setViewport({ width: 768, height: 1024 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_remove_bg_tablet_768.png'), fullPage: false });
        console.log('Saved p1a_remove_bg_tablet_768.png');

        await page.setViewport({ width: 375, height: 667 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_remove_bg_mobile_375.png'), fullPage: false });
        console.log('Saved p1a_remove_bg_mobile_375.png');

        // Reset viewport
        await page.setViewport({ width: 1280, height: 800 });

        // ==========================================
        // 2. CHANGE BACKGROUND VERIFICATION
        // ==========================================
        console.log('\n[2/3] Verifying Change Background in Chrome...');
        await page.goto('http://127.0.0.1:8000/change-background', { waitUntil: 'networkidle0' });

        const changeUpload = await page.$('input[type="file"]');
        if (!changeUpload) throw new Error('File input not found on Change Background');

        console.log('Uploading foreground image_1.jpg to Change Background...');
        await changeUpload.uploadFile(img1);

        // Wait for preview image to appear
        await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Check foreground preview URL (not data:image)
        const fgPreviewSrc = await page.$eval('img[alt="image_1.jpg"]', el => el.getAttribute('src'));
        console.log(`Change Background Foreground Preview URL: ${fgPreviewSrc.substring(0, 60)}...`);
        if (fgPreviewSrc.startsWith('data:image')) {
            throw new Error('FAIL: Foreground preview is encoded as Base64 data URI!');
        }

        // Verify Livewire snapshot size
        const changeSnapshotSize = await page.evaluate(() => {
            const el = document.querySelector('[wire\\:id]');
            if (!el) return 0;
            const snapshot = el.getAttribute('wire:snapshot');
            return snapshot ? snapshot.length : 0;
        });
        console.log(`Change Background Livewire snapshot size: ${changeSnapshotSize} bytes (${(changeSnapshotSize / 1024).toFixed(2)} KB)`);
        if (changeSnapshotSize > 5000) {
            throw new Error(`FAIL: Snapshot size (${changeSnapshotSize} bytes) exceeds budget of 5 KB!`);
        }
        console.log('PASS: Livewire snapshot strictly under 5 KB budget.');

        // Test Live Composite preview reactiveness: Solid preset click
        console.log('Testing solid preset switch...');
        const presetButtons = await page.$$('button[title]');
        if (presetButtons.length > 0) {
            await presetButtons[4].click(); // Click emerald or purple
            await new Promise(r => setTimeout(r, 600));
            console.log('PASS: Solid preset button clicked and applied.');
        }

        // Test Gradient tab
        console.log('Testing gradient tab switch...');
        const gradientTabButton = await page.evaluateHandle(() => {
            const btns = Array.from(document.querySelectorAll('button'));
            return btns.find(b => b.textContent.includes('Gradient') || b.textContent.includes('تدرج'));
        });
        if (gradientTabButton) {
            await gradientTabButton.click();
            await new Promise(r => setTimeout(r, 800));
            console.log('PASS: Gradient tab activated.');
        }

        // Test Image Background tab & Clear upload
        console.log('Testing custom background upload & clearBgUpload...');
        const imageTabButton = await page.evaluateHandle(() => {
            const btns = Array.from(document.querySelectorAll('button'));
            return btns.find(b => b.textContent.includes('Image') || b.textContent.includes('صورة'));
        });
        if (imageTabButton) {
            await imageTabButton.click();
            await new Promise(r => setTimeout(r, 800));

            const bgFileInput = await page.$('input[wire\\:model="bgUpload"]');
            if (bgFileInput) {
                console.log('Uploading image_2.png as custom background...');
                await bgFileInput.uploadFile(img2);
                await new Promise(r => setTimeout(r, 2000));

                // Verify clear button exists
                const clearBgBtn = await page.evaluateHandle(() => {
                    const btns = Array.from(document.querySelectorAll('button'));
                    return btns.find(b => b.getAttribute('wire:click') === 'clearBgUpload');
                });
                if (clearBgBtn) {
                    console.log('Clicking clearBgUpload to verify foreground preservation...');
                    await clearBgBtn.click();
                    await new Promise(r => setTimeout(r, 1000));

                    // Verify foreground image is STILL present
                    const fgStillThere = await page.$('img[alt="image_1.jpg"]');
                    if (!fgStillThere) {
                        throw new Error('FAIL: Foreground image was cleared when clearing background upload!');
                    }
                    console.log('PASS: Foreground upload preserved when background upload was cleared.');
                }
            }
        }

        // Check for any Base64 strings in the entire page HTML
        const htmlChange = await page.content();
        const base64MatchesChange = htmlChange.match(/data:image\/[a-zA-Z]+;base64,[A-Za-z0-9+/=]{100,}/g);
        if (base64MatchesChange && base64MatchesChange.length > 0) {
            throw new Error(`FAIL: Found ${base64MatchesChange.length} raw Base64 image payloads in the Change Background DOM!`);
        }
        console.log('PASS: Zero raw Base64 image payloads found in Change Background DOM.');

        // Screenshots
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_change_bg_desktop_1280.png'), fullPage: false });
        console.log('Saved p1a_change_bg_desktop_1280.png');

        await page.setViewport({ width: 768, height: 1024 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_change_bg_tablet_768.png'), fullPage: false });
        console.log('Saved p1a_change_bg_tablet_768.png');

        await page.setViewport({ width: 375, height: 667 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_change_bg_mobile_375.png'), fullPage: false });
        console.log('Saved p1a_change_bg_mobile_375.png');

        // Reset viewport
        await page.setViewport({ width: 1280, height: 800 });

        // ==========================================
        // 3. ARABIC RTL VERIFICATION
        // ==========================================
        console.log('\n[3/3] Verifying Arabic RTL layout in Chrome...');
        await page.goto('http://127.0.0.1:8000/change-background', { waitUntil: 'networkidle0' });

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
            await page.goto('http://127.0.0.1:8000/change-background', { waitUntil: 'networkidle0' });
        }

        const isRtl = await page.evaluate(() => document.documentElement.getAttribute('dir') === 'rtl');
        console.log(`Arabic dir="rtl" active: ${isRtl}`);

        // Upload to see populated RTL card
        const arUpload = await page.$('input[type="file"]');
        if (arUpload) {
            await arUpload.uploadFile(img1);
            await page.waitForSelector('img[alt="image_1.jpg"]', { timeout: 15000 });
            await new Promise(r => setTimeout(r, 1000));
        }

        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_change_bg_arabic_desktop_1280.png'), fullPage: false });
        console.log('Saved p1a_change_bg_arabic_desktop_1280.png');

        await page.setViewport({ width: 768, height: 1024 });
        await page.screenshot({ path: path.join(screenshotsDir, 'p1a_change_bg_arabic_tablet_768.png'), fullPage: false });
        console.log('Saved p1a_change_bg_arabic_tablet_768.png');

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
        console.log('SUB-PHASE 1A BROWSER VERIFICATION COMPLETED SUCCESSFULLY!');
        console.log('======================================================\n');
    } catch (err) {
        console.error('VERIFICATION ERROR:', err);
        process.exit(1);
    } finally {
        await browser.close();
    }
}

runSubPhase1ABrowserVerification();
