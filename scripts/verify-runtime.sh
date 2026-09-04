#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
API_DIR="$ROOT_DIR/apps/api"
WEB_DIR="$ROOT_DIR/apps/web"

for command in php composer node npm mysql; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "Missing required command: $command" >&2
    exit 2
  fi
done

if [[ "${ALLOW_DATABASE_RESET:-0}" != "1" ]]; then
  echo "Refusing to reset a database. Re-run with ALLOW_DATABASE_RESET=1 after verifying apps/api/.env.ci points to a disposable test database." >&2
  exit 2
fi

if ! grep -Eq '^APP_ENV=testing$' "$API_DIR/.env.ci" || ! grep -Eq '^DB_DATABASE=stitchra_test$' "$API_DIR/.env.ci"; then
  echo "apps/api/.env.ci must use APP_ENV=testing and DB_DATABASE=stitchra_test." >&2
  exit 2
fi

echo '== API dependency and syntax verification =='
cd "$API_DIR"
composer validate --no-interaction
composer install --no-interaction --prefer-dist --no-progress
cp .env.ci .env
php artisan key:generate --force
find app bootstrap config database routes tests -name '*.php' -print0 | xargs -0 -n1 php -l

echo '== Fresh MySQL migration, seed, and full Pest suite =='
STITCHRA_BASE_CURRENCY=USD php artisan migrate:fresh --seed --force
php artisan migrate:status
./vendor/bin/pest --colors=always

echo '== Web deterministic install, TypeScript, and production build =='
cd "$WEB_DIR"
npm ci --no-audit --no-fund
npx tsc --noEmit
npm run build

if [[ "${RUN_E2E:-0}" == "1" ]]; then
  echo '== Playwright login smoke =='
  npx playwright install --with-deps chromium
  npm run start -- -H 127.0.0.1 -p 3000 > /tmp/stitchra-web.log 2>&1 &
  WEB_PID=$!
  trap 'kill "$WEB_PID" 2>/dev/null || true; cat /tmp/stitchra-web.log' EXIT

  for attempt in $(seq 1 30); do
    if curl --fail --silent http://127.0.0.1:3000/login > /dev/null; then
      break
    fi
    if [[ "$attempt" -eq 30 ]]; then
      echo 'Web server did not become ready.' >&2
      exit 1
    fi
    sleep 2
  done

  E2E_BASE_URL=http://127.0.0.1:3000 npm run test:e2e
fi

echo 'Runtime verification completed successfully.'
