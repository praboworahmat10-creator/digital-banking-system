<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Transaction extends Model
{
    use HasFactory;

    protected $fillable = [
        'reference_number',
        'sender_account_id',
        'recipient_account_id',
        'amount',
        'type',
        'description',
        'status',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
    ];

    public function senderAccount()
    {
        return $this->belongsTo(Account::class, 'sender_account_id');
    }

    public function recipientAccount()
    {
        return $this->belongsTo(Account::class, 'recipient_account_id');
    }
}
