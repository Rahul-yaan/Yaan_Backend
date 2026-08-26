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
        'aadhaar_number',
        'pan_number',
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
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'is_profile_complete' => 'boolean',
    ];

    protected $appends = [
        'aadhaar_front_url',
        'aadhaar_back_url',
        'pan_card_url',
        'fssai_license_url',
        'gst_image_url',
        'business_proof_url',
    ];

    private function getStorageUrl($path)
    {
        if (empty($path)) return null;
        if (str_starts_with($path, 'data:') || str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return $path;
        }
        $clean = ltrim(str_replace('\\', '/', $path), '/');
        if (str_starts_with($clean, 'storage/')) {
            $clean = substr($clean, 8);
        }
        return asset('storage/' . ltrim($clean, '/'));
    }

    public function getAadhaarFrontAttribute($value)
    {
        return $this->getStorageUrl($value) ?? $value;
    }

    public function getAadhaarBackAttribute($value)
    {
        return $this->getStorageUrl($value) ?? $value;
    }

    public function getPanCardAttribute($value)
    {
        return $this->getStorageUrl($value) ?? $value;
    }

    public function getFssaiLicenseAttribute($value)
    {
        return $this->getStorageUrl($value) ?? $value;
    }

    public function getGstImageAttribute($value)
    {
        return $this->getStorageUrl($value) ?? $value;
    }

    public function getBusinessProofAttribute($value)
    {
        return $this->getStorageUrl($value) ?? $value;
    }

    public function getAadhaarFrontUrlAttribute()
    {
        return $this->getStorageUrl($this->attributes['aadhaar_front'] ?? null);
    }

    public function getAadhaarBackUrlAttribute()
    {
        return $this->getStorageUrl($this->attributes['aadhaar_back'] ?? null);
    }

    public function getPanCardUrlAttribute()
    {
        return $this->getStorageUrl($this->attributes['pan_card'] ?? null);
    }

    public function getFssaiLicenseUrlAttribute()
    {
        return $this->getStorageUrl($this->attributes['fssai_license'] ?? null);
    }

    public function getGstImageUrlAttribute()
    {
        return $this->getStorageUrl($this->attributes['gst_image'] ?? null);
    }

    public function getBusinessProofUrlAttribute()
    {
        return $this->getStorageUrl($this->attributes['business_proof'] ?? null);
    }

    public function setIsProfileCompleteAttribute($value)
    {
        $this->attributes['is_profile_complete'] = filter_var($value, FILTER_VALIDATE_BOOLEAN);
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}