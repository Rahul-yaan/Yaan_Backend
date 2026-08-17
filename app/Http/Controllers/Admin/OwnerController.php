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
                $query->whereRaw('is_verified IS TRUE')
                ->whereHas('ownerProfile', function($q) {
                    $q->whereRaw('is_profile_complete IS TRUE');
                });
            } else {
                $query->where(function($q) {
                    $q->whereRaw('(is_verified IS FALSE OR is_verified IS NULL)')
                    ->orWhereHas('ownerProfile', function($sq) {
                        $sq->whereRaw('(is_profile_complete IS FALSE OR is_profile_complete IS NULL)');
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
            return (in_array($b->payment_status, ['paid', 'pay_at_hotel', 'cash', 'completed']) || in_array($b->status, ['confirmed', 'completed'])) && $b->payment_status !== 'refunded';
        });

        $baseRevenueSum = (float) $confirmedBookings->sum(function($b) {
            return (float) ($b->total_amount ?? $b->price_per_night ?? 0);
        });

        $totalCustomerPaid = (float) $confirmedBookings->sum(function($b) {
            return (float) ($b->total_payable ?? $b->total_amount ?? 0);
        });

        $ownerPayableRevenue  = round($baseRevenueSum * 0.66, 2);      // ₹66 per ₹100 base
        $ownerGstAmount       = round($ownerPayableRevenue * 0.18, 2); // ₹11.88 GST on ₹66
        $platformFeeCollected = round($baseRevenueSum * 0.34, 2);      // ₹34 Platform Fee (34%)

        $totalCancelled = $bookings->filter(function($b) {
            return $b->status === 'cancelled' || in_array($b->payment_status, ['refunded', 'refund_initiated']);
        })->count();

        return response()->json([
            'owner'       => $owner,
            'hotels'      => $hotels->isNotEmpty() ? $hotels : $owner->hotels,
            'analytics'   => [
                'total_revenue'          => $totalCustomerPaid,    // ₹112.10 Gross
                'total_customer_paid'    => $totalCustomerPaid,
                'gross_revenue'          => $totalCustomerPaid,
                'owner_payable_revenue'  => $ownerPayableRevenue,  // ₹62.70 Owner Net
                'payable_amount'         => $ownerPayableRevenue,
                'total_earnings'         => $ownerPayableRevenue,
                'owner_gst_amount'       => $ownerGstAmount,       // ₹11.29 Owner GST
                'gst_amount'             => $ownerGstAmount,
                'platform_fee_collected' => $platformFeeCollected, // ₹32.30 Platform Fee
                'platform_fee'           => $platformFeeCollected,
                'total_bookings'         => $totalBookings,
                'confirmed_bookings'     => $confirmedBookings->count(),
                'cancelled_bookings'     => $totalCancelled,
            ],
            'visiting_customers' => $bookings->take(25),
        ]);
    }

    public function verifyOwner(Request $request, $id)
    {
        $request->validate([
            'is_verified'      => 'nullable|boolean',
            'status'           => 'nullable|string|in:approved,rejected,pending',
            'notes'            => 'nullable|string|max:1000',
            'reason'           => 'nullable|string|max:1000',
            'rejection_reason' => 'nullable|string|max:1000',
        ]);

        $owner = User::where('role', 'owner')->findOrFail($id);
        $profile = OwnerProfile::firstOrCreate(['user_id' => $owner->id]);

        $statusInput = strtolower($request->input('status', ''));
        $isVerifiedInput = $request->has('is_verified') ? filter_var($request->is_verified, FILTER_VALIDATE_BOOLEAN) : null;

        $isApproved = ($statusInput === 'approved') || ($isVerifiedInput === true);
        $isRejected = ($statusInput === 'rejected') || ($isVerifiedInput === false && $statusInput !== 'approved');

        $reason = $request->input('rejection_reason') ?? $request->input('reason') ?? $request->input('notes');

        if ($isApproved) {
            $owner->is_verified = true;
            $owner->save();

            $profile->status = 'approved';
            $profile->is_profile_complete = true;
            $profile->rejection_reason = null;
            $profile->save();

            Hotel::where('owner_id', $owner->id)->update([
                'status'           => 'approved',
                'rejection_reason' => null,
            ]);

            $statusText = 'Approved';
            $message = 'Owner profile and hotel listing have been approved successfully.';
        } elseif ($isRejected) {
            $rejectionMessage = !empty($reason) ? trim($reason) : 'Admin rejected your profile verification request.';

            $owner->is_verified = false;
            $owner->save();

            $profile->status = 'rejected';
            $profile->is_profile_complete = false;
            $profile->rejection_reason = $rejectionMessage;
            $profile->save();

            Hotel::where('owner_id', $owner->id)->update([
                'status'           => 'rejected',
                'rejection_reason' => $rejectionMessage,
            ]);

            $statusText = 'Rejected';
            $message = "Owner verification has been rejected. Reason: {$rejectionMessage}";
        } else {
            $owner->is_verified = false;
            $owner->save();

            $profile->status = 'pending';
            $profile->save();

            $statusText = 'Pending';
            $message = 'Owner verification status updated to Pending.';
        }

        return response()->json([
            'message'          => $message,
            'status'           => $statusText,
            'rejection_reason' => $profile->rejection_reason,
            'owner'            => $owner->fresh('ownerProfile'),
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
