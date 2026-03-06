<?php

return [
    'dispatch_mode' => env('VIDEO_JOB_DISPATCH_MODE', 'process'),
    'post_rate_limit_per_minute' => (int) env('VIDEO_POST_RATE_LIMIT_PER_MINUTE', 5),
    'remotion_timeout_seconds' => (int) env('VIDEO_REMOTION_TIMEOUT_SECONDS', 900),
    'remotion_crf' => (int) env('VIDEO_REMOTION_CRF', 25),
    'remotion_x264_preset' => env('VIDEO_REMOTION_X264_PRESET', 'veryfast'),
    'remotion_concurrency' => (int) env('VIDEO_REMOTION_CONCURRENCY', 0),
    'remotion_scale' => (float) env('VIDEO_REMOTION_SCALE', 0.95),
    'remotion_bundle_cache_dir' => env('VIDEO_REMOTION_BUNDLE_CACHE_DIR', '/tmp/scrollstop-remotion-bundles'),
    'node_binary' => env('VIDEO_NODE_BINARY', 'node'),
    'keep_workdir_on_error' => filter_var(env('VIDEO_KEEP_WORKDIR_ON_ERROR', false), FILTER_VALIDATE_BOOLEAN),
    'keep_workdir_on_success' => filter_var(env('VIDEO_KEEP_WORKDIR_ON_SUCCESS', false), FILTER_VALIDATE_BOOLEAN),
    'static_mode' => filter_var(env('VIDEO_STATIC_MODE', false), FILTER_VALIDATE_BOOLEAN),
    'skip_storage_upload' => filter_var(env('VIDEO_SKIP_STORAGE_UPLOAD', false), FILTER_VALIDATE_BOOLEAN),
    'default_fps' => (int) env('VIDEO_DEFAULT_FPS', 24),
    'min_scenes' => (int) env('VIDEO_MIN_SCENES', 3),
    'max_scenes' => (int) env('VIDEO_MAX_SCENES', 5),
    'static_image_path' => env('VIDEO_STATIC_IMAGE_PATH', ''),
    'static_audio_path' => env('VIDEO_STATIC_AUDIO_PATH', ''),
];
