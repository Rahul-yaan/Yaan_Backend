<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OwnerProfile;
use App\Models\Hotel;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'owner')->with(['ownerProfile', 'hotels']);

        if ($request->has('search') && !empty($request->search)) {
            $search = trim($request->search);
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%")
                  ->orWhereHas('ownerProfile', function($pq) use ($search) {
                      $pq->where('hotel_name', 'like', "%{$search}%")
                         ->orWhere('gst_number', 'like', "%{$search}%");
                  })
                  ->orWhereHas('hotels', function($hq) use ($search) {
                      $hq->where('name', 'like', "%{$search}%")
                         ->orWhere('city', 'like', "%{$search}%");
                  });
            });
        }

        if ($request->has('city') && !empty($request->city)) {
            $city = trim($request->city);
            $query->whereHas('hotels', function($hq) use ($city) {
                $hq->where('city', 'like', "%{$city}%");
            });
        }

        if ($request->has('verified') && $request->verified !== '' && $request->verified !== null && $request->verified !== 'all') {
            $isVerified = filter_var($request->verified, FILTER_VALIDATE_BOOLEAN);
            if ($isVerified) {
                $query->whereRaw('("is_verified" = true OR "is_verified" IS TRUE)')
                      ->whereHas('ownerProfile', function($q) {
                          $q->whereRaw('("is_profile_complete" = true OR "is_profile_complete" IS TRUE)');
                      });
            } else {
                $query->where(function($q) {
                    $q->whereRaw('("is_verified" = false OR "is_verified" IS FALSE OR "is_verified" IS NULL)')
                      ->orWhereHas('ownerProfile', function($sq) {
                          $sq->whereRaw('("is_profile_complete" = false OR "is_profile_complete" IS FALSE OR "is_profile_complete" IS NULL)');
                      })
                      ->orDoesntHave('ownerProfile');
                });
            }
        }

        $owners = $query->withCount('hotels')->latest()->paginate($request->input('per_page', 15));

        return response()->json($owners);
    }

    public function show($id)
    {
        $owner = User::where('role', 'owner')
            ->with(['ownerProfile', 'hotels.images'])
            ->findOrFail($id);

        $hotelIds = Hotel::where('owner_id', $owner->id)->pluck('id');
        if ($hotelIds->isEmpty() && $owner->ownerProfile && !empty($owner->ownerProfile->hotel_name)) {
            $hotelIds = Hotel::where('name', 'like', "%{$owner->ownerProfile->hotel_name}%")->pluck('id');
        }

        $hotels = Hotel::whereIn('id', $hotelIds)->with('images')->get();

        $bookings = \App\Models\Booking::whereIn('hotel_id', $hotelIds)
            ->with(['user:id,name,email,phone', 'hotel:id,name,city'])
            ->latest()
            ->get();

        $totalBookings     = $bookings->count();
        $confirmedBookings = $bookings->filter(function($b) {
            return ($b->payment_status === 'paid' || in_array($b->status, ['confirmed', 'completed'])) && $b->payment_status !== 'refunded';
        });

        $totalRevenue = (float) $confirmedBookings->sum(function($b) {
            return (float) ($b->total_payable ?? $b->total_amount ?? 0);
        });

        $totalCancelled = $bookings->filter(function($b) {
            return $b->status === 'cancelled' || in_array($b->payment_status, ['refunded', 'refund_initiated']);
        })->count();

        return response()->json([
            'owner'       => $owner,
            'hotels'      => $hotels->isNotEmpty() ? $hotels : $owner->hotels,
            'analytics'   => [
                'total_revenue'      => $totalRevenue,
                'total_bookings'     => $totalBookings,
                'confirmed_bookings' => $confirmedBookings->count(),
                'cancelled_bookings' => $totalCancelled,
            ],
            'visiting_customers' => $bookings->take(25),
        ]);
    }

    public function verifyOwner(Request $request, $id)
    {
        $request->validate([
            'is_verified' => 'required|boolean',
            'notes'       => 'nullable|string|max:255',
        ]);

        $isVerified = filter_var($request->is_verified, FILTER_VALIDATE_BOOLEAN);

        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = OwnerProfile::firstOrCreate(['user_id' => $owner->id]);

        $boolStr = $isVerified ? 'true' : 'false';
        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = {$boolStr}, updated_at = NOW() WHERE user_id = ?", [$owner->id]);
        \Illuminate\Support\Facades\DB::statement("UPDATE users SET is_verified = {$boolStr}, updated_at = NOW() WHERE id = ?", [$owner->id]);

        if (!$isVerified) {
            $owner->tokens()->delete();
        }

        $statusText = $isVerified ? 'Verified' : 'Unverified';

        return response()->json([
            'message' => "Owner verification updated to {$statusText}.",
            'owner'   => $owner->fresh('ownerProfile'),
        ]);
    }

    /**
     * Remove / Reset Owner KYC data completely.
     * Endpoint: POST /api/admin/owners/{id}/reset-kyc
     */
    public function resetKyc($id)
    {
        $owner = User::where('role', 'owner')->findOrFail($id);

        \Illuminate\Support\Facades\DB::statement("UPDATE users SET is_verified = false, updated_at = NOW() WHERE id = ?", [$owner->id]);

        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = false, aadhaar_front = NULL, aadhaar_back = NULL, pan_card = NULL, fssai_license = NULL, gst_image = NULL, business_proof = NULL, gst_number = NULL, fssai_number = NULL, bank_name = NULL, account_number = NULL, ifsc_code = NULL, updated_at = NOW() WHERE user_id = ?", [$owner->id]);

        return response()->json([
            'message' => "Owner KYC data has been removed and reset successfully. The owner can now re-submit fresh KYC details.",
            'owner'   => $owner->fresh('ownerProfile'),
        ]);
    }
}
