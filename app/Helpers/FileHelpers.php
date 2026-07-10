<?php

declare(strict_types=1);

if (! function_exists('formatFileSize')) {
    /**
     * Format a file size in bytes into a human-readable string.
     *
     * - n < 1024              → "{n} B"
     * - 1024 ≤ n < 1,048,576  → "{n} KB"  (rounded to 2 decimal places)
     * - n ≥ 1,048,576         → "{n} MB"  (rounded to 2 decimal places)
     *
     * Requirements: 2.3
     */
    function formatFileSize(int $bytes): string
    {
        if ($bytes < 1024) {
            return "{$bytes} B";
        }

        if ($bytes < 1_048_576) {
            $kb = round($bytes / 1024, 2);
            return "{$kb} KB";
        }

        $mb = round($bytes / 1_048_576, 2);
        return "{$mb} MB";
    }
}
