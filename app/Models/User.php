<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
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
        'avatar',
    ];

    protected $appends = ['avatar_url'];

    public function getAvatarUrlAttribute()
    {
        if (!$this->avatar) {
            return null;
        }
        if (str_starts_with($this->avatar, 'http://') || str_starts_with($this->avatar, 'https://')) {
            return $this->avatar;
        }
        return url(ltrim($this->avatar, '/'));
    }

    protected $hidden = [
        'password',
        'remember_token',
        'firebase_uid',
    ];

    protected $casts = [
        'is_verified' => 'boolean',
        'password'    => 'hashed',
    ];

    public function setIsVerifiedAttribute($value)
    {
        $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        try {
            if (DB::getDriverName() === 'pgsql') {
                $this->attributes['is_verified'] = $isTrue ? DB::raw('true') : DB::raw('false');
                return;
            }
        } catch (\Throwable $e) {}

        $this->attributes['is_verified'] = $isTrue ? 1 : 0;
    }

    public function ownerProfile()
    {
        return $this->hasOne(OwnerProfile::class);
    }

    public function hotels()
    {
        return $this->hasMany(Hotel::class, 'owner_id');
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    public function reviews()
    {
        return $this->hasMany(Review::class);
    }
}