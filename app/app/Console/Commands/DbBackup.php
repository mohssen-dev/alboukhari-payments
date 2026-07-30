<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

class DbBackup extends Command
{
    protected $signature = 'db:backup
                            {--keep=14 : Keep this many recent backups (older are pruned)}';

    protected $description = 'Create a timestamped backup of the database (SQLite copy or mysqldump).';

    public function handle(): int
    {
        $connection = config('database.default');
        $driver     = config("database.connections.{$connection}.driver");
        $stamp      = now()->format('Ymd_His');

        $dir = storage_path('app/backups');
        if (! File::isDirectory($dir)) {
            File::makeDirectory($dir, 0775, true);
        }

        if ($driver === 'sqlite') {
            $src = config("database.connections.{$connection}.database");
            if (! is_file($src)) {
                $this->error("SQLite file not found: {$src}");
                return self::FAILURE;
            }
            $dest = "{$dir}/db-{$stamp}.sqlite";
            if (! copy($src, $dest)) {
                $this->error('Copy failed.');
                return self::FAILURE;
            }
            $this->info("Backup created: {$dest} (" . $this->humanSize(filesize($dest)) . ')');
        } elseif ($driver === 'mysql') {
            $db = config("database.connections.{$connection}.database");
            $user = config("database.connections.{$connection}.username");
            $pass = config("database.connections.{$connection}.password");
            $host = config("database.connections.{$connection}.host");
            $port = config("database.connections.{$connection}.port", 3306);
            $dest = "{$dir}/db-{$stamp}.sql";
            $cmd  = sprintf(
                'mysqldump --host=%s --port=%s --user=%s %s %s > %s 2>&1',
                escapeshellarg($host),
                escapeshellarg((string) $port),
                escapeshellarg($user),
                $pass ? '--password='.escapeshellarg($pass) : '',
                escapeshellarg($db),
                escapeshellarg($dest)
            );
            exec($cmd, $output, $exit);
            if ($exit !== 0) {
                $this->error("mysqldump failed (exit {$exit}): ".implode("\n", $output));
                return self::FAILURE;
            }
            $this->info("Backup created: {$dest} (" . $this->humanSize(filesize($dest)) . ')');
        } else {
            $this->error("Unsupported driver for backup: {$driver}");
            return self::FAILURE;
        }

        $this->prune($dir, (int) $this->option('keep'));
        return self::SUCCESS;
    }

    private function prune(string $dir, int $keep): void
    {
        $files = collect(File::files($dir))
            ->filter(fn ($f) => str_starts_with($f->getFilename(), 'db-'))
            ->sortByDesc(fn ($f) => $f->getMTime())
            ->values();
        $toDelete = $files->slice($keep);
        foreach ($toDelete as $f) {
            @unlink($f->getRealPath());
            $this->line("Pruned old backup: {$f->getFilename()}");
        }
    }

    private function humanSize(int $bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB'];
        $i = 0;
        while ($bytes >= 1024 && $i < count($units) - 1) {
            $bytes /= 1024;
            $i++;
        }
        return sprintf('%.1f %s', $bytes, $units[$i]);
    }
}
