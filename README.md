# Assistance Ghana Attendance – API (v2)

Laravel 13 / PHP 8.3 / PostgreSQL API for the Assistance Ghana Attendance PWA.
Specifications and status: `docs/` (start with `docs/PROGRESS.md`). API contract: `docs/api/openapi.yaml`.

## Run locally (without Docker)
Requirements: PHP 8.3 (pdo_pgsql, intl, mbstring, bcmath, zip), Composer 2, PostgreSQL 16.
```
createuser -P attendance            # password of your choice
createdb -O attendance attendance
createdb -O attendance attendance_test
cp .env.example .env                # set DB_PASSWORD
composer install
php artisan key:generate
php artisan migrate
php artisan serve                   # http://localhost:8000/api/health
php artisan queue:work              # separate terminal: queue worker
php artisan schedule:work           # separate terminal: scheduler
```

## Quality checks
```
php artisan test                    # Pest, on PostgreSQL (database attendance_test, see phpunit.xml)
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
```

## Docker
See `docs/DOCKER.md`.
