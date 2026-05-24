<?php

namespace App\Models;

use Laravel\Sanctum\HasApiTokens; // ✅ REQUIRED
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;



/**
 * @method \Laravel\Sanctum\NewAccessToken createToken(string $name, array $abilities)
 */

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable; // ✅ IMPORTANT

    protected $fillable = [
        'name',
        'email',
        'password',
        'phone',
        'role',
        'firebase_uid',
        'is_verified'
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }
}