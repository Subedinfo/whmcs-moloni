# Changelog

## 8.1.2 - Navigation timeout fixes

### Documents

- Replace loading the entire document history with server-side pagination,
  filtering, and ordering. Load ten rows by default, with a maximum of 25.
- Fetch each visible document once instead of performing duplicate detail
  lookups. Cache document metadata for five minutes and company link metadata
  for 15 minutes.
- Fetch PDF links only when the download button is clicked, after checking the
  local document mapping. PDF URLs are not cached.
- Keep rows visible with explicit local/cached-data warnings when remote
  requests fail. Provide a refresh button for the visible page.
- Search local WHMCS fields and already-cached Moloni metadata without fetching
  the entire remote history. Remote-only metadata is searchable after it has
  been consulted and cached.

### Configuration and API handling

- Cache the five configuration option lists for 15 minutes, with an explicit
  refresh control.
- Add the company-scoped `moloni_navigation_cache` table through a repeatable
  upgrade. Use atomic cache writes for concurrent page loads.
- Validate cached/API data. Allow visibly identified stale data on temporary
  failures, without hiding authentication or company errors.
- Apply a shared 15-second external-API budget to Documents, Configuration, and
  PDF navigation, including token renewal/replay. Invoice generation retains
  its existing 45-second per-request timeout.
- Log API requests taking at least two seconds under `service:api:slow`, without
  recording credentials, request URLs, or payloads.

### Validation and deployment

- Expand the regression suite to 48 authentication/navigation tests, including
  pagination bounds, request counts, cache behavior, lazy downloads, and the
  cumulative API deadline. Check browser interactions using mocked data.
- Document the migration, cache/search limitations, diagnostics, and staging
  checks in [README.md](./README.md). Real WHMCS/MySQL integration still requires
  staging validation; the API budget does not bound database or hosting delays.

## 8.1.1 - Authentication stability fixes

### Token lifecycle

- Coordinate renewal, login, and logout through database-backed locks, and
  re-read credentials after acquiring the lock to avoid concurrent rotation.
- Persist token pairs together using the actual connection row ID and verify
  that persistence succeeded.
- Track access-token and refresh-token expiry separately. Use the API's
  `expires_in` with a safety margin and the documented 14-day refresh lifetime,
  preserving usable legacy connections.
- Preserve credentials and company selection during temporary failures, with
  a 60-second retry cooldown and explicit retry/reconnection states.
- Require reconnection for confirmed expiry/revocation rather than ambiguous
  grant failures. Only explicit logout clears the saved connection.
- Correctly encode login credentials containing special characters.
- Maintain authentication through the WHMCS `AfterCronJob` hook, including
  periods without invoice activity.

### Safe invoice recovery and diagnostics

- Surface transport, malformed-response, HTTP, and authentication errors rather
  than silently skipping affected invoices. Keep credentials and raw grant
  responses out of new diagnostic logs.
- Retry an API operation only after explicit access-token rejection, with one
  coordinated renewal and one replay. Never automatically replay an ambiguous
  invoice insertion failure.
- Add per-invoice locks and recheck local mappings before generation.
- Queue newly paid invoices blocked by authentication before generation starts
  in `moloni_pending_invoices`. Recover up to ten per cron run, respecting
  automatic-generation settings, payment status, existing mappings, and company.
- Mark recovery attempts for manual review before generation begins, preventing
  automatic resubmission after an ambiguous failure. Do not retry historical
  missed invoices or unrelated failures automatically.
- Add repeatable authentication-schema upgrades, deployment guidance, and 29
  mocked regression tests.
- Normalize embedded SCSS source-map line endings to LF without changing the
  mappings or generated styles.

## Upgrade notes

- Back up the plugin and database, then deploy the complete plugin and let WHMCS
  run its upgrade callback. Do not deactivate/reactivate: deactivation deletes
  existing authentication and configuration tables.
- Authentication locking requires MySQL 5.7.5+ or MariaDB 10.0.2+, with all
  workers using the same primary database.
- Keep WHMCS cron running. Provider revocation, prolonged outages beyond refresh
  expiry, or a lost token-rotation response can still require manual login.
