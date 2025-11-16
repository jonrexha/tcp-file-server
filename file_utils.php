<?php
// file_utils.php - Utility functions for file operations

function ensure_directory_exists($path)
{
    if (!is_dir($path)) {
        mkdir($path, 0755, true);
    }
    return is_dir($path);
}

function get_safe_file_path($base_dir, $filename)
{
    $filename = basename($filename);
    $path = realpath($base_dir) . DIRECTORY_SEPARATOR . $filename;
    $base_real = realpath($base_dir);

    // Security check: ensure the file is within the base directory
    if (strpos($path, $base_real) !== 0) {
        return false;
    }
    return $path;
}

function get_directory_files($directory)
{
    if (!is_dir($directory)) {
        return [];
    }
    $files = scandir($directory);
    return array_values(array_diff($files, ['.', '..']));
}

function format_file_info($filepath)
{
    if (!is_file($filepath)) {
        return false;
    }

    return [
        'name' => basename($filepath),
        'size' => filesize($filepath),
        'created' => filectime($filepath),
        'modified' => filemtime($filepath)
    ];
}

function search_files($directory, $keyword)
{
    $files = get_directory_files($directory);
    $matches = [];

    foreach ($files as $file) {
        if (stripos($file, $keyword) !== false) {
            $matches[] = $file;
        }
    }

    return $matches;
}

function delete_file_safe($base_dir, $filename)
{
    $path = get_safe_file_path($base_dir, $filename);
    if (!$path || !is_file($path)) {
        return false;
    }

    return unlink($path);
}
