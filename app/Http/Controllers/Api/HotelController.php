<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use App\Models\Hotel;
use App\Models\Banner;

class HotelController extends Controller
{
    /**
     * Helper scope for customer visible hotels:
     * Hotel must be approved/active AND hotel owner must be verified by admin.
     */
    private function applyApprovedScope($query)
    {
        return $query->whereIn('status', ['active', 'approved'])
            ->whereHas('owner', function($oq) {
                $oq->whereRaw('is_verified IS TRUE')
                   ->whereHas('ownerProfile', function($pq) {
                       $pq->where(function($spq) {
                           $spq->where('status', 'approved')
                               ->orWhereNull('status')
                               ->orWhere('status', '!=', 'rejected');
                       });
                   });
            });
    }

    /**
     * Attach Active Offer Banner Discount Information to Hotel Object
     */
    private function attachActiveDiscountInfo($hotel)
    {
        $activeUserBanner = Banner::whereRaw('is_active IS TRUE')
            ->whereIn('target_audience', ['user', 'all'])
            ->where(function($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>=', now());
            })
            ->where('discount_percentage', '>', 0)
            ->latest()
            ->first();

        $discountPct = $activeUserBanner ? (float) $activeUserBanner->discount_percentage : 0;
        $price = (float) $hotel->price_per_night;

        $discountAmount = round($price * ($discountPct / 100), 2);
        $discountedPrice = max(0, $price - $discountAmount);
        $gstAmount = round($discountedPrice * 0.18, 2);
        $totalPayable = round($discountedPrice + $gstAmount, 2);

        $hotel->active_discount_percentage = $discountPct;
        $hotel->active_promo_code           = $activeUserBanner ? ($activeUserBanner->discount_code ?? $activeUserBanner->promo_code) : null;
        $hotel->banner_title                = $activeUserBanner->title ?? null;
        $hotel->banner_image                = $activeUserBanner->image_url ?? null;
        $hotel->original_price              = $price;
        $hotel->discount_amount             = $discountAmount;
        $hotel->discounted_price            = $discountedPrice;
        $hotel->gst_amount                  = $gstAmount;
        $hotel->total_payable               = $totalPayable;

        return $hotel;
    }

    // GET /api/hotels
    public function index(Request $request)
    {
        $query = Hotel::with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile'])->withAvg('reviews', 'rating');

        $query = $this->applyApprovedScope($query);

        if ($request->city) {
            $query->where('city', 'LIKE', '%' . $request->city . '%');
        }

        $hotels = $query->get();
        foreach ($hotels as $h) {
            $h->ensurePrimaryImageExists();
            $this->attachActiveDiscountInfo($h);
        }

        return response()->json($hotels);
    }

    // GET /api/hotels/{id}
    public function show($id)
    {
        $query = Hotel::with(['images', 'primaryImage', 'reviews.user:id,name,email', 'amenities', 'owner.ownerProfile']);
        $query = $this->applyApprovedScope($query);
        $hotel = $query->findOrFail($id);
        $hotel->ensurePrimaryImageExists();
        $this->attachActiveDiscountInfo($hotel);

        return response()->json($hotel);
    }

    // GET /api/hotels/search
    public function search(Request $request) 
    {
        $destinationCity = $request->query('destination') ?? $request->query('city') ?? $request->query('search');
        
        $query = Hotel::with(['images', 'primaryImage', 'amenities', 'owner.ownerProfile'])->withAvg('reviews', 'rating');
        $query = $this->applyApprovedScope($query);

        if ($destinationCity) {
            $query->where('city', 'LIKE', '%' . $destinationCity . '%');
        }

        $hotels = $query->get();
        foreach ($hotels as $h) {
            $h->ensurePrimaryImageExists();
            $this->attachActiveDiscountInfo($h);
        }
        
        return response()->json([
            'status' => true,
            'count'  => $hotels->count(),
            'hotels' => $hotels,
        ]);
    }
}