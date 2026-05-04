<?php

declare(strict_types=1);

use App\Models\SoundTrack;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../vendor/autoload.php';

$app = require __DIR__.'/../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$audioRoot = trim((string) config('eh.audio_root', 'audio'), '/');
$audioAbsRoot = storage_path('app/private/'.$audioRoot);
$reportDir = base_path('reports');
$sizeCsvPath = $reportDir.'/audio_tracks_by_size.csv';
$sizeTxtPath = $reportDir.'/audio_tracks_by_size.txt';
$durationCsvPath = $reportDir.'/audio_tracks_by_duration.csv';
$durationTxtPath = $reportDir.'/audio_tracks_by_duration.txt';

if (!is_dir($audioAbsRoot)) {
    fwrite(STDERR, "Audio root not found: {$audioAbsRoot}\n");
    exit(1);
}

if (!is_dir($reportDir) && !mkdir($reportDir, 0775, true) && !is_dir($reportDir)) {
    fwrite(STDERR, "Failed to create report dir: {$reportDir}\n");
    exit(1);
}

$allowedExt = ['mp3', 'm4a', 'aac', 'ogg', 'oga', 'wav', 'flac'];
$durationMap = SoundTrack::query()
    ->get(['file_path', 'duration_seconds'])
    ->mapWithKeys(fn (SoundTrack $track) => [$track->file_path => $track->duration_seconds])
    ->all();

$getID3 = class_exists(\getID3::class) ? new \getID3() : null;

$rows = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($audioAbsRoot, FilesystemIterator::SKIP_DOTS)
);

foreach ($iterator as $fileInfo) {
    if (!$fileInfo->isFile()) {
        continue;
    }

    $ext = strtolower((string) pathinfo($fileInfo->getFilename(), PATHINFO_EXTENSION));
    if (!in_array($ext, $allowedExt, true)) {
        continue;
    }

    $fullPath = str_replace('\\', '/', $fileInfo->getPathname());
    $relative = ltrim(substr($fullPath, strlen(str_replace('\\', '/', $audioAbsRoot))), '/');
    $duration = $durationMap[$relative] ?? null;
    $indexed = array_key_exists($relative, $durationMap);

    if ($duration === null && $getID3) {
        $info = @$getID3->analyze($fullPath);
        $duration = isset($info['playtime_seconds']) ? (float) $info['playtime_seconds'] : null;
    }

    $sizeBytes = (int) $fileInfo->getSize();
    $rows[] = [
        'name' => basename($relative),
        'duration_sec' => $duration !== null ? (float) $duration : null,
        'duration_hms' => $duration !== null ? gmdate('H:i:s', (int) round((float) $duration)) : '',
        'size_bytes' => $sizeBytes,
        'size_mb' => $sizeBytes / 1048576,
        'path' => $relative,
        'indexed' => $indexed ? 'yes' : 'no',
    ];
}

/**
 * @param array<int, array<string, mixed>> $orderedRows
 */
$writeReports = static function (array $orderedRows, string $csvPath, string $txtPath, string $sortLabel): void {
    $csv = fopen($csvPath, 'wb');
    if ($csv === false) {
        throw new RuntimeException("Failed to write CSV: {$csvPath}");
    }

    fputcsv($csv, ['name', 'duration_sec', 'duration_hms', 'size_bytes', 'size_mb', 'path', 'indexed_in_db']);
    foreach ($orderedRows as $row) {
        fputcsv($csv, [
            $row['name'],
            $row['duration_sec'] !== null ? number_format($row['duration_sec'], 3, '.', '') : '',
            $row['duration_hms'],
            $row['size_bytes'],
            number_format((float) $row['size_mb'], 3, '.', ''),
            $row['path'],
            $row['indexed'],
        ]);
    }
    fclose($csv);

    $txt = fopen($txtPath, 'wb');
    if ($txt === false) {
        throw new RuntimeException("Failed to write TXT: {$txtPath}");
    }

    fwrite($txt, "Sorted by {$sortLabel}\n");
    fwrite($txt, "name\tduration_hms\tduration_sec\tsize_mb\tsize_bytes\tpath\tindexed_in_db\n");
    foreach ($orderedRows as $row) {
        fwrite($txt, implode("\t", [
            (string) $row['name'],
            (string) $row['duration_hms'],
            $row['duration_sec'] !== null ? number_format($row['duration_sec'], 3, '.', '') : '',
            number_format((float) $row['size_mb'], 3, '.', ''),
            (string) $row['size_bytes'],
            (string) $row['path'],
            (string) $row['indexed'],
        ])."\n");
    }
    fclose($txt);
};

$rowsBySize = $rows;
usort($rowsBySize, static function (array $a, array $b): int {
    $bySize = $b['size_bytes'] <=> $a['size_bytes'];
    if ($bySize !== 0) {
        return $bySize;
    }

    $aDur = $a['duration_sec'] ?? -1;
    $bDur = $b['duration_sec'] ?? -1;
    $byDur = $bDur <=> $aDur;
    if ($byDur !== 0) {
        return $byDur;
    }

    return strcmp((string) $a['path'], (string) $b['path']);
});

$rowsByDuration = $rows;
usort($rowsByDuration, static function (array $a, array $b): int {
    $aDur = $a['duration_sec'] ?? -1;
    $bDur = $b['duration_sec'] ?? -1;
    $byDur = $bDur <=> $aDur;
    if ($byDur !== 0) {
        return $byDur;
    }

    $bySize = $b['size_bytes'] <=> $a['size_bytes'];
    if ($bySize !== 0) {
        return $bySize;
    }

    return strcmp((string) $a['path'], (string) $b['path']);
});

try {
    $writeReports(
        $rowsBySize,
        $sizeCsvPath,
        $sizeTxtPath,
        'size_bytes DESC (then duration_sec DESC)'
    );
    $writeReports(
        $rowsByDuration,
        $durationCsvPath,
        $durationTxtPath,
        'duration_sec DESC (then size_bytes DESC)'
    );
} catch (Throwable $e) {
    fwrite(STDERR, $e->getMessage()."\n");
    exit(1);
}

echo "Exported ".count($rows)." tracks\n";
echo "CSV (size): {$sizeCsvPath}\n";
echo "TXT (size): {$sizeTxtPath}\n";
echo "CSV (duration): {$durationCsvPath}\n";
echo "TXT (duration): {$durationTxtPath}\n";
