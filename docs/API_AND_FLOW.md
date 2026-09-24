# 📖 Dokumentasi Lengkap & Standar Keamanan Digital Banking System

## 1. Arsitektur & Standar Keamanan Data Nasabah (Bank-Grade Security)
- **Backend Framework**: Laravel 11 (PHP 8.2+) + Laravel Sanctum (Token Session Management)
- **Database**: MySQL dengan Enkripsi Kolom Sensitif (PII - *Personally Identifiable Information*)
- **Enkripsi NIK**: **AES-256-CBC Encryption at Rest** menggunakan Laravel Cryptography (`'nik' => 'encrypted'`). NIK tersimpan secara teracak di database dan hanya didekripsi saat diakses oleh sistem berwenang.
- **Enkripsi Password & PIN**: **Bcrypt Hashing (12 Rounds)** dengan proteksi *salt* unik.
- **Perlindungan Brute-Force**: Pembatasan percobaan login & request transaksi melalui *rate limiting*.
- **Transaksi Finansial Atomic**: Eksekusi mutasi saldo menggunakan `DB::transaction()` & Pessimistic Locking (`lockForUpdate()`) untuk mencegah *double spending* dan *race conditions*.

---

## 2. Struktur Database (Schema)

### Tabel `users`
| Field | Tipe | Keterangan & Keamanan |
|---|---|---|
| `id` | BigInteger (PK) | ID Nasabah |
| `name` | String | Nama lengkap sesuai KTP WNI |
| `nik` | Text (**AES-256 Encrypted**) | 16 Digit NIK KTP Warga Negara Indonesia (Terenkripsi) |
| `email` | String (Unique) | Email nasabah terdaftar |
| `phone` | String (Unique) | Nomor HP / WhatsApp nasabah |
| `password` | String (**Bcrypt Hashed**) | Password akun |
| `pin` | String (**Bcrypt Hashed, Nullable**) | 6 Digit PIN Transaksi |

### Tabel `accounts`
| Field | Tipe | Keterangan |
|---|---|---|
| `id` | BigInteger (PK) | ID Rekening |
| `user_id` | Foreign Key (`users.id`) | Relasi nasabah (`onDelete cascade`) |
| `account_number` | String (Unique) | Format `880000000X` (Auto-generated) |
| `balance` | Decimal(15, 2) | Saldo aktif nasabah |

---

## 3. Alur Kerja Autentikasi & Login

### 🔐 1. Alur Login Otomatis Rekening Terkunci (*Locked Account Login*)
1. Saat nasabah telah terdaftar atau pernah login di perangkat:
   - Nomor Rekening (misal: `8800000006`) **otomatis terisi dan terkunci (*disabled & unclickable*)** di layar [LoginScreen.tsx](file:///Users/giovedrahmatprabowo/digital-banking-system/mobile/screens/LoginScreen.tsx).
   - Nasabah **hanya perlu memasukkan Kata Sandi** untuk masuk.
   - Terdapat opsi *"Ganti Akun"* jika nasabah ingin login ke akun lain.
2. API `POST /api/login` menerima `account_number` atau `email` + `password`.

---

### 🇮🇩 2. Alur Pendaftaran Rekening dengan Verifikasi NIK KTP WNI
1. Layanan pendaftaran di [RegisterScreen.tsx](file:///Users/giovedrahmatprabowo/digital-banking-system/mobile/screens/RegisterScreen.tsx):
   - **NIK (16 Digit)**: Divalidasi format angka KTP WNI dan dienkripsi AES-256 sebelum disimpan ke database.
   - **Full Name**: Nama lengkap sesuai KTP.
   - **Email & No HP**.
   - **Password & Confirm Password**.
   - **Persetujuan Syarat & Ketentuan (Checkbox)**.
2. Begitu pendaftaran berhasil:
   - Rekening langsung aktif dengan saldo awal **Rp 100.000**.
   - Nomor rekening otomatis tersimpan untuk login instan selanjutnya.
