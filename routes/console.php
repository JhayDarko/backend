<?php

use Illuminate\Foundation\Console\ServeCommand;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;

// Match the local server limits to the 5 MB OCR upload limit.
$iniScanDir = implode(PATH_SEPARATOR, array_filter([
    getenv('PHP_INI_SCAN_DIR') ?: null,
    base_path('scripts/php-dev-ini'),
]));
putenv("PHP_INI_SCAN_DIR={$iniScanDir}");
$_ENV['PHP_INI_SCAN_DIR'] = $iniScanDir;

$tempDirectory = sys_get_temp_dir();
foreach (['TEMP', 'TMP'] as $variable) {
    if (! getenv($variable)) {
        putenv("{$variable}={$tempDirectory}");
    }

    $_ENV[$variable] = getenv($variable) ?: $tempDirectory;
}
ServeCommand::$passthroughVariables = array_merge(
    ServeCommand::$passthroughVariables,
    ['PHP_INI_SCAN_DIR', 'TEMP', 'TMP'],
);

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');
