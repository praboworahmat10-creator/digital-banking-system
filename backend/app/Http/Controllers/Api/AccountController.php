<?php
namespace App\Http\Controllers\Api;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
class AccountController extends Controller
{
    public function show(Request $request)
    {
        $account = $request->user()->account;
        return response()->json(['success' => true, 'data' => ['account_number' => $account->account_number, 'balance' => (float) $account->balance,],]);
    }
}