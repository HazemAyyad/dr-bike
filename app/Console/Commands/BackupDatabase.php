<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;

class BackupDatabase extends Command
{
    protected $signature = 'database:backup
        {--connection= : Database connection name from config/database.php}
        {--path= : Folder where backup files are stored}
        {--keep-days= : Delete backup files older than this number of days}';

    protected $description = 'Create a MySQL database backup using mysqldump';

    public function handle(): int
    {
        $connectionName = $this->option('connection') ?: config('database.default');
        $connection = config("database.connections.{$connectionName}");

        if (! is_array($connection) || ($connection['driver'] ?? null) !== 'mysql') {
            $this->error("Database backup supports MySQL connections only. Connection: {$connectionName}");

            return self::FAILURE;
        }

        $backupPath = (string) ($this->option('path') ?: config('database_backup.path'));
        File::ensureDirectoryExists($backupPath, 0755, true);

        $database = (string) ($connection['database'] ?? '');
        if ($database === '') {
            $this->error('DB_DATABASE is empty; cannot create backup.');

            return self::FAILURE;
        }

        $baseFilename = sprintf('%s-%s', $database, now('Asia/Hebron')->format('Y-m-d-His'));
        $workingPath = rtrim($backupPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$baseFilename.'-in-progress.sql';
        $completePath = rtrim($backupPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$baseFilename.'-complete.sql';
        $failedPath = rtrim($backupPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.$baseFilename.'-failed.txt';

        $command = $this->buildDumpCommand($connection, $database);
        $process = new Process($command, base_path(), $this->buildEnvironment($connection), null, 300);

        $handle = fopen($workingPath, 'wb');
        if ($handle === false) {
            $this->error("Could not write backup file: {$workingPath}");

            return self::FAILURE;
        }

        try {
            $process->run(function (string $type, string $buffer) use ($handle): void {
                if ($type === Process::OUT) {
                    fwrite($handle, $buffer);
                }
            });
        } finally {
            fclose($handle);
        }

        if (! $process->isSuccessful()) {
            File::delete($workingPath);
            File::put($failedPath, $this->failureReport($process));
            $this->error('Database backup failed.');
            $this->line(trim($process->getErrorOutput()) ?: 'No error output was returned by mysqldump.');
            $this->line("Failure details saved: {$failedPath}");

            return self::FAILURE;
        }

        File::move($workingPath, $completePath);
        $this->deleteOldBackups($backupPath, (int) ($this->option('keep-days') ?: config('database_backup.keep_days')));

        $this->info("Database backup created: {$completePath}");

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<int, string>
     */
    private function buildDumpCommand(array $connection, string $database): array
    {
        $command = [
            $this->resolveDumpBinary(),
            '--single-transaction',
            '--quick',
            '--routines',
            '--triggers',
            '--events',
            '--default-character-set=utf8mb4',
        ];

        if (! empty($connection['host'])) {
            $command[] = '--host='.(string) $connection['host'];
        }

        if (! empty($connection['port'])) {
            $command[] = '--port='.(string) $connection['port'];
        }

        if (! empty($connection['unix_socket'])) {
            $command[] = '--socket='.(string) $connection['unix_socket'];
        }

        if (! empty($connection['username'])) {
            $command[] = '--user='.(string) $connection['username'];
        }

        $command[] = $database;

        return $command;
    }

    private function resolveDumpBinary(): string
    {
        $configured = (string) config('database_backup.mysqldump_binary', 'mysqldump');

        if ($configured !== 'mysqldump' || DIRECTORY_SEPARATOR !== '\\') {
            return $configured;
        }

        foreach (['F:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe', 'C:\\laragon\\bin\\mysql\\*\\bin\\mysqldump.exe'] as $pattern) {
            $matches = glob($pattern);
            if (! empty($matches)) {
                rsort($matches);

                return $matches[0];
            }
        }

        return $configured;
    }

    /**
     * @param  array<string, mixed>  $connection
     * @return array<string, string>
     */
    private function buildEnvironment(array $connection): array
    {
        $password = (string) ($connection['password'] ?? '');

        return $password === '' ? [] : ['MYSQL_PWD' => $password];
    }

    private function failureReport(Process $process): string
    {
        return implode(PHP_EOL, [
            'Database backup status: failed',
            'Failed at: '.now('Asia/Hebron')->toDateTimeString().' Asia/Hebron',
            'Exit code: '.($process->getExitCode() ?? 'unknown'),
            '',
            'Error output:',
            trim($process->getErrorOutput()) ?: 'No error output was returned by mysqldump.',
            '',
            'Standard output:',
            trim($process->getOutput()) ?: 'No standard output was returned.',
            '',
        ]);
    }

    private function deleteOldBackups(string $backupPath, int $keepDays): void
    {
        if ($keepDays <= 0) {
            return;
        }

        $deleteBefore = now()->subDays($keepDays)->getTimestamp();

        foreach (File::glob(rtrim($backupPath, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR.'*') ?: [] as $file) {
            if (! str_ends_with($file, '.sql') && ! str_ends_with($file, '.txt')) {
                continue;
            }

            if (File::lastModified($file) < $deleteBefore) {
                File::delete($file);
            }
        }
    }
}
