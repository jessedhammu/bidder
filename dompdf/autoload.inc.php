<?php
/**
 * Simple autoloader for a non-composer dompdf extraction.
 *
 * Place this file inside the dompdf folder (same level as src/, lib/, etc.)
 * and require it before using Dompdf, e.g.:
 *   require_once __DIR__ . '/dompdf/autoload.inc.php';
 *
 * If your dompdf layout differs, update the $prefixes mapping below.
 */

$prefixes = [
    // Dompdf main namespace -> src directory
    'Dompdf\\'     => __DIR__ . '/src/',

    // Common bundled libraries inside dompdf extracted repo
    // Adjust these paths if your extracted layout differs
    'FontLib\\'    => __DIR__ . '/lib/php-font-lib/src/FontLib/',
    'Svg\\'        => __DIR__ . '/lib/php-svg-lib/src/',
    'Html5\\'      => __DIR__ . '/lib/masterminds/html5/src/',

    // Backwards compatibility or alternate vendor dirs (some releases use different paths)
    'Masterminds\\' => __DIR__ . '/lib/masterminds/html5/src/',
];

// Normalize mapping: ensure trailing slash and realpath if possible
foreach ($prefixes as $k => $p) {
    $p = rtrim($p, '/') . '/';
    if (file_exists($p)) {
        $prefixes[$k] = $p;
    } else {
        // try without 'lib/' prefix (alternative layouts)
        $alt = __DIR__ . '/' . ltrim($p, '/');
        $prefixes[$k] = $p; // keep original; user can adjust if needed
    }
}

spl_autoload_register(function ($class) use ($prefixes) {
    // convert namespace to filesystem path per PSR-4 style
    foreach ($prefixes as $prefix => $baseDir) {
        if (strpos($class, $prefix) === 0) {
            $relative = substr($class, strlen($prefix));
            $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($file)) {
                require_once $file;
                return true;
            }
            // Some libraries use lowercase directories or alternate file names: try a fallback search
            $fallback = $baseDir . str_replace('\\', '/', $relative) . '.php';
            if (file_exists($fallback)) {
                require_once $fallback;
                return true;
            }
        }
    }

    // Last attempt: try searching for the class name (non-namespaced) within the dompdf tree.
    $parts = explode('\\', $class);
    $short = end($parts);
    // naive recursive search (expensive but fallback-only)
    $dir = __DIR__;
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir));
    foreach ($it as $f) {
        if ($f->isFile() && stripos($f->getFilename(), $short) !== false && substr($f->getFilename(), -4) === '.php') {
            // quick check: file contains class name?
            $contents = file_get_contents($f->getPathname());
            if (strpos($contents, 'class ' . $short) !== false || strpos($contents, 'interface ' . $short) !== false) {
                require_once $f->getPathname();
                return true;
            }
        }
    }

    return false;
});
