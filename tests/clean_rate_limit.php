<?php
$dir = sys_get_temp_dir() . '/ott_rate_limits';
if (is_dir($dir)) {
    foreach (glob($dir . '/*') as $f) {
        if (is_file($f)) {
            @unlink($f);
        }
    }
    @rmdir($dir);
    echo "Cleared rate limits at $dir\n";
} else {
    echo "No rate limit dir found at $dir\n";
}
