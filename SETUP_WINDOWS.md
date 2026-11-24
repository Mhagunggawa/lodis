# Panduan Instalasi & Konfigurasi Windows Server / Laragon (`SETUP_WINDOWS.md`)

Dokumen ini menjelaskan cara menyiapkan **Lodis — Web Server & SSL Manager** pada satu server Windows (**Single-Server Deployment**) yang menggunakan **Laragon (Apache / Nginx)** dan **`win-acme` (`wacs.exe`)**.

> [!IMPORTANT]
> **Arsitektur Bebas Blokir Antivirus (Avast Behavior Shield Safe):**
> Aplikasi PHP **tidak pernah** memanggil `popen()`, `exec()`, `shell_exec()`, atau `cmd.exe` secara langsung sehingga aman dari blokir *Avast Behavior Shield (`IDP.Generic - Command line detection`)*.
> Semua aksi **Refresh Web Server** (`reload_webserver`) dan **Renew SSL** (`renew_ssl`) menggunakan pola **Queue Worker** melalui `storage/app/lodis-queue.json` & database `server_action_logs` yang dieksekusi oleh daemon latar belakang `scripts/lodis-worker.ps1` setiap 3 detik.

---

## 1. Prasyarat Lingkungan Server

1. **OS:** Windows Server (2016/2019/2022/2025) atau Windows 10/11 Pro.
2. **Environment Web Server:** Laragon dengan PHP 8.3 / 8.4 (ekstensi `openssl`, `pdo_mysql`, `curl`, `mbstring` aktif) & MySQL/MariaDB.
3. **Path Project:** `C:\laragon\www\lodis`
4. **Aplikasi `win-acme`:** Terpasang di `C:\win-acme\wacs.exe`

---

## 2. Langkah Instalasi Aplikasi

Buka terminal **PowerShell** di direktori `C:\laragon\www\lodis`:

```powershell
# 1. Salin file konfigurasi environment
Copy-Item .env.example .env

# 2. Instal dependensi PHP & Node.js
composer install
npm install

# 3. Generate application key & jalankan migrasi beserta akun admin default
php artisan key:generate
php artisan migrate --seed

# 4. Build aset frontend (Vue 3 + Tailwind CSS + Chart.js + SweetAlert2)
npm run build
```

### Akun Login Admin Default
- **URL Dashboard:** `http://localhost:8000/login` (atau virtual host Laragon Anda, misal `http://lodis.test/login`)
- **Email:** `admin@lodis.local`
- **Password:** `password`

---

## 3. Mendaftarkan Background Worker (`scripts/lodis-worker.ps1`) di Windows Task Scheduler (WAJIB)

Agar tombol **Refresh Web Server** dan **Renew SSL** langsung dieksekusi setiap 3 detik di latar belakang tanpa memicu deteksi antivirus pada PHP/Apache, daftarkan `scripts/lodis-worker.ps1` ke **Windows Task Scheduler** untuk berjalan otomatis saat *Startup* dengan hak `SYSTEM`.

### Perintah PowerShell Pendaftaran `lodis-worker.ps1` (Run as Administrator)

Buka **PowerShell sebagai Administrator**, lalu salin dan jalankan perintah berikut:

```powershell
$WorkerScript = "C:\laragon\www\lodis\scripts\lodis-worker.ps1"
$WorkingDir   = "C:\laragon\www\lodis"

$Action = New-ScheduledTaskAction `
    -Execute "powershell.exe" `
    -Argument "-NoProfile -NonInteractive -WindowStyle Hidden -ExecutionPolicy Bypass -File `"$WorkerScript`"" `
    -WorkingDirectory $WorkingDir

# Berjalan otomatis saat Windows Startup
$Trigger = New-ScheduledTaskTrigger -AtStartup

# Berjalan di bawah akun SYSTEM dengan hak tertinggi (Highest Privileges)
$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

# Izinkan worker berjalan terus-menerus (daemon tanpa batas waktu) dan otomatis restart jika berhenti
$Settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -MultipleInstances IgnoreNew

Register-ScheduledTask `
    -TaskName "Lodis-Background-Worker" `
    -Action $Action `
    -Trigger $Trigger `
    -Principal $Principal `
    -Settings $Settings `
    -Description "Background Queue Worker Lodis (mengecek antrean reload_webserver & renew_ssl setiap 3 detik tanpa PHP cmd.exe)" `
    -Force

# Langsung jalankan worker sekarang juga tanpa perlu reboot server
Start-ScheduledTask -TaskName "Lodis-Background-Worker"
```

### Memeriksa Status atau Menjalankan Worker Secara Manual

- **Cek Status Task Scheduler:**
  ```powershell
  Get-ScheduledTask -TaskName "Lodis-Background-Worker" | Get-ScheduledTaskInfo
  ```
- **Menjalankan 1 Siklus Antrean Secara Manual (Testing):**
  ```powershell
  powershell.exe -NoProfile -ExecutionPolicy Bypass -File "C:\laragon\www\lodis\scripts\lodis-worker.ps1" -RunOnce
  ```

---

## 4. Konfigurasi Windows Task Scheduler untuk Reminder SSL Harian

Agar perintah `php artisan ssl:check-reminder` berjalan otomatis setiap hari pukul 08:00 pagi untuk memeriksa masa aktif sertifikat SSL dan mengirim notifikasi ke **Telegram Bot API** & **Email SMTP**:

### Perintah PowerShell (Run as Administrator)

```powershell
$PhpPath    = "C:\laragon\bin\php\php-8.4.14-Win32-vs17-x64\php.exe" # Sesuaikan dengan folder versi PHP Laragon Anda
$ProjectDir = "C:\laragon\www\lodis"

$Action = New-ScheduledTaskAction `
    -Execute $PhpPath `
    -Argument "artisan ssl:check-reminder" `
    -WorkingDirectory $ProjectDir

$Trigger = New-ScheduledTaskTrigger -Daily -At "08:00AM"

$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

Register-ScheduledTask `
    -TaskName "Lodis-SSL-Check-Reminder" `
    -Action $Action `
    -Trigger $Trigger `
    -Principal $Principal `
    -Description "Pengecekan masa berlaku SSL harian & pengiriman reminder Telegram/Email oleh Lodis" `
    -Force
```

---

## 5. Cara Kerja Alur Queue Worker (Tanpa `popen` / `cmd.exe` dari PHP)

1. **Admin Klik Tombol di Dashboard:**
   - Saat tombol **Refresh Web Server** atau **Renew SSL** diklik, `ServerActionService::dispatchAction()` di PHP hanya membuat baris baru pada tabel `server_action_logs` berstatus `queued` dan menulis payload antrean ke `storage/app/lodis-queue.json`.
   - PHP langsung mengembalikan HTTP `202 Accepted` berisi `log_id` tanpa menyentuh `cmd.exe` atau `powershell.exe`.
2. **Polling 3 Detik oleh `scripts/lodis-worker.ps1`:**
   - Proses `lodis-worker.ps1` yang sudah berjalan di latar belakang mengecek `storage/app/lodis-queue.json` (dan fallback endpoint `GET /api/internal/pending-actions`) setiap 3 detik.
   - Worker memvalidasi kunci aksi berdasarkan **Command Whitelist** (`reload_webserver` atau `renew_ssl`).
   - Untuk `reload_webserver`, worker memvalidasi konfigurasi Apache Laragon (`httpd.exe -t`) menggunakan `.NET Diagnostics.Process` (tanpa `cmd.exe`), menghentikan PID `httpd.exe` lama, dan menyalakan kembali daemon `httpd.exe` baru.
   - Untuk `renew_ssl`, worker mengeksekusi `C:\win-acme\wacs.exe --renew` (atau memicu Scheduled Task `WIN_ACME_TASK_NAME`).
3. **Callback & Auto-Reconnect Frontend:**
   - Worker mengirimkan hasil `stdout`/`stderr` dan status akhir (`success` / `failed`) ke `POST /api/internal/action-callback` dengan header `X-Internal-Token`.
   - Modal **SweetAlert2** di browser melakukan polling ke `GET /api/health` dan `/server-actions/{id}/status` hingga selesai, lalu memperbarui tabel **Audit Log**.
