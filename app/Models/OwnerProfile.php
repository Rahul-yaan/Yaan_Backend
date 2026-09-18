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
        if (str_starts_with($path, 'data:')) {
            return $path;
        }

        $clean = str_replace('\\', '/', $path);
        if (preg_match('#^https?://[^/]+/(.*)$#i', $clean, $matches)) {
            $clean = $matches[1];
        }
        $clean = ltrim($clean, '/');
        if (str_starts_with($clean, 'storage/')) {
            $clean = substr($clean, 8);
        }
        $clean = ltrim($clean, '/');

        if (empty($clean)) return null;

        try {
            if (\Illuminate\Support\Facades\Storage::disk('public')->exists($clean)) {
                return url('storage/' . $clean);
            }
        } catch (\Throwable $e) {}

        // If path exists in DB but file was missing/wiped from disk, return clean SVG document preview badge
        return 'data:image/svg+xml;utf8,' . rawurlencode('<svg xmlns="http://www.w3.org/2000/svg" width="300" height="200" viewBox="0 0 300 200"><rect width="300" height="200" fill="#1e293b" rx="8"/><path d="M130 60h40v20h-40zM120 90h60v10h-60zM120 110h60v10h-60zM120 130h40v10h-40z" fill="#64748b"/><text x="50%" y="85%" dominant-baseline="middle" text-anchor="middle" fill="#38bdf8" font-size="13" font-family="sans-serif" font-weight="bold">📄 Document Uploaded</text></svg>');
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