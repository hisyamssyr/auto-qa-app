# Auto QA

A Laravel 13, Breeze, and Livewire 4 app that tests websites, proposes repairs, verifies changes, opens draft pull requests, and exposes a token-protected chat API.

The operator supplies a GitHub repository, a reachable website (including approved localhost addresses), a goal, and expected behavior. GitHub writes use a GitHub App installed on that repository. A separate bot account is unnecessary.

## Current implementation

- Breeze authentication with first-operator registration, login, logout, and single-operator enforcement.
- Authenticated project setup/editing, owner-scoped history and cancellation.
- Token-protected chat API, SSE streaming, bounded history, and optional model fallback.
- Stored QA transcripts with messages and structured browser tool logs.
- Private Playwright worker with bounded snapshots, current-page element references, screenshots/traces, and exact local-origin approval.
- Model client using an OpenAI-compatible endpoint (campus gateway or local Ollama); structured decisions are validated before browser actions.
- Findings backed by HTTP server errors or deterministic browser assertions, with private evidence links.
- Source diagnosis tied to the website's exact deployed GitHub commit, bounded full source reads, and a small patch with a new regression test.
- Isolated Docker verification and publication gates. Each required result must pass and match the source commit and patch digest before a repair branch and draft PR are created.
- GitHub App JWT/installation authentication, source retrieval, restricted new repair branches, bot attribution, and draft PR creation.

A model's success claim alone does not complete a run. A model can still choose an incomplete or unsuitable journey; the report identifies the recorded coverage. Model output is a proposed action or patch, not a verification result.

## Start the workspace

Requirements: PHP 8.3 or later with SQLite, cURL, OpenSSL, mbstring and XML extensions; Composer; Node 22 or later; a Chromium-compatible Linux environment. Development here uses PHP 8.5. Docker is required for automatic repair verification.

```bash
composer install
cp .env.example .env
php artisan key:generate
touch database/database.sqlite
php artisan migrate
npm ci
npm run build
```

Set a unique `BROWSER_WORKER_TOKEN` in `.env` (at least 24 characters; `openssl rand -hex 24` generates one). Both Laravel and the worker read this private file. Do not replace an existing `.env` or application key when updating an installation. Breeze scaffolding is committed; `composer install` is enough. The SQLite defaults use WAL and a 5-second busy timeout; adjust `DB_JOURNAL_MODE`/`DB_BUSY_TIMEOUT` if needed.

Install the worker's dependencies and browser:

```bash
cd worker
npm ci
npx playwright install chromium
npm start
```

Keep this process running. In another terminal at the project root:

```bash
php artisan serve --host=127.0.0.1 --port=8000
```

Open [the local workspace](http://127.0.0.1:8000). Create your own first operator account; registration closes afterward. For SSE demos with the built-in server, keep `PHP_CLI_SERVER_WORKERS=4` enabled in `.env` so a stream does not block other requests. A separate terminal runs the only QA queue worker:

```bash
php artisan queue:work --timeout=1800 --tries=0 --sleep=1
```

`DB_QUEUE_RETRY_AFTER=1860` exceeds the job timeout. A shared lock serializes heavy jobs. Restart the worker after changing configuration. For deployment, use a process supervisor, a normal Laravel web server and HTTPS; the development server is for local use.

## Connect the model, repository and website

The default model is the campus LLM gateway (`qwen3:14b`) exposed through an OpenAI-compatible endpoint. Configure it privately in `.env`; `MODEL_API_KEY` is required by this gateway.

```bash
MODEL_URL=https://senopati.its.ac.id/llm-gateway/v1
MODEL_NAME=qwen3:14b
MODEL_API_KEY=<your campus key>
```

Keep the context bounded and measure repair accuracy and runtime on your demonstration repository. The app does not start the model server automatically. A local Ollama server remains usable as an alternative by overriding the same `MODEL_*` values. Set `FALLBACK_*` values and `FALLBACK_ENABLED=true` to retry connection failures, timeouts, and 5xx responses on a second provider; 4xx responses are never retried.

Follow [GitHub setup](docs/GITHUB_SETUP.md): register an App under your existing account, grant Contents and Pull requests read/write, install it only on the selected repository, and privately configure its App ID and RSA key path. The Connections page includes the registration and installation links. Actual access is checked per project.

Enter the repository and website addresses, goal, and expected behavior. Supply the deployed **40-character commit SHA** under Source version and branch, or expose it in the website's `X-AutoQA-Commit` response header. A branch head alone does not prove which source is deployed. Conflicting versions block repair.

For a local website, add its exact origin to `AUTOQA_LOCAL_TARGETS`, for example `http://127.0.0.1:8080,http://127.0.0.1:8081`. Restart the browser worker and queue after changing it. Localhost refers to the browser server; a remote server needs a tunnel or staging deployment to reach your laptop. The repair preview origin must also be approved. [Worker details](docs/BROWSER_WORKER.md).

## Chat API and transcripts

Create a Sanctum token in **Settings → API tokens** (it receives the `chat` ability) and call `POST /api/chat-ai`. The same token can read `GET /api/chat-sessions` and owner-scoped history; the HTML transcript is at `/chat-sessions/{id}`. Streaming is available at `POST /api/chat-ai/stream`.

The complete request, SSE, error, and configuration reference is in [docs/CHAT_API.md](docs/CHAT_API.md). QA runs also write `ChatSession`, `ChatMessage`, and `ToolExecutionLog` records automatically; the recorder never changes the run result if persistence fails.

## Authorize automatic repair

Choose **Verified draft PR** on a project to authorize a new repair branch, commits and a draft PR after verification. **Suggest only** saves a proposed patch for review.

Follow [repair runner setup](docs/REPAIR_RUNNER.md) to prepare a trusted per-repository Docker image with the target's locked dependencies and disposable fixtures. Set `AUTOQA_RUNNER_IMAGE` to that image. The runner must be prepared by the operator; repository code never installs dependencies or runs on the orchestrator host.

Publication requires all six gates: original suite, regression assertion failure on the original source, repaired regression, repaired suite, original browser replay, and a separate normal control journey. Missing Docker, image, source identity, assertions, or passing results leaves the finding/patch visible and prevents publication. Human review and merging remain on GitHub.

## Verification and limits

```bash
php artisan test --compact
npm run build
cd worker
npm test
```

Automated API/model orchestration tests use fake outbound responses; those do not establish access to a real GitHub repository or a model's ability to repair it. Real container verification requires a running Docker daemon and a prepared runner image. The first version handles small Laravel PHP/Blade repairs (up to five files / 64 KB, source candidates up to 6 KB each), one new feature regression test, and sequential journeys. It does not support arbitrary project stacks, merge or deploy repairs, or receive GitHub webhooks.

Browser URL validation is defense in depth, not a substitute for operating-system egress restrictions; hostile target DNS changes require network-level controls. Use owned staging applications and disposable accounts/data.

Design documents: [Week 11 PRD](docs/WEEK_11.md), [chat API](docs/CHAT_API.md), [browser worker](docs/BROWSER_WORKER.md).
