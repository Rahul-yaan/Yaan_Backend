<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class RazorpayWebhook extends Model
{
    protected $fillable = [
        'event_id',
        'event',
        'payload',
        'signature_verified',
        'status',
        'error_message',
    ];

    protected $casts = [
        'signature_verified' => 'boolean',
    ];
}
