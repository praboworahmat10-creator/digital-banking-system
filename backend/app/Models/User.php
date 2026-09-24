<?php

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'nik',
        'password',
        'pin',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'pin',
    ];

    public function account()
    {
        return $this->hasOne(Account::class);
    }

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'nik' => 'encrypted', // Bank-Grade AES-256 Encryption for sensitive National ID (PII)
        ];
    }
}
