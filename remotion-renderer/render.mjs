import fs from 'node:fs';
import path from 'node:path';
import crypto from 'node:crypto';
import {bundle} from '@remotion/bundler';
import {renderMedia, selectComposition} from '@remotion/renderer';

const args = process.argv.slice(2);

const readArg = (key) => {
  const idx = args.findIndex((arg) => arg === key);
  if (idx === -1 || idx + 1 >= args.length) {
    return null;
  }

  return args[idx + 1];
};

const resolveRendererRoot = () => {
  const cwd = process.cwd();
  const nested = path.resolve(cwd, 'remotion-renderer');

  if (fs.existsSync(path.join(nested, 'src', 'index.js'))) {
    return nested;
  }

  if (fs.existsSync(path.join(cwd, 'src', 'index.js'))) {
    return cwd;
  }

  return nested;
};

const specPath = readArg('--spec');
const outPath = readArg('--out');
const crfArg = readArg('--crf');
const x264PresetArg = readArg('--x264-preset');
const concurrencyArg = readArg('--concurrency');
const scaleArg = readArg('--scale');
const statsOutArg = readArg('--stats-out');
const bundleCacheDirArg = readArg('--bundle-cache-dir');

if (!specPath || !outPath) {
  console.error('Usage: node render.mjs --spec /path/spec.json --out /path/output.mp4');
  process.exit(1);
}

const fileToDataUri = (filePath) => {
  const absPath = path.resolve(filePath);
  const ext = path.extname(absPath).toLowerCase();
  const mime =
    ext === '.jpg' || ext === '.jpeg'
      ? 'image/jpeg'
      : ext === '.webp'
        ? 'image/webp'
        : ext === '.mp3'
          ? 'audio/mpeg'
          : ext === '.wav'
            ? 'audio/wav'
            : 'image/png';

  const buffer = fs.readFileSync(absPath);
  return `data:${mime};base64,${buffer.toString('base64')}`;
};

const latestMtimeInDirectory = (directoryPath) => {
  if (!fs.existsSync(directoryPath)) {
    return 0;
  }

  const stack = [directoryPath];
  let latestMtime = 0;

  while (stack.length > 0) {
    const current = stack.pop();
    const stat = fs.statSync(current);
    latestMtime = Math.max(latestMtime, stat.mtimeMs);

    if (!stat.isDirectory()) {
      continue;
    }

    const entries = fs.readdirSync(current);
    for (const entry of entries) {
      stack.push(path.join(current, entry));
    }
  }

  return latestMtime;
};

const specRaw = fs.readFileSync(path.resolve(specPath), 'utf8');
const parsedSpec = JSON.parse(specRaw);

const scenes = Array.isArray(parsedSpec.scenes) ? parsedSpec.scenes : [];
const preparedScenes = scenes.map((scene) => {
  const next = {...scene};

  if (typeof next.imagePath === 'string' && next.imagePath.trim() !== '' && fs.existsSync(next.imagePath)) {
    next.imageSrc = fileToDataUri(next.imagePath);
  } else if (typeof next.imageUrl === 'string' && next.imageUrl.trim() !== '') {
    next.imageSrc = next.imageUrl;
  } else {
    next.imageSrc = null;
  }

  return next;
});

let voiceoverAudioSrc = null;
if (
  typeof parsedSpec.voiceoverAudioPath === 'string' &&
  parsedSpec.voiceoverAudioPath.trim() !== '' &&
  fs.existsSync(parsedSpec.voiceoverAudioPath)
) {
  voiceoverAudioSrc = fileToDataUri(parsedSpec.voiceoverAudioPath);
}

const inputProps = {
  durationSeconds: Number(parsedSpec.durationSeconds ?? 15),
  fps: Number(parsedSpec.fps ?? 30),
  aspectRatio: String(parsedSpec.aspectRatio ?? '9:16'),
  tone: String(parsedSpec.tone ?? 'Bold'),
  scenes: preparedScenes,
  voiceoverAudioSrc,
};

const parsedCrf = crfArg === null ? Number.NaN : Number(crfArg);
const renderCrf = Number.isFinite(parsedCrf) && parsedCrf >= 0 ? parsedCrf : 23;

const parsedConcurrency = Number(concurrencyArg);
const renderConcurrency =
  Number.isFinite(parsedConcurrency) && parsedConcurrency > 0
    ? Math.floor(parsedConcurrency)
    : null;

const parsedScale = Number(scaleArg);
const renderScale =
  Number.isFinite(parsedScale) && parsedScale > 0 && parsedScale <= 1
    ? parsedScale
    : 1;

const rendererRoot = resolveRendererRoot();
const entryPoint = path.resolve(rendererRoot, 'src/index.js');
const srcDirectory = path.resolve(rendererRoot, 'src');
const bundleStartedAt = Date.now();

let bundled = null;
let usedBundleCache = false;
let bundleCacheDir = null;

if (bundleCacheDirArg && bundleCacheDirArg.trim() !== '') {
  const resolvedCacheRoot = path.resolve(bundleCacheDirArg.trim());
  fs.mkdirSync(resolvedCacheRoot, {recursive: true});

  const sourceSignature = crypto
    .createHash('sha1')
    .update(
      JSON.stringify({
        renderMtime: fs.statSync(path.resolve(rendererRoot, 'render.mjs')).mtimeMs,
        srcMtime: latestMtimeInDirectory(srcDirectory),
        packageJsonMtime: fs.statSync(path.resolve(rendererRoot, 'package.json')).mtimeMs,
      }),
    )
    .digest('hex')
    .slice(0, 16);

  bundleCacheDir = path.join(resolvedCacheRoot, sourceSignature);
  const cachedIndexPath = path.join(bundleCacheDir, 'index.html');

  if (fs.existsSync(cachedIndexPath)) {
    bundled = bundleCacheDir;
    usedBundleCache = true;
  } else {
    bundled = await bundle({entryPoint, outDir: bundleCacheDir});
  }
} else {
  bundled = await bundle({entryPoint});
}

const bundleMs = Date.now() - bundleStartedAt;

const composition = await selectComposition({
  serveUrl: bundled,
  id: 'ScrollStopAd',
  inputProps,
});

const renderStartedAt = Date.now();

await renderMedia({
  composition,
  serveUrl: bundled,
  codec: 'h264',
  outputLocation: path.resolve(outPath),
  inputProps,
  imageFormat: 'jpeg',
  crf: renderCrf,
  x264Preset: x264PresetArg || 'veryfast',
  concurrency: renderConcurrency ?? undefined,
  scale: renderScale,
});

const renderMs = Date.now() - renderStartedAt;
const totalMs = bundleMs + renderMs;

if (statsOutArg && statsOutArg.trim() !== '') {
  const resolvedStatsPath = path.resolve(statsOutArg.trim());
  fs.mkdirSync(path.dirname(resolvedStatsPath), {recursive: true});
  fs.writeFileSync(
    resolvedStatsPath,
    JSON.stringify(
      {
        bundleMs,
        renderMs,
        totalMs,
        usedBundleCache,
        bundleCacheDir,
      },
      null,
      2,
    ),
    'utf8',
  );
}
