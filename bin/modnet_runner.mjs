#!/usr/bin/env node

import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import { pipeline, env } from '@huggingface/transformers';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

function parseArgs() {
  const args = process.argv.slice(2);
  const options = {
    input: null,
    output: null,
    transparent: null,
    modelDir: path.resolve(__dirname, '../storage/app/models/modnet'),
  };

  for (let i = 0; i < args.length; i++) {
    const arg = args[i];
    if (arg === '--input' && i + 1 < args.length) {
      options.input = path.resolve(args[++i]);
    } else if (arg === '--output' && i + 1 < args.length) {
      options.output = path.resolve(args[++i]);
    } else if (arg === '--transparent' && i + 1 < args.length) {
      options.transparent = path.resolve(args[++i]);
    } else if (arg === '--model-dir' && i + 1 < args.length) {
      const candidate = path.resolve(args[++i]);
      if (candidate.startsWith(path.resolve(__dirname, '..'))) {
        options.modelDir = candidate;
      }
    }
  }

  return options;
}

async function main() {
  const t0 = performance.now();
  const options = parseArgs();

  if (!options.input || !fs.existsSync(options.input)) {
    console.error(JSON.stringify({
      success: false,
      error: `Input image does not exist: ${options.input}`,
    }));
    process.exit(1);
  }

  if (!options.output) {
    console.error(JSON.stringify({
      success: false,
      error: 'Output mask path is required (--output)',
    }));
    process.exit(1);
  }

  const modelOnnxPath = path.join(options.modelDir, 'onnx', 'model.onnx');
  if (!fs.existsSync(modelOnnxPath)) {
    console.error(JSON.stringify({
      success: false,
      error: `MODNet model.onnx not found at: ${modelOnnxPath}`,
    }));
    process.exit(1);
  }

  const outDir = path.dirname(options.output);
  if (!fs.existsSync(outDir)) {
    fs.mkdirSync(outDir, { recursive: true });
  }

  // Strict local / offline mode
  env.allowRemoteModels = false;
  env.localModelPath = path.dirname(options.modelDir);

  const tLoad0 = performance.now();
  const segmenter = await pipeline('image-segmentation', options.modelDir, {
    device: 'cpu',
    dtype: 'fp32',
  });
  const tLoad1 = performance.now();

  const tInf0 = performance.now();
  const output = await segmenter(options.input);
  const tInf1 = performance.now();

  const mask = Array.isArray(output) ? output[0].mask : output.mask;
  if (!mask || !mask.width || !mask.height) {
    console.error(JSON.stringify({
      success: false,
      error: 'Segmenter produced invalid or empty mask',
    }));
    process.exit(2);
  }

  await mask.save(options.output);

  const stats = {
    success: true,
    width: mask.width,
    height: mask.height,
    load_ms: Math.round(tLoad1 - tLoad0),
    inference_ms: Math.round(tInf1 - tInf0),
    total_ms: Math.round(performance.now() - t0),
    mask_path: options.output,
  };

  console.log(JSON.stringify(stats));
  process.exit(0);
}

main().catch((err) => {
  console.error(JSON.stringify({
    success: false,
    error: err.message || String(err),
  }));
  process.exit(1);
});
