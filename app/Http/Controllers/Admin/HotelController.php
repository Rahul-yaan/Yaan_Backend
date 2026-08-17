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

        return response()->json([
            'hotel'      => $hotel,
            'analytics'  => [
                'total_revenue'      => $totalRevenue,
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
            'status' => 'required|in:approved,pending,rejected,suspended,active,inactive',
            'reason' => 'nullable|string|max:255',
        ]);

        try {
            if (\Illuminate\Support\Facades\DB::getDriverName() === 'pgsql') {
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE hotels DROP CONSTRAINT IF EXISTS hotels_status_check;");
                \Illuminate\Support\Facades\DB::statement("ALTER TABLE hotels ALTER COLUMN status TYPE VARCHAR(50);");
            }
        } catch (\Throwable $e) {}

        $hotel = Hotel::findOrFail($id);
        $hotel->status = $request->status;
        $hotel->save();

        if (in_array(strtolower($request->status), ['approved', 'active'])) {
            $hotel->ensurePrimaryImageExists();
        }

        return response()->json([
            'message' => "Hotel status successfully updated to {$request->status}.",
            'hotel'   => $hotel->load(['images', 'primaryImage', 'amenities']),
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

        $hasPrimary = $hotel->images()->where(function($q) {
            $q->where('is_primary', true)->orWhere('is_primary', 1);
        })->exists();

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
