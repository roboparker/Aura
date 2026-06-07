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
  `NotificationTriggersTest`, …) keep working without a worker.

## Running the worker

**Dev (Docker Compose):** the `worker` service starts automatically with
`docker compose up -d`. To run it on demand or tail it:

```bash
docker compose up -d worker
docker compose logs -f worker
```

Or run a consumer by hand inside the php container:

```bash
docker compose exec php bin/console messenger:consume async -vv
```

**Production (Helm):** a dedicated `*-worker` Deployment runs
`messenger:consume`. Toggle/scale it via `values.yaml`:

```yaml
worker:
  enabled: true
  replicaCount: 1
  timeLimit: "3600"     # recycle the process hourly to bound memory
  memoryLimit: "128M"
```

The worker handles `SIGTERM` gracefully (finishes the in-flight message,
then exits), so rolling restarts don't drop jobs.

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
