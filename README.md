# Logs monitoring

Provides endpoints that indicate about errors in the log files and about cron
that stopped running.

## Configuration
In the form /admin/config/development/logs-monitoring-settings should be set:
1) paths to logs
2) a count of rows in the end where a search will be performed
3) a list of the words that indicate errors
4) the cron max age, in seconds.

### Usage

#### Logs
The module provide REST endpoint /admin/reports/logs-monitoring where a list
of logs with their statuses will be provided. It can be used by Uptimerobot to detect errors.
Returns HTTP status codes:
- 200 - no errors found
- 500 - some errors found

#### Cron
The REST endpoint /admin/reports/cron-monitoring reports how long ago cron last
completed. Point a second Uptimerobot monitor at it.
Returns HTTP status codes:
- 200 - cron completed within the configured max age
- 503 - cron has not completed within the configured max age, or never ran

Example response:

```json
{
  "check": "cron",
  "status": "ok",
  "last_run": 1753600000,
  "age_seconds": 312,
  "threshold": 3600
}
```

`status` is one of `ok`, `stale` or `never`. When it is `never` there is no
`system.cron_last` timestamp yet and `age_seconds` is `null`.

Drupal writes `system.cron_last` only after all cron handlers and all queues
have finished, so a cron run that dies half way through (fatal error, PHP
timeout, killed worker) does not advance it. The endpoint therefore detects a
silently failing cron, not just a cron that was never triggered.
