# Anaya API

Laravel 13 API for **Project Anaya** — employee, computer, site, and daily click reporting.

## Setup

```bash
cd anayaapi
cp .env.example .env
php artisan key:generate
touch database/database.sqlite   # already created on first install
php artisan migrate:fresh --seed
php artisan serve
```

API: `http://localhost:8000/api`

Frontend (from `sales-ops-dashboard`): `http://localhost:3000` with `NEXT_PUBLIC_API_URL=http://127.0.0.1:8000/api`.

SQLite is the default so the app runs without MySQL. To use MySQL, set `DB_CONNECTION=mysql` and the `DB_*` values in `.env`.

## Demo logins

All passwords default to `password` (`ADMIN_PASSWORD` in `.env`).

| Unique ID | Role | Notes |
|---|---|---|
| `ANAYA-ADMIN` | Admin | Full access |
| `ANAYA-MGR` | Manager | Employees, computers, work, reports |
| `ANAYA-0001` | Employee | Ravi — two sites assigned today |
| `ANAYA-0002` | Employee | Neha |
| `ANAYA-0003` | Interview pass | Cannot log in until onboarded |

## Scheduler

Copies yesterday’s work to today when an employee has no schedule:

```bash
php artisan schedule:work
# or once
php artisan anaya:copy-yesterday-assignments
```
