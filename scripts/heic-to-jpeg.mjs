import { readFile, writeFile } from 'node:fs/promises';
import convert from 'heic-convert';

const [, , inputPath, outputPath, qualityArg = '92'] = process.argv;

if (!inputPath || !outputPath) {
    console.error('Usage: node heic-to-jpeg.mjs <input> <output> [quality]');
    process.exit(2);
}

try {
    const input = await readFile(inputPath);
    const quality = Math.min(1, Math.max(0, Number(qualityArg) / 100));
    const output = await convert({ buffer: input, format: 'JPEG', quality });
    await writeFile(outputPath, output);
    process.stdout.write(JSON.stringify({
        success: true,
        size: output.length,
    }));
} catch (error) {
    console.error(error instanceof Error ? error.message : String(error));
    process.exit(1);
}
