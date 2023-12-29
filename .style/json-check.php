<?php

    declare(strict_types=1);

    if ($argc == 1) {
        $mode = '';
        $dir = '.';
    } else if($argc == 2) {
        $mode = $argv[1];
        $dir = '.';
    } else if($argc == 3) {
        $mode = $argv[1];
        $dir = $argv[2];
    } else {
        die('JSON Checker cannot be run with this set of parameters!');
    }

    if (!in_array($mode, ['', 'fix'])) {
        die('Unsupported mode "' . $mode . '"!');
    }

    $start = microtime(true);
    $invalidFiles = jsonStyleCheck($dir, $mode);
    $duration = microtime(true) - $start;

    foreach ($invalidFiles as $invalidFile) {
        echo $invalidFile . PHP_EOL;
    }

    if (!empty($invalidFiles)) {
        echo PHP_EOL;
    }

    echo 'Checked all files in ' . number_format($duration, 3) . ' seconds, ' . number_format(memory_get_peak_usage() / 1024 / 1024, 3) . " MB memory used" . PHP_EOL;

    if (!empty($invalidFiles)) {
        exit(1);
    }

    function jsonStyleCheck(string $dir, string $mode)
    {
        $ignore = ['./.vscode', './.idea', './.git','./libs/vendor'];
        $invalidFiles = [];
        $files = scandir($dir);
        foreach ($files as $file) {
            if ($file != '.' && $file != '..' && !in_array($dir, $ignore)) {
                if (is_dir($dir . '/' . $file)) {
                    $invalidFiles = array_merge($invalidFiles, jsonStyleCheck($dir . '/' . $file, $mode));
                } else {
                    if (fnmatch('*.json', $dir . '/' . $file)) {
                        $invalidFile = checkContentInFile($dir . '/' . $file, $mode);
                        if ($invalidFile !== false) {
                            $invalidFiles[] = $invalidFile;
                        }
                    }
                }
            }
        }
        return $invalidFiles;
    }

    function checkContentInFile(string $dir, string $mode)
    {
        $fileOriginal = file_get_contents($dir);

        // Normalize line endings
        $fileOriginal = str_replace("\r\n", "\n", $fileOriginal);
        $fileOriginal = str_replace("\r", "\n", $fileOriginal);

        // Ignore line break at the end of the file
        $fileOriginal = rtrim($fileOriginal, "\n");

        // Reformat JSON using PHP
        $fileCompare = json_encode(json_decode($fileOriginal), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION);

        if ($fileOriginal == $fileCompare) {
            return false;
        }

        if ($mode == 'fix') {
            file_put_contents($dir, $fileCompare);
        }

        return $dir;
    }
