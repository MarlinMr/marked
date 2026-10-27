<?php
declare(strict_types=1);
$root=dirname(__DIR__); $failed=false;
foreach (['app','bin','database','public','tests'] as $directory) {
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root.'/'.$directory)) as $file) {
        if ($file->getExtension()!=='php') continue;
        passthru(PHP_BINARY.' -l '.escapeshellarg($file->getPathname()),$code); $failed=$failed || $code!==0;
    }
}
exit($failed?1:0);
