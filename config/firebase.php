<?php
return [
    'credentials_b64' => env('FIREBASE_CREDENTIALS_B64'),
    'project_id' => env('FIREBASE_PROJECT_ID'),
    'firestore_database' => env('FIREBASE_FIRESTORE_DATABASE', '(default)'),
];
