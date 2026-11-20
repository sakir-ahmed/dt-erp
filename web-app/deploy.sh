#!/usr/bin/env bash
set -euo pipefail

usage() {
    cat <<'USAGE'
Usage: ./deploy.sh [--dry-run] [--skip-build] [--yes]

Deploy the current branch of an existing DT ERP checkout. Set DEPLOY_REMOTE
to use a remote other than origin. Set DEPLOY_RELOAD_CMD to a shell command
that reloads PHP-FPM on hosts that require it.

--dry-run     Print the commands without fetching or changing the checkout.
--skip-build  Reuse the existing frontend build when assets have not changed.
--yes         Proceed without prompting.
USAGE
}

dry_run=false
skip_build=false
assume_yes=false

while [ "$#" -gt 0 ]; do
    case "$1" in
        --dry-run) dry_run=true ;;
        --skip-build) skip_build=true ;;
        -y|--yes) assume_yes=true ;;
        -h|--help) usage; exit 0 ;;
        *) printf 'Unknown option: %s\n' "$1" >&2; usage >&2; exit 2 ;;
    esac
    shift
done

fail() {
    printf '%s\n' "$1" >&2
    exit 1
}

run() {
    printf '  $'
    printf ' %q' "$@"
    printf '\n'
    if [ "$dry_run" = false ]; then
        "$@"
    fi
}

cd "$(dirname "${BASH_SOURCE[0]}")"
command -v git >/dev/null || fail 'Git is required.'
remote="${DEPLOY_REMOTE:-origin}"
git remote get-url "$remote" >/dev/null || fail "Remote $remote is unavailable."
branch=$(git symbolic-ref --quiet --short HEAD) || fail 'Check out the deployment branch first.'
previous_commit=$(git rev-parse HEAD)
[ -f artisan ] && [ -f .env ] || fail 'An existing DT ERP installation with an .env file is required.'
if [ "$skip_build" = true ] && [ ! -f public/build/manifest.json ]; then
    fail 'No existing Vite build found. Run without --skip-build.'
fi

printf 'Deploying %s/%s from %s\n' "$remote" "$branch" "$previous_commit"

if [ "$dry_run" = false ]; then
    command -v php >/dev/null || fail 'PHP is required.'
    command -v composer >/dev/null || fail 'Composer is required.'
    [ -f vendor/autoload.php ] || fail 'Install PHP dependencies before the first deployment.'
    if [ "$skip_build" = false ]; then
        command -v npm >/dev/null || fail 'npm is required for the frontend build.'
    fi
    [ -z "$(git status --porcelain --untracked-files=no)" ] || fail 'The checkout has tracked changes. Resolve them before deploying.'

    if [ "$assume_yes" = false ]; then
        [ -t 0 ] || fail 'Use --yes when running without an interactive terminal.'
        read -r -p 'Continue? [y/N] ' answer
        case "$answer" in
            y|Y|yes|YES) ;;
            *) exit 1 ;;
        esac
    fi

    lock_dir=storage/framework/deploy.lock
    mkdir "$lock_dir" || fail "Could not acquire deployment lock at $lock_dir."
    maintenance_started=false
    finish() {
        local status=$?
        rmdir "$lock_dir"
        if [ "$status" -ne 0 ] && [ "$maintenance_started" = true ]; then
            printf 'Deployment stopped. Review the failure before running php artisan up. Previous commit: %s\n' "$previous_commit" >&2
        fi
    }
    trap finish EXIT
    trap 'exit 130' INT
    trap 'exit 143' TERM
fi

run git fetch -- "$remote" "refs/heads/$branch"
if [ "$dry_run" = false ]; then
    target=$(git rev-parse --verify 'FETCH_HEAD^{commit}')
    git merge-base --is-ancestor "$previous_commit" "$target" ||
        fail 'The remote branch cannot fast-forward this checkout.'
    [ "$(git rev-parse HEAD)" = "$previous_commit" ] ||
        fail 'The checkout changed while fetching.'
else
    target=FETCH_HEAD
fi

maintenance_started=true
run php artisan down --retry=60 --no-interaction
run git merge --ff-only "$target"
run composer install --no-dev --optimize-autoloader --no-interaction --no-progress
if [ "$skip_build" = false ]; then
    run npm ci --include=dev --no-audit --no-fund
    run npm run build
fi

run php artisan config:cache --no-interaction
run php artisan migrate --force --no-interaction
if [ ! -L public/storage ]; then
    run php artisan storage:link --no-interaction
fi
run php artisan route:cache --no-interaction
run php artisan view:cache --no-interaction
if [ -n "${DEPLOY_RELOAD_CMD:-}" ]; then
    run bash -c "$DEPLOY_RELOAD_CMD"
fi
run php artisan queue:restart --no-interaction
run php artisan up --no-interaction
maintenance_started=false

if [ "$dry_run" = true ]; then
    printf 'Dry run finished.\n'
else
    printf 'Deployed %s at %s.\n' "$branch" "$(git rev-parse --short HEAD)"
fi
