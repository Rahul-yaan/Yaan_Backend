<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class KycDocument extends Model
{
    public const FIELDS = [
        'aadhaar_front',
        'aadhaar_back',
        'pan_card',
        'business_proof',
        'fssai_license',
        'gst_image',
    ];

    protected $fillable = [
        'user_id',
        'field',
        'mime',
        'data',
    ];

    // Never send file content in normal JSON responses
    protected $hidden = [
        'data',
    ];

    /**
     * Save (or replace) the database copy of one KYC document.
     */
    public static function storeCopy(int $userId, string $field, string $bytes, string $mime): void
    {
        self::updateOrCreate(
            ['user_id' => $userId, 'field' => $field],
            ['mime' => $mime, 'data' => base64_encode($bytes)]
        );
    }
}
