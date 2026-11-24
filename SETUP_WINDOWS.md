# Panduan Instalasi & Konfigurasi Windows Server / Laragon (`SETUP_WINDOWS.md`)

Dokumen ini menjelaskan cara menyiapkan **Lodis — Web Server & SSL Manager** pada satu server Windows (**Single-Server Deployment**) yang menggunakan **Laragon (Apache / Nginx)** dan **`win-acme` (`wacs.exe`)**, termasuk konfigurasi izin Administrator (`SYSTEM`) melalui **Windows Task Scheduler** agar proses *restart/reload* web server dan pembaruan SSL berjalan tanpa kendala *Permission Denied*.

---

## 1. Prasyarat Lingkungan Server

1. **OS:** Windows Server (2016/2019/2022/2025) atau Windows 10/11 Pro.
2. **Environment Web Server:** Laragon dengan PHP 8.3 / 8.4 (ekstensi `openssl`, `pdo_mysql`, `curl`, `mbstring` aktif) & MySQL/MariaDB.
3. **Path Project:** `C:\laragon\www\lodis`
4. **Aplikasi `win-acme`:** Terpasang di `C:\win-acme\wacs.exe`

---

## 2. Langkah Instalasi Aplikasi

Buka terminal **PowerShell** atau **Laragon Terminal** di direktori `C:\laragon\www\lodis`:

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
- **URL Dashboard:** `http://localhost:8000/login` (atau virtual host Laragon Anda, misal `https://lodis.test/login`)
- **Email:** `admin@lodis.local`
- **Password:** `password`

---

## 3. Konfigurasi Variabel `.env`

Pastikan bagian konfigurasi Lodis di file `.env` telah disesuaikan dengan lingkungan server Anda:

```dotenv
INTERNAL_API_TOKEN=lodis-internal-secret-token-change-me
ADMIN_IP_WHITELIST=
WEBSERVER_TARGET="Laragon (Apache / Nginx)"
WEBSERVER_SERVICE_NAME=Apache2.4
WIN_ACME_PATH=C:\win-acme\wacs.exe
WIN_ACME_TASK_NAME=
TELEGRAM_BOT_TOKEN=123456789:ABCDEF...
TELEGRAM_CHAT_ID=-100123456789
SSL_NOTIFICATION_CHANNEL=both
SSL_NOTIFICATION_EMAIL=admin@lodis.local
SSL_REMINDER_THRESHOLDS=14,7,3,1
```

> **Catatan Keamanan IP Whitelist:**
> Jika Anda ingin membatasi akses dashboard hanya dari IP localhost dan jaringan VPN internal, isi `ADMIN_IP_WHITELIST=127.0.0.1,::1,10.8.0.5`. Kosongkan nilai tersebut untuk mengizinkan akses dari semua IP.

---

## 4. Konfigurasi Windows Task Scheduler untuk Reminder SSL Harian

Agar perintah `php artisan ssl:check-reminder` berjalan otomatis setiap hari untuk memeriksa masa aktif sertifikat SSL dan mengirim notifikasi ke **Telegram Bot API** & **Email SMTP**:

### Cara Cepat via PowerShell (Run as Administrator)

Buka **PowerShell sebagai Administrator**, lalu jalankan perintah berikut:

```powershell
$PhpPath = "C:\laragon\bin\php\php-8.4.5-Win32-vs17-x64\php.exe" # Sesuaikan dengan folder versi PHP Laragon Anda
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
    -Description "Pengecekan masa berlaku SSL harian & pengiriman reminder Telegram/Email oleh Lodis"
```

### Cara Manual via GUI Windows Task Scheduler
1. Buka **Task Scheduler** (`taskschd.msc`).
2. Klik **Create Task...** (bukan Basic Task).
3. Pada tab **General**:
   - **Name:** `Lodis-SSL-Check-Reminder`
   - Pilih **Run whether user is logged on or not**.
   - Centang **Run with highest privileges**.
   - Klik **Change User or Group...** -> ketik `SYSTEM` -> **OK**.
4. Pada tab **Triggers**:
   - Klik **New...** -> Pilih **Daily** pukul `08:00:00` -> **OK**.
5. Pada tab **Actions**:
   - Klik **New...** -> Action: **Start a program**
   - **Program/script:** `C:\laragon\bin\php\php-8.4.x\php.exe` (path lengkap `php.exe` di Laragon)
   - **Add arguments:** `artisan ssl:check-reminder`
   - **Start in:** `C:\laragon\www\lodis`
6. Klik **OK** dan simpan. Untuk menguji langsung, klik kanan pada task tersebut lalu pilih **Run**.

---

## 5. Konfigurasi Hak Akses Administrator (`SYSTEM`) untuk `win-acme` & Restart Service

Secara default, proses web server (Apache/Nginx) yang berjalan di bawah user biasa mungkin tidak memiliki izin Administrator penuh untuk mengakses *Windows Certificate Store* atau me-restart Windows Service (`Apache2.4` / `W3SVC`).

Untuk menjaga keamanan tanpa harus menjalankan seluruh web server sebagai Administrator penuh, gunakan metode **Delegasi Scheduled Task (`SYSTEM`)** berikut:

### 5.1 Mendaftarkan Scheduled Task `win-acme` dengan Hak `SYSTEM`

Jalankan di **PowerShell (Run as Administrator)**:

```powershell
$Action = New-ScheduledTaskAction `
    -Execute "C:\win-acme\wacs.exe" `
    -Argument "--renew --baseuri `"https://acme-v02.api.letsencrypt.org/`"" `
    -WorkingDirectory "C:\win-acme"

$Principal = New-ScheduledTaskPrincipal `
    -UserId "SYSTEM" `
    -LogonType ServiceAccount `
    -RunLevel Highest

Register-ScheduledTask `
    -TaskName "win-acme-renew" `
    -Action $Action `
    -Principal $Principal `
    -Description "Pembaruan sertifikat SSL win-acme dengan hak SYSTEM untuk Lodis"
```

Setelah task `win-acme-renew` terdaftar:
1. Isi `WIN_ACME_TASK_NAME=win-acme-renew` pada file `.env` (atau melalui menu **Pengaturan & Reminder** di dashboard Lodis).
2. Saat tombol **Renew SSL (win-acme)** diklik di dashboard, skrip `scripts/server-runner.ps1` akan memicu `schtasks.exe /Run /TN "win-acme-renew"`, atau jika `WIN_ACME_TASK_NAME` dikosongkan, langsung mengeksekusi `C:\win-acme\wacs.exe --renew`.

### 5.2 Izin Reload Web Server Laragon (`scripts/server-runne

r.ps1`)

Skrip `scripts/server-runner.ps1` dirancang khusus untuk arsitektur **Single-Server Laragon**:
1. **Jeda Asinkron 3 Detik (`Start-Sleep -Seconds 3`):** Saat admin menekan tombol **Refresh Web Server**, Laravel mengembalikan HTTP `202 Accepted` seketika, sementara proses PowerShell menunggu 3 detik sebelum me-reload Apache/Nginx.
2. **Deteksi Otomatis Laragon & Windows Service:**
   - Jika `Apache2.4` terdaftar sebagai Windows Service, skrip menjalankan `Restart-Service -Name Apache2.4 -Force`.
   - Jika berjalan sebagai proses native Laragon (`httpd.exe` atau `nginx.exe`), skrip mengirim sinyal *graceful restart/reload* (`httpd.exe -k restart` atau `nginx.exe -s reload`) sehingga sesi pengguna tidak terputus secara kasar.
3. **Auto-Reconnect Frontend:** Modal **SweetAlert2** pada browser otomatis melakukan *polling* ke `GET /api/health` setiap 2 detik hingga server kembali merespon `200 OK`, lalu memperbarui tabel **Audit Log**.
