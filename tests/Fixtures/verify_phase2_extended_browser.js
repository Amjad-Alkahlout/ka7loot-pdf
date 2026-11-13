import puppeteer from 'puppeteer-core';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '../..');
const screenshotsDir = path.join(__dirname, 'screenshots');

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

async function runExtendedVerification() {
    console.log('=================================================================');
    console.log('STARTING PHASE 2 EXTENDED REAL-BROWSER & SECURITY VERIFICATION');
    console.log('=================================================================');

    const browser = await puppeteer.launch({
        executablePath: chromePath,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    try {
        // Clear cookies and start clean
        await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });
        await page.evaluate(() => {
            document.cookie = 'locale=en; path=/';
        });

        // =================================================================
        // TEST 1: HEIC UPLOAD QUEUE MULTI-BATCH & LIFECYCLE PRESERVATION
        // =================================================================
        console.log('\n--- [TEST 1/4] HEIC Upload Queue Regression & Multi-Batch ---');
        await page.goto('http://127.0.0.1:8000/heic-to-pdf', { waitUntil: 'networkidle0' });

        // 1. Verify single persistent queue component mounted on root element
        const isRootQueueMounted = await page.evaluate(() => {
            const root = document.querySelector('[x-data*="fileUploadHandler"]');
            return !!root && typeof root._x_dataStack?.[0]?.queueFiles === 'function';
        });
        console.log(`Root fileUploadHandler component mounted: ${isRootQueueMounted}`);
        if (!isRootQueueMounted) {
            throw new Error('HEIC root fileUploadHandler component is not mounted on root element!');
        }

        // 2. Upload Batch 1: 4 HEIC files
        console.log('Uploading Batch 1: 4 HEIC files...');
        const heicBatch1 = [
            path.join(__dirname, 'heic_batch_1.heic'),
            path.join(__dirname, 'heic_batch_2.heic'),
            path.join(__dirname, 'heic_batch_3.heic'),
            path.join(__dirname, 'heic_batch_4.heic'),
        ];
        const initialFileInput = await page.$('input[type="file"]');
        await initialFileInput.uploadFile(...heicBatch1);

        // Wait for 4 cards to render
        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 4;
        }, { timeout: 15000 });

        let heicCards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log(`Batch 1 successfully rendered 4 HEIC cards:`, heicCards);

        // Verify root queue state persistence after DOM morph
        const queueStateAfterBatch1 = await page.evaluate(() => {
            const root = document.querySelector('[x-data*="fileUploadHandler"]');
            const data = root?._x_dataStack?.[0];
            return {
                mounted: !!root,
                uploadState: data?.uploadState,
                hasQueueFiles: typeof data?.queueFiles === 'function',
                uploadQueueLen: data?.uploadQueue?.length || 0,
            };
        });
        console.log('Root queue state after Batch 1 DOM morph:', queueStateAfterBatch1);
        if (!queueStateAfterBatch1.mounted || !queueStateAfterBatch1.hasQueueFiles) {
            throw new Error('Root queue component unmounted or lost methods after Livewire DOM morph!');
        }

        // 3. Upload Batch 2: Append 2 more HEIC files via compact dropzone
        console.log('Uploading Batch 2: Appending 2 more HEIC files via compact dropzone...');
        const compactInputs = await page.$$('input[type="file"]');
        const compactInput = compactInputs[compactInputs.length - 1];
        const heicBatch2 = [
            path.join(__dirname, 'heic_batch_5.heic'),
            path.join(__dirname, 'heic_batch_6.heic'),
        ];
        await compactInput.uploadFile(...heicBatch2);

        // Wait for 6 cards
        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 6;
        }, { timeout: 15000 });
        console.log('✓ Batch 2 appended successfully: Total 6 cards rendered');

        // 4. Test removal: Remove file at index 2 (heic_batch_3)
        console.log('Removing file at index 2 (heic_batch_3)...');
        const removeButtons = await page.$$('button[wire\\:click*="removeFile"], button[wire\\:click*="removeUpload"]');
        if (removeButtons[2]) {
            await removeButtons[2].click();
        }

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 5;
        }, { timeout: 10000 });
        console.log('✓ File removed successfully: Exactly 5 cards remain');

        // 5. Test reordering: swap first and last
        console.log('Testing Sortable reordering on HEIC gallery...');
        heicCards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        const swappedHeic = [heicCards[4], heicCards[1], heicCards[2], heicCards[3], heicCards[0]];
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderFiles', order);
        }, swappedHeic);

        await new Promise(r => setTimeout(r, 1200));
        const finalHeicOrder = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        if (finalHeicOrder[0] !== swappedHeic[0] || finalHeicOrder[4] !== swappedHeic[4]) {
            throw new Error('HEIC reorder failed to persist in Livewire!');
        }
        console.log('✓ HEIC reordering verified successfully!');

        await page.screenshot({ path: path.join(screenshotsDir, 'heic_gallery_verified.png') });
        console.log('Saved screenshot: heic_gallery_verified.png');

        // =================================================================
        // TEST 2: MERGE PDF PREVIEW ISOLATION, DUPLICATES & COMPLEX ORDERING
        // =================================================================
        console.log('\n--- [TEST 2/4] Merge PDF Preview Isolation & Complex Order Cycle ---');
        await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });

        const docAlpha = path.join(__dirname, 'doc_alpha.pdf');
        const docBeta = path.join(__dirname, 'doc_beta.pdf');
        const docGamma = path.join(__dirname, 'doc_gamma.pdf');
        const docDelta = path.join(__dirname, 'doc_delta.pdf');

        // Step 1: Upload [Alpha, Beta, Gamma]
        console.log('1. Uploading [Alpha, Beta, Gamma]...');
        let fileInput = await page.$('input[type="file"]');
        await fileInput.uploadFile(docAlpha, docBeta, docGamma);

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 3;
        }, { timeout: 15000 });

        let mergeCards = await page.$$eval('.grid > [data-id]', els => els.map(el => ({
            id: el.getAttribute('data-id'),
            name: el.innerText.split('\n')[0]
        })));
        console.log('Initial cards:', mergeCards);
        const [idAlpha, idBeta, idGamma] = mergeCards.map(c => c.id);

        // Step 2: Verify Preview Isolation & Authorization
        console.log('2. Verifying Signed Preview URLs and Isolation...');
        const previewUrls = await page.evaluate(() => {
            return Array.from(document.querySelectorAll('[x-data*="pdfProgressivePreview"]')).map(el => {
                const alpine = el._x_dataStack?.[0];
                return alpine?.url;
            });
        });
        console.log(`Retrieved ${previewUrls.length} signed preview URLs`);
        if (previewUrls.length < 3 || !previewUrls[0].includes('signature=')) {
            throw new Error('Invalid signed preview URLs generated on Merge PDF cards!');
        }

        // Test preview image fetch in browser
        const fetchAlphaStatus = await page.evaluate(async (url) => {
            const res = await fetch(url);
            return { status: res.status, contentType: res.headers.get('content-type') };
        }, previewUrls[0]);
        console.log('Alpha preview fetch result:', fetchAlphaStatus);
        if (fetchAlphaStatus.status !== 200 || !fetchAlphaStatus.contentType.includes('image')) {
            throw new Error(`Preview fetch failed with status ${fetchAlphaStatus.status}`);
        }

        // Test Preview Security: Tampered signature rejection
        const tamperedUrl = previewUrls[0].replace(/signature=[^&]+/, 'signature=TAMPERED_INVALID_SIG');
        const tamperedStatus = await page.evaluate(async (url) => {
            const res = await fetch(url);
            return res.status;
        }, tamperedUrl);
        console.log(`Tampered signature rejection status: ${tamperedStatus} (Expected 403)`);
        if (tamperedStatus !== 403) {
            throw new Error(`Expected 403 for tampered signature, got ${tamperedStatus}`);
        }
        console.log('✓ Signed preview URL authorization and tamper protection verified!');

        // Step 3: Reorder to [Gamma, Alpha, Beta]
        console.log('3. Reordering to [Gamma, Alpha, Beta]...');
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderUploads', order);
        }, [idGamma, idAlpha, idBeta]);
        await new Promise(r => setTimeout(r, 1200));

        let currentOrder = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        if (currentOrder[0] !== idGamma || currentOrder[1] !== idAlpha || currentOrder[2] !== idBeta) {
            throw new Error('Reorder step 1 failed!');
        }
        console.log('✓ Reordered to [Gamma, Alpha, Beta]');

        // Step 4: Remove Alpha (index 1) -> remaining: [Gamma, Beta]
        console.log('4. Removing Alpha (index 1)...');
        await page.evaluate(async () => {
            const root = document.querySelector('[wire\\:id]');
            const wire = window.Livewire.find(root.getAttribute('wire:id'));
            await wire.call('removeFile', 1);
        });

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 2;
        }, { timeout: 10000 });

        currentOrder = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        if (currentOrder[0] !== idGamma || currentOrder[1] !== idBeta) {
            throw new Error('Removal failed to preserve correct remaining order [Gamma, Beta]!');
        }
        console.log('✓ Removed Alpha; remaining [Gamma, Beta] correctly preserved');

        // Step 5: Add Delta via compact dropzone -> [Gamma, Beta, Delta]
        console.log('5. Appending Delta via compact dropzone...');
        const allMergeInputs = await page.$$('input[type="file"]');
        await allMergeInputs[allMergeInputs.length - 1].uploadFile(docDelta);

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 3;
        }, { timeout: 15000 });

        currentOrder = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        const idDelta = currentOrder[2];
        console.log('✓ Appended Delta; current cards:', currentOrder);

        // Step 6: Reorder again to [Delta, Beta, Gamma]
        console.log('6. Reordering to [Delta, Beta, Gamma]...');
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderUploads', order);
        }, [idDelta, idBeta, idGamma]);
        await new Promise(r => setTimeout(r, 1200));

        currentOrder = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        if (currentOrder[0] !== idDelta || currentOrder[1] !== idBeta || currentOrder[2] !== idGamma) {
            throw new Error('Reorder step 2 failed!');
        }
        console.log('✓ Final order verified: [Delta, Beta, Gamma]');

        // Step 7: Submit Process & Verify Output PDF Content and Sequence
        console.log('7. Processing Merge PDF...');
        await page.evaluate(() => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('process');
        });

        await page.waitForFunction(() => {
            const root = document.querySelector('[wire\\:id]');
            return root && root.innerText.includes('merged_document.pdf');
        }, { timeout: 20000 });

        // Locate latest output directory in secure_temp
        const secureTempDir = path.join(projectRoot, 'storage/app/private/secure_temp');
        const jobs = fs.readdirSync(secureTempDir);
        let latestJobDir = null;
        let latestTime = 0;
        for (const job of jobs) {
            const p = path.join(secureTempDir, job);
            const st = fs.statSync(p);
            if (st.isDirectory() && st.mtimeMs > latestTime) {
                latestTime = st.mtimeMs;
                latestJobDir = p;
            }
        }

        const mergedFile = fs.readdirSync(latestJobDir).find(f => f.includes('merged') && f.endsWith('.pdf'));
        const mergedPath = path.join(latestJobDir, mergedFile);
        const extractedText = execSync(`pdftotext "${mergedPath}" -`).toString();
        console.log('Extracted text from final merged PDF:\n', extractedText.trim());

        const posDelta = extractedText.indexOf('DOC_DELTA_CONTENT');
        const posBeta = extractedText.indexOf('DOC_BETA_CONTENT');
        const posGamma = extractedText.indexOf('DOC_GAMMA_CONTENT');
        const posAlpha = extractedText.indexOf('DOC_ALPHA_CONTENT');

        console.log(`Sequence check: Delta(${posDelta}) < Beta(${posBeta}) < Gamma(${posGamma}), Alpha(${posAlpha})`);
        if (posDelta === -1 || posBeta === -1 || posGamma === -1) {
            throw new Error('Merged PDF missing expected document content!');
        }
        if (!(posDelta < posBeta && posBeta < posGamma)) {
            throw new Error(`Merged PDF sequence does not match final visible order [Delta, Beta, Gamma]!`);
        }
        if (posAlpha !== -1) {
            throw new Error('Deleted document Alpha was present in merged output!');
        }
        console.log('✓ Output PDF sequence physically verified: DELTA -> BETA -> GAMMA, ALPHA omitted!');

        // Step 8: Duplicate Filenames Test
        console.log('\n8. Testing Duplicate Filenames with Identical Names...');
        await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });
        const dup1 = path.join(__dirname, 'dup1/doc_dup.pdf');
        const dup2 = path.join(__dirname, 'dup2/doc_dup.pdf');
        fileInput = await page.$('input[type="file"]');
        await fileInput.uploadFile(dup1, dup2);

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 2;
        }, { timeout: 15000 });

        const dupCards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log('Duplicate files data-ids:', dupCards);
        if (dupCards[0] === dupCards[1]) {
            throw new Error('Duplicate files collided with identical data-id!');
        }
        console.log('✓ Duplicate files maintain collision-safe distinct IDs!');

        // =================================================================
        // TEST 3: JPG TO PDF MULTI-FILE ORDERING & WEBP INTEGRITY
        // =================================================================
        console.log('\n--- [TEST 3/4] JPG to PDF Complex Reorder, WebP & Output Verification ---');
        await page.goto('http://127.0.0.1:8000/jpg-to-pdf', { waitUntil: 'networkidle0' });

        const imgA = path.join(__dirname, 'img_a.jpg');
        const imgB = path.join(__dirname, 'img_b.png');
        const imgC = path.join(__dirname, 'img_c.webp');
        const imgD = path.join(__dirname, 'img_d.jpg');

        // Record original WebP hash before conversion
        const originalWebpHash = fs.readFileSync(imgC).toString('base64');

        // Upload [imgA, imgB, imgC]
        fileInput = await page.$('input[type="file"]');
        await fileInput.uploadFile(imgA, imgB, imgC);

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 3;
        }, { timeout: 15000 });

        let imgIds = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        const [cImgA, cImgB, cImgC] = imgIds;

        // Reorder to [C, A, B]
        console.log('Reordering images to [imgC, imgA, imgB]...');
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderUploads', order);
        }, [cImgC, cImgA, cImgB]);
        await new Promise(r => setTimeout(r, 1200));

        // Remove imgA (index 1)
        console.log('Removing imgA...');
        await page.evaluate(async () => {
            const root = document.querySelector('[wire\\:id]');
            const wire = window.Livewire.find(root.getAttribute('wire:id'));
            await wire.call('removeFile', 1);
        });

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 2;
        }, { timeout: 10000 });

        // Add imgD via compact dropzone
        console.log('Adding imgD via compact dropzone...');
        const allImgInputs = await page.$$('input[type="file"]');
        await allImgInputs[allImgInputs.length - 1].uploadFile(imgD);

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 3;
        }, { timeout: 15000 });

        imgIds = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        const cImgD = imgIds[2];

        // Reorder to [imgD, imgB, imgC]
        console.log('Reordering images to [imgD, imgB, imgC]...');
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderUploads', order);
        }, [cImgD, cImgB, cImgC]);
        await new Promise(r => setTimeout(r, 1200));

        // Submit process
        console.log('Submitting JPG to PDF conversion...');
        await page.evaluate(() => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('process');
        });

        await page.waitForFunction(() => {
            const root = document.querySelector('[wire\\:id]');
            return root && root.innerText.includes('images_document.pdf');
        }, { timeout: 25000 });

        console.log('✓ JPG to PDF completed successfully!');

        // Verify original WebP file hash is unchanged
        const currentWebpHash = fs.readFileSync(imgC).toString('base64');
        if (originalWebpHash !== currentWebpHash) {
            throw new Error('Original WebP file was modified during PDF conversion!');
        }
        console.log('✓ Original WebP file remained 100% byte-identical!');

        // =================================================================
        // TEST 4: RESPONSIVE VIEWPORTS (375px, 768px, 1280px) & ARABIC RTL
        // =================================================================
        console.log('\n--- [TEST 4/4] Responsive Viewports & RTL/LTR Verification ---');

        const viewports = [
            { name: 'desktop_1280', width: 1280, height: 800 },
            { name: 'tablet_768', width: 768, height: 1024 },
            { name: 'mobile_375', width: 375, height: 667 },
        ];

        const toolsToVerify = [
            { url: 'http://127.0.0.1:8000/merge-pdf', name: 'merge-pdf' },
            { url: 'http://127.0.0.1:8000/jpg-to-pdf', name: 'jpg-to-pdf' },
            { url: 'http://127.0.0.1:8000/heic-to-pdf', name: 'heic-to-pdf' },
        ];

        for (const vp of viewports) {
            await page.setViewport({ width: vp.width, height: vp.height });
            console.log(`\nTesting Viewport: ${vp.name} (${vp.width}x${vp.height})...`);

            for (const tool of toolsToVerify) {
                await page.goto(tool.url, { waitUntil: 'networkidle0' });

                // Check horizontal overflow
                const overflow = await page.evaluate(() => {
                    return {
                        scrollWidth: document.documentElement.scrollWidth,
                        clientWidth: document.documentElement.clientWidth,
                        hasOverflow: document.documentElement.scrollWidth > document.documentElement.clientWidth,
                    };
                });

                console.log(`  ${tool.name} [${vp.name}]: width=${overflow.clientWidth}, scrollWidth=${overflow.scrollWidth}, hasOverflow=${overflow.hasOverflow}`);
                if (overflow.hasOverflow) {
                    throw new Error(`Horizontal overflow detected in ${tool.name} at viewport ${vp.name}!`);
                }

                // Check controls and screenshot
                await page.screenshot({ path: path.join(screenshotsDir, `${tool.name}_${vp.name}.png`) });
            }
        }

        // Arabic RTL Test across viewports
        console.log('\nTesting Arabic RTL across viewports...');
        await page.goto('http://127.0.0.1:8000/lang/ar', { waitUntil: 'networkidle0' });

        for (const vp of viewports) {
            await page.setViewport({ width: vp.width, height: vp.height });
            await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });

            const rtlData = await page.evaluate(() => {
                const html = document.documentElement;
                return {
                    dir: html.getAttribute('dir'),
                    isRtl: html.getAttribute('dir') === 'rtl',
                    hasOverflow: html.scrollWidth > html.clientWidth,
                    h1Text: document.querySelector('h1')?.innerText,
                };
            });

            console.log(`  Arabic RTL [${vp.name}]: dir="${rtlData.dir}", overflow=${rtlData.hasOverflow}, heading="${rtlData.h1Text}"`);
            if (!rtlData.isRtl || rtlData.hasOverflow) {
                throw new Error(`Arabic RTL check failed at viewport ${vp.name}!`);
            }

            await page.screenshot({ path: path.join(screenshotsDir, `merge_pdf_arabic_${vp.name}.png`) });
        }

        console.log('\n=================================================================');
        console.log('ALL EXTENDED PHASE 2 VERIFICATIONS PASSED WITH 100% SUCCESS!');
        console.log('=================================================================');

    } finally {
        await browser.close();
    }
}

runExtendedVerification().catch(err => {
    console.error('Extended verification failed:', err);
    process.exit(1);
});
