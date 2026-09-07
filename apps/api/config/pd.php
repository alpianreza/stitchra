<?php

return [
    // Private local storage for development; configure s3/MinIO in production.
    'tech_pack_disk' => env('PD_TECH_PACK_DISK', env('FILESYSTEM_DISK', 'local')),
    'tech_pack_max_kb' => (int) env('PD_TECH_PACK_MAX_KB', 10240),
];