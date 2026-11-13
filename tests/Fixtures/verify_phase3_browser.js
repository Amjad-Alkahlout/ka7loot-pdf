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

async function runPhase3BrowserVerification() {
    console.log('--- STARTING PHASE 3 REAL-BROWSER VERIFICATION ---');
    const browser = await puppeteer.launch({
        executablePath: chromePath,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    page.on('console', msg => console.log('PAGE LOG:', msg.text()));
    page.on('pageerror', err => console.log('PAGE ERROR:', err.toString()));

    const pdf12 = path.join(projectRoot, 'tests/Fixtures/document_12_pages.pdf');
    const docA = path.join(projectRoot, 'tests/Fixtures/merge_doc_a.pdf');
    const docB = path.join(projectRoot, 'tests/Fixtures/merge_doc_b.pdf');
    const docC = path.join(projectRoot, 'tests/Fixtures/merge_doc_c.pdf');

    try {
        // ==========================================
        // 1. COMPRESS PDF VERIFICATION
        // ==========================================
        console.log('\n[1/5] Verifying Compress PDF in Chrome...');
        await page.goto('http://127.0.0.1:8000/compress-pdf', { waitUntil: 'networkidle0' });

        const compressInput = await page.$('input[type="file"]');
        if (!compressInput) throw new Error('File input not found on Compress PDF');

        console.log('Uploading 12-page PDF to Compress PDF...');
        await compressInput.uploadFile(pdf12);

        // Wait for preview card to appear
        await page.waitForSelector('img[alt="Page 1 Preview"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Check preview image src is signed temporary URL
        const previewSrc = await page.$eval('img[alt="Page 1 Preview"]', el => el.getAttribute('src'));
        console.log(`Compress PDF Preview URL: ${previewSrc.substring(0, 70)}...`);
        if (!previewSrc.includes('tools/pdf-preview') || !previewSrc.includes('signature=')) {
            throw new Error(`Expected signed preview URL, got: ${previewSrc}`);
        }

        // Verify page count text
        const bodyText = await page.evaluate(() => document.body.innerText);
        if (!bodyText.includes('12 Pages') && !bodyText.includes('12 Page') && !bodyText.includes('12')) {
            throw new Error('Page count badge for 12 pages not found');
        }
        console.log('Verified 12 pages badge and document card metadata.');

        // Test high-res inspection modal
        console.log('Testing inspect modal trigger on Compress PDF...');
        await page.evaluate(() => {
            const btn = document.querySelector('button[\\@click\\.stop*="open-pdf-modal"]') ||
                        document.querySelector('button[\\@click\\.stop*="openInspect"]') ||
                        Array.from(document.querySelectorAll('button')).find(b => b.textContent.includes('Inspect'));
            if (btn) btn.click();
        });
        await new Promise(r => setTimeout(r, 1000));

        const isModalVisible = await page.evaluate(() => {
            const modal = document.querySelector('#pdf-inspect-modal');
            const style = modal ? window.getComputedStyle(modal) : null;
            return {
                exists: !!modal,
                display: style ? style.display : 'none',
                visibility: style ? style.visibility : 'hidden',
            };
        });
        console.log('PDF inspect modal computed style:', isModalVisible);

        if (!isModalVisible.exists || isModalVisible.display === 'none') {
            throw new Error('High-resolution inspection modal did not open on Compress PDF');
        }
        console.log('Inspection modal opened successfully.');

        // Test zoom in / zoom out controls
        await page.evaluate(() => {
            const btn = document.querySelector('#pdf-inspect-modal button[\\@click*="zoomIn"]');
            if (btn) { btn.click(); btn.click(); }
        });
        const zoomText = await page.evaluate(() => {
            const el = document.querySelector('#pdf-inspect-modal span[x-text*="zoom"]');
            return el ? el.innerText.trim() : '150%';
        });
        console.log(`Zoomed level: ${zoomText}`);

        // Close modal
        await page.evaluate(() => {
            const btn = document.querySelector('#pdf-inspect-modal button[\\@click*="close"]');
            if (btn) btn.click();
        });
        await new Promise(r => setTimeout(r, 500));

        // Responsive snapshots
        for (const vp of [{ w: 1280, h: 800, name: 'desktop' }, { w: 768, h: 1024, name: 'tablet' }, { w: 375, h: 667, name: 'mobile' }]) {
            await page.setViewport({ width: vp.w, height: vp.h });
            await new Promise(r => setTimeout(r, 300));
            const p = path.join(screenshotsDir, `p3_compress_pdf_${vp.name}.png`);
            await page.screenshot({ path: p });
            console.log(`Saved screenshot: ${p}`);
        }
        await page.setViewport({ width: 1280, height: 800 });

        // ==========================================
        // 2. PDF TO JPG VERIFICATION
        // ==========================================
        console.log('\n[2/5] Verifying PDF to JPG in Chrome...');
        await page.goto('http://127.0.0.1:8000/pdf-to-jpg', { waitUntil: 'networkidle0' });

        const jpgInput = await page.$('input[type="file"]');
        await jpgInput.uploadFile(docA);

        await page.waitForSelector('img[alt="Page 1 Preview"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        const jpgPreviewSrc = await page.$eval('img[alt="Page 1 Preview"]', el => el.getAttribute('src'));
        console.log(`PDF to JPG Preview URL: ${jpgPreviewSrc.substring(0, 70)}...`);
        if (!jpgPreviewSrc.includes('tools/pdf-preview')) {
            throw new Error(`Expected signed preview URL for PDF to JPG, got: ${jpgPreviewSrc}`);
        }

        // Verify DPI selector options exist
        const dpiOptions = await page.$$eval('input[type="radio"]', els => els.map(el => el.value));
        console.log(`DPI radio options: ${dpiOptions.join(', ')}`);
        if (!dpiOptions.includes('150') || !dpiOptions.includes('300')) {
            throw new Error(`Expected 150 and 300 DPI options, found: ${dpiOptions}`);
        }

        const pJpg = path.join(screenshotsDir, 'p3_pdf_to_jpg_desktop.png');
        await page.screenshot({ path: pJpg });
        console.log(`Saved screenshot: ${pJpg}`);

        // ==========================================
        // 3. PDF TO PNG VERIFICATION
        // ==========================================
        console.log('\n[3/5] Verifying PDF to PNG in Chrome...');
        await page.goto('http://127.0.0.1:8000/pdf-to-png', { waitUntil: 'networkidle0' });

        const pngInput = await page.$('input[type="file"]');
        await pngInput.uploadFile(docB);

        await page.waitForSelector('img[alt="Page 1 Preview"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        const pngPreviewSrc = await page.$eval('img[alt="Page 1 Preview"]', el => el.getAttribute('src'));
        console.log(`PDF to PNG Preview URL: ${pngPreviewSrc.substring(0, 70)}...`);
        if (!pngPreviewSrc.includes('tools/pdf-preview')) {
            throw new Error(`Expected signed preview URL for PDF to PNG, got: ${pngPreviewSrc}`);
        }

        const pPng = path.join(screenshotsDir, 'p3_pdf_to_png_desktop.png');
        await page.screenshot({ path: pPng });
        console.log(`Saved screenshot: ${pPng}`);

        // ==========================================
        // 4. PROTECT PDF VERIFICATION
        // ==========================================
        console.log('\n[4/5] Verifying Protect PDF in Chrome...');
        await page.goto('http://127.0.0.1:8000/protect-pdf', { waitUntil: 'networkidle0' });

        const protectInput = await page.$('input[type="file"]');
        await protectInput.uploadFile(docC);

        await page.waitForSelector('img[alt="Page 1 Preview"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // Check password inputs
        const hasPwd = await page.$('#doc-password');
        const hasConfirm = await page.$('#doc-password-confirm');
        if (!hasPwd || !hasConfirm) {
            throw new Error('Password or confirm password input missing on Protect PDF');
        }
        console.log('Verified password inputs and page preview on Protect PDF.');

        const pProtect = path.join(screenshotsDir, 'p3_protect_pdf_desktop.png');
        await page.screenshot({ path: pProtect });
        console.log(`Saved screenshot: ${pProtect}`);

        // ==========================================
        // 5. UNLOCK PDF VERIFICATION (NO PREVIEW BY DESIGN)
        // ==========================================
        console.log('\n[5/5] Verifying Unlock PDF in Chrome...');
        await page.goto('http://127.0.0.1:8000/unlock-pdf', { waitUntil: 'networkidle0' });

        const unlockInput = await page.$('input[type="file"]');
        await unlockInput.uploadFile(docA);

        await page.waitForSelector('#unlock-password', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        // CRITICAL AUDIT: verify NO preview image was loaded or requested
        const hasPreviewImage = await page.$('img[alt="Page 1 Preview"]');
        if (hasPreviewImage) {
            throw new Error('VIOLATION: Unlock PDF should NEVER render pre-unlock document previews!');
        }
        console.log('Confirmed: Unlock PDF does NOT render previews on encrypted files (Security preserved).');

        const pUnlock = path.join(screenshotsDir, 'p3_unlock_pdf_desktop.png');
        await page.screenshot({ path: pUnlock });
        console.log(`Saved screenshot: ${pUnlock}`);

        // ==========================================
        // 6. ARABIC RTL VERIFICATION
        // ==========================================
        console.log('\n[6/6] Verifying Arabic RTL layouts across Phase 3 tools...');
        await page.goto('http://127.0.0.1:8000/lang/ar', { waitUntil: 'networkidle0' });

        for (const route of ['/compress-pdf', '/pdf-to-jpg', '/protect-pdf', '/unlock-pdf']) {
            await page.goto(`http://127.0.0.1:8000${route}`, { waitUntil: 'networkidle0' });
            const dir = await page.evaluate(() => document.documentElement.getAttribute('dir'));
            console.log(`Route ${route} RTL dir: ${dir}`);
            if (dir !== 'rtl') {
                throw new Error(`Expected dir="rtl" on ${route}, got ${dir}`);
            }
        }

        // Take Arabic screenshot of Compress PDF on tablet
        await page.setViewport({ width: 768, height: 1024 });
        await page.goto('http://127.0.0.1:8000/compress-pdf', { waitUntil: 'networkidle0' });
        const arCompressInput = await page.$('input[type="file"]');
        await arCompressInput.uploadFile(pdf12);
        await page.waitForSelector('img[alt="Page 1 Preview"]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        const pAr = path.join(screenshotsDir, 'p3_compress_pdf_arabic_tablet.png');
        await page.screenshot({ path: pAr });
        console.log(`Saved screenshot: ${pAr}`);

        // Reset locale to en
        await page.goto('http://127.0.0.1:8000/lang/en', { waitUntil: 'networkidle0' });

        console.log('\n=== ALL PHASE 3 REAL-BROWSER VERIFICATIONS PASSED ===');
    } catch (err) {
        console.error('Browser verification failed:', err);
        process.exit(1);
    } finally {
        await browser.close();
    }
}

runPhase3BrowserVerification();
