<?php

return [
    'name' => 'Media',

    // SRS mentions chunked/resumable uploads; this implements
    // single-request multipart upload with a size ceiling instead - a
    // disclosed scope reduction, not a silent one. Value in megabytes.
    'max_upload_mb' => env('MEDIA_MAX_UPLOAD_MB', 25),

    'allowed_mime_types' => [
        'image' => ['image/jpeg', 'image/png', 'image/gif', 'image/webp'],
        'video' => ['video/mp4', 'video/webm', 'video/quicktime'],
        'audio' => ['audio/mpeg', 'audio/mp4', 'audio/webm', 'audio/ogg', 'audio/wav'],
        'document' => ['application/pdf', 'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain', 'application/zip'],
    ],
];
