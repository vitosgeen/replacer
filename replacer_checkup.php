<?php

try {
    find_dirs_websites();
} catch (Exception $e) {
    echo $e->getMessage();
}

function find_dirs_websites() {
    $targetDir = realpath(__DIR__ . '/../../');
    $sites = scandir($targetDir);
    $res = [];
    foreach ($sites as $site) {
        if ($site === '.' || $site === '..') {
            continue;
        }
        $path = $targetDir . '/' . $site;
        var_dump($path);
        if (is_dir($path)) {
            $res[] = $site;
        }
    }
    return $res;
}