<?php

use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Str;

/*
 * Runs the real deploy.sh inside a throwaway sandbox: a bare git remote, a clone
 * that holds a copy of the script, and fake php/composer/npm/sudo/curl commands
 * that only log what they were asked to do. Nothing real is touched.
 */

beforeEach(function (): void {
    $this->sandbox = sys_get_temp_dir().'/deploy-test-'.Str::random(8);
    $this->work = $this->sandbox.'/work';
    mkdir($this->sandbox.'/bin', 0755, true);

    $shims = [
        'php' => <<<'SH'
#!/bin/sh
echo "php $*" >> "$SANDBOX/calls.log"
[ "$1" = "artisan" ] || exit 0
shift
case "$1 $2" in
    "config:show app.env") echo "  app.env ........ ${FAKE_APP_ENV:-production}  " ;;
    "config:show app.url") echo "  app.url ........ https://merlin.test  " ;;
    "config:show database.default") echo "  database.default ........ mariadb  " ;;
    "config:show database.connections.mariadb.database") echo "  database.connections.mariadb.database ........ merlin_nettsite  " ;;
esac
if [ "$1" = "migrate:status" ]; then
    case "${FAKE_PENDING:-none}" in
        none) echo " INFO  No pending migrations. " ;;
        pending) echo "  2026_11_21_000000_add_things ........ [Pending]  " ;;
        broken) echo "SQLSTATE[HY000] [2002] Connection refused" >&2; exit 1 ;;
    esac
fi
exit 0
SH,
        'composer' => "#!/bin/sh\necho \"composer \$*\" >> \"\$SANDBOX/calls.log\"\nexit \${FAKE_COMPOSER_EXIT:-0}\n",
        'npm' => "#!/bin/sh\necho \"npm \$*\" >> \"\$SANDBOX/calls.log\"\n",
        'sudo' => "#!/bin/sh\necho \"sudo \$*\" >> \"\$SANDBOX/calls.log\"\n",
        'curl' => <<<'SH'
#!/bin/sh
echo "curl $*" >> "$SANDBOX/calls.log"
for last; do :; done
case "$last" in
    */login) printf 200 ;;
    *) printf '%s' "${FAKE_ROOT_CODE:-302}" ;;
esac
SH,
    ];

    foreach ($shims as $name => $body) {
        file_put_contents("{$this->sandbox}/bin/{$name}", $body);
        chmod("{$this->sandbox}/bin/{$name}", 0755);
    }

    // A bare "origin" and a clone that carries the script under test.
    deployGit($this->sandbox, 'init', '--bare', '--initial-branch=main', 'origin.git');
    deployGit($this->sandbox, 'clone', '--quiet', 'origin.git', 'work');
    deployGit($this->work, 'checkout', '-B', 'main');

    mkdir($this->work.'/database/migrations', 0755, true);
    file_put_contents($this->work.'/artisan', "<?php\n");
    file_put_contents($this->work.'/database/migrations/.gitkeep', '');
    copy(base_path('deploy.sh'), $this->work.'/deploy.sh');
    chmod($this->work.'/deploy.sh', 0755);

    deployGit($this->work, 'add', '.');
    deployGit($this->work, 'commit', '--quiet', '-m', 'initial');
    deployGit($this->work, 'push', '--quiet', '-u', 'origin', 'main');
});

afterEach(function (): void {
    File::deleteDirectory($this->sandbox);
});

function deployGit(string $dir, string ...$args): void
{
    Process::path($dir)->run(['git', '-c', 'user.name=Test', '-c', 'user.email=test@example.com', ...$args])->throw();
}

/** Pushes a commit to the remote from a second clone, as if it had been merged on GitHub. */
function deployIncoming(string $sandbox, string $path, string $content): void
{
    if (! is_dir("{$sandbox}/pusher")) {
        deployGit($sandbox, 'clone', '--quiet', 'origin.git', 'pusher');
    }

    deployGit("{$sandbox}/pusher", 'pull', '--quiet');
    @mkdir(dirname("{$sandbox}/pusher/{$path}"), 0755, true);
    file_put_contents("{$sandbox}/pusher/{$path}", $content);
    deployGit("{$sandbox}/pusher", 'add', '.');
    deployGit("{$sandbox}/pusher", 'commit', '--quiet', '-m', "add {$path}");
    deployGit("{$sandbox}/pusher", 'push', '--quiet');
}

/**
 * @param  array<int, string>  $args
 * @param  array<string, string>  $env
 */
function runDeploy(object $test, array $args = ['--yes'], array $env = []): ProcessResult
{
    return Process::path($test->work)
        ->env(['PATH' => $test->sandbox.'/bin:'.getenv('PATH'), 'SANDBOX' => $test->sandbox] + $env)
        ->run(['bash', './deploy.sh', ...$args]);
}

/** @return array<int, string> every fake command that was run, without the config look-ups */
function deployCalls(object $test): array
{
    $log = $test->sandbox.'/calls.log';

    if (! file_exists($log)) {
        return [];
    }

    return array_values(array_filter(
        file($log, FILE_IGNORE_NEW_LINES),
        fn (string $line): bool => ! str_contains($line, 'config:show'),
    ));
}

function deployCalled(array $calls, string $needle): bool
{
    return collect($calls)->contains(fn (string $call): bool => str_contains($call, $needle));
}

/** @param  array<int, string>  $needles */
function expectCallsInOrder(array $calls, array $needles): void
{
    $previous = -1;

    foreach ($needles as $needle) {
        // A trailing $ anchors the needle to the end of the command.
        $index = collect($calls)->search(fn (string $call): bool => str_ends_with($needle, '$')
            ? str_ends_with($call, substr($needle, 0, -1))
            : str_contains($call, $needle));

        expect($index)->not->toBeFalse("never called: {$needle}")
            ->and($index)->toBeGreaterThan($previous, "out of order: {$needle}");

        $previous = $index;
    }
}

it('is executable, parses, and checks the environment before it changes anything', function (): void {
    $script = file_get_contents(base_path('deploy.sh'));

    expect(is_executable(base_path('deploy.sh')))->toBeTrue()
        ->and(Process::run(['bash', '-n', base_path('deploy.sh')])->successful())->toBeTrue()
        ->and(strpos($script, 'not \'production\''))->toBeLessThan(strpos($script, 'php artisan down'))
        ->and($script)->not->toContain('config:cache');
});

it('deploys in the right order: maintenance mode, code, dependencies, backup, migrate, caches, FPM, back up, probes', function (): void {
    deployIncoming($this->sandbox, 'database/migrations/2026_11_21_000000_add_things.php', "<?php // adds a table\n");

    $result = runDeploy($this, ['--yes'], ['FAKE_PENDING' => 'pending']);

    expect($result->exitCode())->toBe(0, $result->output())
        ->and($result->output())->toContain('Deploying https://merlin.test')->toContain('database merlin_nettsite')
        ->toContain('1 pending: taking a backup first')->toContain('Deploy complete')
        ->and(file_exists($this->work.'/database/migrations/2026_11_21_000000_add_things.php'))->toBeTrue();

    expectCallsInOrder(deployCalls($this), [
        'php artisan down --retry=15',
        'composer install --no-dev --no-interaction --optimize-autoloader',
        'npm ci',
        'npm run build',
        'php artisan migrate:status --pending',
        'php artisan database:backup',
        'php artisan migrate --force',
        'php artisan storage:link',
        'php artisan optimize:clear',
        'php artisan optimize --except=config',
        'sudo -n systemctl reload php8.4-fpm',
        'php artisan up',
        'curl -sS -o /dev/null -w %{http_code} https://merlin.test/login',
        'curl -sS -o /dev/null -w %{http_code} https://merlin.test/$',
    ]);
});

it('does not take a backup or migrate when nothing is pending, and says so', function (): void {
    $result = runDeploy($this, ['--yes'], ['FAKE_PENDING' => 'none']);

    expect($result->exitCode())->toBe(0, $result->output())
        ->and($result->output())->toContain('Already up to date')->toContain('None pending.')
        ->and(deployCalled(deployCalls($this), 'database:backup'))->toBeFalse()
        ->and(deployCalled(deployCalls($this), 'migrate --force'))->toBeFalse()
        ->and(deployCalled(deployCalls($this), 'php artisan up'))->toBeTrue();
});

it('rebuilds the route, view and event caches but never the config cache, so edits to .env take effect straight away', function (): void {
    runDeploy($this, ['--yes']);

    $optimizeCalls = collect(deployCalls($this))
        ->filter(fn (string $call): bool => str_contains($call, 'artisan optimize') && ! str_contains($call, 'optimize:clear'));

    expect($optimizeCalls)->toHaveCount(1)
        ->and($optimizeCalls->first())->toContain('--except=config')
        ->and(deployCalled(deployCalls($this), 'config:cache'))->toBeFalse();
});

it('shows what is incoming on a dry run and changes nothing', function (): void {
    deployIncoming($this->sandbox, 'database/migrations/2026_11_21_000000_drop_clients.php', "<?php\nSchema::dropIfExists('clients');\n");

    $head = trim(Process::path($this->work)->run(['git', 'rev-parse', 'HEAD'])->output());
    $result = runDeploy($this, ['--dry-run']);

    expect($result->exitCode())->toBe(0, $result->output())
        ->and($result->output())->toContain('add database/migrations/2026_11_21_000000_drop_clients.php')
        ->toContain('New migrations:')->toContain('look destructive')->toContain('Dry run: nothing was changed.')
        ->and(trim(Process::path($this->work)->run(['git', 'rev-parse', 'HEAD'])->output()))->toBe($head)
        ->and(deployCalled(deployCalls($this), 'artisan down'))->toBeFalse()
        ->and(deployCalled(deployCalls($this), 'composer'))->toBeFalse();
});

it('refuses --yes when a new migration looks destructive', function (): void {
    deployIncoming($this->sandbox, 'database/migrations/2026_11_21_000000_drop_clients.php', "<?php\nSchema::dropIfExists('clients');\n");

    $result = runDeploy($this, ['--yes']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('Refusing --yes')
        ->and(deployCalled(deployCalls($this), 'artisan down'))->toBeFalse();
});

it('will not deploy without a terminal to confirm on unless told to', function (): void {
    $result = runDeploy($this, []);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('No terminal to confirm on')
        ->and(deployCalled(deployCalls($this), 'artisan down'))->toBeFalse();
});

it('refuses to run when the app is not in production', function (): void {
    $result = runDeploy($this, ['--yes'], ['FAKE_APP_ENV' => 'local']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain("APP_ENV is 'local', not 'production'")
        ->and(deployCalls($this))->toBe([]);
});

it('refuses when tracked files have local changes', function (): void {
    file_put_contents($this->work.'/artisan', "<?php // edited on the server\n");

    $result = runDeploy($this);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('uncommitted changes')
        ->and(deployCalled(deployCalls($this), 'artisan down'))->toBeFalse();
});

it('refuses when local main has diverged from the remote', function (): void {
    file_put_contents($this->work.'/local-only.txt', 'x');
    deployGit($this->work, 'add', '.');
    deployGit($this->work, 'commit', '--quiet', '-m', 'local commit');
    deployIncoming($this->sandbox, 'remote-only.txt', 'y');

    $result = runDeploy($this);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('diverged')
        ->and(deployCalled(deployCalls($this), 'artisan down'))->toBeFalse();
});

it('leaves the app in maintenance mode when a step fails', function (): void {
    $result = runDeploy($this, ['--yes'], ['FAKE_COMPOSER_EXIT' => '1']);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output())->toContain('Deploy FAILED')->toContain('still in maintenance mode')
        ->and(deployCalled(deployCalls($this), 'artisan down'))->toBeTrue()
        ->and(deployCalled(deployCalls($this), 'artisan up'))->toBeFalse()
        ->and(deployCalled(deployCalls($this), 'npm'))->toBeFalse();
});

it('stops when the migration status cannot be read instead of assuming nothing is pending', function (): void {
    $result = runDeploy($this, ['--yes'], ['FAKE_PENDING' => 'broken']);

    expect($result->exitCode())->not->toBe(0)
        ->and($result->output())->toContain('Could not read the migration status')
        ->and(deployCalled(deployCalls($this), 'database:backup'))->toBeFalse()
        ->and(deployCalled(deployCalls($this), 'migrate --force'))->toBeFalse()
        ->and(deployCalled(deployCalls($this), 'artisan up'))->toBeFalse();
});

it('fails loudly, with the site up, when a post-deploy check does not return what it should', function (): void {
    $result = runDeploy($this, ['--yes'], ['FAKE_ROOT_CODE' => '500']);

    expect($result->exitCode())->toBe(1)
        ->and($result->output())->toContain('returned 500')
        ->and(deployCalled(deployCalls($this), 'artisan up'))->toBeTrue();
});

it('keeps a log of every deploy', function (): void {
    runDeploy($this, ['--yes']);

    expect(file_get_contents($this->work.'/storage/logs/deploy.log'))->toContain('=== deploy started')->toContain('Deploy complete');
});
