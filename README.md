# ScrollStop Backend (Laravel 12)

ScrollStop mobile app backend.

## Features

- Firebase ID token auth middleware (`Authorization: Bearer <token>`)
- Caption generation API (existing)
- Async video ad generation pipeline:
  - OpenAI -> structured Remotion render spec
  - Flux -> scene images
  - Remotion renderer -> MP4
  - Firebase Storage upload (`videos/{uid}/{jobId}.mp4`)
  - Firestore job status tracking (`ai_jobs/{jobId}`)

## API Endpoints

All `/api/*` routes require Firebase auth.

- `POST /api/captions`
- `GET /api/captions/recent?limit=10`
- `POST /api/videos`
- `GET /api/videos/recent?limit=20`
- `GET /api/videos/{jobId}`
- `GET /health` (public)

### Create Video Job

`POST /api/videos`

Request body:

```json
{
  "productName": "GlowSkin Serum",
  "productDescription": "Vitamin C + hyaluronic acid serum",
  "brandName": "GlowSkin",
  "platform": "TikTok",
  "durationSeconds": 15,
  "tone": "Bold",
  "language": "English",
  "voice": {
    "enabled": true,
    "gender": "female",
    "style": "friendly"
  },
  "aspectRatio": "9:16",
  "includePrice": true,
  "priceText": "$19.99",
  "cta": "Shop now",
  "referenceImageUrls": [
    "https://example.com/product-packaged.jpg",
    "https://example.com/product-in-use.jpg"
  ],
  "referenceImageNotes": [
    "packaged",
    "in-use"
  ]
}
```

You can also send up to 5 product photos as multipart files:

- field name: `productImages[]`
- accepted: `jpg`, `jpeg`, `png`, `webp`
- max size per image: `8MB`

When reference images are provided, Flux Kontext generation uses `inputImage` for scene-level image editing.

Response:

```json
{
  "ok": true,
  "jobId": "01JXXXX...",
  "status": "pending"
}
```

### Poll Job Status

`GET /api/videos/{jobId}`

Response:

```json
{
  "ok": true,
  "jobId": "01JXXXX...",
  "status": "processing",
  "videoUrl": null,
  "error": null,
  "output": {}
}
```

When completed:

```json
{
  "ok": true,
  "jobId": "01JXXXX...",
  "status": "success",
  "videoUrl": "https://storage.googleapis.com/...",
  "error": null,
  "output": {
    "sceneCount": 3,
    "mode": "dynamic"
  }
}
```

## Required Environment Variables

Minimum required for video pipeline:

- `OPENAI_API_KEY`
- `FLUXAI_API_KEY`
- `FIREBASE_CREDENTIALS_B64`
- `FIREBASE_PROJECT_ID`
- `FIREBASE_STORAGE_BUCKET`
  - Usually one of: `{project-id}.appspot.com` or `{project-id}.firebasestorage.app`
  - If you get `The specified bucket does not exist`, create Firebase Storage for the project first or temporarily set `VIDEO_SKIP_STORAGE_UPLOAD=true` for local render tests.

Also used:

- `FIREBASE_FIRESTORE_DATABASE` (default: `(default)`)
- `OPENAI_MODEL` (default: `gpt-4.1-mini`)
- `OPENAI_TTS_MODEL` (default: `tts-1-hd`)
- `OPENAI_TTS_SPEED` (default: `0.95`)
- `OPENAI_REQUEST_TIMEOUT_SECONDS` (default: `900`)
- `OPENAI_TTS_TIMEOUT_SECONDS` (default: `900`)
- `FLUXAI_API_ENDPOINT` (default: `https://api.fluxapi.ai/api/v1/flux/kontext/generate`)
- `FLUXAI_POLL_ENDPOINT` (default: `https://api.fluxapi.ai/api/v1/flux/kontext/record-info`)
- `FLUXAI_MODEL` (default: `flux-kontext-pro`)
- `FLUXAI_ENABLE_TRANSLATION` (default: `true`)
- `FLUXAI_PROMPT_UPSAMPLING` (default: `false`)
- `FLUXAI_OUTPUT_FORMAT` (default: `jpeg`)
- `FLUXAI_SAFETY_TOLERANCE` (default: `2`)
- `FLUXAI_TIMEOUT_SECONDS` (default: `600`)
- `FLUXAI_POLL_REQUEST_TIMEOUT_SECONDS` (default: `180`)
- `FLUXAI_JOB_TIMEOUT_SECONDS` (default: `1800`)
- `FLUXAI_POLL_ATTEMPTS` (default: `1800`)
- `FLUXAI_POLL_DELAY_MS` (default: `1000`)
- `FIRESTORE_TIMEOUT_SECONDS` (default: `120`)
- `VIDEO_JOB_DISPATCH_MODE` (`process` | `queue` | `sync`, default: `process`)
- `VIDEO_POST_RATE_LIMIT_PER_MINUTE` (default: `5`)
- `VIDEO_REMOTION_TIMEOUT_SECONDS` (default: `1800`)
- `VIDEO_REMOTION_DELAY_RENDER_TIMEOUT_MS` (default: `1800000`)
- `VIDEO_REMOTION_CRF` (default: `24`, higher = faster/less quality)
- `VIDEO_REMOTION_X264_PRESET` (default: `superfast`)
- `VIDEO_REMOTION_CONCURRENCY` (default: `0`, auto)
- `VIDEO_REMOTION_SCALE` (default: `0.90`)
- `VIDEO_REMOTION_BUNDLE_CACHE_DIR` (default: `/tmp/scrollstop-remotion-bundles`)
- `VIDEO_KEEP_WORKDIR_ON_ERROR` (default: `false`, keeps `/tmp/scrollstop-video-{jobId}` for debug)
- `VIDEO_KEEP_WORKDIR_ON_SUCCESS` (default: `false`)
- `VIDEO_STATIC_MODE` (default: `false`, skips OpenAI/Flux/TTS and uses static assets)
- `VIDEO_SKIP_STORAGE_UPLOAD` (default: `false`, marks job success without Storage upload and returns `videoUrl: null`)
- `VIDEO_DEFAULT_FPS` (default: `22`)
- `VIDEO_MIN_SCENES` (default: `3`)
- `VIDEO_MAX_SCENES` (default: `4`)
- `VIDEO_FFPROBE_TIMEOUT_SECONDS` (default: `300`)
- `VIDEO_STATIC_IMAGE_PATH` (default: `remotion-renderer/assets/static-input.png`)
- `VIDEO_STATIC_AUDIO_PATH` (optional local mp3/wav path used only in static mode)

## Async Processing Modes

`VIDEO_JOB_DISPATCH_MODE=process` (default):
- `POST /api/videos` creates Firestore job and starts detached artisan worker process.
- Safer for HTTP timeouts, but on Cloud Run you should run with CPU always allocated for reliable long-running background work.

`VIDEO_JOB_DISPATCH_MODE=queue`:
- Uses Laravel queue (`ProcessVideoAdJob`).
- Recommended if you run a dedicated queue worker.

`VIDEO_JOB_DISPATCH_MODE=sync`:
- Runs pipeline inline (debug/local only).

`VIDEO_STATIC_MODE=true`:
- Disables OpenAI, Flux, and TTS calls for video jobs.
- Uses `VIDEO_STATIC_IMAGE_PATH` for all scenes and renders deterministic output.

`VIDEO_SKIP_STORAGE_UPLOAD=true`:
- Skips Firebase Storage upload step (useful when bucket is not ready yet).
- Job completes with `status=success`, `videoUrl=null`, and render/debug metadata under `output`.

## Performance Logging

Video pipeline logs stage durations into Laravel logs:

- `openai_spec`
- `flux_images`
- `openai_tts`
- `openai_tts_retry` (only when initial voice track is too short)
- `remotion_render`
- `storage_upload`
- `total`

`outputPayload.debug.remotionStats` also includes:

- `bundleMs`
- `renderMs`
- `totalMs`
- `usedBundleCache`

Look for log messages:

- `Video job stage completed`
- `Video job processing completed`
- `Video job processing failed`

## Firestore Job Schema

Collection: `ai_jobs`

Fields:

- `userId`
- `jobType` (`video`)
- `status` (`pending|processing|success|error`)
- `inputPayload`
- `outputPayload`
- `createdAt`
- `updatedAt`
- `completedAt`
- `videoUrl`
- `errorMessage`

`GET /api/videos/{jobId}` enforces ownership (`job.userId === firebase_uid`).

## Remotion Renderer

Renderer lives in:

- `remotion-renderer/render.mjs`
- `remotion-renderer/src/*`

Laravel invokes it via `node` and passes a temporary render spec JSON.

## Local Run

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan serve --host=0.0.0.0 --port=8087
```

## Quick Manual Test (curl)

```bash
# create job
curl -X POST http://127.0.0.1:8087/api/videos \
  -H "Authorization: Bearer <FIREBASE_ID_TOKEN>" \
  -H "Content-Type: application/json" \
  -d '{
    "productName":"GlowSkin Serum",
    "platform":"TikTok",
    "durationSeconds":15,
    "tone":"Bold",
    "language":"English",
    "voice":{"enabled":false}
  }'

# poll
curl http://127.0.0.1:8087/api/videos/<jobId> \
  -H "Authorization: Bearer <FIREBASE_ID_TOKEN>"
```

## Health

```bash
curl http://127.0.0.1:8087/health
```
