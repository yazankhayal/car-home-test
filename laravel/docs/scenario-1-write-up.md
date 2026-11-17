# Scenario 1: Duplicate webhooks

## Problem

Providers can send the same webhook more than once. We stored each copy and processed it again. One event could run twice.

## Fix

I added a unique index on `(provider, event_id)` in `webhook_events`. The database now refuses a second row for the same event.

The controller uses `createOrFirst`. It tries the insert first. If the index rejects it, it returns the existing row instead.

A duplicate returns 200. It does not queue a second job.

I edited the original migration since this is a test project. In production, I'd add a new migration.

## Job

`ProcessWebhookJob` now claims the event atomically. It moves the status from `pending` to `processing` in one SQL update, and only if the status is still `pending`.

If no row changes, the event is taken or done, and the job exits. Only one worker can process an event.

## Rejected options

- **Check, then insert.** Two requests can both pass the check before either inserts.
- **Wrapping it in a transaction.** On its own, this does not stop two inserts.
- **`lockForUpdate`.** There is no row to lock before the first insert.

The database index is the real guarantee.

## Tests

New tests cover:

- Duplicate webhooks are stored once and queued once.
- A duplicate does not overwrite the original event.
- The same event ID from different providers is stored separately.
- The job skips events that are already `processing` or `completed`.
- The job skips an event another worker claimed after it was loaded.

All tests pass.

## Removed test

I removed the concurrent insert test. Under `RefreshDatabase`, `createOrFirst` runs inside a savepoint. The simulated competing row was rolled back with it. That cannot happen in production.

A plain duplicate follows the same code path as a lost race: insert, constraint error, then lookup. The duplicate tests cover that path.
