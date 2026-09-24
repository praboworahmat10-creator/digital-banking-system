<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use App\Models\Withdrawal;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class WithdrawalController extends Controller
{
    /**
     * Mengajukan kode tarik tunai tanpa kartu di ATM
     */
    public function requestWithdrawal(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:50000',
            'pin' => 'required|digits:6',
        ]);

        $user = $request->user();
        $account = $user->account;

        if (!$user->pin) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum membuat PIN transaksi. Silakan buat PIN terlebih dahulu.',
            ], 422);
        }

        if (!Hash::check($validated['pin'], $user->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN transaksi yang Anda masukkan salah.',
            ], 422);
        }

        $amount = (float) $validated['amount'];

        if ((float) $account->balance < $amount) {
            return response()->json([
                'success' => false,
                'message' => 'Saldo Anda tidak mencukupi untuk melakukan tarik tunai ini.',
            ], 422);
        }

        // Buat 6-digit kode tarik tunai unik yang aktif selama 30 menit
        $withdrawalCode = (string) random_int(100000, 999999);
        $expiresAt = now()->addMinutes(30);

        $withdrawal = Withdrawal::create([
            'account_id' => $account->id,
            'withdrawal_code' => $withdrawalCode,
            'amount' => $amount,
            'status' => 'pending',
            'expires_at' => $expiresAt,
        ]);

        return response()->json([
            'success' => true,
            'message' => 'Kode tarik tunai berhasil dibuat! Gunakan kode ini di ATM terdekat dalam 30 menit.',
            'data' => [
                'id' => $withdrawal->id,
                'withdrawal_code' => $withdrawal->withdrawal_code,
                'amount' => $amount,
                'expires_at' => $expiresAt->format('Y-m-d H:i:s'),
                'expires_in_minutes' => 30,
            ],
        ]);
    }

    /**
     * Ambil kode tarik tunai aktif milik user
     */
    public function getActive(Request $request)
    {
        $account = $request->user()->account;

        $activeWithdrawal = Withdrawal::where('account_id', $account->id)
            ->where('status', 'pending')
            ->where('expires_at', '>', now())
            ->orderBy('created_at', 'desc')
            ->first();

        return response()->json([
            'success' => true,
            'data' => $activeWithdrawal,
        ]);
    }

    /**
     * Simulasi pengambilan uang di ATM (Claim Tarik Tunai)
     */
    public function claim(Request $request)
    {
        $validated = $request->validate([
            'withdrawal_code' => 'required|string|size:6',
        ]);

        $withdrawal = Withdrawal::with('account.user')
            ->where('withdrawal_code', $validated['withdrawal_code'])
            ->where('status', 'pending')
            ->first();

        if (!$withdrawal) {
            return response()->json([
                'success' => false,
                'message' => 'Kode tarik tunai tidak valid atau sudah digunakan.',
            ], 404);
        }

        if (now()->greaterThan($withdrawal->expires_at)) {
            $withdrawal->status = 'expired';
            $withdrawal->save();

            return response()->json([
                'success' => false,
                'message' => 'Kode tarik tunai telah kedaluwarsa.',
            ], 422);
        }

        try {
            DB::transaction(function () use ($withdrawal) {
                $account = Account::where('id', $withdrawal->account_id)->lockForUpdate()->first();

                if ((float) $account->balance < (float) $withdrawal->amount) {
                    throw new \Exception('Saldo rekening tidak mencukupi saat proses penarikan di ATM.');
                }

                // Potong saldo
                $account->balance -= (float) $withdrawal->amount;
                $account->save();

                // Ubah status withdrawal
                $withdrawal->status = 'completed';
                $withdrawal->save();

                // Catat mutasi transaksi
                Transaction::create([
                    'reference_number' => 'WD' . date('YmdHis') . strtoupper(Str::random(4)),
                    'sender_account_id' => $account->id,
                    'recipient_account_id' => null,
                    'amount' => (float) $withdrawal->amount,
                    'type' => 'withdrawal',
                    'description' => 'Tarik Tunai ATM Tanpa Kartu (' . $withdrawal->withdrawal_code . ')',
                    'status' => 'success',
                ]);
            });

            return response()->json([
                'success' => true,
                'message' => 'Penarikan tunai di ATM berhasil! Uang sebesar Rp ' . number_format($withdrawal->amount, 0, ',', '.') . ' telah dikeluarkan.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }
}
