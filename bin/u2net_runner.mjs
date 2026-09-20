#!/usr/bin/env node
import * as ort from 'onnxruntime-node';
import fs from 'fs';

async function main() {
    const args = process.argv.slice(2);
    if (args.length < 3) process.exit(1);
    
    const modelPath = args[0];
    const inputDataPath = args[1];
    const outputDataPath = args[2];

    if (!fs.existsSync(modelPath)) {
        console.error(JSON.stringify({ success: false, error: 'Model file not found' }));
        process.exit(1);
    }
    
    if (!fs.existsSync(inputDataPath)) {
        console.error(JSON.stringify({ success: false, error: 'Input data not found' }));
        process.exit(1);
    }

    const buffer = fs.readFileSync(inputDataPath);
    const floatArray = new Float32Array(buffer.buffer, buffer.byteOffset, buffer.length / 4);

    const tensor = new ort.Tensor('float32', floatArray, [1, 3, 320, 320]);

    // Bound memory/CPU to strict constraints for Azure 2 vCPU
    const session = await ort.InferenceSession.create(modelPath, {
        executionProviders: ['cpu'],
        interOpNumThreads: 1,
        intraOpNumThreads: 2
    });

    const start = performance.now();
    const results = await session.run({ [session.inputNames[0]]: tensor });
    const inference_ms = performance.now() - start;

    const outData = results[session.outputNames[0]].data;
    const outBuffer = Buffer.from(outData.buffer, outData.byteOffset, outData.byteLength);
    fs.writeFileSync(outputDataPath, outBuffer);

    console.log(JSON.stringify({ success: true, inference_ms: Math.round(inference_ms) }));
}

main().catch(err => {
    console.error(JSON.stringify({ success: false, error: err.message }));
    process.exit(1);
});
