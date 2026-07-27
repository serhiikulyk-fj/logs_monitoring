#!/usr/bin/env bash
#
# drush-healthcheck.sh - proves scheduled Drush execution works on this site.
#
# Runs "drush logs-monitoring:heartbeat" for every site. That command performs
# the actual checks (bootstrap, database query, entity query) and records a
# timestamp in Drupal State; this script only handles the things bash is good
# at: locking, timeouts, iterating over a multisite and reporting exit codes.
#
# The heartbeat write is the proof. If Drush is broken the timestamp never
# updates, /admin/reports/drush-monitoring starts answering 503 and your uptime
# monitor alerts. Nothing needs to push a failure anywhere.
#
# Exit codes:
#   0  all checks passed
#   1  a check failed (see stderr)
#   2  bad usage / configuration
#   3  another instance is already running
#
# Usage:
#   drush-healthcheck.sh [options]
#
# Options:
#   -r, --root PATH        Drupal project root (composer root). Default: auto-detect.
#   -d, --drush PATH       Drush binary. Default: auto-detect.
#   -s, --sites LIST       Comma/space separated site URIs. Default: "-".
#                          "-" means a single-site run with no --uri.
#   -p, --ping-url URL     Dead-man's-switch URL, curled only on success.
#                          "{site}" in the URL is replaced by the site URI.
#   -t, --timeout SECONDS  Per-command timeout. Default: 120
#   -c, --config FILE      Config file to source. Default: auto-detect.
#       --no-state         Run the checks without recording the heartbeat.
#   -q, --quiet            Only output on failure.
#   -h, --help             Show this help.
#
# Environment variables:
#   DRUPAL_ROOT, DRUSH_BIN, DRUSH_HC_SITES, DRUSH_HC_PING_URL, DRUSH_HC_TIMEOUT
#
# Config file (first found wins, unless -c is given):
#   ./drush-healthcheck.conf
#   <script dir>/drush-healthcheck.conf
#   ~/.drush-healthcheck.conf
#   /etc/drush-healthcheck.conf
#
# Setting DRUPAL_ROOT, DRUSH_BIN and DRUSH_HC_SITES explicitly is strongly
# recommended for anything scheduled. Auto-detection exists so the script can
# be run by hand in an unfamiliar checkout; it can only guess.

set -uo pipefail

readonly SCRIPT_NAME="${0##*/}"
SCRIPT_DIR="$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)"
readonly SCRIPT_DIR

readonly HEARTBEAT_COMMAND="logs-monitoring:heartbeat"

# ---------------------------------------------------------------------------
# Defaults
# ---------------------------------------------------------------------------

DRUPAL_ROOT="${DRUPAL_ROOT:-}"
DRUSH_BIN="${DRUSH_BIN:-}"
SITES_RAW="${DRUSH_HC_SITES:-}"
PING_URL="${DRUSH_HC_PING_URL:-}"
TIMEOUT="${DRUSH_HC_TIMEOUT:-120}"
CONFIG_FILE=""
DO_STATE=1
QUIET=0

# Docroot directory names, in probe order.
readonly DOCROOT_CANDIDATES=(web docroot html public_html www .)

# ---------------------------------------------------------------------------
# Output helpers
# ---------------------------------------------------------------------------

log()  { [[ "${QUIET}" -eq 1 ]] || printf '%s %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*"; }
warn() { printf '%s [WARN] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
err()  { printf '%s [FAIL] %s\n' "$(date '+%Y-%m-%d %H:%M:%S')" "$*" >&2; }
die()  { err "$1"; exit "${2:-1}"; }

usage() {
  sed -n '3,48p' "${BASH_SOURCE[0]}" | sed 's/^# \{0,1\}//'
  exit "${1:-0}"
}

# ---------------------------------------------------------------------------
# Argument parsing
# ---------------------------------------------------------------------------

while [[ $# -gt 0 ]]; do
  case "$1" in
    -r|--root)       DRUPAL_ROOT="${2:-}"; shift 2 ;;
    -d|--drush)      DRUSH_BIN="${2:-}";   shift 2 ;;
    -s|--sites)      SITES_RAW="${2:-}";   shift 2 ;;
    -p|--ping-url)   PING_URL="${2:-}";    shift 2 ;;
    -t|--timeout)    TIMEOUT="${2:-}";     shift 2 ;;
    -c|--config)     CONFIG_FILE="${2:-}"; shift 2 ;;
    --no-state)      DO_STATE=0; shift ;;
    -q|--quiet)      QUIET=1;    shift ;;
    -h|--help)       usage 0 ;;
    *) err "Unknown option: $1"; usage 2 ;;
  esac
done

# ---------------------------------------------------------------------------
# Config file
# ---------------------------------------------------------------------------

if [[ -z "${CONFIG_FILE}" ]]; then
  for candidate in \
    "${PWD}/drush-healthcheck.conf" \
    "${SCRIPT_DIR}/drush-healthcheck.conf" \
    "${HOME:-/nonexistent}/.drush-healthcheck.conf" \
    "/etc/drush-healthcheck.conf"
  do
    [[ -r "${candidate}" ]] && { CONFIG_FILE="${candidate}"; break; }
  done
fi

if [[ -n "${CONFIG_FILE}" ]]; then
  [[ -r "${CONFIG_FILE}" ]] || die "Config file not readable: ${CONFIG_FILE}" 2
  # shellcheck disable=SC1090
  source "${CONFIG_FILE}"
  log "Config: ${CONFIG_FILE}"
fi

[[ "${TIMEOUT}" =~ ^[0-9]+$ ]] || die "Timeout must be an integer: ${TIMEOUT}" 2

# ---------------------------------------------------------------------------
# Resolve the Drupal root and docroot
# ---------------------------------------------------------------------------

# Prints the docroot inside $1, or fails if there is none.
resolve_docroot() {
  local dir="$1" sub
  for sub in "${DOCROOT_CANDIDATES[@]}"; do
    if [[ -f "${dir}/${sub}/core/lib/Drupal.php" ]]; then
      if [[ "${sub}" == "." ]]; then printf '%s' "${dir}"; else printf '%s/%s' "${dir}" "${sub}"; fi
      return 0
    fi
  done
  return 1
}

# Walks up from the CWD and the script location looking for a project root.
# A composer root (composer.json or vendor/ next to the docroot) wins over a
# bare docroot, so running from inside web/ still resolves to the project root.
detect_root() {
  local dir start fallback=""
  for start in "${PWD}" "${SCRIPT_DIR}"; do
    dir="${start}"
    while [[ "${dir}" != "/" && -n "${dir}" ]]; do
      if resolve_docroot "${dir}" >/dev/null; then
        if [[ -f "${dir}/composer.json" || -d "${dir}/vendor" ]]; then
          printf '%s' "${dir}"; return 0
        fi
        [[ -z "${fallback}" ]] && fallback="${dir}"
      fi
      dir="$(dirname -- "${dir}")"
    done
  done
  [[ -n "${fallback}" ]] && { printf '%s' "${fallback}"; return 0; }
  return 1
}

if [[ -z "${DRUPAL_ROOT}" ]]; then
  DRUPAL_ROOT="$(detect_root)" || die "Could not auto-detect the Drupal root. Pass --root." 2
fi

[[ -d "${DRUPAL_ROOT}" ]] || die "Drupal root is not a directory: ${DRUPAL_ROOT}" 2
DRUPAL_ROOT="$(cd -- "${DRUPAL_ROOT}" && pwd -P)"

DOCROOT="$(resolve_docroot "${DRUPAL_ROOT}")" \
  || die "No Drupal docroot found under: ${DRUPAL_ROOT}" 2

# ---------------------------------------------------------------------------
# Resolve the Drush binary
# ---------------------------------------------------------------------------

detect_drush() {
  local candidates=(
    "${DRUPAL_ROOT}/vendor/bin/drush"
    "${DRUPAL_ROOT}/bin/drush"
    "${DOCROOT}/../vendor/bin/drush"
  )
  local c dir
  for c in "${candidates[@]}"; do
    if [[ -x "${c}" ]]; then
      dir="$(cd -- "$(dirname -- "${c}")" && pwd -P)"
      printf '%s/%s' "${dir}" "$(basename -- "${c}")"
      return 0
    fi
  done
  # Fall back to whatever is on PATH. Note that cron's PATH is minimal, which
  # is one of the failures this check is meant to catch in the first place.
  if command -v drush >/dev/null 2>&1; then
    command -v drush; return 0
  fi
  return 1
}

if [[ -z "${DRUSH_BIN}" ]]; then
  DRUSH_BIN="$(detect_drush)" || die "Could not find a Drush binary. Pass --drush." 2
fi

[[ -x "${DRUSH_BIN}" ]] || die "Drush binary is not executable: ${DRUSH_BIN}" 2

# ---------------------------------------------------------------------------
# Resolve the site list
# ---------------------------------------------------------------------------

# Site URIs are deliberately not auto-detected. Directory names under sites/
# are not reliable URIs once sites.php aliases are involved, and guessing wrong
# would silently check the wrong site. Single-site installs need no --uri, so
# "-" is the right default.
declare -a SITES=()
if [[ -n "${SITES_RAW}" ]]; then
  # Accept comma and/or whitespace separated values.
  IFS=', ' read -r -a SITES <<< "${SITES_RAW}"
else
  SITES=("-")
fi

[[ ${#SITES[@]} -gt 0 ]] || die "No sites resolved. Pass --sites." 2

# ---------------------------------------------------------------------------
# Locking (avoid overlapping cron runs)
# ---------------------------------------------------------------------------

LOCK_ID="$(printf '%s' "${DRUPAL_ROOT}" | cksum | cut -d' ' -f1)"
LOCK_PATH="${TMPDIR:-/tmp}/${SCRIPT_NAME}.${LOCK_ID}.lock"

if command -v flock >/dev/null 2>&1; then
  exec 9>"${LOCK_PATH}" || die "Cannot open lock file: ${LOCK_PATH}" 2
  flock -n 9 || die "Another instance is already running (${LOCK_PATH})" 3
else
  # No flock (macOS, minimal images): fall back to a directory lock, clearing
  # one that is older than an hour so a killed run cannot block forever.
  if ! mkdir "${LOCK_PATH}" 2>/dev/null; then
    if find "${LOCK_PATH}" -maxdepth 0 -mmin +60 2>/dev/null | grep -q .; then
      warn "Clearing stale lock: ${LOCK_PATH}"
      rmdir "${LOCK_PATH}" 2>/dev/null || true
      mkdir "${LOCK_PATH}" 2>/dev/null || die "Could not acquire lock: ${LOCK_PATH}" 3
    else
      die "Another instance is already running (${LOCK_PATH})" 3
    fi
  fi
  trap 'rmdir "${LOCK_PATH}" 2>/dev/null || true' EXIT INT TERM
fi

# ---------------------------------------------------------------------------
# Command runner
# ---------------------------------------------------------------------------

# Prefer `timeout` when available so a hung database never wedges cron.
if command -v timeout >/dev/null 2>&1; then
  run() { timeout --signal=TERM --kill-after=10s "${TIMEOUT}s" "$@"; }
else
  run() { "$@"; }
fi

# drush_run <site-uri-or-dash> <drush args...>
drush_run() {
  local site="$1"; shift
  if [[ "${site}" == "-" ]]; then
    run "${DRUSH_BIN}" "$@"
  else
    run "${DRUSH_BIN}" --uri="${site}" "$@"
  fi
}

do_ping() {
  local site="$1" url="${PING_URL}"
  [[ -n "${url}" ]] || return 0
  command -v curl >/dev/null 2>&1 || { warn "curl not found; skipping ping"; return 0; }
  url="${url//\{site\}/${site}}"
  curl -fsS --max-time 15 --retry 3 --retry-delay 2 "${url}" >/dev/null 2>&1 \
    || warn "Heartbeat ping failed for ${site}"
}

# ---------------------------------------------------------------------------
# Checks
# ---------------------------------------------------------------------------

cd -- "${DRUPAL_ROOT}" || die "Cannot cd into ${DRUPAL_ROOT}" 2

log "Root:    ${DRUPAL_ROOT}"
log "Docroot: ${DOCROOT}"
log "Drush:   ${DRUSH_BIN}"
log "Sites:   ${SITES[*]}"

# Does the binary execute at all? No bootstrap, no database. This is what
# catches a broken vendor/, an autoload mismatch or a PHP fatal such as an
# incompatible Drush/Symfony pair - none of which would produce a useful error
# message from a bootstrapping command.
if ! version_out="$(run "${DRUSH_BIN}" version --format=string 2>&1)"; then
  die "Drush binary failed to execute:
${version_out}"
fi
log "Drush version: ${version_out}"

declare -a HEARTBEAT_ARGS=("${HEARTBEAT_COMMAND}")
[[ "${DO_STATE}" -eq 0 ]] && HEARTBEAT_ARGS+=("--no-state")

FAILED=0
declare -a FAILED_SITES=()

for site in "${SITES[@]}"; do
  label="${site}"
  [[ "${site}" == "-" ]] && label="(default)"

  # Everything else - bootstrap, database query, entity query and the
  # heartbeat write - happens inside this single command, so a site costs one
  # Drupal bootstrap rather than one per check.
  if ! out="$(drush_run "${site}" "${HEARTBEAT_ARGS[@]}" 2>&1)"; then
    err "${label}: ${HEARTBEAT_COMMAND} failed:
${out}"
    FAILED=1; FAILED_SITES+=("${label}"); continue
  fi

  log "${label}: OK${out:+ - ${out}}"
  do_ping "${site}"
done

if [[ "${FAILED}" -ne 0 ]]; then
  err "Healthcheck FAILED for: ${FAILED_SITES[*]}"
  exit 1
fi

log "All sites healthy (${#SITES[@]})"
exit 0
