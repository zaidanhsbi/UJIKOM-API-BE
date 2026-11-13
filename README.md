# BinoExam Backend API (Laravel)

Backend REST API untuk sistem ujian **BinoExam** (autentikasi multi-sekolah, manajemen akademik Admin, bank soal & ujian Guru, serta pengerjaan & sinkronisasi semi-offline Siswa).

---

## 1. Prerequisites (Yang Harus Di-download)

- **PHP >= 8.2** (ekstensi wajib: `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `gd`/`zip`)
- **Composer**: https://getcomposer.org/download/
- **MySQL / MariaDB** (bisa lewat Laragon, XAMPP, atau MySQL Server mandiri)
- **Git**: https://git-scm.com/downloads

Cek instalasi:
```powershell
php -v
composer -V
```

---

## 2. Langkah-langkah Instalasi

1. Masuk ke folder `dashboard/be`:
   ```powershell
   cd dashboard/be
   ```
2. Install dependency PHP:
   ```powershell
   composer install
   ```
3. Salin file konfigurasi environment:
   ```powershell
   copy .env.example .env
   ```
4. Generate application key:
   ```powershell
   php artisan key:generate
   ```
5. Buat database kosong di MySQL (misal bernama `binoexam`), lalu jalankan migrasi & seeder demo SMK:
   ```powershell
   php artisan migrate
   php artisan db:seed --class=SmkDemoSeeder
   ```
   *(Jika ingin reset database dari awal: `php artisan migrate:fresh; php artisan db:seed --class=SmkDemoSeeder`.)*

---

## 3. Konfigurasi (`.env`)

Sesuaikan bagian berikut di `dashboard/be/.env`:

```env
APP_NAME=BinoExam
APP_ENV=local
APP_DEBUG=true
APP_TIMEZONE=Asia/Jakarta
APP_URL=http://127.0.0.1:8000

DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=binoexam
DB_USERNAME=root
DB_PASSWORD=
```

- Sesuaikan `APP_TIMEZONE` dengan zona waktu sekolah agar seluruh jadwal ujian (`starts_at`, `ends_at`) akurat:
  - **WIB (UTC+7):** `APP_TIMEZONE=Asia/Jakarta`
  - **WITA (UTC+8):** `APP_TIMEZONE=Asia/Makassar`
  - **WIT (UTC+9):** `APP_TIMEZONE=Asia/Jayapura`
  *(Setelah mengubah `.env` di mode produksi, jalankan `php artisan config:clear` atau `php artisan config:cache`.)*

---

## 4. Mode Offline (Lokal) vs Online (Produksi)

### A. Mode Offline / Server Lokal Sekolah
- Jalankan server agar bisa diakses dari HP/laptop lain dalam satu jaringan Wi-Fi/LAN:
  ```powershell
  php artisan serve --host=0.0.0.0 --port=8000
  ```
- Jika hanya diakses dari laptop yang sama:
  ```powershell
  php artisan serve
  ```

### B. Mode Online / Produksi
1. Ubah `.env` menjadi mode produksi:
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://api.domainanda.com/api
   ```
2. Optimalkan cache konfigurasi & route:
   ```powershell
   php artisan config:cache
   php artisan route:cache
   php artisan view:cache
   ```
3. Jalankan di web server produksi (**Nginx / Apache + PHP-FPM**) yang mengarah ke folder `dashboard/be/public`.

---

## 5. Menjalankan Pengujian

```powershell
php artisan test
```
