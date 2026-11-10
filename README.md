# Nextcloud Work Tracker

A multi-user time tracking app for Nextcloud 35+ with a dashboard widget, project tracking, pause management, daily reporting and CSV/XLSX export.

## Status

Initial repository bootstrap. Implementation is developed on the `Main` branch.

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

## Development target

Nextcloud 35.0.2 (AIO/Docker deployment), PHP app framework, Vue dashboard UI, Nextcloud database abstraction and background jobs.
