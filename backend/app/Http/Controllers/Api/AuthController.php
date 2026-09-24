<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class AuthController extends Controller
{
    /**
     * Registrasi Pembukaan Rekening Nasabah Baru (dengan Verifikasi NIK KTP WNI)
     */
    public function register(Request $request)
    {
        $validated = $request->validate([
            'name' => 'required|string|max:255',
            'nik' => [
                'required',
                'string',
                'size:16',
                'regex:/^[1-9][0-9]{15}$/', // Format valid 16 Digit NIK KTP Indonesia
            ],
            'email' => 'required|string|email|max:255|unique:users,email',
            'phone' => 'required|string|max:20|unique:users,phone',
            'password' => 'required|string|min:8|confirmed',
        ], [
            'nik.regex' => 'Format NIK harus berupa 16 digit angka yang valid sesuai KTP WNI.',
            'nik.size' => 'NIK harus tepat terdiri dari 16 digit angka.',
            'email.unique' => 'Alamat email ini sudah terdaftar sebagai nasabah.',
            'phone.unique' => 'Nomor handphone ini sudah terdaftar sebagai nasabah.',
            'password.min' => 'Kata sandi minimal terdiri dari 8 karakter.',
            'password.confirmed' => 'Konfirmasi kata sandi tidak cocok.',
        ]);

        $user = User::create([
            'name' => $validated['name'],
            'nik' => $validated['nik'], // Disimpan secara otomatis dengan enkripsi AES-256
            'email' => $validated['email'],
            'phone' => $validated['phone'],
            'password' => Hash::make($validated['password']),
        ]);

        $account = $user->account()->create([
            'account_number' => '88' . str_pad($user->id, 8, '0', STR_PAD_LEFT),
            'balance' => 100000,
        ]);

        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Pembukaan rekening berhasil! Saldo awal Rp 100.000 telah aktif.',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'account' => [
                    'account_number' => $account->account_number,
                    'balance' => (float) $account->balance,
                ],
                'token' => $token,
            ],
        ], 201);
    }

    /**
     * Autentikasi Login Nasabah (Mendukung No. Rekening ataupun Email)
     */
    public function login(Request $request)
    {
        $validated = $request->validate([
            'account_number' => 'nullable|string',
            'email' => 'nullable|string',
            'password' => 'required|string',
        ]);

        $user = null;

        // Cek login via Nomor Rekening
        if (!empty($validated['account_number'])) {
            $account = Account::with('user')->where('account_number', $validated['account_number'])->first();
            if ($account) {
                $user = $account->user;
            }
        } 
        // Cek login via Email
        elseif (!empty($validated['email'])) {
            $user = User::with('account')->where('email', $validated['email'])->first();
        }

        if (!$user || !Hash::check($validated['password'], $user->password)) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor rekening / email atau kata sandi tidak sesuai.',
            ], 422);
        }

        // Hapus token lama untuk keamanan single-device session
        $user->tokens()->delete();
        $token = $user->createToken('auth_token')->plainTextToken;

        return response()->json([
            'success' => true,
            'message' => 'Login berhasil',
            'data' => [
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'phone' => $user->phone,
                ],
                'account' => [
                    'account_number' => $user->account->account_number ?? null,
                    'balance' => (float) ($user->account->balance ?? 0),
                ],
                'token' => $token,
            ],
        ]);
    }

    /**
     * Logout Sesi Nasabah
     */
    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'success' => true,
            'message' => 'Sesi login berhasil diakhiri.',
        ]);
    }
}