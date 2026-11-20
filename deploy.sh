#!/usr/bin/env bash
#
# Deploy the Merlin production instance (https://merlin.app) from main.
#
#   ./deploy.sh              show what is incoming, ask, then deploy
#   ./deploy.sh --dry-run    show what is incoming and change nothing
#   ./deploy.sh --yes        skip the confirmation (refused when a new migration
#                            looks destructive: that needs a person)
#
# Run it on the production box, from anywhere: it works on the directory it lives in.
# It refuses to run unless the app reports APP_ENV=production, so it can never
# deploy over the development working tree or touch its database.
#
# Routes, views and events are cleared and rebuilt at the end of every deploy, but config
# is deliberately NOT cached: NETTMAIL_SANDBOX_ADDRESS and other settings in .env must
# take effect immediately, and a cached config silently ignores edits. If you run
# `php artisan optimize` by hand, add --except=config.
#
# If a step fails the app is left in maintenance mode (a half-deployed ledger app
# should not serve requests): fix the cause and re-run, or `php artisan up`.
#
# Optional passwordless sudo for one command (failure only warns):
#   sudo systemctl reload php8.4-fpm

set -Eeuo pipefail

cd "$(dirname "$(readlink -f "${BASH_SOURCE[0]}")")"

DRY_RUN=0
ASSUME_YES=0

for arg in "$@"; do
    case "$arg" in
        --dry-run) DRY_RUN=1 ;;
        -y | --yes) ASSUME_YES=1 ;;
        *)
            echo "Usage: ./deploy.sh [--dry-run] [--yes]" >&2
            exit 2
            ;;
    esac
done

step() {
    echo
    echo "==> $1"
}

fail() {
    echo "ERROR: $1" >&2
    exit 1
}

config() {
    local output
    output=$(php artisan config:show "$1")

    # Last word of the last non-empty line: "app.env ....... production" -> production.
    printf '%s\n' "$output" | awk 'NF { value = $NF } END { print value }'
}

probe() {
    local path=$1 expected=$2 code
    code=$(curl -sS -o /dev/null -w '%{http_code}' "$APP_URL$path" || true)

    if [ "$code" != "$expected" ]; then
        fail "$APP_URL$path returned ${code:-no response}, expected $expected. The app is up; check storage/logs/laravel.log."
    fi

    echo "$path -> $code"
}

main() {
    [ -f artisan ] || fail "No artisan file in $(pwd)."

    local app_env connection database
    app_env=$(config app.env)
    [ "$app_env" = "production" ] || fail "APP_ENV is '$app_env', not 'production'. This script only deploys the production instance."

    APP_URL=$(config app.url)
    connection=$(config database.default)
    database=$(config "database.connections.$connection.database")

    echo "Deploying $APP_URL ($PWD, database $database)"

    [ "$(git rev-parse --abbrev-ref HEAD)" = "main" ] || fail "Not on the main branch."
    git diff --quiet && git diff --cached --quiet || fail "Tracked files have uncommitted changes; resolve them first."

    step "Checking for new commits"
    git fetch --quiet
    git rev-parse --abbrev-ref '@{u}' > /dev/null 2>&1 || fail "main has no upstream branch."
    git merge-base --is-ancestor HEAD '@{u}' || fail "Local main has diverged from its upstream; fix this by hand."

    if [ "$(git rev-parse HEAD)" = "$(git rev-parse '@{u}')" ]; then
        echo "Already up to date at $(git log -1 --oneline). Dependencies, assets and caches are still refreshed."
    else
        git log --oneline HEAD..'@{u}'
    fi

    local migrations destructive
    migrations=$(git diff --name-only --diff-filter=A HEAD '@{u}' -- database/migrations database/settings)
    [ -z "$migrations" ] || { echo; echo "New migrations:"; echo "$migrations" | sed 's/^/  /'; }

    # Only added lines of schema migrations; a view being rebuilt trips this too, so it asks, not blocks.
    destructive=$(git diff HEAD '@{u}' -- database/migrations | grep -E '^\+[^+]' | grep -Ei 'drop|truncate|rename|delete from|->change\(' || true)

    if [ "$DRY_RUN" = 1 ]; then
        [ -z "$destructive" ] || { echo; echo "These new migration lines look destructive:"; echo "$destructive"; }
        echo
        echo "Dry run: nothing was changed."
        return 0
    fi

    if [ -n "$destructive" ]; then
        echo
        echo "These new migration lines look destructive:"
        echo "$destructive"
        [ "$ASSUME_YES" = 0 ] || fail "Refusing --yes with destructive-looking migrations; run ./deploy.sh interactively."
        [ -t 0 ] || fail "No terminal to confirm on."

        read -r -p "Type 'yes' to deploy anyway: " answer
        [ "$answer" = "yes" ] || fail "Cancelled."
    elif [ "$ASSUME_YES" = 0 ]; then
        [ -t 0 ] || fail "No terminal to confirm on; pass --yes."

        read -r -p "Deploy now? [y/N] " answer
        [[ "$answer" =~ ^[Yy]$ ]] || fail "Cancelled."
    fi

    step "Entering maintenance mode"
    php artisan down --retry=15
    trap 'echo; echo "Deploy FAILED at the step above. The app is still in maintenance mode: fix the cause and re-run ./deploy.sh, or run php artisan up." >&2' ERR

    step "Pulling latest code"
    git pull --ff-only --quiet
    git log -1 --oneline

    step "Installing PHP dependencies"
    composer install --no-dev --no-interaction --optimize-autoloader

    step "Installing JS dependencies and building assets"
    npm ci --no-audit --no-fund
    npm run build

    step "Database migrations"
    local status pending
    status=$(php artisan migrate:status --pending 2>&1 || true)

    # Read the output, not the exit code, and stop on anything unexpected: an unreadable
    # status (database down, say) must not be taken as "nothing to migrate".
    if grep -q 'No pending migrations' <<< "$status"; then
        pending=0
    elif grep -q 'Pending' <<< "$status"; then
        pending=$(grep -c 'Pending' <<< "$status")
    else
        echo "$status" >&2
        fail "Could not read the migration status (is the database reachable?)."
    fi

    if [ "$pending" -gt 0 ]; then
        echo "$pending pending: taking a backup first."
        php artisan database:backup
        php artisan migrate --force
    else
        echo "None pending."
    fi

    if [ ! -L public/storage ]; then
        step "Linking storage"
        php artisan storage:link
    fi

    # Last, with the final code and migrations in place. Config is left out on purpose
    # (see the header): a config cache would ignore edits to .env.
    step "Rebuilding caches (everything except config)"
    php artisan optimize:clear
    php artisan optimize --except=config

    step "Reloading PHP-FPM"
    sudo -n systemctl reload php8.4-fpm || echo "WARNING: could not reload PHP-FPM (needs passwordless sudo); OPcache may serve old code for a moment."

    step "Leaving maintenance mode"
    php artisan up
    trap - ERR

    step "Checking the site"
    probe /login 200
    probe / 302

    step "Deploy complete: $(git log -1 --oneline)"
}

mkdir -p storage/logs
{
    echo
    echo "=== deploy started $(date '+%F %T') ==="
    main
} 2>&1 | tee -a storage/logs/deploy.log

# Everything above runs in a function so that a `git pull` that rewrites this very
# file cannot change the script while bash is still reading it.
exit "${PIPESTATUS[0]}"
