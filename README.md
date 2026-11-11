# Moloni WHMCS authentication improvements (8.1.1)

## Deploying

1. Back up the plugin and the WHMCS database. Test on a controlled WHMCS instance
   before production; invoice generation makes real API calls.
2. Pause WHMCS automation while deploying the complete plugin directory.
3. Open the Moloni addon as a WHMCS administrator. WHMCS should run the module's
   upgrade callback for version 8.1.1. Confirm that the upgrade succeeds before
   resuming automation.
4. Verify that `moloni` has the new `access_expires_at`, `refresh_expires_at`,
   `auth_error`, and `retry_after` columns, and that `moloni_pending_invoices`
   exists. The upgrade preserves existing credentials, company selection,
   configuration, and invoice mappings and can run repeatedly.
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
- API requests have a 10-second connection timeout and a 45-second total timeout.
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

## Validation

Run the dependency-free regression suite with a PHP CLI:

```sh
php tests/authentication.php
```

Tests use in-memory WHMCS/database doubles and mocked HTTP responses. They do
not contact Moloni or a real database. Validate actual MySQL/MariaDB locking,
WHMCS upgrade/cron integration, invoice creation, and email behavior in staging.

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

References:
[Moloni authentication](https://www.moloni.pt/dev/autenticacao/),
[Moloni errors](https://www.moloni.pt/dev/controlo-de-erros/),
[WHMCS cron hooks](https://developers.whmcs.com/hooks-reference/cron/).
