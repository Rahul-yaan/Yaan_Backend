<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Hotel;
use Illuminate\Http\Request;

class HotelController extends Controller
{
    public function index(Request $request)
    {
        $query = Hotel::with(['owner', 'owner.ownerProfile', 'images', 'amenities']);

        // Filter by Status
        if ($request->has('status') && !empty($request->status) && $request->status !== 'all') {
            $status = strtolower($request->status);
            if ($status === 'approved') {
                $query->whereIn('status', ['approved', 'active']);
            } elseif ($status === 'suspended') {
                $query->whereIn('status', ['suspended', 'inactive']);
            } else {
                $query->where('status', $status);
            }
        }

        // Filter by City
        if ($request->has('city') && !empty($request->city)) {
            $city = trim($request->city);
            $query->where('city', 'like', "%{$city}%");
        }

        // Filter by State / Region
        if ($request->has('state') && !empty($request->state)) {
            $state = trim($request->state);
            $query->where(function($q) use ($state) {
                $q->where('address', 'like', "%{$state}%")
                  ->orWhere('city', 'like', "%{$state}%");
            });
        }

        // Global Search (Name, City, State/Address, Owner Name, Owner Phone)
        if ($request->has('search') && !empty($request->search)) {
            $search = trim($request->search);
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('city', 'like', "%{$search}%")
                  ->orWhere('address', 'like', "%{$search}%")
                  ->orWhereHas('owner', function($oq) use ($search) {
                      $oq->where('name', 'like', "%{$search}%")
                         ->orWhere('phone', 'like', "%{$search}%");
                  });
            });
        }

        $hotels = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json($hotels);
    }

    public function getLocations()
    {
        $cities = Hotel::whereNotNull('city')->where('city', '!=', '')->distinct()->pluck('city');
        return response()->json([
            'cities' => $cities,
        ]);
    }

    public function show($id)
    {
        $hotel = Hotel::with(['owner', 'owner.ownerProfile', 'images', 'amenities', 'reviews.user'])->findOrFail($id);

        $bookings = \App\Models\Booking::where('hotel_id', $hotel->id)
            ->with(['user:id,name,email,phone'])
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

        $baseHotelRevenue = $totalRevenue > 0 ? round($totalRevenue / 1.18, 2) : 0.00;
        $platformFeeCollected = round($baseHotelRevenue * 0.34, 2);
        $ownerPayableRevenue = round($baseHotelRevenue - $platformFeeCollected, 2);
        $ownerGstAmount = round($ownerPayableRevenue * 0.18, 2);

        return response()->json([
            'hotel'      => $hotel,
            'analytics'  => [
                'total_revenue'          => $ownerPayableRevenue,
                'owner_payable_revenue'  => $ownerPayableRevenue,
                'gross_revenue'          => $totalRevenue,
                'total_customer_paid'    => $totalRevenue,
                'base_revenue'           => $baseHotelRevenue,
                'platform_fee_collected' => $platformFeeCollected,
                'owner_gst_amount'       => $ownerGstAmount,
                'total_bookings'     => $totalBookings,
                'confirmed_bookings' => $confirmedBookings->count(),
                'cancelled_bookings' => $totalCancelled,
            ],
            'visiting_customers' => $bookings->take(20),
        ]);
    }

    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'           => 'required|in:approved,pending,rejected,suspended,active,inactive',
            'reason'           => 'nullable|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        try {
            if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE hotels DROP CONSTRAINT IF EXISTS hotels_status_check;");
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE hotels ALTER COLUMN status TYPE VARCHAR(50);");
            }
        } catch (\Throwable $e) {}

        $hotel = Hotel::findOrFail($id);
        $newStatus = strtolower($request->input('status', $request->status));
        $reason = $request->input('rejection_reason') ?? $request->input('reason');

        $hotel->status = $newStatus;

        if ($newStatus === 'rejected') {
            $hotel->rejection_reason = !empty($reason) ? trim($reason) : 'Admin rejected this hotel listing.';
            if ($hotel->owner_id) {
                $owner = \App\Models\User::find($hotel->owner_id);
                if ($owner) {
                    $owner->is_verified = false;
                    $owner->save();
                }
                $profile = \App\Models\OwnerProfile::where('user_id', $hotel->owner_id)->first();
                if ($profile) {
                    $profile->status = 'rejected';
                    $profile->rejection_reason = $hotel->rejection_reason;
                    $profile->save();
                }
            }
        } elseif (in_array($newStatus, ['approved', 'active'])) {
            $hotel->rejection_reason = null;
            $hotel->ensurePrimaryImageExists();
            if ($hotel->owner_id) {
                $owner = \App\Models\User::find($hotel->owner_id);
                if ($owner) {
                    $owner->is_verified = true;
                    $owner->save();
                }
                $profile = \App\Models\OwnerProfile::firstOrCreate(['user_id' => $hotel->owner_id]);
                $profile->status = 'approved';
                $profile->is_profile_complete = true;
                $profile->rejection_reason = null;
                $profile->save();
            }
        }

        $hotel->save();

        return response()->json([
            'message'          => "Hotel status successfully updated to {$newStatus}.",
            'hotel'            => $hotel->load(['images', 'primaryImage', 'amenities']),
            'rejection_reason' => $hotel->rejection_reason,
        ]);
    }

    public function uploadImage(Request $request, $id)
    {
        $hotel = Hotel::findOrFail($id);

        if ($request->hasFile('image')) {
            $file = $request->file('image');
            $path = $file->store('hotels', 'public');
        } elseif ($request->has('image_url') && !empty($request->image_url)) {
            $path = $request->image_url;
        } else {
            return response()->json(['error' => 'No image provided.'], 422);
        }

        $hasPrimary = $hotel->images()->whereRaw('is_primary IS TRUE')->exists();

        $image = \App\Models\HotelImage::create([
            'hotel_id'   => $hotel->id,
            'image_path' => $path,
            'is_primary' => !$hasPrimary,
        ]);

        $hotel->ensurePrimaryImageExists();

        return response()->json([
            'message' => 'Hotel photo uploaded successfully.',
            'image'   => $image,
            'hotel'   => $hotel->load(['images', 'primaryImage', 'amenities']),
        ]);
    }

    public function deleteImage($id, $imageId)
    {
        $image = \App\Models\HotelImage::where('hotel_id', $id)->where('id', $imageId)->first();
        if ($image) {
            $image->delete();
        }
        return response()->json(['message' => 'Image removed successfully.']);
    }
}
