<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Hotel extends Model
{
    protected $fillable = [
        'owner_id',
        'name',
        'description',
        'city',
        'address',
        'latitude',
        'longitude',
        'price_per_night',
        'discount_price',
        'wheel_prices',
        'total_rooms',
        'available_rooms',
        'rating',
        'review_count',
        'status',
        'rejection_reason',
    ];

    protected $casts = [
        'latitude'       => 'double',
        'longitude'      => 'double',
        'price_per_night'=> 'decimal:2',
        'discount_price' => 'decimal:2',
        'wheel_prices'   => 'array',
        'rating'         => 'decimal:2',
    ];

    protected $appends = [
        'primary_image',
        'image_url',
        'image',
        'primary_image_url',
    ];

    public function owner()
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function images()
    {
        return $this->hasMany(HotelImage::class);
    }

    public function primaryImage()
    {
        return $this->hasOne(HotelImage::class)->whereRaw('is_primary IS TRUE');
    }

    public function getPrimaryImageAttribute()
    {
        if ($this->relationLoaded('primaryImage') && $this->getRelation('primaryImage') !== null) {
            return $this->getRelation('primaryImage');
        }

        if ($this->relationLoaded('images') && $this->images->isNotEmpty()) {
            $primary = $this->images->firstWhere('is_primary', true);
            $img = $primary ?? $this->images->first();
            $this->setRelation('primaryImage', $img);
            return $img;
        }

        $img = $this->images()->whereRaw('is_primary IS TRUE')->first()
            ?? $this->images()->first();

        if ($img) {
            $this->setRelation('primaryImage', $img);
            return $img;
        }

        // Check owner profile for uploaded documents if hotel_images table has no entries
        if ($this->relationLoaded('owner') && $this->owner && $this->owner->ownerProfile) {
            $profile = $this->owner->ownerProfile;
            $photoPath = $profile->business_proof ?? $profile->aadhaar_front ?? $profile->gst_image ?? null;
            if ($photoPath) {
                try {
                    $img = HotelImage::create([
                        'hotel_id'   => $this->id,
                        'image_path' => $photoPath,
                        'is_primary' => true,
                    ]);
                    $this->setRelation('primaryImage', $img);
                    return $img;
                } catch (\Throwable $e) {}
            }
        }

        // Fallback default high quality hotel image URL
        $fallbackUrl = 'https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1000&q=80';
        $dummy = new HotelImage([
            'hotel_id'   => $this->id ?? 0,
            'image_path' => $fallbackUrl,
            'is_primary' => true,
        ]);
        $dummy->id = 0;
        $this->setRelation('primaryImage', $dummy);
        return $dummy;
    }

    public function getImageUrlAttribute()
    {
        $primary = $this->primary_image;
        return $primary ? $primary->url : null;
    }

    public function getImageAttribute()
    {
        $primary = $this->primary_image;
        return $primary ? ($primary->url ?? $primary) : null;
    }

    public function getPrimaryImageUrlAttribute()
    {
        return $this->image_url;
    }

    public function ensurePrimaryImageExists()
    {
        try {
            $images = $this->relationLoaded('images') ? $this->images : $this->images()->get();
            if ($images->isNotEmpty()) {
                $hasPrimary = $images->contains(function ($img) {
                    return (bool) $img->is_primary;
                });

                if (!$hasPrimary) {
                    $first = $images->first();
                    $first->is_primary = true;
                    $first->save();
                }
            } elseif ($this->owner && $this->owner->ownerProfile) {
                $profile = $this->owner->ownerProfile;
                $photoPath = $profile->business_proof ?? $profile->aadhaar_front ?? $profile->gst_image ?? null;
                if ($photoPath) {
                    HotelImage::create([
                        'hotel_id'   => $this->id,
                        'image_path' => $photoPath,
                        'is_primary' => true,
                    ]);
                }
            }
            $this->load(['images', 'primaryImage']);
        } catch (\Throwable $e) {}
    }

    public function toArray()
    {
        $array = parent::toArray();
        $primary = $this->primary_image;

        if (empty($array['primary_image']) && $primary) {
            $array['primary_image'] = $primary->toArray();
        }
        if (empty($array['image_url'])) {
            $array['image_url'] = $this->image_url;
        }
        if (empty($array['image'])) {
            $array['image'] = $this->image;
        }
        if (empty($array['primary_image_url'])) {
            $array['primary_image_url'] = $this->primary_image_url;
        }
        if (empty($array['images']) || (is_array($array['images']) && count($array['images']) === 0)) {
            if ($primary) {
                $array['images'] = [$primary->toArray()];
            }
        }
        return $array;
    }

    public function amenities()
    {
        return $this->belongsToMany(Amenity::class, 'hotel_amenities');
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