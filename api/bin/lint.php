<?php

declare(strict_types=1);

/**
 * Syntax-checks every PHP file outside vendor/ with `php -l`.
 */

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
$failed = 0;
$checked = 0;

foreach ($iterator as $file) {
    $path = $file->getPathname();
    if ($file->getExtension() !== 'php' || str_contains($path, DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR)) {
        continue;
    }
    $checked++;
    exec(escapeshellarg(PHP_BINARY) . ' -l ' . escapeshellarg($path) . ' 2>&1', $output, $code);
    if ($code !== 0) {
        $failed++;
        echo implode("\n", $output), "\n";
    }
    $output = [];
}

echo "Checked {$checked} file(s), {$failed} with errors.\n";
exit($failed === 0 ? 0 : 1);
