# PRD — Auto QA: Transkrip Agent, API Chat Terproteksi, Streaming, dan Fallback Model

| Item | Nilai |
|---|---|
| Repo | `github.com/hisyamssyr/auto-qa-app` |
| Baseline yang dianalisis | commit `fc0ceb3` (2026-10-13), "feat: added translation for bahasa indonesia and dark mode support", 134 file ter-track |
| Tanggal PRD | 2026-11-15 |
| Status | Draft untuk implementasi. Fase 1 sudah dimulai secara lokal (lihat §3.12) |
| Pembaca utama | Pengembang dan AI agent yang akan mengimplementasikan update |
| Konteks | Tugas kuliah: Pemodelan Relasi Agentic AI Platform (Week 1) dan Secure LLM Chat API Hub (Week 2) |
| Revisi | v1.1 (2026-11-15): basis data **tetap SQLite** (§8.8); bentuk API model kampus Senopati AI ITS dimasukkan dari contoh curl pengguna (§3.13) |

---

## 1. Ringkasan

Auto QA adalah aplikasi Laravel 13 + Livewire 4 yang menguji website lewat browser (Playwright), mengusulkan perbaikan, memverifikasinya di Docker, lalu membuka draft pull request. Model bahasa yang dipakai di repo adalah server lokal OpenAI-compatible (default Ollama `qwen3:1.7b`). Menurut pengguna, di salinan lokalnya model sudah diarahkan ke API kampus Senopati AI ITS (§3.13). Basis data tetap **SQLite**; tidak ada migrasi ke DBMS lain.

Update ini menambahkan empat kemampuan **tanpa mengubah perilaku QA yang sudah ada**:

1. **Transkrip agent yang tersimpan.** Setiap panggilan model dan setiap pemanggilan alat browser oleh agent direkam ke tabel relasional (`ChatSession`, `ChatMessage`, `ToolExecutionLog`).
2. **API chat terproteksi.** Endpoint `POST /api/chat-ai` di bawah Laravel Sanctum untuk bertanya langsung ke LLM, dengan validasi `FormRequest` dan respons `API Resource`.
3. **Riwayat yang efisien.** Endpoint dan halaman riwayat dengan eager loading bertingkat yang bebas N+1.
4. **Challenge:** streaming SSE, system prompt bertema, dan fallback otomatis ke model kampus.

Sumber kebenaran adalah **repo ini**. Deskripsi tugas dosen hanya diikuti bila kompatibel dengan repo atau memang bisa diubah (lihat §4).

---

## 2. Prinsip keputusan

1. **Repo adalah acuan.** Pola yang sudah ada (Livewire, auth kustom, queue database, SQLite, `decide()` JSON) dipertahankan.
2. **Perubahan bersifat aditif.** Tidak ada perubahan pada tipe kembalian `ModelClient::decide()`, nama status run, atau ekspektasi test yang ada. Test existing memakai Mockery untuk `ModelClient`, `BrowserWorker`, dan `GitHubAppClient`.
3. **Urutan fase tidak wajib berurutan**, tetapi ada dependensi (lihat §11). Fallback dikerjakan paling akhir.
4. **Jujur soal yang belum teruji.** Fitur yang bergantung pada layanan eksternal (model kampus) ditandai *belum teruji*; hanya bentuk pemanggilan dari contoh curl pengguna yang dianggap diketahui.
5. **SQLite tetap.** Semua rancangan harus berjalan di SQLite; tidak ada fitur yang bergantung pada MySQL/PostgreSQL.
6. **Jangan mengirim parameter yang tidak diketahui didukung gateway kampus.** Untuk endpoint chat, body dibuat minimal dan parameter tambahan bersifat opsional lewat konfigurasi.

---

## 3. Current state (baseline `fc0ceb3`)

### 3.1 Stack

| Lapisan | Teknologi (dari `composer.json`, `package.json`, README) |
|---|---|
| Bahasa/runtime | PHP `^8.3` (README: dikembangkan dengan 8.5), Node 22+ |
| Framework | `laravel/framework ^13.17`, `livewire/livewire ^4.4`, `laravel/tinker ^3.0` |
| Dev | `laravel/boost`, `laravel/pail`, `laravel/pao`, `laravel/pint`, `phpunit ^12.5.12`, `mockery`, `fakerphp/faker`, `nunomaduro/collision` |
| Frontend | Vite 8, Tailwind 4 (`@tailwindcss/vite`), `laravel-vite-plugin` |
| Database | SQLite (default `.env.example`), session/cache/queue di database. **Dipertahankan.** `config/database.php`: `foreign_key_constraints` = `env('DB_FOREIGN_KEYS', true)`; `busy_timeout`, `journal_mode`, `synchronous` bernilai `null` (hardcoded, bukan dari env); `transaction_mode` = `DEFERRED` |
| Browser worker | Node + Playwright Chromium, port 3001, satu sesi sekaligus |
| Sandbox | Docker (`sandbox/runner.php` dijalankan di dalam image tepercaya) |
| Lockfile | `composer.lock`, `package-lock.json`, `worker/package-lock.json` ter-commit |

### 3.2 Arsitektur: tiga proses

1. **Laravel app**: UI Livewire, auth, job queue (`ExecuteQaRun`, timeout 1800 detik, `WithoutOverlapping('autoqa-heavy')` bersama).
2. **Browser worker** (`worker/*.mjs`): HTTP server dengan bearer token. Mengambil snapshot halaman (maksimal 80 elemen, teks 4000 karakter), menjalankan aksi, menyimpan screenshot dan trace.
3. **Repair sandbox** (`RepairSandbox` + `sandbox/runner.php`): container Docker tanpa network, read-only, 2 GB RAM, 1 CPU, 128 proses, semua capability dibuang.

### 3.3 Alur run (`QaAgent::run`)

1. Run berstatus `queued`, lalu `testing`. `TargetPolicy` memeriksa URL target.
2. `BrowserWorker::start` membuka sesi. Loop maksimal `min(40, AUTOQA_MAX_STEPS)` (default 25). Tiap iterasi: `ModelClient::decide(...)` menghasilkan satu aksi JSON, divalidasi `validateAction`, lalu dijalankan lewat `BrowserWorker::act`.
3. Aksi yang diizinkan: `click`, `fill`, `select`, `press`, `navigate`, `assert_text`, `assert_url`, `finish`. Klaim `finish: passed` ditolak kecuali minimal satu assertion browser lolos.
4. `observeFailure` membuat `Finding` jika ada respons HTTP >= 500 atau assertion gagal, dan mengambil screenshot.
5. Jika ada kegagalan: fase repair (`diagnosing`, `repairing`): model memilih file, menulis patch dan regression test, merencanakan control journey; `RepairSandbox::verify` menjalankan enam gate; bila semua lolos, `GitHubAppClient` membuat branch `agent/fix-*` dan draft PR.
6. Status run: `queued`, `testing`, `diagnosing`, `repairing` (non-terminal); `completed`, `failed`, `cancelled`, `blocked`, `needs_configuration`, `verification_failed`, `suggestion_ready`, `ready_for_review` (terminal; `Dashboard::TERMINAL_STATUSES`).

### 3.4 Komponen kode

| File | Tanggung jawab |
|---|---|
| `app/Services/QaAgent.php` (346 baris) | Orkestrasi run, validasi aksi, redaksi password, repair, publikasi |
| `app/Services/ModelClient.php` (58) | `health()` dan `decide()`: POST `/chat/completions`, `temperature 0.7`, `max_tokens 6144`, `response_format json_object`, menambahkan `/no_think` dan instruksi "Return only a JSON object", membuang `<think>...</think>`, menolak `finish_reason: length`. Mengembalikan **hanya** array hasil parse (usage, model, dan teks mentah dibuang) |
| `app/Services/BrowserWorker.php` (109) | Klien HTTP ke worker; validasi nama file bukti |
| `app/Services/TargetPolicy.php`, `worker/policy.mjs` | Kebijakan target (`public` / `local`) di PHP dan Node |
| `app/Services/PatchPolicy.php`, `RepairSandbox.php` | Batas patch (5 file, 64 KB, hanya `app/`, `resources/views/`, `routes/`, `tests/Feature/`) dan enam gate verifikasi |
| `app/Services/GitHub/GitHubAppClient.php` (503) | JWT GitHub App, baca source, commit repair, draft PR |
| `app/Jobs/ExecuteQaRun.php` | Job queue; `failed()` menandai run `failed` |
| `app/Livewire/Dashboard.php` (318), `dashboard.blade.php` (605) | UI tunggal: proyek, run, pengaturan; aksi `startRun`, `cancelRun`, `checkConnection`, `saveProject`, dll. |
| `app/Http/Controllers/AuthController.php` | Auth kustom (lihat §3.7) |
| `app/Http/Controllers/PreferencesController.php` | Simpan `locale` (`en`/`id`) dan `theme` (`light`/`dark`) ke `users` plus cookie |
| `app/Http/Controllers/EvidenceController.php` | Mengirim screenshot/trace; rute dibatasi regex `browser-[a-zA-Z0-9-]+\.(png\|zip)` |
| `app/Http/Middleware/ApplyUiPreferences.php` | Menerapkan preferensi UI ke grup `web` |

### 3.5 Data yang ada

| Tabel | Kolom penting |
|---|---|
| `users` | bawaan Laravel + `locale` (2, default `en`), `theme` (5, default `light`) |
| `projects` | `user_id` (FK cascade), `name`, `repository_url`, `website_url`, `target_mode`, `goal`, `expected_behavior`, `base_branch`, `deployed_sha`, `repair_policy` (default `suggest_only`), `connection` (json) |
| `qa_runs` | `project_id` (FK cascade), `status` (index), `goal`, `expected_behavior`, `base_sha`, `patch_files`/`verification`/`report` (json), `error`, `pr_url`, `started_at`, `finished_at` |
| `run_steps` | `run_id` (FK `qa_runs`, cascade), `sequence`, `kind`, `summary`, `data` (json), unique `(run_id, sequence)` |
| `findings` | `run_id` (FK cascade), `title`, `severity`, `status`, `expected`, `actual`, `evidence` (json) |
| `jobs`, `cache`, `sessions`, dll. | bawaan Laravel |

Kepemilikan: `User -> Project -> QaRun -> (RunStep, Finding)`. `QaRun` tidak punya `user_id` langsung.

Tidak ada tabel yang menyimpan prompt, jawaban mentah, nama model, atau jumlah token.

### 3.6 Rute

| Rute | Middleware | Keterangan |
|---|---|---|
| `GET /` | - | Redirect ke `dashboard` atau `login` |
| `GET/POST /login` | `guest` | Rate limit manual 5 percobaan per kunci email+IP |
| `GET/POST /register` | `guest`; POST `throttle:5,1` | Hanya sebelum ada user pertama |
| `POST /settings/preferences` | `auth` | |
| `GET /dashboard` | `auth` | Komponen Livewire `Dashboard` |
| `GET /runs/{run}/evidence/{filename}` | `auth` | |
| `POST /logout` | `auth` | |
| `GET /up` | - | Health check bawaan |

**Tidak ada `routes/api.php`.** `bootstrap/app.php` hanya mendaftarkan `web`, `commands`, dan `health`. Sudah ada `shouldRenderJsonWhen` untuk `api/*`.

### 3.7 Autentikasi

- Guard `web` (session, provider Eloquent). Tidak ada Sanctum dan tidak ada `HasApiTokens`.
- **Registrasi hanya untuk operator pertama.** Setelah ada satu `User`, `/register` mengarahkan ke login dan `register()` memberi 403. Penciptaan operator pertama memakai `Cache::lock('autoqa-first-operator')` + transaksi. Password minimal 12 karakter.
- Bukan Breeze. Model `User` memakai atribut `#[Fillable(['name','email','password','locale','theme'])]` dan `#[Hidden]`, tanpa relasi.

### 3.8 Konfigurasi (`config/autoqa.php`, `.env.example`)

`MODEL_URL` (default `http://127.0.0.1:11434/v1`), `MODEL_NAME` (`qwen3:1.7b`), `MODEL_TIMEOUT` (config default 90; `.env.example` 180), `MODEL_API_KEY`, `AUTOQA_MAX_STEPS` (25), `BROWSER_WORKER_URL/TOKEN/TIMEOUT`, `AUTOQA_LOCAL_TARGETS`, `AUTOQA_RUNNER_IMAGE`, `AUTOQA_PREVIEW_ORIGIN`, `AUTOQA_RUNNER_TIMEOUT`, serta `GITHUB_*` (`config/github.php`).

`ModelClient` menyusun URL sebagai `rtrim(MODEL_URL, '/').'/chat/completions'` dan mengirim `Authorization: Bearer <MODEL_API_KEY>` (atau `local` bila kunci kosong). `health()` memanggil `GET {MODEL_URL}/models` dengan timeout 3 detik. `Dashboard::startRun` hanya memeriksa `ModelClient::ready()` (URL terisi), bukan `health()`.

### 3.9 Testing

- PHPUnit (`phpunit.xml`): SQLite `:memory:`, `QUEUE_CONNECTION=sync`, cache dan session `array`.
- Feature: `AuthTest`, `DashboardTest`, `EvidenceTest`, `ExampleTest`, `ExecuteQaRunTest`, `PreferencesTest`, `QaAgentTest`, `RepairSandboxTest`. Unit: `BrowserWorkerTest`, `ExampleTest`, `GitHubAppClientTest`, `GitHubRepositoryUrlTest`, `ModelClientTest`, `PatchPolicyTest`, `TargetPolicyTest`. Worker: `browser`, `browser.integration`, `policy`, `server`.
- Factory: `UserFactory`, `ProjectFactory` (state `local()`, `publishing()`), `QaRunFactory`. Seeder: `DatabaseSeeder` (membuat "Test User"), `PreviewSeeder` (hanya jalan di `APP_ENV=testing` dengan database `/tmp/autoqa-ui-preview-*`).
- Dependensi luar di-mock. **Status lulus/gagal suite pada baseline belum diverifikasi oleh penulis PRD** (folder `vendor` belum terpasang di lingkungan analisis). Langkah pertama implementasi adalah menjalankan suite untuk baseline.

### 3.10 Batasan yang sudah terdokumentasi (README dan docs)

Perbaikan hanya untuk Laravel PHP/Blade kecil (maks. 5 file / 64 KB, kandidat source maks. 6 KB), satu regression test baru, jalur sekuensial, tanpa webhook, tanpa merge atau deploy otomatis. `plans/` ada di `.gitignore`, sehingga dokumen rencana yang ditautkan README tidak ada di repo.

### 3.11 Gap terhadap tujuan update

| Gap | Dampak |
|---|---|
| Prompt, jawaban, model, token tidak disimpan | Tidak ada riwayat interaksi LLM |
| Aksi alat tersimpan hanya sebagai ringkasan `run_steps` | Tidak ada log alat terstruktur per pesan |
| Tidak ada API, Sanctum, `HasApiTokens` | Tidak ada endpoint terproteksi token |
| Validasi inline di Livewire/controller | Tidak ada contoh `FormRequest` |
| `decide()` memaksa JSON dan membuang `<think>` | Tidak cocok untuk jawaban chat teks bebas dan streaming |
| Tidak ada streaming, tidak ada fallback model | |
| `User` tidak punya relasi ke sesi | |

### 3.12 Perubahan lokal yang belum ada di GitHub

Di salinan kerja lokal (sandbox analisis) sudah dibuat dan di-commit secara lokal (`a4d46f9`, branch `feat/chat-transcript-schema`), **belum di-push** karena akun GitHub belum terhubung ke sesi: `database/migrations/2026_11_15_000001_create_chat_tables.php`, `app/Models/{ChatSession,ChatMessage,ToolExecutionLog}.php`, serta relasi `chatSession()` dan `toolExecutionLogs()` pada `QaRun`. Factory, relasi `User::chatSessions()`, seeder, dan test belum dibuat. Migration belum dijalankan dan belum diuji.

### 3.13 Konfigurasi lokal pengguna (di luar GitHub)

Menurut pengguna, di komputernya model sudah diarahkan ke API kampus Senopati AI ITS. Perubahan itu **belum ada di repo GitHub** dan tidak diverifikasi oleh penulis PRD (file lokal pengguna tidak diakses). Contoh pemanggilan dari pengguna (kunci disamarkan):

```bash
curl https://senopati.its.ac.id/llm-gateway/v1/chat/completions \
  -H "Authorization: Bearer xxx" \
  -H "Content-Type: application/json" \
  -d '{"model": "qwen3:14b", "messages": [{"role": "user", "content": "halo"}]}'
```

Kecocokan dengan kode yang ada: `ModelClient` sudah menambahkan `/chat/completions` ke `MODEL_URL` dan mengirim Bearer dari `MODEL_API_KEY`, sehingga konfigurasinya cukup `MODEL_URL=https://senopati.its.ac.id/llm-gateway/v1`, `MODEL_NAME=qwen3:14b`, `MODEL_API_KEY=<kunci>`. Nama model `qwen3:14b` juga sama dengan contoh respons di tugas dosen.

| Status | Hal |
|---|---|
| **Diketahui** (dari contoh pengguna) | URL endpoint, autentikasi Bearer, body bergaya OpenAI (`model`, `messages` dengan `role` dan `content`), model `qwen3:14b` |
| **Belum diketahui** | Dukungan `stream: true` (SSE); keberadaan field `usage` di respons; dukungan `response_format: json_object` (dipakai `decide()`); keberadaan `GET /models` (dipakai `health()`); dukungan `max_tokens` dan `temperature`; kuota dan rate limit; latensi dan timeout `qwen3:14b`; apakah blok `<think>` muncul; format respons error |

Konsekuensi untuk desain: semua fitur yang bergantung pada hal "belum diketahui" harus opsional atau punya jalur cadangan (lihat FR-3.6, FR-5.6, FR-7.4, FR-7.6, FR-7.7). Prompt dan konteks halaman target yang dikirim ke gateway diterima server kampus (lihat R15).

---

## 4. Pemetaan requirement dosen ke keputusan

| Requirement dosen | Keputusan | Catatan |
|---|---|---|
| Model `User` bawaan Breeze | **Tidak diimplementasikan** | Memasang Breeze menimpa `AuthController`, rute, dan view. `User` yang ada dipakai sebagai padanan. Perlu konfirmasi dosen jika Breeze dipersyaratkan |
| `ChatSession`, `ChatMessage`, `ToolExecutionLog` | **Ya** | Lihat §8 |
| `User hasMany ChatSession` | **Ya** | `chat_sessions.user_id` langsung |
| Migrations dengan FK + `onDelete('cascade')` | **Ya** | Semua FK cascade |
| Factory untuk data dummy relasional | **Ya** | Plus `ChatDemoSeeder` |
| Polymorphic `morphs('loggable')` | **Ya, disesuaikan** | `chat_message_id` (FK) **dipertahankan** di samping `loggable`. Target `loggable` = model yang sudah ada (`QaRun`), bukan `SecurityScanner`/`MalwareScanner`/`LogAnalyzer` |
| Eager loading bertingkat + bukti Debugbar | **Ya, disesuaikan** | Bukti di halaman HTML; endpoint JSON dibuktikan dengan test jumlah kueri |
| `POST /api/chat-ai` di `auth:sanctum` | **Ya** | |
| `FormRequest` (prompt string, maks. 1000) | **Ya** | `ChatRequest` |
| Tabel log: user, prompt, jawaban, model, token | **Ya** | Lewat `chat_sessions` + `chat_messages` |
| `API Resource` + format `{status, data{...}}` | **Ya** | `ChatResource` |
| Ollama lokal atau Senopati AI ITS | **Ya** | Penyedia diatur lewat `MODEL_*` (utama) dan `FALLBACK_MODEL_*`. Profil A (kondisi lokal pengguna): Senopati sebagai utama. Profil B (untuk Challenge fallback): Ollama utama, Senopati fallback (Lampiran A) |
| Basis data | **Tetap SQLite** | Dosen tidak mensyaratkan DBMS tertentu; lihat §8.8 untuk batasan dan mitigasi |
| Challenge SSE | **Ya, endpoint terpisah** | Tidak digabung dengan Resource |
| Challenge system prompt tema | **Ya** | Tema QA engineer |
| Challenge fallback otomatis | **Ya, bersyarat, paling akhir** | Bentuk API kampus diketahui dari contoh curl; fitur lanjutan belum diketahui; integrasi nyata belum teruji |
| Antarmuka Livewire (Pertemuan 12) | **Di luar lingkup** | API dirancang agar bisa dipakai UI nanti |
| Registrasi untuk anggota kelompok | **Tidak diubah** | Tetap single-operator; token dibuat per operator |

---

## 5. Ruang lingkup

**Dalam lingkup:** skema chat, model, factory, seeder; perekaman transkrip dari `QaAgent`/`ModelClient`; Sanctum dan manajemen token di panel Settings; endpoint chat, riwayat, dan stream; `FormRequest`, `Resource`, `Policy`; system prompt bertema; fallback; test, Pint, dokumentasi.

**Di luar lingkup:** memasang Breeze; migrasi basis data dari SQLite ke DBMS lain; mengubah aturan registrasi; function/tool calling di dalam endpoint chat bebas; UI chat Livewire; retensi atau pembersihan otomatis transkrip; mengubah alur repair/verifikasi; model agent `SecurityScanner`/`MalwareScanner`/`LogAnalyzer`; webhook GitHub.

---

## 6. Pengguna dan user stories

| ID | Sebagai | Saya ingin | Agar |
|---|---|---|---|
| US-1 | Operator | melihat transkrip lengkap sebuah run (pesan model dan pemanggilan alat) | memahami keputusan agent dan mendiagnosis kegagalan |
| US-2 | Operator | membuat dan mencabut token API di Settings | mengakses API tanpa membuka sesi web |
| US-3 | Klien API | mengirim prompt dan menerima jawaban JSON terstruktur | mengintegrasikan LLM ke aplikasi lain |
| US-4 | Klien API | melanjutkan sesi chat dengan `session_id` | mempertahankan konteks percakapan |
| US-5 | Klien API | menerima jawaban bertahap (SSE) | menampilkan teks mengalir |
| US-6 | Operator | tetap mendapat jawaban saat Ollama mati | layanan tidak terhenti |
| US-7 | Penguji/dosen | memverifikasi relasi, cascade, dan kueri riwayat tanpa N+1 | menilai kualitas skema dan optimasi |

---

## 7. Requirements fungsional

Prioritas: **P0** wajib, **P1** penting, **P2** challenge/opsional.

### WS1 — Skema, model, factory

| ID | Requirement | Prioritas |
|---|---|---|
| FR-1.1 | Tabel `chat_sessions`: `user_id` (FK, cascade), `qa_run_id` (nullable FK `qa_runs`, cascade), `title` (nullable), timestamps, index `(user_id, created_at)` | P0 |
| FR-1.2 | Tabel `chat_messages`: `chat_session_id` (FK, cascade), `sequence`, `role` (`system`/`user`/`assistant`), `content` (longText), `model_used`, `prompt_tokens`, `completion_tokens`, `total_tokens` (nullable), unique `(chat_session_id, sequence)` | P0 |
| FR-1.3 | Tabel `tool_execution_logs`: `chat_message_id` (FK, cascade), `morphs('loggable')`, `tool_name`, `arguments` (json), `result` (json), `status` (`succeeded`/`failed`), `duration_ms` | P0 |
| FR-1.4 | Model dan relasi: `User::chatSessions()` hasMany; `ChatSession::{user, qaRun, messages}`; `ChatMessage::{chatSession, toolExecutionLogs}`; `ToolExecutionLog::{chatMessage, loggable}`; `QaRun::{chatSession, toolExecutionLogs}` | P0 |
| FR-1.5 | Factory untuk ketiga model, plus state `ChatSessionFactory::withMessages($turns, $logsPerReply)` yang menetapkan `sequence` berurutan dan `loggable` ke satu `QaRun` | P0 |
| FR-1.6 | `ChatDemoSeeder` membuat data relasional lewat factory; dipanggil dari `DatabaseSeeder`. `PreviewSeeder` tidak diubah | P1 |

### WS2 — Perekaman transkrip dari agent

| ID | Requirement | Prioritas |
|---|---|---|
| FR-2.1 | Satu `ChatSession` dibuat per `QaRun` saat run mulai diproses (`qa_run_id` terisi, `user_id` dari `project->user_id`, `title` dari goal) | P0 |
| FR-2.2 | Setiap `ModelClient::decide()` menghasilkan tiga pesan: `system` (instruksi), `user` (konteks JSON), `assistant` (teks mentah jawaban). Jawaban menyimpan `model_used` dan token dari `usage` bila tersedia | P0 |
| FR-2.3 | Setiap `BrowserWorker::act` dalam loop menghasilkan satu `ToolExecutionLog` yang menempel pada pesan `assistant` yang memutuskannya. `loggable` = `QaRun`. `arguments` = aksi yang **sudah diredaksi** (`redactActions`); `result` = ringkasan (`assertion`, `url`, jumlah `network_errors`), **bukan** snapshot lengkap; `status = failed` bila assertion gagal atau terjadi exception; `duration_ms` terukur | P0 |
| FR-2.4 | Aksi yang bukan keputusan model (start sesi, screenshot bukti, close) menempel pada pesan `system` yang dibuat recorder | P0 |
| FR-2.5 | Panggilan model fase repair (pemilihan file, patch, control plan) juga direkam. Konten dipotong sesuai batas (§10); patch lengkap tetap di `qa_runs.patch_files` | P1 |
| FR-2.6 | Kegagalan merekam **tidak boleh** menggagalkan run QA. Error dilaporkan lewat `report()` | P0 |
| FR-2.7 | Tipe kembalian `decide()` dan alur status run tidak berubah | P0 |

### WS3 — API chat terproteksi

| ID | Requirement | Prioritas |
|---|---|---|
| FR-3.1 | Pasang Sanctum (`php artisan install:api`), tambahkan trait `HasApiTokens` ke `User` secara manual, daftarkan `routes/api.php` di `bootstrap/app.php` | P0 |
| FR-3.2 | Manajemen token di panel Settings Livewire: buat (nama, ability `chat`, token tampil sekali), daftar, cabut | P0 |
| FR-3.3 | `POST /api/chat-ai` di grup `auth:sanctum` + `ability:chat` + `throttle` | P0 |
| FR-3.4 | `ChatRequest`: `prompt` `required|string|max:1000`; `session_id` `nullable|integer`, wajib milik user yang login | P0 |
| FR-3.5 | `ChatResource` berformat dosen (lihat §9.1) | P0 |
| FR-3.6 | `ModelClient::chat()`: teks bebas (tanpa `response_format`), body minimal `model` + `messages` (`temperature` hanya bila `CHAT_MODEL_TEMPERATURE` diisi, `max_tokens` hanya bila `CHAT_MAX_TOKENS` diisi), header Bearer dari `MODEL_API_KEY`, membuang `<think>` bila ada, membaca `usage.total_tokens` bila ada (bila tidak, token `null`), mengirim system prompt tema dan riwayat sesi terbatas | P0 |
| FR-3.7 | Persistensi dalam transaksi singkat: sesi baru atau lanjutan dan pesan `user` disimpan lebih dulu; panggilan ke model berjalan **di luar** transaksi; pesan `assistant` (model dan token) disimpan setelahnya. `sequence` dihitung `max + 1` di dalam transaksi; pengaman utamanya unique `(chat_session_id, sequence)` dengan retry satu kali, karena SQLite mengabaikan `lockForUpdate` (§8.8) | P0 |
| FR-3.8 | Respons error JSON seragam untuk `/api/*`: 401, 403, 422, 429, 503 | P1 |

### WS4 — Riwayat dan eager loading

| ID | Requirement | Prioritas |
|---|---|---|
| FR-4.1 | `GET /api/chat-sessions`: daftar sesi milik user, terpaginasi, dengan `withCount('messages')` | P1 |
| FR-4.2 | `GET /api/chat-sessions/{session}`: `ChatSession::with(['messages.toolExecutionLogs'])`; jumlah kueri tetap konstan terhadap jumlah pesan dan log | P0 |
| FR-4.3 | `GET /chat-sessions/{session}` (web, `auth`): halaman HTML read-only yang memakai kueri yang sama, agar bisa dibuktikan dengan Debugbar | P0 |
| FR-4.4 | `ChatSessionPolicy`: user hanya boleh melihat sesinya sendiri (termasuk sesi yang dibuat dari `QaRun`) | P0 |

### WS5 — Streaming SSE (Challenge)

| ID | Requirement | Prioritas |
|---|---|---|
| FR-5.1 | `POST /api/chat-ai/stream` memakai middleware dan `ChatRequest` yang sama; keluaran `text/event-stream` | P2 |
| FR-5.2 | Jawaban lengkap dan token disimpan di blok `finally`, termasuk saat klien memutus koneksi | P2 |
| FR-5.3 | Penyaring `<think>` berbasis state (tag bisa terpotong antar-chunk) | P2 |
| FR-5.4 | Jumlah token diambil dari chunk `usage` terakhir bila server mendukung; bila tidak, kolom token dibiarkan `null` | P2 |
| FR-5.5 | Tidak ada transaksi atau lock database yang terbuka selama streaming (SQLite memiliki satu penulis sekaligus): pesan `user` disimpan lebih dulu, stream berjalan, pesan `assistant` disimpan dalam transaksi singkat di akhir | P2 |
| FR-5.6 | `stream_options.include_usage` hanya dikirim bila `CHAT_STREAM_USAGE=true`. Bila gateway kampus tidak mendukung `stream: true`, endpoint stream membalas 501 JSON dan endpoint non-stream tetap berfungsi | P2 |

### WS6 — System prompt bertema (Challenge)

| ID | Requirement | Prioritas |
|---|---|---|
| FR-6.1 | Konfigurasi `autoqa.chat_system_prompt` (env `CHAT_SYSTEM_PROMPT`); default bertema QA engineer | P2 |
| FR-6.2 | Hanya diterapkan pada endpoint chat; prompt internal QA (`decide()`) tidak berubah | P2 |

### WS7 — Fallback otomatis (Challenge, dikerjakan terakhir)

| ID | Requirement | Prioritas |
|---|---|---|
| FR-7.1 | Konfigurasi fallback (`FALLBACK_MODEL_URL`, `_NAME`, `_API_KEY`, `FALLBACK_ENABLED`); nonaktif secara default. Penyedia utama tetap `MODEL_*`; keduanya bebas diarahkan ke Ollama atau Senopati (profil di Lampiran A) | P2 |
| FR-7.2 | Pindah ke fallback hanya pada kegagalan koneksi (connect timeout 3 detik), timeout, atau HTTP 5xx dari penyedia utama; **tidak** pada 4xx. Keputusan diambil dari kegagalan permintaan chat itu sendiri, bukan dari `health()` | P2 |
| FR-7.3 | `model_used` mencatat model yang benar-benar menjawab | P2 |
| FR-7.4 | Penyedia kampus dipanggil dengan bentuk yang diketahui dari contoh curl (§3.13): `POST {URL}/chat/completions`, Bearer, `model`, `messages`. Dukungan streaming, `usage`, `response_format`, dan `/models` belum diketahui sehingga kode tidak boleh mengandalkannya. Status integrasi nyata **belum teruji** dan harus tertulis di dokumentasi | P2 |
| FR-7.5 | Jika semua penyedia gagal, API membalas 503 JSON | P2 |
| FR-7.6 | `ModelClient::health()` tidak boleh menjadi satu-satunya penentu ketersediaan, karena `GET /models` belum terbukti ada di gateway kampus. Bila dipakai untuk status tampilan, kegagalannya pada gateway kampus ditampilkan sebagai "tidak diketahui", bukan "mati" | P2 |
| FR-7.7 | Tambah `MODEL_JSON_MODE` (default `true`, perilaku sekarang tidak berubah) agar `response_format: json_object` pada `decide()` dapat dimatikan bila gateway menolaknya | P2 |

### WS8 — Kualitas

| ID | Requirement | Prioritas |
|---|---|---|
| FR-8.1 | Test baru sesuai §12; semua test lama tetap hijau | P0 |
| FR-8.2 | Gaya PSR-12 untuk file baru/diubah (Pint `--preset psr12`) | P0 |
| FR-8.3 | `barryvdh/laravel-debugbar` sebagai dev dependency | P1 |
| FR-8.4 | Dokumentasi `docs/CHAT_API.md`, pembaruan `README.md` dan `.env.example` | P1 |
| FR-8.5 | Basis data tetap SQLite: atur `busy_timeout` (mis. 5000 ms) dan `journal_mode` `wal` di `config/database.php` (nilai saat ini hardcoded `null`, bukan dari env), dan jangan mematikan `DB_FOREIGN_KEYS` | P1 |
| FR-8.6 | Test yang memastikan foreign key SQLite aktif (cascade benar-benar berjalan) dan `sequence` ganda ditangani dengan retry | P0 |

---

## 8. Desain teknis

### 8.1 ERD

```mermaid
erDiagram
    users ||--o{ chat_sessions : "user_id (cascade)"
    qa_runs ||--o| chat_sessions : "qa_run_id (nullable, cascade)"
    chat_sessions ||--o{ chat_messages : "chat_session_id (cascade)"
    chat_messages ||--o{ tool_execution_logs : "chat_message_id (cascade)"
    qa_runs ||--o{ tool_execution_logs : "loggable (polymorphic, tanpa FK)"
    users ||--o{ projects : "user_id"
    projects ||--o{ qa_runs : "project_id"
```

### 8.2 Aturan cascade

| Kejadian | Efek |
|---|---|
| Hapus `User` | `chat_sessions` ikut terhapus, lalu `chat_messages` dan `tool_execution_logs` |
| Hapus `QaRun` | `chat_sessions` terkait ikut terhapus (rantai yang sama); `run_steps` dan `findings` tetap seperti sekarang |
| Hapus `ChatMessage` | `tool_execution_logs` terkait ikut terhapus |
| Hapus model `loggable` | **Tidak ada cascade dari database** (kolom morph tidak bisa punya FK). Karena `loggable` = `QaRun` dan hapus `QaRun` sudah menghapus sesi, logs ikut hilang lewat rantai pesan. Untuk target `loggable` lain di masa depan, cascade harus diatur di model |

### 8.3 Semantik `loggable`

`chat_message_id` menjawab "di pesan mana alat ini dipanggil" (ditegakkan database). `loggable` menjawab "komponen mana yang mengeksekusi" (ditegakkan aplikasi). Pada versi ini semua log browser memakai `loggable = QaRun`. Nilai `loggable` lain (misalnya `Finding` untuk verifikasi repair) adalah ekstensi, bukan syarat.

### 8.4 Perekaman: komponen `TranscriptRecorder`

Karena test existing me-*mock* `ModelClient`, perekaman **tidak boleh bergantung pada `ModelClient` asli saja**. Rancangan:

- Service `App\Services\TranscriptRecorder` (terdaftar scoped di container) dengan metode: `begin(QaRun)`, `recordExchange(system, userContext, assistantText, model, usage)`, `currentMessage()`, `logTool(action, result, status, durationMs)`.
- `ModelClient::decide()` memanggil `recordExchange` setelah respons valid, jika recorder punya sesi aktif. Nilai kembalian `decide()` tidak berubah.
- `QaAgent` memanggil `begin()` di awal run dan `logTool()` setelah setiap `BrowserWorker::act`. Bila `currentMessage()` kosong (misalnya model di-mock pada test), recorder membuat pesan `system` pengganti, sehingga FK tetap terpenuhi.
- Semua pemanggilan recorder dibungkus try/catch; kegagalan hanya di-`report()` (FR-2.6).

### 8.5 Query riwayat

```php
ChatSession::query()
    ->whereBelongsTo($request->user())
    ->with(['messages.toolExecutionLogs'])
    ->findOrFail($id);
```

Jumlah kueri aplikasi: 3 (sesi, pesan, log), ditambah kueri autentikasi Sanctum (token dan pemilik token) pada jalur API. Jumlah ini harus konstan untuk 1 atau 100 pesan. Bila `loggable` ikut dimuat (`toolExecutionLogs.loggable`), kueri bertambah satu per tipe `loggable` yang berbeda (bukan per baris).

### 8.6 `ModelClient::chat()` dan `stream()`

- Memakai endpoint `/chat/completions` yang sama dengan `decide()`, tanpa `response_format` dan tanpa instruksi "Return only a JSON object". Body minimal: `model`, `messages`; sisanya (`temperature`, `max_tokens`, `stream`, `stream_options`) hanya dikirim bila dikonfigurasi atau dibutuhkan mode stream.
- Riwayat: system prompt tema + N pesan terakhir sesi (`CHAT_HISTORY_MESSAGES`, default 10) + prompt baru.
- `temperature` terpisah (`CHAT_MODEL_TEMPERATURE`, default kosong sehingga tidak dikirim); timeout mengikuti `MODEL_TIMEOUT` (naikkan untuk `qwen3:14b` bila perlu).
- Pembuangan `<think>`: regex untuk jawaban utuh; mesin state untuk streaming.
- `stream()` memakai `stream: true`; `stream_options.include_usage` hanya bila `CHAT_STREAM_USAGE=true` (dukungan gateway belum diketahui; bila tidak didukung, token `null`).

### 8.7 Fallback

Daftar penyedia terurut `[primary, fallback]`. Penyedia utama dicoba lebih dulu; pada kegagalan koneksi/timeout/5xx, penyedia kedua dicoba. Keduanya berbicara dialek OpenAI-compatible yang sama (`POST {URL}/chat/completions` + Bearer), jadi perbedaan hanya URL, kunci, dan nama model. Bila gateway kampus ternyata menyimpang (mis. struktur respons), adaptasinya dilokalisir di satu kelas penyedia agar controller tidak berubah.

| Profil | Utama (`MODEL_*`) | Fallback (`FALLBACK_MODEL_*`) | Kegunaan |
|---|---|---|---|
| A (kondisi lokal pengguna) | Senopati | nonaktif | Pemakaian harian dan demo tanpa Ollama |
| B | Ollama lokal | Senopati | Memenuhi Challenge 3 Week 2 |

### 8.8 Basis data: SQLite dipertahankan

Seluruh skema (§8.1) dan alur (§7) berjalan di SQLite. Hal yang perlu diperhatikan:

| Aspek | Perilaku di SQLite | Keputusan |
|---|---|---|
| Foreign key dan cascade | Aktif selama `DB_FOREIGN_KEYS` bernilai true (default di `config/database.php`) | Jangan dimatikan; diuji (FR-8.6, AC-14) |
| FK pada tabel baru | Didukung karena dibuat lewat `Schema::create` (SQLite tidak bisa menambah FK ke tabel yang sudah ada via `ALTER`, tetapi itu tidak relevan di sini) | Tidak ada tindakan |
| `lockForUpdate()` | Tidak berefek; SQLite hanya mengizinkan satu penulis sekaligus | Pengaman: unique `(chat_session_id, sequence)` + retry satu kali (FR-3.7) |
| Transaksi `DEFERRED` | Transaksi yang mulai membaca lalu menulis bisa gagal "database is locked" | Transaksi dibuat singkat dan langsung menulis; `busy_timeout` diatur (FR-8.5) |
| Satu file untuk semuanya | Queue, session, cache, data QA, dan chat berbagi satu file database | `journal_mode` `wal` dan `busy_timeout` (FR-8.5); tidak ada transaksi terbuka selama streaming (FR-5.5) |
| Kolom `json` | Disimpan sebagai teks; kueri `->` sudah dipakai repo (`evidence->artifact` di `EvidenceController`) | Tidak ada tindakan |
| `longText` | Berupa `TEXT` tanpa batas praktis | Tetap dipotong 32 KB per pesan (§10) |
| `morphs('loggable')` | Dua kolom plus indeks, tanpa FK (sama di semua DBMS) | Cascade dijaga lewat `chat_message_id` (§8.2) |
| Test | `:memory:`; pragma FK mengikuti config yang sama; WAL tidak berlaku di memori | Test memakai pola yang ada |
| Server dev | `php artisan serve` default memproses satu request sekaligus (`PHP_CLI_SERVER_WORKERS` masih dikomentari di `.env.example`), sehingga satu stream SSE dapat menahan request lain | Aktifkan `PHP_CLI_SERVER_WORKERS=4` saat demo (dokumentasi) |
| Batas skala | Cukup untuk satu operator dan demo lokal; banyak pengguna bersamaan lebih cocok DBMS lain | Di luar lingkup |

---

## 9. Spesifikasi API

Semua endpoint JSON, header `Accept: application/json`, `Authorization: Bearer <token>`.

### 9.1 `POST /api/chat-ai`

Request:

```json
{ "prompt": "Jelaskan middleware!", "session_id": 12 }
```

`session_id` opsional (bila kosong, sesi baru dibuat).

Respons 200:

```json
{
  "status": "success",
  "data": {
    "session_id": 12,
    "model_used": "qwen3:14b",
    "prompt": "Jelaskan middleware!",
    "ai_response": "Middleware adalah...",
    "total_tokens": 214,
    "timestamp": "2026-11-15 09:30:00"
  }
}
```

`model_used` mengikuti model yang benar-benar menjawab. `timestamp` memakai zona waktu `app.timezone`, yang saat ini `UTC` di `config/app.php` (ubah bila demo butuh WIB). `total_tokens` adalah penambahan atas contoh dosen (nullable).

### 9.2 `POST /api/chat-ai/stream`

Request sama. Header respons: `Content-Type: text/event-stream`, `Cache-Control: no-cache`, `X-Accel-Buffering: no`.

```
event: token
data: {"delta":"Middleware"}

event: done
data: {"session_id":12,"model_used":"qwen3:14b","total_tokens":214}
```

Kegagalan di tengah stream mengirim `event: error` dengan pesan umum.

### 9.3 Riwayat

| Endpoint | Respons |
|---|---|
| `GET /api/chat-sessions` | Daftar terpaginasi: `id`, `title`, `messages_count`, `created_at` |
| `GET /api/chat-sessions/{session}` | Sesi dengan `messages[]`, tiap pesan memuat `tool_execution_logs[]` |

### 9.4 Kode error

| Kode | Kondisi | Bentuk |
|---|---|---|
| 401 | Token hilang/tidak valid | `{"status":"error","message":"Unauthenticated."}` |
| 403 | Ability tidak cukup, atau sesi milik user lain | `{"status":"error","message":"..."}` |
| 422 | Validasi gagal (mis. prompt > 1000 karakter) | `{"status":"error","message":"...","errors":{"prompt":["..."]}}` |
| 429 | Melebihi throttle | `{"status":"error","message":"Too many requests."}` |
| 503 | Semua penyedia model gagal | `{"status":"error","message":"The language model is unavailable."}` |

Normalisasi bentuk error dilakukan di `withExceptions` pada `bootstrap/app.php` untuk `api/*`.

---

## 10. Persyaratan non-fungsional

| Area | Persyaratan |
|---|---|
| Keamanan | Token Sanctum disimpan hash oleh framework dan hanya tampil sekali. Endpoint dibatasi ability `chat` dan `throttle` (default 20 permintaan/menit per user, dapat dikonfigurasi). Semua kueri riwayat dibatasi ke pemilik (`whereBelongsTo`/Policy) |
| Privasi | Transkrip run menyimpan konteks halaman target yang bisa memuat data pribadi; hanya operator pemilik yang dapat melihatnya. Nilai `fill` untuk field password **harus** diredaksi sebelum disimpan (pakai `redactActions`). Tidak ada pembersihan otomatis (di luar lingkup) |
| Ukuran data | Konten pesan dipotong maksimal 32 KB dengan penanda `[truncated]`. Perkiraan kasar: 25 langkah x (pesan hingga 32 KB + instruksi) menghasilkan di bawah 1 MB per run, wajar untuk SQLite |
| Konkurensi | `sequence` dihitung (`max + 1`) dalam transaksi singkat; karena SQLite mengabaikan `lockForUpdate`, pengaman utamanya unique `(chat_session_id, sequence)` dengan retry satu kali. Tidak ada transaksi terbuka saat memanggil model atau streaming (§8.8) |
| Performa | Riwayat memakai eager loading; jumlah kueri konstan (§8.5) |
| Ketahanan | Kegagalan perekaman tidak menggagalkan run; kegagalan penyedia utama memicu fallback (bila aktif) |
| Kompatibilitas | PHP `^8.3`, Laravel `^13.17`, Livewire `^4.4`, SQLite default. Versi `laravel/sanctum` ditentukan saat `install:api` (harus kompatibel dengan Laravel 13; verifikasi saat instalasi) |
| Gaya kode | PSR-12 untuk file baru/diubah. Repo tidak punya `pint.json`, jadi gunakan `./vendor/bin/pint --preset psr12 <file>`; jangan memformat ulang seluruh repo |
| i18n | Pesan UI baru (panel token, halaman riwayat) ditambahkan ke `resources/lang/en` dan `resources/lang/id` |

---

## 11. Rencana fase

Urutan fase tidak wajib berurutan; kolom "Bergantung pada" menunjukkan dependensi nyata.

| Fase | Isi | Bergantung pada | Status |
|---|---|---|---|
| 0 | `composer install`, jalankan suite sebagai baseline, pasang `npm` bila perlu; uji manual gateway Senopati: apakah mendukung streaming, `usage`, `response_format`, dan `GET /models` (§3.13) | - | Belum |
| 1 | Skema, model, relasi, factory, seeder, test relasi/cascade (FR-1.x) | 0 | **Berjalan**: migration + 3 model + relasi `QaRun` sudah ditulis lokal (§3.12) |
| 2 | `TranscriptRecorder`, integrasi `ModelClient`/`QaAgent` (FR-2.x) | 1 | Belum |
| 3 | Sanctum, token Settings, `ChatRequest`, `ChatResource`, `ModelClient::chat()`, `POST /api/chat-ai` (FR-3.x) | 1 | Belum |
| 4 | Riwayat API + halaman HTML + Policy + Debugbar (FR-4.x) | 1 (dan 3 untuk API) | Belum |
| 5 | System prompt tema (FR-6.x) | 3 | Belum |
| 6 | Streaming SSE (FR-5.x) | 3 | Belum |
| 7 | Fallback (FR-7.x) | 3 | Belum (**terakhir**) |
| 8 | Test akhir, Pint, `docs/CHAT_API.md`, README, `.env.example` (FR-8.x) | semua | Belum |

Fase 2 dan fase 3 saling independen dan dapat dikerjakan paralel setelah fase 1.

---

## 12. Kriteria penerimaan dan rencana test

### 12.1 Kriteria penerimaan

| ID | Given / When / Then |
|---|---|
| AC-1 | Given migrasi dijalankan, then tiga tabel baru ada dengan FK cascade; menghapus `User` menghapus seluruh sesi, pesan, dan log turunannya |
| AC-2 | Given `QaRun` diproses, when run selesai, then ada satu `ChatSession` dengan pesan `system`/`user`/`assistant` per panggilan model, dan satu `ToolExecutionLog` per aksi browser |
| AC-3 | Given aksi `fill` pada field password, then `arguments` tersimpan sebagai `[QA password hidden]`, bukan nilai asli |
| AC-4 | Given perekaman melempar exception, then run tetap selesai dengan status yang sama seperti tanpa perekaman |
| AC-5 | Given request tanpa token, when memanggil `POST /api/chat-ai`, then 401 JSON |
| AC-6 | Given prompt 1001 karakter atau bukan string, then 422 JSON dengan kunci `errors.prompt` |
| AC-7 | Given token valid dan prompt sah, then 200, bentuk respons sama dengan §9.1, dan satu pesan `user` + satu pesan `assistant` tersimpan dengan `model_used` dan token |
| AC-8 | Given `session_id` milik user lain, then 403/422 dan tidak ada data bocor |
| AC-9 | Given sesi dengan 3 vs 30 pesan, when `GET /api/chat-sessions/{id}`, then jumlah kueri aplikasi sama |
| AC-10 | Given halaman `/chat-sessions/{id}` dibuka dengan Debugbar, then panel menampilkan kueri konstan tanpa pola N+1 |
| AC-11 | Given streaming aktif, then token tiba bertahap dan jawaban lengkap tersimpan setelah selesai atau setelah klien memutus koneksi |
| AC-12 | Given penyedia utama gagal koneksi dan fallback aktif, then jawaban diberikan oleh fallback dan `model_used` mencerminkannya; pada respons 4xx dari penyedia utama, fallback **tidak** dipakai |
| AC-13 | Semua test lama tetap lulus; `php artisan test --compact` hijau |
| AC-14 | Given SQLite, then foreign key aktif dan menghapus `User` benar-benar menghapus sesi, pesan, dan log turunannya |
| AC-15 | Given dua penyisipan pesan dengan `sequence` yang sama, then salah satu ditolak oleh unique dan di-retry, tanpa duplikat dan tanpa error 500 |
| AC-16 | Given gateway tidak mengembalikan `usage` atau tidak mendukung streaming, then endpoint non-stream tetap bekerja (token `null`) dan body permintaan hanya berisi `model` dan `messages` kecuali ada konfigurasi tambahan |

### 12.2 Test baru (PHPUnit, mengikuti pola test existing)

- `tests/Feature/ChatSchemaTest.php`: relasi, cascade, unique `sequence`, factory `withMessages`.
- `tests/Feature/TranscriptRecorderTest.php`: AC-2 sampai AC-4, termasuk skenario model di-mock.
- `tests/Feature/ChatApiTest.php`: AC-5 sampai AC-8 (memakai `Sanctum::actingAs`, `Http::fake`).
- `tests/Feature/ChatHistoryQueryTest.php`: AC-9 (menghitung kueri dengan `DB::enableQueryLog`).
- `tests/Unit/ModelClientTest.php` (tambahan): `chat()`, penyaring `<think>`, pemetaan `usage`, fallback (AC-12) dengan `Http::fake`.
- Streaming: diuji pada level parser chunk; pengiriman SSE sungguhan diuji manual.

### 12.3 Definition of Done

Suite PHPUnit hijau; `cd worker && npm test` tidak terpengaruh; `npm run build` tetap berhasil; Pint psr12 bersih pada file baru/diubah; dokumentasi diperbarui; fitur yang belum teruji (fallback, streaming) ditandai eksplisit di dokumentasi.

---

## 13. Manifest file

**Baru:** `database/migrations/2026_11_15_000001_create_chat_tables.php` (sudah ada lokal); `app/Models/{ChatSession,ChatMessage,ToolExecutionLog}.php` (sudah ada lokal); `database/factories/{ChatSession,ChatMessage,ToolExecutionLog}Factory.php`; `database/seeders/ChatDemoSeeder.php`; `app/Services/TranscriptRecorder.php`; `app/Http/Requests/ChatRequest.php`; `app/Http/Resources/{ChatResource,ChatSessionResource}.php`; `app/Http/Controllers/Api/{ChatController,ChatSessionController}.php`; `app/Http/Controllers/ChatSessionPageController.php`; `app/Policies/ChatSessionPolicy.php`; `routes/api.php`; `resources/views/chat/show.blade.php`; test baru (§12.2); `docs/CHAT_API.md`.

**Diubah:** `app/Models/User.php` (`HasApiTokens`, `chatSessions()`), `app/Models/QaRun.php` (sudah lokal), `app/Services/ModelClient.php` (`chat()`, `stream()`, perekaman), `app/Services/QaAgent.php` (pemanggilan recorder), `app/Livewire/Dashboard.php` + `dashboard.blade.php` (panel token), `bootstrap/app.php` (`api`, normalisasi error), `routes/web.php` (halaman riwayat), `config/autoqa.php`, `config/database.php` (`busy_timeout`, `journal_mode`), `.env.example`, `database/seeders/DatabaseSeeder.php`, `composer.json`, `resources/lang/{en,id}/*.php`, `README.md`.

---

## 14. Risiko, asumsi, dan pertanyaan terbuka

| # | Risiko / pertanyaan | Mitigasi / status |
|---|---|---|
| R1 | Bentuk dasar API kampus kini diketahui (§3.13), tetapi streaming, `usage`, `response_format`, `/models`, dan kuota belum | Fitur lanjutan opsional lewat konfigurasi; fallback terisolasi, nonaktif default, ditandai belum teruji |
| R2 | Dosen mungkin mensyaratkan Breeze | Perlu konfirmasi; bila wajib, bangun di project terpisah |
| R3 | Test existing me-mock `ModelClient` | Recorder membuat pesan `system` pengganti (§8.4); test existing tidak diubah |
| R4 | Ukuran transkrip membengkak (snapshot per langkah) | Pemotongan 32 KB per pesan; konteks sudah dibatasi worker |
| R5 | Token streaming tidak tersedia di server | Kolom token boleh `null` |
| R6 | Server dev (`php artisan serve`) bisa menahan output sehingga streaming tidak terasa bertahap | Uji dan dokumentasikan; gunakan flush dan header anti-buffering |
| R7 | Qwen3 mengeluarkan `<think>` yang terpotong antar-chunk | Mesin state di penyaring streaming |
| R8 | Timeout 90 detik tidak cukup untuk model besar di CPU | Naikkan `MODEL_TIMEOUT`; dokumentasikan |
| R9 | Registrasi tertutup membatasi pembuatan akun anggota kelompok | Di luar lingkup; token dibuat per operator |
| R10 | Versi Sanctum untuk Laravel 13 | Verifikasi saat `install:api`; catat versi di PRD/README |
| R11 | Kolom morph tidak bisa FK | Dimitigasi dengan `chat_message_id` ber-FK (§8.2) |
| R12 | `decide()` selalu mengirim `response_format: json_object`; gateway kampus mungkin menolak atau mengabaikannya, sehingga run QA bisa gagal ("Model request failed") atau mengembalikan non-JSON | `MODEL_JSON_MODE` (FR-7.7); uji manual di Fase 0 |
| R13 | `health()` memanggil `GET /models` yang belum tentu ada di gateway, sehingga status bisa salah menampilkan "tidak tersedia" padahal chat berfungsi | FR-7.6 |
| R14 | Kunci API kampus berada di `.env` (sudah di `.gitignore`) | Jangan masukkan header `Authorization` ke `arguments`/`result`/log; contoh di dokumentasi memakai placeholder |
| R15 | Prompt dan konteks halaman target (bisa memuat data pribadi) dikirim ke server kampus | Dokumentasikan; gunakan target dan data uji, bukan data sungguhan |
| R16 | "database is locked" pada SQLite saat queue, API, dan streaming menulis bersamaan | WAL, `busy_timeout`, transaksi singkat (§8.8, FR-8.5, FR-5.5) |
| R17 | Latensi `qwen3:14b` lewat gateway lebih tinggi dari default `MODEL_TIMEOUT` repo (90 detik; 180 di `.env.example`) | Naikkan `MODEL_TIMEOUT`; dokumentasikan |
| A1 | Asumsi: satu operator menggunakan seluruh sistem; kapasitas SQLite memadai | |
| A2 | Asumsi: baseline test hijau | Diverifikasi pada Fase 0 |

---

## 15. Jejak ke rubrik penilaian

| Rubrik | Bobot | Dipenuhi oleh |
|---|---|---|
| Week 1 — Rancangan Skema & Migrasi | 35% | FR-1.1 s.d. FR-1.3, §8.2, AC-1 |
| Week 1 — Implementasi Model & ORM | 25% | FR-1.4, §8.1 |
| Week 1 — Optimasi Eager Loading | 20% | FR-4.2, FR-4.3, AC-9, AC-10 |
| Week 1 — Challenge Polymorphic | 10% | FR-1.3, §8.3 |
| Week 1 — PSR-12 | 10% | FR-8.2 |
| Week 2 — Fungsionalitas API LLM | 40% | FR-3.3, FR-3.5, FR-3.6, §9.1 |
| Week 2 — Sanctum dan validasi | 20% | FR-3.1 s.d. FR-3.4, AC-5, AC-6 |
| Week 2 — Logging dan riwayat DB | 20% | FR-1.2, FR-3.7, AC-7 |
| Week 2 — Challenge A+ | 10% | WS5, WS6, WS7 |
| Week 2 — Kerapian kode dan demo | 10% | FR-8.2, FR-8.4 |

---

## Lampiran A — Variabel lingkungan baru

| Variabel | Default | Keterangan |
|---|---|---|
| `CHAT_SYSTEM_PROMPT` | teks QA engineer | System prompt tema untuk endpoint chat |
| `CHAT_MODEL_TEMPERATURE` | kosong | Hanya endpoint chat; tidak dikirim bila kosong |
| `CHAT_MAX_TOKENS` | kosong | Batas token jawaban; tidak dikirim bila kosong |
| `CHAT_STREAM_USAGE` | `false` | Kirim `stream_options.include_usage` pada mode stream |
| `MODEL_JSON_MODE` | `true` | `false` mematikan `response_format: json_object` pada `decide()` |
| `CHAT_HISTORY_MESSAGES` | `10` | Jumlah pesan terakhir yang dikirim ke model |
| `CHAT_API_THROTTLE` | `20` | Permintaan per menit per user |
| `FALLBACK_ENABLED` | `false` | Mengaktifkan fallback |
| `FALLBACK_MODEL_URL` | kosong | Basis URL OpenAI-compatible |
| `FALLBACK_MODEL_NAME` | kosong | Nama model fallback |
| `FALLBACK_MODEL_API_KEY` | kosong | Kunci API fallback |

Variabel yang sudah ada (`MODEL_URL`, `MODEL_NAME`, `MODEL_TIMEOUT`, `MODEL_API_KEY`, dst.) tetap berlaku (§3.8).

**Profil A: Senopati sebagai utama (kondisi lokal pengguna)**

```dotenv
MODEL_URL=https://senopati.its.ac.id/llm-gateway/v1
MODEL_NAME=qwen3:14b
MODEL_API_KEY=<kunci dari kampus>
MODEL_TIMEOUT=180
FALLBACK_ENABLED=false
```

**Profil B: Ollama utama, Senopati fallback (Challenge 3)**

```dotenv
MODEL_URL=http://127.0.0.1:11434/v1
MODEL_NAME=qwen3:14b
FALLBACK_ENABLED=true
FALLBACK_MODEL_URL=https://senopati.its.ac.id/llm-gateway/v1
FALLBACK_MODEL_NAME=qwen3:14b
FALLBACK_MODEL_API_KEY=<kunci dari kampus>
```

Profil B mengharuskan model `qwen3:14b` tersedia di Ollama lokal. Kunci asli tidak boleh ditulis di dokumentasi atau di-commit.

## Lampiran B — Contoh isi `ChatSession` dari satu run (ilustrasi)

```
ChatSession #7 (user_id=1, qa_run_id=42)
 ├─ #1 system    "You are a web QA agent. ..."
 ├─ #2 user      {"goal": "...", "page": {...}}
 ├─ #3 assistant {"tool":"fill","id":"e1-3","value":"QA data"}   model=qwen3:1.7b
 │    └─ ToolExecutionLog(tool=fill, loggable=QaRun#42, status=succeeded)
 ├─ #4 user      {... "previous_actions": [...]}
 └─ #5 assistant {"tool":"assert_text","text":"Account created"}
      └─ ToolExecutionLog(tool=assert_text, loggable=QaRun#42, status=succeeded)
```