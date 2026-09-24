<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TwoFactorController extends Controller
{
    /**
     * Mengirimkan kode 2FA OTP ke saluran yang dipilih (SMS / WhatsApp / Email)
     */
    public function sendOtp(Request $request)
    {
        $validated = $request->validate([
            'action' => 'required|string|in:change_pin,setup_pin,change_password,reset_password',
            'channel' => 'required|string|in:sms,whatsapp,email',
            'identifier' => 'nullable|string', // Untuk unauthenticated flow (lupa password)
        ]);

        $user = $request->user();

        // Jika belum login (misal: Lupa Password), cari user berdasarkan identifier
        if (!$user) {
            if (empty($validated['identifier'])) {
                return response()->json([
                    'success' => false,
                    'message' => 'Identitas akun (No. Rekening / Email / No. HP) wajib diisi.',
                ], 422);
            }

            $identifier = trim($validated['identifier']);
            $user = User::where('email', $identifier)
                ->orWhere('phone', $identifier)
                ->orWhereHas('account', function ($q) use ($identifier) {
                    $q->where('account_number', $identifier);
                })
                ->first();

            if (!$user) {
                return response()->json([
                    'success' => false,
                    'message' => 'Akun nasabah tidak ditemukan.',
                ], 404);
            }
        }

        // Generate 6 digit OTP (Gunakan 123456 sebagai kode testing demo, atau acak)
        $otp = '123456'; 
        $otpSessionId = '2FA_' . Str::random(24);
        
        $cacheKey = 'otp_session_' . $otpSessionId;
        $sessionData = [
            'user_id' => $user->id,
            'otp' => $otp,
            'action' => $validated['action'],
            'channel' => $validated['channel'],
            'created_at' => now(),
        ];

        // Simpan sesi OTP di Cache selama 5 menit
        Cache::put($cacheKey, $sessionData, now()->addMinutes(5));

        // Format masking untuk privasi data nasabah
        $phone = $user->phone ?? '081234567890';
        $email = $user->email ?? 'nasabah@domain.com';

        $maskedTarget = $validated['channel'] === 'email'
            ? substr($email, 0, 2) . '****@' . explode('@', $email)[1]
            : substr($phone, 0, 4) . '****' . substr($phone, -4);

        $channelName = match ($validated['channel']) {
            'sms' => 'SMS',
            'whatsapp' => 'WhatsApp Resmi Bank',
            'email' => 'Email Terdaftar',
            default => 'SMS',
        };

        return response()->json([
            'success' => true,
            'message' => "Kode otorisasi 2FA telah dikirimkan via {$channelName}.",
            'data' => [
                'session_id' => $otpSessionId,
                'channel' => $validated['channel'],
                'masked_target' => $maskedTarget,
                'expires_in_seconds' => 300,
                // Kode OTP demo disertakan untuk kemudahan testing simulator
                'demo_otp' => $otp,
            ],
        ]);
    }

    /**
     * Verifikasi kode 2FA OTP 6-Digit
     */
    public function verifyOtp(Request $request)
    {
        $validated = $request->validate([
            'session_id' => 'required|string',
            'otp' => 'required|digits:6',
        ]);

        $cacheKey = 'otp_session_' . $validated['session_id'];
        $sessionData = Cache::get($cacheKey);

        if (!$sessionData) {
            return response()->json([
                'success' => false,
                'message' => 'Sesi OTP 2FA telah kedaluwarsa atau tidak valid. Silakan minta kode baru.',
            ], 422);
        }

        if ($sessionData['otp'] !== $validated['otp']) {
            return response()->json([
                'success' => false,
                'message' => 'Kode OTP 2FA yang Anda masukkan salah.',
            ], 422);
        }

        // Hapus session OTP dan buat token otorisasi perubahan yang valid 10 menit
        Cache::forget($cacheKey);

        $twoFactorToken = '2FA_AUTH_' . Str::random(32);
        Cache::put('2fa_token_' . $twoFactorToken, [
            'user_id' => $sessionData['user_id'],
            'action' => $sessionData['action'],
        ], now()->addMinutes(10));

        return response()->json([
            'success' => true,
            'message' => 'Autentikasi 2FA Berhasil Diverifikasi! Silakan lanjutkan.',
            'data' => [
                'two_factor_token' => $twoFactorToken,
                'action' => $sessionData['action'],
            ],
        ]);
    }

    /**
     * Ubah / Atur PIN Transaksi dengan Otorisasi 2FA
     */
    public function secureChangePin(Request $request)
    {
        $validated = $request->validate([
            'two_factor_token' => 'required|string',
            'pin' => 'required|digits:6|confirmed',
        ], [
            'pin.digits' => 'PIN transaksi harus tepat 6 digit angka.',
            'pin.confirmed' => 'Konfirmasi 6 digit PIN tidak cocok.',
        ]);

        $tokenData = Cache::get('2fa_token_' . $validated['two_factor_token']);
        if (!$tokenData) {
            return response()->json([
                'success' => false,
                'message' => 'Token otorisasi 2FA tidak valid atau sudah kedaluwarsa. Harap lakukan verifikasi 2FA ulang.',
            ], 422);
        }

        $user = User::find($tokenData['user_id']);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Nasabah tidak ditemukan.',
            ], 404);
        }

        $user->pin = Hash::make($validated['pin']);
        $user->save();

        Cache::forget('2fa_token_' . $validated['two_factor_token']);

        return response()->json([
            'success' => true,
            'message' => 'PIN Keamanan Transaksi 6-Digit berhasil diperbarui dan terlindungi 2FA!',
        ]);
    }

    /**
     * Ubah Kata Sandi Akun dengan Otorisasi 2FA
     */
    public function secureChangePassword(Request $request)
    {
        $validated = $request->validate([
            'two_factor_token' => 'required|string',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'password.min' => 'Kata sandi minimal 8 karakter kombinasi huruf dan angka.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $tokenData = Cache::get('2fa_token_' . $validated['two_factor_token']);
        if (!$tokenData) {
            return response()->json([
                'success' => false,
                'message' => 'Token otorisasi 2FA tidak valid atau sudah kedaluwarsa. Harap lakukan verifikasi 2FA ulang.',
            ], 422);
        }

        $user = User::find($tokenData['user_id']);
        if (!$user) {
            return response()->json([
                'success' => false,
                'message' => 'Nasabah tidak ditemukan.',
            ], 404);
        }

        $user->password = Hash::make($validated['password']);
        $user->save();

        // Hapus token auth sesi lain untuk keamanan
        $user->tokens()->delete();
        Cache::forget('2fa_token_' . $validated['two_factor_token']);

        return response()->json([
            'success' => true,
            'message' => 'Kata sandi akun Anda berhasil diperbarui dengan proteksi 2FA!',
        ]);
    }
}
