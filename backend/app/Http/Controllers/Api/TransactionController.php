<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Transaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

class TransactionController extends Controller
{
    /**
     * Pengecekan rekening tujuan sebelum transfer (Account Inquiry)
     */
    public function checkAccount(Request $request)
    {
        $validated = $request->validate([
            'account_number' => 'required|string',
        ]);

        $senderAccount = $request->user()->account;

        if ($senderAccount->account_number === $validated['account_number']) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mentransfer ke nomor rekening sendiri.',
            ], 422);
        }

        $recipientAccount = Account::with('user')
            ->where('account_number', $validated['account_number'])
            ->first();

        if (!$recipientAccount) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor rekening tujuan tidak ditemukan.',
            ], 404);
        }

        return response()->json([
            'success' => true,
            'message' => 'Rekening ditemukan',
            'data' => [
                'account_number' => $recipientAccount->account_number,
                'recipient_name' => $recipientAccount->user->name,
            ],
        ]);
    }

    /**
     * Eksekusi transfer antar rekening secara aman dengan verifikasi PIN (Atomic Transaction)
     */
    public function transfer(Request $request)
    {
        $validated = $request->validate([
            'recipient_account_number' => 'required|string',
            'amount' => 'required|numeric|min:10000',
            'description' => 'nullable|string|max:255',
            'pin' => 'required|digits:6',
        ]);

        $user = $request->user();
        $senderAccount = $user->account;

        // Validasi PIN Transaksi
        if (!$user->pin) {
            return response()->json([
                'success' => false,
                'message' => 'Anda belum membuat PIN transaksi. Silakan buat PIN terlebih dahulu.',
                'require_pin_setup' => true,
            ], 422);
        }

        if (!Hash::check($validated['pin'], $user->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN transaksi yang Anda masukkan salah.',
            ], 422);
        }

        if ($senderAccount->account_number === $validated['recipient_account_number']) {
            return response()->json([
                'success' => false,
                'message' => 'Tidak dapat mentransfer ke nomor rekening sendiri.',
            ], 422);
        }

        $amount = (float) $validated['amount'];

        try {
            $transactionResult = DB::transaction(function () use ($senderAccount, $validated, $amount) {
                $sender = Account::where('id', $senderAccount->id)->lockForUpdate()->first();
                $recipient = Account::with('user')->where('account_number', $validated['recipient_account_number'])->lockForUpdate()->first();

                if (!$recipient) {
                    throw new \Exception('Nomor rekening tujuan tidak ditemukan.', 404);
                }

                if ((float) $sender->balance < $amount) {
                    throw new \Exception('Saldo Anda tidak mencukupi untuk melakukan transfer ini.', 422);
                }

                // Potong saldo pengirim
                $sender->balance -= $amount;
                $sender->save();

                // Tambah saldo penerima
                $recipient->balance += $amount;
                $recipient->save();

                // Catat transaksi
                $referenceNumber = 'TRX' . date('YmdHis') . strtoupper(Str::random(4));
                $transaction = Transaction::create([
                    'reference_number' => $referenceNumber,
                    'sender_account_id' => $sender->id,
                    'recipient_account_id' => $recipient->id,
                    'amount' => $amount,
                    'type' => 'transfer',
                    'description' => $validated['description'] ?? 'Transfer Saldo',
                    'status' => 'success',
                ]);

                return [
                    'transaction' => $transaction,
                    'sender_balance' => (float) $sender->balance,
                    'recipient_name' => $recipient->user->name,
                    'recipient_account_number' => $recipient->account_number,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Transfer berhasil diproses!',
                'data' => [
                    'reference_number' => $transactionResult['transaction']->reference_number,
                    'amount' => $amount,
                    'recipient_name' => $transactionResult['recipient_name'],
                    'recipient_account_number' => $transactionResult['recipient_account_number'],
                    'description' => $transactionResult['transaction']->description,
                    'created_at' => $transactionResult['transaction']->created_at,
                    'remaining_balance' => $transactionResult['sender_balance'],
                ],
            ]);
        } catch (\Exception $e) {
            $statusCode = in_array($e->getCode(), [404, 422]) ? $e->getCode() : 500;
            return response()->json([
                'success' => false,
                'message' => $e->getMessage() ?: 'Terjadi kesalahan saat memproses transfer.',
            ], $statusCode);
        }
    }

    /**
     * Top Up Saldo Akun Instan / Virtual Account
     */
    public function topup(Request $request)
    {
        $validated = $request->validate([
            'amount' => 'required|numeric|min:10000',
            'payment_method' => 'required|string',
        ]);

        $user = $request->user();
        $account = $user->account;
        $amount = (float) $validated['amount'];

        try {
            $result = DB::transaction(function () use ($account, $amount, $validated) {
                $acc = Account::where('id', $account->id)->lockForUpdate()->first();
                $acc->balance += $amount;
                $acc->save();

                $referenceNumber = 'TOP' . date('YmdHis') . strtoupper(Str::random(4));
                $transaction = Transaction::create([
                    'reference_number' => $referenceNumber,
                    'sender_account_id' => null,
                    'recipient_account_id' => $acc->id,
                    'amount' => $amount,
                    'type' => 'topup',
                    'description' => 'Top Up Saldo via ' . $validated['payment_method'],
                    'status' => 'success',
                ]);

                return [
                    'transaction' => $transaction,
                    'new_balance' => (float) $acc->balance,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Top Up saldo berhasil! Saldo telah masuk ke rekening Anda.',
                'data' => [
                    'reference_number' => $result['transaction']->reference_number,
                    'amount' => $amount,
                    'payment_method' => $validated['payment_method'],
                    'new_balance' => $result['new_balance'],
                    'created_at' => $result['transaction']->created_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal memproses Top Up: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Pembayaran QRIS Merchant
     */
    public function qrisPay(Request $request)
    {
        $validated = $request->validate([
            'merchant_name' => 'required|string',
            'amount' => 'required|numeric|min:1000',
            'pin' => 'required|digits:6',
        ]);

        $user = $request->user();
        $account = $user->account;

        if (!$user->pin || !Hash::check($validated['pin'], $user->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN transaksi yang Anda masukkan salah.',
            ], 422);
        }

        $amount = (float) $validated['amount'];

        try {
            $result = DB::transaction(function () use ($account, $amount, $validated) {
                $acc = Account::where('id', $account->id)->lockForUpdate()->first();

                if ((float) $acc->balance < $amount) {
                    throw new \Exception('Saldo Anda tidak mencukupi untuk pembayaran QRIS.');
                }

                $acc->balance -= $amount;
                $acc->save();

                $referenceNumber = 'QRS' . date('YmdHis') . strtoupper(Str::random(4));
                $transaction = Transaction::create([
                    'reference_number' => $referenceNumber,
                    'sender_account_id' => $acc->id,
                    'recipient_account_id' => null,
                    'amount' => $amount,
                    'type' => 'qris',
                    'description' => 'Pembayaran QRIS di ' . $validated['merchant_name'],
                    'status' => 'success',
                ]);

                return [
                    'transaction' => $transaction,
                    'remaining_balance' => (float) $acc->balance,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Pembayaran QRIS Berhasil!',
                'data' => [
                    'reference_number' => $result['transaction']->reference_number,
                    'merchant_name' => $validated['merchant_name'],
                    'amount' => $amount,
                    'remaining_balance' => $result['remaining_balance'],
                    'created_at' => $result['transaction']->created_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Pengecekan nomor tujuan / ID Pelanggan / Akun E-Wallet (Biller Inquiry)
     */
    public function billerCheck(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string',
            'provider_name' => 'required|string',
            'target_number' => 'required|string',
        ]);

        $category = $validated['category'];
        $provider = $validated['provider_name'];
        $number = trim($validated['target_number']);

        if (empty($number) || strlen($number) < 4) {
            return response()->json([
                'success' => false,
                'message' => 'Nomor tujuan / ID tidak valid.',
            ], 422);
        }

        // Cari user yang terdaftar dengan nomor HP tersebut atau nomor rekening
        $foundUser = \App\Models\User::where('phone', $number)
            ->orWhereHas('account', function ($q) use ($number) {
                $q->where('account_number', $number);
            })
            ->first();

        if ($foundUser) {
            $recipientName = strtoupper($foundUser->name);
        } else {
            // Mapping nama realistis untuk demo/pengujian
            $simulatedNames = [
                '087800122047' => 'GIOVED RAHMAT P.',
                '081234567890' => 'BUDI SANTOSO',
                '085678901234' => 'SITI AMINAH',
                '089912345678' => 'ANDI WIJAYA',
                '14123456789' => 'BUDI SANTOSO (R1M/900VA)',
                '54123456789' => 'HENDRA PRASETYA (R1/1300VA)',
            ];

            if (isset($simulatedNames[$number])) {
                $recipientName = $simulatedNames[$number];
            } else {
                if ($category === 'PLN') {
                    $recipientName = 'PELANGGAN PLN ' . substr($number, -4) . ' (R1/900VA)';
                } elseif ($category === 'Pulsa') {
                    $recipientName = 'NOMOR ' . strtoupper(explode(' ', $provider)[0]) . ' (' . substr($number, 0, 4) . '***' . substr($number, -3) . ')';
                } else {
                    $recipientName = 'PENGGUNA ' . strtoupper(explode(' ', $provider)[0]) . ' (' . substr($number, -4) . ')';
                }
            }
        }

        return response()->json([
            'success' => true,
            'message' => 'Akun tujuan berhasil ditemukan',
            'data' => [
                'category' => $category,
                'provider_name' => $provider,
                'target_number' => $number,
                'recipient_name' => $recipientName,
                'admin_fee' => 0,
                'status' => 'ACTIVE',
            ],
        ]);
    }

    /**
     * Pembayaran Tagihan / E-Wallet / Pulsa / Listrik PLN
     */
    public function billerPay(Request $request)
    {
        $validated = $request->validate([
            'category' => 'required|string', // 'E-Wallet', 'PLN', 'Pulsa'
            'provider_name' => 'required|string', // 'GoPay', 'Token PLN', 'Telkomsel'
            'target_number' => 'required|string',
            'amount' => 'required|numeric|min:10000',
            'pin' => 'required|digits:6',
            'recipient_name' => 'nullable|string',
        ]);

        $user = $request->user();
        $account = $user->account;

        if (!$user->pin || !Hash::check($validated['pin'], $user->pin)) {
            return response()->json([
                'success' => false,
                'message' => 'PIN transaksi yang Anda masukkan salah.',
            ], 422);
        }

        $amount = (float) $validated['amount'];
        $recipientName = $request->input('recipient_name') ?: $validated['target_number'];

        try {
            $result = DB::transaction(function () use ($account, $amount, $validated, $recipientName) {
                $acc = Account::where('id', $account->id)->lockForUpdate()->first();

                if ((float) $acc->balance < $amount) {
                    throw new \Exception('Saldo Anda tidak mencukupi untuk melakukan transaksi ini.');
                }

                $acc->balance -= $amount;
                $acc->save();

                $referenceNumber = 'BIL' . date('YmdHis') . strtoupper(Str::random(4));
                $transaction = Transaction::create([
                    'reference_number' => $referenceNumber,
                    'sender_account_id' => $acc->id,
                    'recipient_account_id' => null,
                    'amount' => $amount,
                    'type' => 'biller',
                    'description' => $validated['category'] . ' - ' . $validated['provider_name'] . ' ke ' . $recipientName . ' (' . $validated['target_number'] . ')',
                    'status' => 'success',
                ]);

                return [
                    'transaction' => $transaction,
                    'remaining_balance' => (float) $acc->balance,
                ];
            });

            return response()->json([
                'success' => true,
                'message' => 'Transaksi ' . $validated['provider_name'] . ' Berhasil!',
                'data' => [
                    'reference_number' => $result['transaction']->reference_number,
                    'provider_name' => $validated['provider_name'],
                    'recipient_name' => $recipientName,
                    'target_number' => $validated['target_number'],
                    'amount' => $amount,
                    'remaining_balance' => $result['remaining_balance'],
                    'created_at' => $result['transaction']->created_at,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        }
    }

    /**
     * Mengambil ringkasan mutasi & analitik pemasukan/pengeluaran bulan ini
     */
    public function history(Request $request)
    {
        $account = $request->user()->account;

        $transactions = Transaction::with(['senderAccount.user', 'recipientAccount.user'])
            ->where(function ($query) use ($account) {
                $query->where('sender_account_id', $account->id)
                      ->orWhere('recipient_account_id', $account->id);
            })
            ->orderBy('created_at', 'desc')
            ->get();

        $totalIncome = 0;
        $totalExpense = 0;

        $formatted = $transactions->map(function ($trx) use ($account, &$totalIncome, &$totalExpense) {
            $isOutgoing = $trx->sender_account_id === $account->id;
            
            if ($isOutgoing) {
                $totalExpense += (float) $trx->amount;
            } else {
                $totalIncome += (float) $trx->amount;
            }

            if ($trx->type === 'topup') {
                $counterpartyName = 'Top Up Saldo';
                $counterpartyAccountNumber = 'Virtual Account / Cash';
            } elseif ($trx->type === 'withdrawal') {
                $counterpartyName = 'Tarik Tunai ATM';
                $counterpartyAccountNumber = 'ATM Cardless';
            } elseif ($trx->type === 'qris') {
                $counterpartyName = 'Merchant QRIS';
                $counterpartyAccountNumber = 'QRIS Payment';
            } elseif ($trx->type === 'biller') {
                $counterpartyName = 'Beli & Bayar Tagihan';
                $counterpartyAccountNumber = $trx->description ?: 'Biller Services';
            } else {
                $counterpartyName = $isOutgoing 
                    ? ($trx->recipientAccount->user->name ?? 'Penerima') 
                    : ($trx->senderAccount->user->name ?? 'Pengirim');

                $counterpartyAccountNumber = $isOutgoing
                    ? ($trx->recipientAccount->account_number ?? '-')
                    : ($trx->senderAccount->account_number ?? '-');
            }

            return [
                'id' => $trx->id,
                'reference_number' => $trx->reference_number,
                'direction' => $isOutgoing ? 'OUT' : 'IN',
                'type' => $trx->type,
                'amount' => (float) $trx->amount,
                'counterparty_name' => $counterpartyName,
                'counterparty_account_number' => $counterpartyAccountNumber,
                'description' => $trx->description,
                'status' => $trx->status,
                'created_at' => $trx->created_at->format('Y-m-d H:i:s'),
            ];
        });

        return response()->json([
            'success' => true,
            'data' => $formatted,
            'summary' => [
                'total_income' => $totalIncome,
                'total_expense' => $totalExpense,
            ],
        ]);
    }
}
