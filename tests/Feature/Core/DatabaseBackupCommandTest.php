<?php

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

beforeEach(function (): void {
    Carbon::setTestNow('2026-11-20 02:00:00');

    $this->dir = sys_get_temp_dir().'/dbbackup-test-'.Str::random(8);
    mkdir($this->dir, 0700);

    // A separate connection: the default one must stay SQLite for the framework's own test setup and teardown.
    config([
        'database.connections.backup_test' => [
            'driver' => 'mariadb',
            'database' => 'merlin_test',
            'username' => 'backup_user',
            'password' => 'p@ss"w\\ord#1',
            'host' => '127.0.0.1',
            'port' => '3306',
            'unix_socket' => '',
        ],
        'database.backup.path' => $this->dir.'/data-backups',
    ]);

    $this->backups = $this->dir.'/data-backups';
});

afterEach(function (): void {
    File::deleteDirectory($this->dir);
});

/**
 * A stand-in for mariadb-dump: records its arguments and the credentials file it
 * was given, then prints $body (by default a complete dump unique to this run).
 */
function fakeDumper(string $dir, string $body = '', int $exit = 0): string
{
    $body = $body !== '' ? $body : 'echo "-- run $(date +%s%N)"; echo "CREATE TABLE t (id int);"; echo "-- Dump completed on 2026-11-20 02:00:00"';

    $script = str_replace(['__DIR__', '__BODY__', '__EXIT__'], [$dir, $body, (string) $exit], <<<'SH'
#!/bin/sh
printf '%s\n' "$@" > "__DIR__/args.txt"
cp "${1#--defaults-extra-file=}" "__DIR__/defaults.txt"
__BODY__
exit __EXIT__
SH);

    file_put_contents("{$dir}/fake-dump", $script);
    chmod("{$dir}/fake-dump", 0755);

    return "{$dir}/fake-dump";
}

/**
 * @return array<int, string>
 */
function backupFiles(string $dir): array
{
    return array_values(array_diff(scandir($dir) ?: [], ['.', '..']));
}

function runBackup(object $test, string $binary): void
{
    $test->artisan('database:backup', ['--connection' => 'backup_test', '--binary' => $binary])->assertSuccessful();
}

it('writes a private gzipped dump named after the database, with no date in the name', function (): void {
    $this->artisan('database:backup', ['--connection' => 'backup_test', '--binary' => fakeDumper($this->dir)])
        ->expectsOutputToContain('Backing up database merlin_test')
        ->assertSuccessful();

    $file = "{$this->backups}/merlin_test.sql.gz";

    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.gz'])
        ->and(gzdecode(file_get_contents($file)))->toContain('CREATE TABLE t')
        ->and(substr(sprintf('%o', fileperms($file)), -4))->toBe('0600')
        ->and(substr(sprintf('%o', fileperms($this->backups)), -4))->toBe('0700');
});

it('passes credentials through a temporary option file, never on the command line', function (): void {
    runBackup($this, fakeDumper($this->dir));

    $args = file($this->dir.'/args.txt', FILE_IGNORE_NEW_LINES);
    $defaults = file_get_contents($this->dir.'/defaults.txt');

    expect($args[0])->toStartWith('--defaults-extra-file=')
        ->and(implode(' ', $args))->not->toContain('p@ss')
        ->and($args)->toContain('--single-transaction')
        ->and(end($args))->toBe('merlin_test')
        ->and($defaults)->toContain('user="backup_user"')
        ->and($defaults)->toContain('password="p@ss\\"w\\\\ord#1"')
        ->and($defaults)->toContain('host="127.0.0.1"')
        ->and(file_exists(Str::after($args[0], '=')))->toBeFalse();
});

it('rotates the newest dump through three fixed filenames', function (): void {
    $dumper = fakeDumper($this->dir);
    $read = fn (string $name): string => gzdecode(file_get_contents("{$this->backups}/{$name}"));
    $runs = [];

    foreach ([1, 2, 3, 4] as $run) {
        runBackup($this, $dumper);
        $runs[$run] = $read('merlin_test.sql.gz');

        // Each run's dump is unique, so the content proves which generation is which.
        expect(array_unique($runs))->toHaveCount($run);
    }

    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.bak.gz', 'merlin_test.sql.bbk.gz', 'merlin_test.sql.gz'])
        ->and($read('merlin_test.sql.gz'))->toBe($runs[4])
        ->and($read('merlin_test.sql.bak.gz'))->toBe($runs[3])
        ->and($read('merlin_test.sql.bbk.gz'))->toBe($runs[2]);
});

it('builds the generations up over the first three runs', function (): void {
    $dumper = fakeDumper($this->dir);

    runBackup($this, $dumper);
    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.gz']);

    runBackup($this, $dumper);
    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.bak.gz', 'merlin_test.sql.gz']);

    runBackup($this, $dumper);
    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.bak.gz', 'merlin_test.sql.bbk.gz', 'merlin_test.sql.gz']);
});

it('leaves other files in the folder alone', function (): void {
    mkdir($this->backups, 0700);
    file_put_contents("{$this->backups}/notes.txt", 'keep me');
    file_put_contents("{$this->backups}/other_db.sql.gz", 'another database');

    runBackup($this, fakeDumper($this->dir));

    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.gz', 'notes.txt', 'other_db.sql.gz'])
        ->and(file_get_contents("{$this->backups}/other_db.sql.gz"))->toBe('another database');
});

it('keeps all three existing generations untouched when the dump fails', function (): void {
    mkdir($this->backups, 0700);
    file_put_contents("{$this->backups}/merlin_test.sql.gz", 'newest');
    file_put_contents("{$this->backups}/merlin_test.sql.bak.gz", 'previous');
    file_put_contents("{$this->backups}/merlin_test.sql.bbk.gz", 'oldest');

    $this->artisan('database:backup', [
        '--connection' => 'backup_test',
        '--binary' => fakeDumper($this->dir, 'echo "-- MariaDB dump"; echo "access denied for user" >&2', 2),
    ])
        ->expectsOutputToContain('The dump failed')
        ->expectsOutputToContain('access denied for user')
        ->assertFailed();

    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.bak.gz', 'merlin_test.sql.bbk.gz', 'merlin_test.sql.gz'])
        ->and(file_get_contents("{$this->backups}/merlin_test.sql.gz"))->toBe('newest')
        ->and(file_get_contents("{$this->backups}/merlin_test.sql.bak.gz"))->toBe('previous')
        ->and(file_get_contents("{$this->backups}/merlin_test.sql.bbk.gz"))->toBe('oldest');
});

it('rejects a dump that exits cleanly but was cut short, without rotating', function (): void {
    mkdir($this->backups, 0700);
    file_put_contents("{$this->backups}/merlin_test.sql.gz", 'newest');

    $this->artisan('database:backup', [
        '--connection' => 'backup_test',
        '--binary' => fakeDumper($this->dir, 'echo "-- MariaDB dump"; echo "CREATE TABLE t (id int);"'),
    ])
        ->expectsOutputToContain('The dump failed')
        ->assertFailed();

    expect(backupFiles($this->backups))->toBe(['merlin_test.sql.gz'])
        ->and(file_get_contents("{$this->backups}/merlin_test.sql.gz"))->toBe('newest');
});

it('refuses to run against a non-MySQL connection', function (): void {
    $this->artisan('database:backup', ['--connection' => 'sqlite', '--binary' => fakeDumper($this->dir)])
        ->expectsOutputToContain('is not MySQL/MariaDB')
        ->assertFailed();
});

it('rejects an unknown connection name', function (): void {
    $this->artisan('database:backup', ['--connection' => 'nope', '--binary' => fakeDumper($this->dir)])
        ->expectsOutputToContain('Unknown database connection [nope]')
        ->assertFailed();
});

it('rejects a missing dump tool', function (): void {
    $this->artisan('database:backup', ['--connection' => 'backup_test', '--binary' => $this->dir.'/does-not-exist'])
        ->expectsOutputToContain('Neither mariadb-dump nor mysqldump was found')
        ->assertFailed();
});

it('defaults to the data-backups folder in the app root, and git ignores it', function (): void {
    $defaults = require config_path('database.php');

    expect($defaults['backup']['path'])->toBe(base_path('data-backups'))
        ->and(file_get_contents(base_path('.gitignore')))->toContain("/data-backups\n");
});
