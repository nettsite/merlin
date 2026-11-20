<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

class BackupDatabaseCommand extends Command
{
    protected $signature = 'database:backup
        {--connection= : Database connection to back up (default: the application\'s default)}
        {--binary= : Path to mariadb-dump/mysqldump when it is not on PATH}';

    protected $description = 'Dump the database to data-backups/<database>.sql.gz, rotating the two previous runs to .bak.gz and .bbk.gz';

    public function handle(): int
    {
        $connection = $this->option('connection') ?: config('database.default');
        $config = config("database.connections.{$connection}");

        if (! is_array($config)) {
            $this->error("Unknown database connection [{$connection}].");

            return self::FAILURE;
        }

        if (! in_array($config['driver'] ?? null, ['mysql', 'mariadb'], true)) {
            $this->error("The connection [{$connection}] is not MySQL/MariaDB; nothing to back up.");

            return self::FAILURE;
        }

        $finder = new ExecutableFinder;
        $binary = $this->option('binary') ?: ($finder->find('mariadb-dump') ?? $finder->find('mysqldump'));

        if ($binary === null || ! is_executable($binary)) {
            $this->error('Neither mariadb-dump nor mysqldump was found. Install the MariaDB client or pass --binary.');

            return self::FAILURE;
        }

        $directory = (string) config('database.backup.path');

        if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
            $this->error("Cannot create {$directory}.");

            return self::FAILURE;
        }

        // Fixed names, newest first: an off-site sync sees the same three files every day.
        $base = "{$directory}/{$config['database']}.sql";
        $generations = ["{$base}.gz", "{$base}.bak.gz", "{$base}.bbk.gz"];
        $partial = "{$base}.gz.partial";

        $this->line("Backing up database {$config['database']}...");

        if (! $this->dump($binary, $config, $partial)) {
            return self::FAILURE;
        }

        // Only now, with a complete new dump in hand, is anything rotated. Each
        // rename replaces its target atomically, so no step can lose the last good copy.
        for ($i = count($generations) - 1; $i > 0; $i--) {
            if (file_exists($generations[$i - 1])) {
                rename($generations[$i - 1], $generations[$i]);
            }
        }

        rename($partial, $generations[0]);

        $size = round(filesize($generations[0]) / 1024 / 1024, 2);
        $this->info("Wrote {$generations[0]} ({$size} MB).");

        foreach (array_slice($generations, 1) as $older) {
            if (file_exists($older)) {
                $this->line('Kept '.basename($older).'.');
            }
        }

        return self::SUCCESS;
    }

    /**
     * Streams the dump into a gzip file. The credentials go through a temporary
     * option file (never the command line, where `ps` would show them).
     *
     * @param  array<string, mixed>  $config
     */
    private function dump(string $binary, array $config, string $partial): bool
    {
        $defaults = tempnam(sys_get_temp_dir(), 'dbbackup_');
        chmod($defaults, 0600);
        file_put_contents($defaults, $this->optionFile($config));

        $gz = gzopen($partial, 'wb9');
        chmod($partial, 0600);

        $tail = '';
        $errors = '';

        try {
            // --defaults-extra-file must be the first argument.
            $process = new Process([
                $binary,
                "--defaults-extra-file={$defaults}",
                '--single-transaction',
                '--quick',
                '--routines',
                '--triggers',
                '--events',
                '--default-character-set=utf8mb4',
                $config['database'],
            ]);
            $process->setTimeout(null);

            $process->run(function (string $type, string $buffer) use ($gz, &$tail, &$errors): void {
                if ($type === Process::OUT) {
                    gzwrite($gz, $buffer);
                    $tail = substr($tail.$buffer, -300);

                    return;
                }

                $errors .= $buffer;
            });
        } finally {
            gzclose($gz);
            @unlink($defaults);
        }

        // mariadb-dump ends a complete dump with "-- Dump completed"; a dump cut
        // short by an error must never be kept as if it were a backup.
        if (! $process->isSuccessful() || ! str_contains($tail, 'Dump completed')) {
            @unlink($partial);
            $this->error('The dump failed; no backup was written and existing backups are untouched.');

            if (trim($errors) !== '') {
                $this->line(trim($errors));
            }

            return false;
        }

        return true;
    }

    /**
     * @param  array<string, mixed>  $config
     */
    private function optionFile(array $config): string
    {
        $quote = fn (string $value): string => '"'.str_replace(['\\', '"'], ['\\\\', '\\"'], $value).'"';

        $lines = [
            '[client]',
            'user='.$quote((string) $config['username']),
            'password='.$quote((string) ($config['password'] ?? '')),
        ];

        if (! empty($config['unix_socket'])) {
            $lines[] = 'socket='.$quote((string) $config['unix_socket']);
        } else {
            $lines[] = 'host='.$quote((string) $config['host']);
            $lines[] = 'port='.$quote((string) $config['port']);
        }

        return implode("\n", $lines)."\n";
    }
}
