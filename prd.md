# Product Requirements Document (PRD): Lodis — Web Server & SSL Manager

- **Nama Produk:** Lodis
- **Versi:** 1.0
- **Arsitektur Deployment:** Single-Server (Website pengendali berada di mesin Windows yang sama dengan web server target)
- **Tema UI:** Minimalis Ungu & Putih (Purple & White)

---

## 1. Ringkasan Produk

**Lodis** adalah aplikasi *web control panel* ringan yang dirancang untuk administrator instansi guna memantau kesehatan server Windows, melakukan *refresh/restart* web server secara asinkron tanpa memutus respon halaman secara mendadak, memicu pembaruan sertifikat SSL melalui `win-acme`, serta mengirimkan pengingat (*reminder*) otomatis sebelum masa berlaku SSL berakhir.

### Tujuan Utama
1. Memungkinkan kontrol rutin web server dan pembaruan SSL secara jarak jauh melalui browser tanpa ketergantungan pada akses *Remote Desktop (RDP)*.
2. Mencegah *downtime* akibat SSL kedaluwarsa melalui pemantauan otomatis dan notifikasi proaktif.
3. Menyediakan mekanisme eksekusi perintah sistem yang ketat (*command whitelisting*), aman, dan tercatat lengkap dalam *audit log*.

---

## 2. Konfigurasi Lingkungan Server (Diisi Sebelum Implementasi)

Sesuaikan nilai di bawah ini dengan kondisi server instansi sebelum memulai pengembangan:

| Parameter | Nilai Target |
| :--- | :--- |
| **Sistem Operasi** | Windows / Windows Server |
| **Web Server Target** | `Laragon (Apache / Nginx)` |
| **Nama Service / Perintah Reload** | `Apache2.4` (atau graceful reload `httpd.exe -k restart` / `nginx -s reload`) |
| **Path Executable `win-acme`** | `C:\win-acme\wacs.exe` |
| **Nama Scheduled Task `win-acme`** | *(Opsional via `.env` `WIN_ACME_TASK_NAME`, default kosong)* |
| **Kanal Notifikasi Reminder** | `Keduanya (Telegram Bot API & SMTP Email)` |

---

## 3. Arsitektur & Tech Stack

| Lapisan | Teknologi | Peran & Alasan Pemilihan |
| :--- | :--- | :--- |
| **Backend** | Laravel 13 (PHP 8.3 / 8.4) | Arsitektur rapi, proteksi keamanan bawaan (CSRF, Auth, Rate Limiting), Artisan Command untuk penjadwalan, dan fungsi native OpenSSL. |
| **Frontend & Interaktivitas** | Vue js + Tailwind CSS + Alpine.js | Ringan dan mudah dirawat. Alpine.js mengelola *polling* status server (*auto-reconnect*), filter log, dan *state* antarmuka secara reaktif. |
| **Modal & Notifikasi UI** | SweetAlert2 | Konfirmasi aksi kritikal, modal *loading* dinamis saat proses *restart*, dan *toast notification*. |
| **Grafik & Ikon** | Chart.js & Lucide Icons | Visualisasi penggunaan CPU, RAM, Disk, dan ikon antarmuka modern. |
| **Database** | MySQL / MariaDB (atau SQLite) | Penyimpanan akun admin, daftar domain terpantau, pengaturan aplikasi, dan riwayat log eksekusi. |
| **Eksekutor & Penjadwal** | PowerShell (`.ps1`) + Windows Task Scheduler | Menjalankan proses *detached* dengan jeda 3 detik untuk *restart* web server, menjalankan `wacs.exe`, serta memicu pengecekan reminder harian. |
| **Notifikasi Eksternal** | Telegram Bot API & SMTP Email | Mengirim peringatan masa aktif SSL dan status kegagalan layanan. |

---

## 4. Panduan Desain UI/UX (Tema Ungu & Putih)

### Palet Warna
- **Latar Kanvas Utama:** `#F8F7FC` (Putih keunguan sangat lembut)
- **Kartu & Panel:** `#FFFFFF` (Putih bersih) dengan garis tepi `#EDE9FE`
- **Warna Primer (Tombol Utama, Sidebar Aktif, Aksen):** `#7C3AED` (*hover:* `#6D28D9`)
- **Aksen Lembut (Badge, Highlight):** `#EDE9FE` dan `#DDD6FE`
- **Teks Judul & Angka:** `#1E1B4B`
- **Teks Sekunder & Label:** `#64748B`
- **Warna Status Semantik:**
  - Online / Aman: `#10B981` (Hijau Emerald)
  - Peringatan SSL: `#F59E0B` (Kuning Amber)
  - Kritis / Gagal / Offline: `#EF4444` (Merah Rose)

### Standar Integrasi SweetAlert2
- **Tema Tombol:** Tombol konfirmasi menggunakan warna primer `#7C3AED` dan tombol batal `#E2E8F0` dengan teks gelap (`#1E1B4B`).
- **Dialog Konfirmasi:** Wajib muncul sebelum mengeksekusi *Refresh Web Server*, *Renew SSL*, dan hapus domain.
- **Modal Auto-Reconnect:** Saat *Refresh Web Server* dijalankan, SweetAlert2 menampilkan *loading spinner* berwarna ungu tanpa tombol tutup (`allowOutsideClick: false`) dengan pesan dinamis (*"Menunggu jeda eksekusi 3 detik..."* lalu *"Menghubungkan ulang ke server..."*) hingga server kembali merespon normal.
- **Toast Notification:** Muncul otomatis selama 3 detik di pojok kanan atas untuk aksi cepat (tambah/edit domain, scan SSL manual, tes kirim notifikasi).

---

## 5. Spesifikasi Fitur Fungsional

### 5.1 Autentikasi & Proteksi Akses
- Halaman login admin bertema kartu putih di atas latar gradasi ungu lembut.
- *Rate Limiting* pada form login (maksimal 5 percobaan gagal per menit per IP).
- **Middleware IP Whitelist (Opsional):** Membaca variabel `ADMIN_IP_WHITELIST` dari `.env` untuk membatasi akses hanya pada IP tertentu atau jaringan internal/VPN.

### 5.2 Dashboard Monitoring & Kesehatan Server
- **KPI Cards:** Menampilkan Status Web Server (Online/Offline), Total Domain Terpantau, Jumlah SSL Kritis (<= 7 hari), dan Status Aksi Terakhir.
- **Metrik Resource Server:** Membaca persentase penggunaan CPU, RAM, dan kapasitas penyimpanan Disk Windows melalui skrip PowerShell ringan yang di-*cache* selama 30 detik, ditampilkan dalam grafik Chart.js.
- **Quick Actions:** Tombol pintas untuk *Refresh Web Server*, *Renew SSL (`win-acme`)*, dan *Scan Ulang Semua SSL*.

### 5.3 Kontrol Web Server Asinkron (Jeda 3 Detik)
1. Admin menekan tombol **Refresh Web Server** dan menyetujui konfirmasi **SweetAlert2**.
2. Backend membuat entri baru pada tabel `server_action_logs` berstatus `queued`.
3. Backend memicu skrip `scripts/server-runner.ps1` secara *detached / non-blocking* (misal via `popen('start /B powershell ...', 'r')`) dengan parameter `-Action reload_webserver -LogId {id}`.
4. Backend langsung mengembalikan respon JSON HTTP `202 Accepted` berisi `log_id`.
5. Skrip PowerShell menjalankan `Start-Sleep -Seconds 3` agar respon HTTP selesai diterima browser dengan aman sebelum layanan web dimatikan/di-reload.
6. Setelah jeda selesai, PowerShell mengeksekusi perintah *reload/restart* sesuai konfigurasi, menangkap seluruh output `stdout` dan `stderr`, lalu mengirim hasil akhir ke endpoint internal `POST /api/internal/action-callback` (dilindungi header `X-Internal-Token`) untuk memperbarui status menjadi `success` atau `failed`.
7. Di sisi frontend, Alpine.js bersama SweetAlert2 melakukan *polling* ke `GET /api/health` setiap 2 detik hingga mendapat respon `200 OK`, kemudian menampilkan notifikasi berhasil dan memperbarui tabel riwayat log.

### 5.4 Monitor SSL Native PHP & Pembaruan (`win-acme`)
- **CRUD Domain:** Tambah, ubah, aktif/nonaktifkan, dan hapus domain yang dipantau beserta port (default `443`).
- **Inspeksi SSL Otomatis:** Service `SslCheckerService` menggunakan fungsi native PHP `stream_socket_client()` dan `openssl_x509_parse()` untuk mengambil `issuer`, `valid_from`, `expires_at`, dan menghitung `days_remaining`.
- **Indikator Warna Sisa Masa Aktif:**
  - **Hijau (`valid`):** sisa > 14 hari.
  - **Kuning (`warning`):** sisa 8–14 hari.
  - **Merah (`critical`):** sisa <= 7 hari atau sudah kedaluwarsa.
- **Eksekusi `win-acme`:** Tombol **Renew SSL** memicu `scripts/server-runner.ps1` dengan parameter `-Action renew_ssl -LogId {id}` untuk menjalankan `wacs.exe --renew` (atau memicu *Scheduled Task* milik `win-acme`). Setelah selesai, sistem otomatis memindai ulang data SSL seluruh domain.

### 5.5 Sistem Reminder Otomatis
- **Artisan Command:** `php artisan ssl:check-reminder` dijalankan harian melalui Windows Task Scheduler.
- **Aturan Pengiriman & Anti-Spam:** Jika `days_remaining` masuk dalam ambang batas peringatan (default: `14, 7, 3, 1` hari), sistem mengirim notifikasi terformat ke Telegram Bot API dan/atau Email. Kolom `last_reminded_at` diperiksa agar setiap domain maksimal menerima 1 notifikasi per hari.
- **Halaman Pengaturan (Settings):** Form untuk mengelola Token Bot Telegram, Chat ID, ambang batas hari pengingat, serta tombol **Tes Kirim Notifikasi** dengan umpan balik SweetAlert2.

### 5.6 Audit Log & Terminal Output Viewer
- Tabel riwayat eksekusi menampilkan waktu mulai, waktu selesai, admin eksekutor, jenis aksi, status (`queued`, `running`, `success`, `failed`), dan filter pencarian.
- Tombol **Lihat Output** membuka modal bergaya *console* gelap (*monospace*) untuk membaca output asli dari PowerShell dan `win-acme`.

---

## 6. Skema Database Inti

### Tabel `users`
- `id` (BIGINT, PK)
- `name` (VARCHAR)
- `email` (VARCHAR, UNIQUE)
- `password` (VARCHAR)
- `last_login_at` (TIMESTAMP, NULLABLE)
- `created_at`, `updated_at`

### Tabel `monitored_domains`
- `id` (BIGINT, PK)
- `domain_name` (VARCHAR, UNIQUE)
- `port` (INT, DEFAULT 443)
- `issuer` (VARCHAR, NULLABLE)
- `ssl_valid_from` (DATETIME, NULLABLE)
- `ssl_expires_at` (DATETIME, NULLABLE)
- `days_remaining` (INT, NULLABLE)
- `status` (ENUM: `valid`, `warning`, `critical`, `error`, DEFAULT `error`)
- `last_checked_at` (DATETIME, NULLABLE)
- `last_reminded_at` (DATETIME, NULLABLE)
- `is_active` (BOOLEAN, DEFAULT true)
- `created_at`, `updated_at`

### Tabel `server_action_logs`
- `id` (BIGINT, PK)
- `user_id` (BIGINT, FK -> `users.id`)
- `action_type` (ENUM: `reload_webserver`, `renew_ssl`)
- `status` (ENUM: `queued`, `running`, `success`, `failed`)
- `output` (LONGTEXT, NULLABLE)
- `started_at` (DATETIME, NULLABLE)
- `finished_at` (DATETIME, NULLABLE)
- `created_at`, `updated_at`

### Tabel `app_settings`
- `id` (BIGINT, PK)
- `key` (VARCHAR, UNIQUE)
- `value` (TEXT, NULLABLE)
- `created_at`, `updated_at`

---

## 7. Variabel Konfigurasi (`.env`)

| Variabel | Deskripsi |
| :--- | :--- |
| `INTERNAL_API_TOKEN` | Token rahasia untuk autentikasi callback dari PowerShell ke `/api/internal/action-callback` |
| `ADMIN_IP_WHITELIST` | Daftar IP yang diizinkan mengakses dashboard (dipisahkan koma; kosongkan untuk menonaktifkan) |
| `WEBSERVER_SERVICE_NAME` | Nama Windows Service atau perintah reload web server target |
| `WIN_ACME_PATH` | Path lengkap menuju file `wacs.exe` |
| `WIN_ACME_TASK_NAME` | Nama Windows Scheduled Task untuk `win-acme` (opsional) |
| `TELEGRAM_BOT_TOKEN` | Token Bot Telegram untuk pengiriman reminder |
| `TELEGRAM_CHAT_ID` | ID chat/grup tujuan notifikasi Telegram |
| `SSL_REMINDER_THRESHOLDS` | Ambang batas hari reminder, contoh: `14,7,3,1` |

---

## 8. Persyaratan Keamanan & Hak Akses Windows

1. **Command Whitelisting Ketat:** Backend dilarang menerima string perintah bebas dari request pengguna. Hanya kunci aksi statis (`reload_webserver` dan `renew_ssl`) yang diizinkan masuk ke `server-runner.ps1`.
2. **Validasi Parameter Ketat:** Parameter `-LogId` wajib divalidasi sebagai integer murni (`int`) sebelum diteruskan ke shell untuk mencegah *Command Injection*.
3. **Proteksi Endpoint Callback:** Endpoint `/api/internal/action-callback` hanya menerima request dari `127.0.0.1` / `::1` dengan header `X-Internal-Token` yang cocok dengan `.env`.
4. **Hak Akses Administrator Windows:** Dokumentasi instalasi (`SETUP_WINDOWS.md`) wajib menyediakan panduan konfigurasi **Windows Task Scheduler** dengan hak `SYSTEM` / *Run with highest privileges* untuk eksekusi *restart service*, `win-acme`, serta penjadwalan harian `ssl:check-reminder`, sehingga proses web server utama tidak perlu dijalankan dengan hak Administrator penuh.

---

## 9. Kriteria Penerimaan (Acceptance Criteria)

- [ ] Seluruh halaman menerapkan tema Ungu (`#7C3AED`) dan Putih (`#FFFFFF` / `#F8F7FC`) secara konsisten dan responsif.
- [ ] Seluruh aksi kritikal dilindungi konfirmasi SweetAlert2, dan aksi cepat menampilkan *toast* SweetAlert2.
- [ ] Klik tombol **Refresh Web Server** langsung mengembalikan respon `202 Accepted`, menjalankan jeda 3 detik di PowerShell, me-restart layanan, dan browser otomatis terhubung kembali melalui polling `/api/health`.
- [ ] Inspeksi SSL berhasil membaca masa berlaku sertifikat domain secara langsung via OpenSSL PHP dan menampilkan warna status sesuai sisa hari.
- [ ] Aksi **Renew SSL** mencatat output terminal `win-acme` secara lengkap ke tabel `server_action_logs` dan dapat dibaca lewat modal *Terminal Output Viewer*.
- [ ] Command `php artisan ssl:check-reminder` berhasil mengirim peringatan ke Telegram/Email tanpa mengirim spam berulang di hari yang sama.
- [ ] File `SETUP_WINDOWS.md` tersedia dan menjelaskan langkah instalasi serta konfigurasi izin Windows secara runtut.