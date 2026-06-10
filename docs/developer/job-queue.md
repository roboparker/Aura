# Background job queue

Aura runs background jobs through **Symfony Messenger** with the **Doctrine
transport**, so the queue is just a table in the existing PostgreSQL
database — no extra broker (Redis/RabbitMQ) to operate. Jobs are enqueued
by the API during a request and drained by a long-running worker process.

The first use of the queue is **all outbound email**. Symfony Mailer turns
every `$mailer->send()` into a `SendEmailMessage`; routing that message to
the async transport (in `messenger.yaml`) moves notification, invite,
password-reset, 2FA, email-change, waitlist, and digest/reminder emails onto
the queue — each with retries and a dead-letter transport. Previously every
email was sent inline, putting SMTP latency on the request's critical path.

## Moving parts

| Piece | Location |
| --- | --- |
| Transport config + routing | [api/config/packages/messenger.yaml](../../api/config/packages/messenger.yaml) |
| Queue table | `messenger_messages` (created by `Version20260606130000`) |
| Async email message | `Symfony\Component\Mailer\Messenger\SendEmailMessage` (framework-provided) |
| Email producers | every `*Mailer` service / controller that calls `MailerInterface::send()` (e.g. [NotificationDispatcher.php](../../api/src/Service/NotificationDispatcher.php), `InviteMailer`, `PasswordController`) |
| Recurring-job schedule | [api/src/Scheduler/MainScheduleProvider.php](../../api/src/Scheduler/MainScheduleProvider.php) (symfony/scheduler, consumed as the `scheduler_default` transport) |
| Worker (dev) | `worker` service in [compose.yaml](../../compose.yaml) |
| Worker (k8s) | [helm/api-platform/templates/worker-deployment.yaml](../../helm/api-platform/templates/worker-deployment.yaml) |

## How it works

- **Transport** — `async` is a Doctrine transport on `MESSENGER_TRANSPORT_DSN`
  (`doctrine://default?auto_setup=false`). The worker pulls jobs with
  `SELECT ... FOR UPDATE SKIP LOCKED`, so it's safe to run **multiple
  workers** concurrently. An `AFTER INSERT/UPDATE` trigger fires
  `pg_notify`, so the worker wakes immediately instead of polling.
- **`auto_setup` is off** — the `messenger_messages` table (and its
  LISTEN/NOTIFY trigger) is provisioned by a migration so it lives in the
  same schema-as-code flow as the rest of the DB and passes the Doctrine
  Schema CI check.
- **Retries + dead-letter** — a job that throws is retried (3 times, 1s
  delay, ×2 backoff). After the last failure it's parked on the `failed`
  transport (also Doctrine, `queue_name=failed`) for inspection instead of
  being dropped.
- **Tests run synchronously** — under `when@test` the `async` transport is
  `sync://`, so dispatched jobs are handled in-process during the same
  request. Existing email assertions (`NotificationTest`,
  `NotificationTriggersTest`, …) keep working without a worker. The
  scheduler transport needs no test override: `scheduler_default` isn't a
  configured transport at all — it's materialised on demand by the
  scheduler component when a worker consumes it, which never happens in
  the test environment.

## Running the worker

**Dev (Docker Compose):** the `worker` service starts automatically with
`docker compose up -d`. To run it on demand or tail it:

```bash
docker compose up -d worker
docker compose logs -f worker
```

Or run a consumer by hand inside the php container:

```bash
docker compose exec php bin/console messenger:consume async scheduler_default -vv
```

**Production (Helm):** a dedicated `*-worker` Deployment runs
`messenger:consume async scheduler_default`. Toggle/scale it via
`values.yaml`:

```yaml
worker:
  enabled: true
  replicaCount: 1
  timeLimit: "3600"     # recycle the process hourly to bound memory
  memoryLimit: "128M"
```

The worker handles `SIGTERM` gracefully (finishes the in-flight message,
then exits), so rolling restarts don't drop jobs.

## Recurring jobs (symfony/scheduler)

Cron-style jobs ride the same worker instead of a system cron:
[MainScheduleProvider](../../api/src/Scheduler/MainScheduleProvider.php)
declares the schedule with `#[AsSchedule]`, which exposes it as a virtual
Messenger transport named `scheduler_default`. When the worker consumes
that transport, the scheduler computes which triggers are due and yields
their messages into the normal pipeline — handlers, retries, and the
failed transport all behave exactly like any other job.

Current schedule (all times UTC):

| Message | Trigger | Handler delegates to |
| --- | --- | --- |
| `App\Message\DispatchTaskReminders` | `*/5 * * * *` | `App\Service\TaskReminderDispatcher` |
| `App\Message\DispatchNotificationDigest('hourly')` | `55 * * * *` | `App\Service\NotificationDigestDispatcher` |
| `App\Message\DispatchNotificationDigest('daily')` | `0 8 * * *` | `App\Service\NotificationDigestDispatcher` |

The same services back the `app:tasks:reminders:dispatch` and
`app:notifications:dispatch-digest --period=hourly|daily` console
commands, so a one-off manual run goes through identical code. Both
passes are idempotent — reminders dedupe on a (recipient, task, offset)
unique index, digests stamp `digestedAt` — so an extra run is harmless.

Semantics declared on the schedule:

- **`stateful(cache.app)`** — last-run timestamps survive worker restarts
  (the worker recycles hourly via `--time-limit`), so a tick that lands
  during the restart window is caught up instead of skipped.
- **`processOnlyLastMissedRun(true)`** — after longer downtime, only the
  most recent missed tick per message is replayed, not the whole backlog.
- **`lock()`** — two consumers of `scheduler_default` won't process the
  same tick twice. With the default `LOCK_DSN=flock` and filesystem cache
  this only holds per host: scale the worker by keeping one replica on
  `scheduler_default` (others on `async` only) or move `LOCK_DSN` and
  `cache.app` to shared stores.

Inspect what's due when with:

```bash
docker compose exec php bin/console debug:scheduler
```

**Adding a recurring job:** write a message + handler exactly as in
[Adding a new job](#adding-a-new-job) (skip the routing — the scheduler
transport delivers it directly), then attach a `RecurringMessage` to the
schedule in `MainScheduleProvider::getSchedule()`. Keep the handler
idempotent: under at-least-once delivery plus catch-up runs, "ran twice"
must be safe.

**Tests:** the schedule never fires in the test environment (nothing
consumes `scheduler_default` there), and the underlying services are unit-
testable directly — no override in `when@test` is needed.

## Inspecting failures

```bash
# List jobs parked on the failed transport
docker compose exec php bin/console messenger:failed:show

# Show one failure in detail
docker compose exec php bin/console messenger:failed:show <id> -vv

# Retry (interactively) or remove
docker compose exec php bin/console messenger:failed:retry -vv
docker compose exec php bin/console messenger:failed:remove <id>
```

## Adding a new job

Each job is a message class + a handler. Two files and one routing line:

1. **Message** — a plain, immutable DTO under `api/src/Message/`. Carry
   identifiers (e.g. an entity UUID as a string), not whole entities, so the
   serialized payload stays small and the handler re-loads fresh state.

   ```php
   namespace App\Message;

   final class GenerateExport
   {
       public function __construct(private string $exportId) {}
       public function getExportId(): string { return $this->exportId; }
   }
   ```

2. **Handler** — a class under `api/src/MessageHandler/` tagged
   `#[AsMessageHandler]` with an `__invoke(TheMessage $m)` method. Let
   transient failures throw so Messenger retries; return early (no-op) when
   the referenced entity no longer exists.

   ```php
   namespace App\MessageHandler;

   use App\Message\GenerateExport;
   use Symfony\Component\Messenger\Attribute\AsMessageHandler;

   #[AsMessageHandler]
   final class GenerateExportHandler
   {
       public function __invoke(GenerateExport $message): void
       {
           // ... do the work ...
       }
   }
   ```

3. **Route** it to the async transport in `messenger.yaml`:

   ```yaml
   framework:
       messenger:
           routing:
               App\Message\GenerateExport: async
   ```

4. **Enqueue** it from anywhere by injecting
   `Symfony\Component\Messenger\MessageBusInterface`:

   ```php
   $this->bus->dispatch(new GenerateExport((string) $export->getId()));
   ```

Do any "should this run at all?" gating **before** dispatching, so the queue
only holds jobs that are meant to execute — the handler then stays a simple
"do the work" unit. (That's why `NotificationDispatcher` checks the email
preference matrix and quiet hours before calling `$mailer->send()`, which —
with `SendEmailMessage` routed to async — is itself the enqueue.)
