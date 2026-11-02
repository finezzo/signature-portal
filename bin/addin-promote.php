<?php
declare(strict_types=1);

/**
 * Promotes the tested preview add-in to stable. This is the step that decides
 * what every user runs, so by default it only shows what would change.
 *
 * Usage: php bin/addin-promote.php         show what would change
 *        php bin/addin-promote.php --yes   copy the files into public/addin/
 *
 * Nothing reaches users until public/addin/ is uploaded to the server. To roll
 * back, restore the previous state from git and upload again:
 *        git checkout <commit> -- public/addin
 *
 * The stable manifest stays untouched: files only. Icons live in public/addin/
 * for all channels and are not part of this.
 */

$root    = dirname(__DIR__);
$preview = $root . '/public/addin-preview';
$stable  = $root . '/public/addin';
$files   = ['commands.html', 'commands.js', 'taskpane.html', 'taskpane.js', '.htaccess'];

$changed = [];
foreach ($files as $file) {
    if (!is_file("{$preview}/{$file}")) {
        fwrite(STDERR, "Missing public/addin-preview/{$file}, nothing was copied.\n");
        exit(1);
    }
    if (!is_file("{$stable}/{$file}") || !hash_equals(sha1_file("{$stable}/{$file}"), sha1_file("{$preview}/{$file}"))) {
        $changed[] = $file;
    }
}

if ($changed === []) {
    echo "public/addin/ already matches public/addin-preview/.\n";
    exit(0);
}

echo "Differs from stable:\n";
foreach ($changed as $file) {
    echo "  - {$file}\n";
}

if (!in_array('--yes', $argv, true)) {
    echo "\nNothing was copied. Run again with --yes to promote these files.\n";
    exit(0);
}

foreach ($changed as $file) {
    if (!copy("{$preview}/{$file}", "{$stable}/{$file}")) {
        fwrite(STDERR, "Could not copy {$file}.\n");
        exit(1);
    }
}

echo "\nCopied. Upload public/addin/ to the server; that is what reaches every user.\n";
