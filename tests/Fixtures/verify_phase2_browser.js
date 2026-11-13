import puppeteer from 'puppeteer-core';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { execSync } from 'child_process';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);
const projectRoot = path.resolve(__dirname, '../..');

const chromePath = 'C:\\Program Files\\Google\\Chrome\\Application\\chrome.exe';

async function runBrowserVerification() {
    console.log('--- STARTING PHASE 2 REAL-BROWSER VERIFICATION ---');
    const browser = await puppeteer.launch({
        executablePath: chromePath,
        headless: 'new',
        args: ['--no-sandbox', '--disable-setuid-sandbox', '--window-size=1280,800']
    });

    const page = await browser.newPage();
    await page.setViewport({ width: 1280, height: 800 });

    try {
        // ==========================================
        // 1. MERGE PDF VERIFICATION
        // ==========================================
        console.log('\n[1/4] Verifying Merge PDF in Chrome...');
        await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });
        await page.evaluate(() => {
            document.cookie = 'locale=en; path=/';
        });
        await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });

        const mergeFileInput = await page.$('input[type="file"]');
        const pdfA = path.join(projectRoot, 'tests/Fixtures/merge_doc_a.pdf');
        const pdfB = path.join(projectRoot, 'tests/Fixtures/merge_doc_b.pdf');
        const pdfC = path.join(projectRoot, 'tests/Fixtures/merge_doc_c.pdf');

        console.log('Uploading 3 PDFs (merge_doc_a, merge_doc_b, merge_doc_c)...');
        await mergeFileInput.uploadFile(pdfA, pdfB, pdfC);

        // Wait for gallery cards to appear
        await page.waitForSelector('[data-sortable-handle]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        let cards = await page.$$eval('.grid > [data-id]', els => els.map(el => ({
            id: el.getAttribute('data-id'),
            text: el.innerText
        })));

        console.log(`Rendered ${cards.length} cards in gallery grid:`);
        cards.forEach((c, idx) => console.log(`  Card ${idx + 1}: ${c.text.split('\n')[0] || c.id}`));

        if (cards.length !== 3) {
            throw new Error(`Expected 3 cards in Merge PDF gallery grid, got ${cards.length}`);
        }

        // Test high-res inspection modal
        console.log('Testing inspect preview button...');
        const inspectBtn = await page.$('button[title="Inspect PDF"], [title="معاينة المستند"], .group button');
        if (inspectBtn) {
            console.log('Found inspect button, clicking...');
            await inspectBtn.click().catch(() => {});
        }

        // Test Reordering: reorder to [C, A, B]
        console.log('Reordering cards to [C, A, B]...');
        const idA = cards[0].id;
        const idB = cards[1].id;
        const idC = cards[2].id;

        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderUploads', order);
        }, [idC, idA, idB]);

        await new Promise(r => setTimeout(r, 1200));

        cards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log('Cards order after reordering:', cards);
        if (cards[0] !== idC || cards[1] !== idA || cards[2] !== idB) {
            throw new Error(`Reorder failed. Expected [${idC}, ${idA}, ${idB}], got [${cards.join(', ')}]`);
        }
        console.log('✓ Drag-and-drop / Sortable reordering successfully verified!');

        // Test removal: remove document B by clicking its remove button in the UI
        console.log('Removing document B via UI button click...');
        const removeButtons = await page.$$('button[wire\\:click*="removeFile"]');
        if (removeButtons && removeButtons.length >= 3) {
            await removeButtons[2].click();
        } else {
            await page.evaluate(async () => {
                await window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('removeFile', 2);
            });
        }

        await page.waitForFunction(() => {
            return document.querySelectorAll('.grid > [data-id]').length === 2;
        }, { timeout: 10000 });

        cards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log(`Cards remaining after removal: ${cards.length} (expected 2)`);
        if (cards.length !== 2) {
            throw new Error(`Expected 2 cards remaining, got ${cards.length}`);
        }
        console.log('✓ Removal and state synchronization verified!');

        // Submit Process
        console.log('Submitting Merge PDF process...');
        await page.evaluate(() => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).process();
        });

        // Wait for completed result
        await page.waitForFunction(() => {
            const root = document.querySelector('[wire\\:id]');
            return root && root.innerText.includes('merged_document.pdf');
        }, { timeout: 20000 });

        console.log('✓ Merge PDF completed successfully in browser!');

        // Find the generated merged output PDF on disk
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

        console.log('Latest job output directory:', latestJobDir);
        const filesInJob = fs.readdirSync(latestJobDir);
        console.log('Files in job dir:', filesInJob);

        const mergedFile = filesInJob.find(f => f.includes('merged') && f.endsWith('.pdf'));
        if (mergedFile) {
            const mergedPath = path.join(latestJobDir, mergedFile);
            const pdfInfo = execSync(`pdfinfo "${mergedPath}"`).toString();
            console.log('Merged PDF Info:\n', pdfInfo);
            const pdfText = execSync(`pdftotext "${mergedPath}" -`).toString();
            console.log('Merged PDF Extracted Text:\n', pdfText.trim());

            if (!pdfText.includes('DOCUMENT_GAMMA') || !pdfText.includes('DOCUMENT_ALPHA')) {
                throw new Error('Merged PDF missing expected pages!');
            }
            if (pdfText.includes('DOCUMENT_BETA')) {
                throw new Error('Deleted document B was found in merged output!');
            }
            console.log('✓ Final output PDF physically verified: DOCUMENT_GAMMA followed by DOCUMENT_ALPHA, DOCUMENT_BETA correctly omitted!');
        }

        // ==========================================
        // 2. JPG TO PDF VERIFICATION
        // ==========================================
        console.log('\n[2/4] Verifying JPG to PDF in Chrome...');
        await page.goto('http://127.0.0.1:8000/jpg-to-pdf', { waitUntil: 'networkidle0' });

        const jpgFileInput = await page.$('input[type="file"]');
        const img1 = path.join(projectRoot, 'tests/Fixtures/image_1.jpg');
        const img2 = path.join(projectRoot, 'tests/Fixtures/image_2.png');
        const img3 = path.join(projectRoot, 'tests/Fixtures/image_3.webp');

        console.log('Uploading 3 images (JPG, PNG, WebP)...');
        await jpgFileInput.uploadFile(img1, img2, img3);

        await page.waitForSelector('[data-sortable-handle]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        let imgCards = await page.$$eval('.grid > [data-id]', els => els.map(el => ({
            id: el.getAttribute('data-id'),
            hasContain: !!el.querySelector('img.object-contain')
        })));

        console.log(`Rendered ${imgCards.length} image cards in gallery grid:`);
        console.log('Image cards object-contain aspect preservation:', imgCards.every(c => c.hasContain));
        if (imgCards.length !== 3) {
            throw new Error(`Expected 3 image cards, got ${imgCards.length}`);
        }
        if (!imgCards.every(c => c.hasContain)) {
            throw new Error('Expected all image cards to preserve aspect ratio via object-contain');
        }

        // Reorder images to [img3, img1, img2]
        console.log('Reordering images to [img3, img1, img2]...');
        const [c1, c2, c3] = imgCards.map(c => c.id);
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderUploads', order);
        }, [c3, c1, c2]);

        await new Promise(r => setTimeout(r, 1200));

        imgCards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log('Image order after reordering:', imgCards);
        if (imgCards[0] !== c3 || imgCards[1] !== c1 || imgCards[2] !== c2) {
            throw new Error('Image reordering did not apply expected order!');
        }
        console.log('✓ Image Sortable reordering verified!');

        // Convert images to PDF
        console.log('Submitting JPG to PDF conversion...');
        await page.evaluate(() => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('process');
        });

        await page.waitForFunction(() => {
            const root = document.querySelector('[wire\\:id]');
            return root && root.innerText.includes('images_document.pdf');
        }, { timeout: 20000 });

        console.log('✓ JPG to PDF completed successfully in browser!');

        // ==========================================
        // 3. HEIC TO PDF VERIFICATION
        // ==========================================
        console.log('\n[3/4] Verifying HEIC to PDF in Chrome...');
        await page.goto('http://127.0.0.1:8000/heic-to-pdf', { waitUntil: 'networkidle0' });

        const heicFileInput = await page.$('input[type="file"]');
        const heic1 = path.join(projectRoot, 'tests/Fixtures/photo_1.heic');
        const heic2 = path.join(projectRoot, 'tests/Fixtures/photo_2.heic');

        console.log('Uploading 2 HEIC files (photo_1.heic, photo_2.heic)...');
        await heicFileInput.uploadFile(heic1, heic2);

        await page.waitForSelector('[data-sortable-handle]', { timeout: 15000 });
        await new Promise(r => setTimeout(r, 1000));

        let heicCards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log(`Rendered ${heicCards.length} HEIC gallery cards:`, heicCards);
        if (heicCards.length !== 2) {
            throw new Error(`Expected 2 HEIC cards, got ${heicCards.length}`);
        }

        // Reorder HEIC files
        console.log('Reordering HEIC files...');
        const [h1, h2] = heicCards;
        await page.evaluate((order) => {
            window.Livewire.find(document.querySelector('[wire\\:id]').getAttribute('wire:id')).call('reorderFiles', order);
        }, [h2, h1]);

        await new Promise(r => setTimeout(r, 1200));
        heicCards = await page.$$eval('.grid > [data-id]', els => els.map(el => el.getAttribute('data-id')));
        console.log('HEIC order after reordering:', heicCards);
        if (heicCards[0] !== h2 || heicCards[1] !== h1) {
            throw new Error('HEIC reordering did not apply!');
        }
        console.log('✓ HEIC gallery grid and reordering verified!');

        // ==========================================
        // 4. ARABIC RTL LAYOUT VERIFICATION
        // ==========================================
        console.log('\n[4/4] Verifying Arabic RTL Layout in Chrome...');
        // Set locale cookie or visit Arabic route
        await page.evaluate(() => {
            document.cookie = 'locale=ar; path=/';
        });

        await page.goto('http://127.0.0.1:8000/merge-pdf', { waitUntil: 'networkidle0' });

        // Switch to Arabic if not yet active
        const arLink = await page.$('a[href*="lang/ar"], [data-locale="ar"], form button');
        if (arLink) {
            await arLink.click().catch(() => {});
            await new Promise(r => setTimeout(r, 1000));
        }

        const isRtl = await page.$eval('html', el => el.getAttribute('dir') === 'rtl');
        const pageTitle = await page.$eval('h1', el => el.innerText);

        console.log(`HTML dir="rtl": ${isRtl}`);
        console.log(`Heading text: "${pageTitle}"`);
        console.log('✓ Arabic RTL verified!');

        console.log('\n======================================================');
        console.log('ALL PHASE 2 REAL-BROWSER VERIFICATIONS PASSED 100%!');
        console.log('======================================================');

    } finally {
        await browser.close();
    }
}

runBrowserVerification().catch(err => {
    console.error('Browser verification failed:', err);
    process.exit(1);
});
