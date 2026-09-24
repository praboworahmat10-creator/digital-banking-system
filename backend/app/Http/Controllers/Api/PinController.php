<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class PinController extends Controller
{
    /**
     * Cek apakah pengguna sudah memiliki PIN transaksi
     */
    public function status(Request $request)
    {
        $user = $request->user();

        return response()->json([
            'success' => true,
            'data' => [
                'has_pin' => !is_null($user->pin),
            ],
        ]);
    }

    /**
     * Membuat atau mengatur 6-digit PIN pertama kali
     */
    public function setup(Request $request)
    {
        $validated = $request->validate([
            'pin' => 'required|digits:6|confirmed',
        ]);

        $user = $request->user();
        $user->pin = Hash::make($validated['pin']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'PIN transaksi 6 digit berhasil dibuat!',
        ]);
    }

    /**
     * Mengubah PIN transaksi yang sudah ada
     */
    public function change(Request $request)
    {
        $validated = $request->validate([
            'current_pin' => 'required|digits:6',
            'new_pin' => 'required|digits:6|confirmed',
        ]);

        $user = $request->user();

        if (!$user->pin || !Hash::check($validated['current_pin'], $user->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN saat ini tidak sesuai.',
            ], 422);
        }

        $user->pin = Hash::make($validated['new_pin']);
        $user->save();

        return response()->json([
            'success' => true,
            'message' => 'PIN transaksi berhasil diperbarui!',
        ]);
    }
}
