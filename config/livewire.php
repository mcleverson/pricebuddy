<?php

/*
 * Only the keys that differ from Livewire's defaults (merged over
 * vendor/livewire/livewire/config/livewire.php, key by key).
 */
return [

    // The video carousel of the publication modal takes videos, not only images.
    'temporary_file_upload' => [
        'disk' => null,
        'rules' => ['required', 'file', 'max:204800'], // 200 MB
        'directory' => null,
        'middleware' => null,
        'preview_mimes' => [
            'png', 'gif', 'bmp', 'svg', 'wav', 'mp4',
            'mov', 'avi', 'wmv', 'mp3', 'm4a',
            'jpg', 'jpeg', 'mpga', 'webp', 'wma',
        ],
        'max_upload_time' => 15,
        'cleanup' => true,
    ],

];
