# A better Moloni - WHMCS plugin

We have been long time users of the WHMCS plugin provided by Moloni.
Unfortunately, we continuously come across issues with that plugin that, despite the tickets and reports, never get solved.

For this reason we have forked their plugin (and will continue to merge changes they make to it) and started correcting these issues.


# Moloni WHMCS authentication and navigation improvements (8.1.2)

Release history: [CHANGES.md](./CHANGES.md).

## Deploying

1. Back up the plugin and the WHMCS database. Test on a controlled WHMCS instance
   before production; invoice generation makes real API calls.
2. Pause WHMCS automation while deploying the complete plugin directory.
3. Open the Moloni addon as a WHMCS administrator. WHMCS should run the module's
   upgrade callback for version 8.1.2. Confirm that the upgrade succeeds before
   resuming automation.
4. Verify that `moloni` has the new `access_expires_at`, `refresh_expires_at`,
   `auth_error`, and `retry_after` columns, and that `moloni_pending_invoices`
   exists. Version 8.1.2 also creates `moloni_navigation_cache`. The upgrade
   preserves existing credentials, company selection, configuration, and invoice
   mappings and can run repeatedly.
5. Resume the normal WHMCS system cron, preferably every five minutes. Its
   `AfterCronJob` hook maintains authentication even when no invoices are paid.

Do **not** deactivate/reactivate the addon to upgrade: the existing deactivation
code deletes its authentication and configuration tables.

The database must support multiple simultaneous, connection-scoped named locks:
MySQL 5.7.5+ or MariaDB 10.0.2+. All WHMCS workers must use the same primary
database for these locks. No additional PHP packages are required.

## Authentication

- Both tokens remain in the WHMCS database, in plaintext, as before. Passwords
  are not retained. Restrict access to the database and its backups.
- Renewal, login, and logout share a database lock. Renewal re-reads the
  connection after acquiring the lock and saves the token pair in one update,
  using the actual row ID and verifying persistence.
- Access expiry uses the API's `expires_in` minus a 60-second margin. Refresh
  expiry is tracked separately using Moloni's documented 14-day lifetime.
  Existing connections use their old expiry metadata until their next renewal.
- Temporary failures preserve the entire connection and impose a 60-second
  retry cooldown. The admin interface offers a retry rather than deleting the
  connection or unnecessarily requesting credentials.
- Confirmed refresh-token expiry/revocation requires login again, but preserves
  the company. Rejected application credentials require correction and login.
  Only explicit logout deletes the saved connection.
- Outside bounded navigation, API requests have a 10-second connection timeout
  and a 45-second total timeout.
  Documented access-token rejection can trigger one coordinated renewal and one
  replay. HTTP 400 alone is not sufficient to trigger renewal.
- Transport failures, malformed responses, and server errors are surfaced
  explicitly. They never cause an automatic replay of an invoice insertion.
  New authentication logs omit credentials, tokens, request URLs, and raw grant
  responses.

The WHMCS cron must continue running to keep an otherwise idle connection alive.
An outage lasting beyond refresh-token expiry, provider revocation, or a token
rotation whose response is lost can still require manual reconnection.

## Invoice recovery and diagnostics

Automatic generation still respects the existing **Gerar automaticamente**
setting. Newly paid invoices blocked by authentication **before generation
starts** are recorded as `pending` in `moloni_pending_invoices`. After
authentication recovers, each cron run processes up to ten queued invoices.
The original company must still match, and the invoice must still be paid and
not already mapped locally. API operations also stop if the selected company
changes mid-operation.

Automatic processing and document generation use per-invoice database locks,
including manual generation. Document generation rechecks the local mapping
under its lock. Successful mapping removes the recovery entry.

Before attempting generation, recovery changes the entry to `manual`. If
generation fails or the process terminates, cron will **not** repeat it: a
timeout may mean the document was already created remotely. The module displays
a manual-review warning, and invoice-specific errors appear in the module logs
and WHMCS Activity Log. Check Moloni first, reconcile any existing document,
and only then use the existing manual invoice-generation controls as appropriate.
Company mismatches also require manual review.

This is not a general retry queue. It does not import historical missed invoices
or automatically retry ambiguous failures, validation errors, or existing manual
review entries. Existing remote-reference checks are preserved; they are not an
API idempotency guarantee, especially for invoices without an invoice number.

Useful module log tags:

- `service:token:refresh`, `service:token:login`, `service:token:maintenance`
- `service:document:authentication-recovery`
- `service:api:error`

## Navigation performance (8.1.2)

- The Documents page renders its shell without fetching document details.
  DataTables retrieves just the requested page through the existing authenticated
  addon AJAX route. The default is ten rows, with a maximum of 25; there is no
  unbounded "all rows" option. Filtering and ordering run before the database
  page slice.
- Document details are fetched once per visible document and cached for five
  minutes, scoped to the selected company. Company link metadata and the five
  configuration option lists are cached for 15 minutes. Cache writes use an
  atomic database upsert to handle concurrent page loads.
- PDF URLs are never prefetched or cached. The download button looks up a fresh
  URL only when clicked, after authenticating and checking that the document is
  linked to this WHMCS installation.
- Navigation has a shared **15-second external-API budget**, including any
  token renewal/replay. Each call receives only the remaining time. This does
  not bound database queries, PHP worker/session queueing, or the entire hosting
  stack. Invoice creation and cron retain their existing 45-second per-call
  timeout.
- If document data cannot be retrieved within the budget, the rows remain
  visible with explicitly identified local/cached data and a warning. A normal
  reload reuses already-fetched entries and can fill the remaining rows.
- **Atualizar página** bypasses the document cache for the visible page.
  **Atualizar listas** bypasses configuration-list caches. Temporary refresh
  failures can use previously cached data, with a visible warning; authentication
  or company errors are not hidden by cached data. A cold configuration failure
  produces an explicit error instead of an empty, potentially destructive form.
- Search includes linked WHMCS invoice numbers/customer fields and remote
  metadata already cached from previous visits. Remote-only names/series are
  not globally searchable until those documents have been consulted; this avoids
  fetching the entire remote history for each search. Remote status/date/total
  values may be up to five minutes old, or older when a stale-data warning is
  displayed. These caches are for navigation only, not invoice generation.

On a cold ten-document page, normal data loading takes at most eleven API calls
(ten details plus company metadata), rather than 31 for ten closed documents or
301 for an entire hundred-document history. A warm page makes no data API calls;
token renewal may still be necessary. Warm configuration likewise makes no data
API calls rather than five.

Requests taking at least two seconds are recorded under `service:api:slow`, with
endpoint, elapsed milliseconds, HTTP status, and cURL error code, but no tokens,
URLs, or request payloads. Cache/partial-document warnings use
`service:navigation:cache` and `service:navigation:documents`.

## Validation

Run the dependency-free regression suite with a PHP CLI:

```sh
php tests/authentication.php
```

The suite includes authentication and navigation tests, with measured request
counts, cache scope/staleness, page limits, lazy PDF lookup, and a simulated
15-second cumulative budget. Tests use in-memory WHMCS/database doubles and
mocked HTTP responses. They do not contact Moloni or a real database. Browser
checks likewise use mocked data. Validate actual MySQL/MariaDB locking and
document search/order queries, WHMCS upgrade/cron integration, invoice creation,
and email behavior in staging.

Suggested staging checks:

1. Upgrade with an existing connection and verify its company/configuration.
2. Expire the access deadline and trigger concurrent admin/payment requests;
   verify that only one token renewal occurs.
3. Simulate a temporary API failure: credentials must remain saved, and an
   affected paid invoice must be queued with its WHMCS invoice ID.
4. Restore connectivity and run cron: the queued invoice must be generated once.
5. Simulate an ambiguous invoice insertion timeout: confirm manual-review status
   and that repeated cron runs do not submit another insertion.
6. Confirm idle renewal, special-character login credentials, manual generation,
   closed/draft document settings, and explicit logout.
7. Upgrade from 8.1.1 without deactivation. Check Documents paging, sorting,
   filtering, refresh, draft/closed/discarded rows, and lazy PDF downloads.
8. Reopen Configuration and Documents to verify warm-cache response times.
   Simulate API slowness and confirm visible stale/local-data warnings rather
   than a stalled whole page. Inspect `service:api:slow` records for actual
   production endpoint timings.

References:
[Moloni authentication](https://www.moloni.pt/dev/autenticacao/),
[Moloni errors](https://www.moloni.pt/dev/controlo-de-erros/),
[WHMCS cron hooks](https://developers.whmcs.com/hooks-reference/cron/).
