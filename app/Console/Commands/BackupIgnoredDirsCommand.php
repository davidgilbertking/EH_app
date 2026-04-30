<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Symfony\Component\Process\Process;

#[Signature('backup:ignored-dirs {--output= : Archive name/path (default: backup_non_git_dirs_YYYYmmdd_HHMMSS.tar.gz)} {--dry-run : Show folders only, do not create archive}')]
#[Description('Archive git-ignored directories (with contents) into one tar.gz file in project root.')]
class BackupIgnoredDirsCommand extends Command
{
    public function handle(): int
    {
        $root = base_path();
        $archive = $this->resolveArchivePath($root, (string) $this->option('output'));

        $scan = new Process(['git', '-C', $root, 'status', '--ignored', '--porcelain', '-z']);
        $scan->run();

        if (! $scan->isSuccessful()) {
            $this->error('git status failed: '.$scan->getErrorOutput());
            return self::FAILURE;
        }

        $dirs = $this->extractIgnoredDirs($root, $scan->getOutput());

        if (! count($dirs)) {
            $this->warn('No ignored directories found.');
            return self::SUCCESS;
        }

        $this->line('Ignored directories: '.count($dirs));
        foreach ($dirs as $dir) {
            $this->line(' - '.$dir);
        }

        if ($this->option('dry-run')) {
            $this->info('Dry run complete. Archive not created.');
            return self::SUCCESS;
        }

        $listFile = tempnam(sys_get_temp_dir(), 'eh_ignored_dirs_');
        if (! $listFile) {
            $this->error('Cannot create temporary file for tar list.');
            return self::FAILURE;
        }

        file_put_contents($listFile, implode("\0", $dirs)."\0");

        $this->line('Creating archive: '.$archive);
        $tar = new Process(['tar', '-czf', $archive, '--null', '-T', $listFile], $root);
        $tar->setTimeout(null);
        $tar->run();
        @unlink($listFile);

        if (! $tar->isSuccessful()) {
            $this->error('tar failed: '.$tar->getErrorOutput());
            return self::FAILURE;
        }

        $sizeBytes = @filesize($archive) ?: 0;
        $this->info(sprintf(
            'Done. %d dirs archived to %s (%s).',
            count($dirs),
            $archive,
            $this->humanSize($sizeBytes),
        ));

        return self::SUCCESS;
    }

    private function resolveArchivePath(string $root, string $option): string
    {
        $name = trim($option);
        if ($name === '') {
            $name = 'backup_non_git_dirs_'.date('Ymd_His').'.tar.gz';
        }

        if (str_starts_with($name, '/')) {
            return $name;
        }

        return $root.'/'.$name;
    }

    /** @return list<string> */
    private function extractIgnoredDirs(string $root, string $porcelainZ): array
    {
        $dirs = [];

        foreach (explode("\0", $porcelainZ) as $entry) {
            if ($entry === '' || ! str_starts_with($entry, '!! ')) {
                continue;
            }

            $path = rtrim(substr($entry, 3), '/');
            if ($path === '') {
                continue;
            }

            $abs = $root.'/'.$path;
            if (is_dir($abs)) {
                $dirs[$path] = true;
            }
        }

        $out = array_keys($dirs);
        sort($out);
        return $out;
    }

    private function humanSize(int $bytes): string
    {
        if ($bytes < 1024) return $bytes.' B';
        if ($bytes < 1024 * 1024) return round($bytes / 1024, 2).' KB';
        if ($bytes < 1024 * 1024 * 1024) return round($bytes / (1024 * 1024), 2).' MB';
        return round($bytes / (1024 * 1024 * 1024), 2).' GB';
    }
}

