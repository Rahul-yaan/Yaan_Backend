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
        if (str_starts_with($path, 'http://') || str_starts_with($path, 'https://')) {
            return preg_replace('/^http:/i', 'https:', $path);
        }
        $clean = ltrim($path, '/');
        if (str_starts_with($clean, 'storage/')) {
            $clean = substr($clean, 8);
        }
        $url = asset('storage/' . $clean);
        return preg_replace('/^http:/i', 'https:', $url);
    }

    public function getAadhaarFrontUrlAttribute()
    {
        return $this->getStorageUrl($this->aadhaar_front);
    }

    public function getAadhaarBackUrlAttribute()
    {
        return $this->getStorageUrl($this->aadhaar_back);
    }

    public function getPanCardUrlAttribute()
    {
        return $this->getStorageUrl($this->pan_card);
    }

    public function getFssaiLicenseUrlAttribute()
    {
        return $this->getStorageUrl($this->fssai_license);
    }

    public function getGstImageUrlAttribute()
    {
        return $this->getStorageUrl($this->gst_image);
    }

    public function getBusinessProofUrlAttribute()
    {
        return $this->getStorageUrl($this->business_proof);
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