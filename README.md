# Logs monitoring

Provides endpoints that indicate about errors in the log files, about cron that
stopped running and about Drush that stopped working from the CLI.

## Configuration
In the form /admin/config/development/logs-monitoring-settings should be set:
1) paths to logs
2) a count of rows in the end where a search will be performed
3) a list of the words that indicate errors
4) the cron max age, in seconds
5) the Drush heartbeat max age, in seconds.

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

#### Drush

The REST endpoint /admin/reports/drush-monitoring reports how long ago Drush
last completed a health check from the CLI. Point a third Uptimerobot monitor
at it.

Returns HTTP status codes:
- 200 - the heartbeat was recorded within the configured max age
- 503 - the heartbeat is older than the configured max age, or was never
  recorded

Example response:

```json
{
  "check": "drush",
  "status": "ok",
  "last_run": 1753600000,
  "age_seconds": 312,
  "threshold": 3600
}
```

`status` is one of `ok`, `stale` or `never`. When it is `never` no heartbeat has
been recorded yet and `age_seconds` is `null`. Users with the *administer site
configuration* permission also get a `meta` object holding the CLI PHP version
and SAPI, the Drush version, the hostname, the system user and how long the
check took - the values needed to work out why a heartbeat went stale. It is
withheld from everyone else so an anonymous uptime monitor is not handed the
host's internals.

##### How it works

A web request cannot test Drush. Shelling out from the web server would run
under the wrong PHP SAPI, the wrong user and the wrong environment, which is
exactly where CLI-only breakage hides. So the check is inverted: the CLI does
the work and the endpoint only reads the result.

`drush logs-monitoring:heartbeat`, scheduled in the system crontab, bootstraps
Drupal, runs a real database query, runs an entity query and only then records a
timestamp in Drupal State. The write is last on purpose - any failure above it
leaves the previous timestamp in place, the endpoint goes stale and your monitor
alerts. Nothing has to push a failure anywhere, which is what makes this a dead
man's switch rather than a report that can itself go missing.

Anything that stops scheduled Drush from completing is caught: a broken
`vendor/` directory, a CLI PHP upgrade that no longer matches the codebase, a
`settings.php` the cron user cannot read, database credentials that only work
over the web server's socket, a PHP memory limit that differs between SAPIs, or
a crontab entry that was removed during a deploy.

Note what that last one implies: a stale heartbeat means *scheduled Drush
execution* is broken, which is not the same claim as "the Drush binary is
broken". Whoever gets paged should check the crontab first. A dead crontab will
also make the cron endpoint go stale, so expect both monitors to fire together.

##### Setup

Requires Drush 12 or newer, which is what discovers commands from
`src/Drush/Commands`.

1. Verify the command runs at all:

   ```bash
   cd /var/www/example.com
   vendor/bin/drush logs-monitoring:heartbeat
   ```

   On a multisite, add `--uri=example.com`.

2. Copy and edit the wrapper's config file:

   ```bash
   cp scripts/drush-healthcheck.conf.example /etc/drush-healthcheck.conf
   $EDITOR /etc/drush-healthcheck.conf
   ```

   Set `DRUPAL_ROOT`, `DRUSH_BIN` and `DRUSH_HC_SITES` explicitly. The script
   can auto-detect them, but for anything scheduled you want the paths pinned:
   a wrong guess would report a different site as healthy.

3. Schedule it as the user that owns the codebase - not root, or it will leave
   root-owned files in the Drupal file system and caches:

   ```cron
   */15 * * * * /var/www/example.com/web/modules/contrib/logs_monitoring/scripts/drush-healthcheck.sh -q
   ```

   `-q` keeps cron silent on success and still mails you the stderr of a
   failure.

4. Set the *Drush heartbeat max age* on the settings form comfortably above the
   crontab interval, so one missed run does not raise an alert. With the
   15-minute schedule above, 3600 seconds tolerates three consecutive failures
   before alerting.

5. Add the Uptimerobot monitor for /admin/reports/drush-monitoring.

The wrapper is optional - the crontab line can call the Drush command directly.
What the wrapper adds is a lock so overlapping runs cannot pile up, a timeout so
a hung database cannot wedge cron, a loop over the sites of a multisite, a
`drush version` pre-flight that produces a readable error when the binary itself
is broken, and an optional outbound ping.

Run `scripts/drush-healthcheck.sh --help` for all options. Exit codes: 0
healthy, 1 a check failed, 2 bad usage or configuration, 3 another instance is
running.

##### Optional: external dead man's switch

Setting `DRUSH_HC_PING_URL` makes the script curl a URL after a site passes
every check, for use with Healthchecks.io, Cronitor or an Uptimerobot heartbeat
monitor. That takes Drupal out of the alert path entirely, so a broken web stack
cannot mask a Drush failure. It needs outbound network access from the server
and keeps the monitoring configuration outside the repository, so treat it as a
complement to the endpoint rather than a replacement.

##### Inspecting and testing

```bash
# What was last recorded.
drush state:get logs_monitoring.drush_last_ok --format=yaml

# Simulate a dead Drush: delete the heartbeat, endpoint answers 503 "never".
drush state:delete logs_monitoring.drush_last_ok

# Simulate a stale heartbeat: backdate it beyond the threshold.
drush state:set logs_monitoring.drush_last_ok 1 --input-format=integer

# Run the checks without touching state, e.g. against a read-only replica.
drush logs-monitoring:heartbeat --no-state
```

A bare integer is accepted as a heartbeat, which is why the `state:set` line
above works for testing.
