<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
        'firebase_uid',
        'is_verified',
    ];

    protected $hidden = [
        'password',
        'remember_token',
        'firebase_uid',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'password'    => 'hashed',
    ];
}