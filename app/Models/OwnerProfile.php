<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;

class OwnerProfile extends Model
{
    protected $fillable = [
        'user_id',
        'hotel_name',
        'owner_name',
        'address',
        'state',
        'city',
        'pincode',
        'business_proof',
        'aadhaar_front',
        'aadhaar_back',
        'pan_card',
        'fssai_license',
        'fssai_number',
        'gst_number',
        'gst_image',
        'bank_name',
        'account_number',
        'ifsc_code',
        'is_profile_complete',
    ];

    protected $casts = [
        'is_profile_complete' => 'boolean',
    ];

    public function setIsProfileCompleteAttribute($value)
    {
        $isTrue = filter_var($value, FILTER_VALIDATE_BOOLEAN);
        $this->attributes['is_profile_complete'] = $isTrue ? DB::raw('true') : DB::raw('false');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}