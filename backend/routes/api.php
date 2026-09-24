<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\AccountController;
use App\Http\Controllers\Api\TransactionController;
use App\Http\Controllers\Api\PinController;
use App\Http\Controllers\Api\WithdrawalController;
use App\Http\Controllers\Api\TwoFactorController;

Route::get('/user', function (Request $request) {
    return $request->user();
})->middleware('auth:sanctum');

Route::get('/ping', function () {
    return response()->json([
        'success' => true,
        'message' => 'Backend Laravel berhasil terhubung!',
        'timestamp' => now(),
    ]);
});

Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// 🔐 2FA Autentikasi Publik & Reset Password
Route::post('/2fa/send-otp', [TwoFactorController::class, 'sendOtp']);
Route::post('/2fa/verify-otp', [TwoFactorController::class, 'verifyOtp']);
Route::post('/password/secure-reset', [TwoFactorController::class, 'secureChangePassword']);

Route::middleware('auth:sanctum')->group(function () {
    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/account', [AccountController::class, 'show']);
    
    // 🛡️ PIN Transaksi & Keamanan 2FA
    Route::get('/pin/status', [PinController::class, 'status']);
    Route::post('/pin/setup', [PinController::class, 'setup']);
    Route::post('/pin/change', [PinController::class, 'change']);
    Route::post('/pin/secure-change', [TwoFactorController::class, 'secureChangePin']);
    Route::post('/password/secure-change', [TwoFactorController::class, 'secureChangePassword']);

    // 💸 Core Transaksi (Transfer, Top Up, QRIS, Tagihan & Mutasi)
    Route::post('/transfer/check', [TransactionController::class, 'checkAccount']);
    Route::post('/transfer', [TransactionController::class, 'transfer']);
    Route::post('/topup', [TransactionController::class, 'topup']);
    Route::post('/qris/pay', [TransactionController::class, 'qrisPay']);
    Route::post('/biller/check', [TransactionController::class, 'billerCheck']);
    Route::post('/biller/pay', [TransactionController::class, 'billerPay']);
    Route::get('/transactions', [TransactionController::class, 'history']);

    // 🏧 Tarik Tunai Tanpa Kartu
    Route::post('/withdrawal/request', [WithdrawalController::class, 'requestWithdrawal']);
    Route::get('/withdrawals/active', [WithdrawalController::class, 'getActive']);
    Route::post('/withdrawal/claim', [WithdrawalController::class, 'claim']);
});