# BinoExam Backend API (Laravel)

Backend REST API untuk sistem ujian **BinoExam** (autentikasi multi-sekolah, manajemen akademik Admin, bank soal & ujian Guru, serta pengerjaan & sinkronisasi *Offline-First* Siswa) yang dibangun menggunakan **Laravel 13** dan **Laravel Sanctum**.

---

## 1. Prerequisites

- **PHP >= 8.3** (ekstensi wajib: `mbstring`, `openssl`, `pdo_mysql`, `tokenizer`, `xml`, `ctype`, `json`, `bcmath`, `fileinfo`, `zip`, serta disarankan mengaktifkan `opcache`)
- **Composer**: https://getcomposer.org/download/
- **MySQL / MariaDB** (bisa lewat Laragon, XAMPP, atau MySQL Server mandiri pada port `3306`)
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
4. Generate application key dan buat symbolic link storage (untuk gambar/media soal):
   ```powershell
   php artisan key:generate
   php artisan storage:link
   ```
5. Buat database kosong di MySQL (misal bernama `binoexam`), lalu jalankan migrasi & seeder demo SMK:
   ```powershell
   php artisan migrate
   php artisan db:seed --class=SmkDemoSeeder
   ```
   *(Jika ingin reset database dari awal: `php artisan migrate:fresh; php artisan db:seed --class=SmkDemoSeeder`.)*

   **Kredensial Default Hasil Seeder:**
   - **NPSN Sekolah**: `20201234`
   - **Role Admin**:
     - Username: `admin`
     - Password: `password`
   - **Role Guru** (Contoh: `GR-001` s/d `GR-005`):
     - Username: `GR-001`
     - Password: `password`
   - **Role Siswa** (Contoh: `SW-0001` s/d `SW-0020`):
     - Username: `0082000001` 
     - Password: `password`

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

- Gunakan `DB_HOST=127.0.0.1` (bukan `localhost`) di Windows agar tidak terjadi jeda resolusi IPv6.
- Sesuaikan `APP_TIMEZONE` dengan zona waktu sekolah agar seluruh jadwal ujian (`starts_at`, `ends_at`) akurat:
  - **WIB (UTC+7):** `APP_TIMEZONE=Asia/Jakarta`
  - **WITA (UTC+8):** `APP_TIMEZONE=Asia/Makassar`
  - **WIT (UTC+9):** `APP_TIMEZONE=Asia/Jayapura`
  *(Setelah mengubah `.env`, jalankan `php artisan optimize:clear` atau `php artisan optimize`.)*

---

## 4. Mode Lokal vs Produksi

### A. Mode Lokal / Server LAN Sekolah
- Agar bisa diakses dari HP/laptop lain dalam satu jaringan Wi-Fi/LAN:
  ```powershell
  php artisan serve --host=0.0.0.0 --port=8000
  ```
- Jika hanya diakses dari komputer yang sama:
  ```powershell
  php artisan serve
  ```

### B. Mode Online / Produksi (VPS)
1. Pastikan versi PHP di server sesuai dengan `composer.json` (**PHP 8.3+**).
2. Ubah `.env` menjadi mode produksi (isi `APP_URL` dengan domain root backend, **tanpa** akhiran `/api`):
   ```env
   APP_ENV=production
   APP_DEBUG=false
   APP_URL=https://api.domainanda.com
   ```
3. Jalankan symbolic link storage dan optimasi cache Laravel:
   ```bash
   php artisan storage:link
   php artisan optimize
   ```
4. Arahkan document root web server produksi (**Nginx / Apache + PHP-FPM**) ke folder `dashboard/be/public`.

---

## 5. Optimasi Performa & Troubleshooting

- **API terasa lambat (~500 ms per request) di Windows (`php artisan serve`)**:
  1. Aktifkan **OPcache** untuk CLI pada `php.ini` yang sedang dipakai (cek lokasinya dengan `php --ini`), lalu hilangkan tanda `;` dan atur nilainya menjadi:
     ```ini
     zend_extension=opcache
     opcache.enable=1
     opcache.enable_cli=1
     ```
  2. Jalankan `php artisan optimize` di folder `dashboard/be`.
  3. Tambahkan folder proyek ke *Exclusion* Windows Defender agar folder `vendor/` tidak dipindai berulang kali setiap request.
  4. Untuk beban paralel yang lebih tinggi di lokal Windows, gunakan **Nginx/Apache bawaan Laragon** dibandingkan `php artisan serve` (yang bersifat *single-threaded*).
- **Error `SQLSTATE[HY000] [2002] No connection could be made... port 3306`**:
  Pastikan layanan **MySQL** di Laragon/XAMPP sudah dijalankan terlebih dahulu.

---

## 6. Menjalankan Pengujian

```powershell
php artisan test
```
