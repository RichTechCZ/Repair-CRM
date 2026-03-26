<?php
function findFiles($dir, $pattern) {
    if (!is_dir($dir)) return [];
    $results = [];
    try {
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file === '.' || $file === '..') continue;
            $path = $dir . DIRECTORY_SEPARATOR . $file;
            if (is_dir($path)) {
                $results = array_merge($results, findFiles($path, $pattern));
            } else {
                if (preg_match($pattern, $file)) {
                    $results[] = $path;
                }
            }
        }
    } catch (Exception $e) {}
    return $results;
}

echo "Searching for .sql files in H:\MY PROJECT\n";
print_r(findFiles('H:\\MY PROJECT', '/\.sql$/i'));
