# Nextcloud Work Tracker

A multi-user time tracking app for Nextcloud 35+ with dashboard widget, project tracking, pause management, daily reporting and CSV/XLSX export.

## Development status

The canonical development branch is `main`. The current repository contains the initial application scaffold and core time-tracking services. The GitHub Actions workflow checks PHP syntax and runs the PHPUnit unit tests.

**Important:** The current code is not yet production-ready. In particular, the dashboard integration, API coverage, correction validation, reporting and full end-to-end behavior still need integration testing against a real Nextcloud AIO instance. Do not install it on a production instance yet.

## Requirements

- Personal and optionally shared projects, with validity dates and extensible metadata (including WBS element).
- Dashboard controls for work start/stop, pauses and project start/stop.
- Starting a project automatically ends an active pause and stops the previously active project.
- Project time is only recorded inside working intervals.
- Multiple pauses per day; pauses can be entered and corrected individually with start/end timestamps.
- Store exact timestamps and exact durations. Store rounded reporting values separately.
- Rounding is to 15-minute increments, nearest quarter hour, with 7m30s rounded upward.
- Work duration is rounded on the daily aggregate; each pause interval is rounded individually; project durations are rounded per project and calendar day.
- Daily, weekly and arbitrary-period views; CSV and XLSX exports.
- Per-user data isolation, with explicit sharing for shared projects.
- Safe schema migrations and preservation of historical records across updates.
- Nextcloud background jobs are best-effort; day boundaries are also handled lazily on subsequent requests so correctness does not depend on a job firing exactly at midnight.

## Install / update on Nextcloud AIO

Run the script on the Docker host:

```bash
bash <(curl -fsSL https://raw.githubusercontent.com/rankerson/nextcloud-worktracker/main/scripts/install-update.sh)
```

The script uses the AIO container name `nextcloud-aio-nextcloud` by default. If yours differs:

```bash
NEXTCLOUD_CONTAINER=your-container-name bash scripts/install-update.sh
```

Before replacing an existing installation, it creates a code backup under `/var/backups/nextcloud-worktracker`. It enables maintenance mode, copies the app from `main`, runs `occ upgrade`, enables the app and turns maintenance mode off. On failure it attempts to restore the previous app code. Database backups remain the responsibility of the Nextcloud backup process.

## Development target

Nextcloud 35.0.2 (AIO/Docker deployment), PHP app framework, Nextcloud database abstraction and background jobs.
