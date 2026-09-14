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
        $query = User::where(function($q) {
            $q->where('role', 'owner')
              ->orWhereHas('hotels')
              ->orWhereHas('ownerProfile');
        })->with(['ownerProfile', 'hotels']);

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

        if ($request->has('verified') && $request->verified !== '' && $request->verified !== null) {
            $verifiedStr = strtolower(trim((string)$request->verified));
            if ($verifiedStr === 'true' || $verifiedStr === '1') {
                $query->whereRaw('is_verified IS TRUE')
                ->whereHas('ownerProfile', function($q) {
                    $q->where('status', 'approved');
                });
            } elseif ($verifiedStr === 'rejected') {
                $query->whereHas('ownerProfile', function($q) {
                    $q->where('status', 'rejected');
                });
            } elseif ($verifiedStr === 'false' || $verifiedStr === 'pending' || $verifiedStr === '0') {
                $query->where(function($q) {
                    $q->whereRaw('(is_verified IS FALSE OR is_verified IS NULL)')
                    ->whereHas('ownerProfile', function($sq) {
                        $sq->whereRaw('is_profile_complete IS TRUE')
                           ->where('status', 'pending');
                    });
                });
            } else {
                // 'all': Active/Verified and Pending owners (excluding rejected/reset owners)
                $query->where(function($q) {
                    $q->whereDoesntHave('ownerProfile')
                      ->orWhereHas('ownerProfile', function($sq) {
                          $sq->where('status', '!=', 'rejected');
                      });
                });
            }
        }

        $owners = $query->withCount('hotels')->latest()->paginate($request->input('per_page', 15));

        return response()->json($owners);
    }

    public function show($id)
    {
        $owner = User::where(function($q) {
            $q->where('role', 'owner')
              ->orWhereHas('hotels')
              ->orWhereHas('ownerProfile');
        })->with(['ownerProfile', 'hotels.images'])
          ->findOrFail($id);

        $hotelQuery = Hotel::where('owner_id', $owner->id);
        if ($owner->ownerProfile && !empty(trim($owner->ownerProfile->hotel_name))) {
            $registeredHotelName = trim($owner->ownerProfile->hotel_name);
            $matchedHotels = (clone $hotelQuery)->where('name', 'like', "%{$registeredHotelName}%")->with('images')->get();
            if ($matchedHotels->isNotEmpty()) {
                $hotels = $matchedHotels;
            } else {
                $hotels = $hotelQuery->with('images')->get();
            }
        } else {
            $hotels = $hotelQuery->with('images')->get();
        }

        $hotelIds = $hotels->pluck('id');
        if ($hotelIds->isEmpty()) {
            $hotelIds = Hotel::where('owner_id', $owner->id)->pluck('id');
        }

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
            return (float) ($b->total_payable ?? round(($b->total_amount ?? 0) * 1.18, 2));
        });

        if ($totalCustomerPaid <= 0 && $baseRevenueSum > 0) {
            $totalCustomerPaid = round($baseRevenueSum * 1.18, 2);
        }

        $baseRoomAmount       = $baseRevenueSum > 0 ? $baseRevenueSum : ($totalCustomerPaid > 0 ? round($totalCustomerPaid / 1.18, 2) : 0.00);
        $platformFeeBase      = round($baseRoomAmount * 0.34, 2);           // 34% Platform Fee Base (e.g. ₹20.40)
        $platformGst          = round($platformFeeBase * 0.18, 2);          // 18% Platform GST (e.g. ₹3.67)
        $platformFeeCollected = round($platformFeeBase + $platformGst, 2);  // Platform Fee Collection (e.g. ₹24.07)

        $ownerBaseShare       = round($baseRoomAmount * 0.66, 2);           // 66% Owner Share Base (e.g. ₹39.60)
        $ownerGstAmount       = round($ownerBaseShare * 0.18, 2);           // 18% Owner GST (e.g. ₹7.13)
        $ownerTotalPayout     = round($ownerBaseShare + $ownerGstAmount, 2);// Total Owner Net Payout (e.g. ₹46.73)
        $totalGst             = round($ownerGstAmount + $platformGst, 2);   // Total Customer GST (e.g. ₹10.80)

        $totalCancelled = $bookings->filter(function($b) {
            return $b->status === 'cancelled' || in_array($b->payment_status, ['refunded', 'refund_initiated']);
        })->count();

        return response()->json([
            'owner'       => $owner,
            'hotels'      => $hotels->isNotEmpty() ? $hotels : $owner->hotels,
            'analytics'   => [
                'total_amount'           => $baseRevenueSum,       // Base Room Price Total (e.g. ₹60.00)
                'base_room_amount'       => $baseRevenueSum,
                'total_payable'          => $totalCustomerPaid,    // Total Customer Paid (e.g. ₹70.80)
                'total_revenue'          => $totalCustomerPaid,
                'total_customer_paid'    => $totalCustomerPaid,
                'gross_revenue'          => $totalCustomerPaid,
                'owner_base_share'       => $ownerBaseShare,       // Owner Base Share (e.g. ₹39.60)
                'owner_payable_revenue'  => $ownerTotalPayout,     // Owner Net Payout (e.g. ₹46.73)
                'payable_amount'         => $ownerTotalPayout,
                'total_earnings'         => $ownerTotalPayout,
                'owner_total_payout'     => $ownerTotalPayout,
                'owner_gst_amount'       => $ownerGstAmount,       // Owner GST (e.g. ₹7.13)
                'platform_fee_base'      => $platformFeeBase,      // Platform Base Fee (e.g. ₹20.40)
                'platform_fee_gst'       => $platformGst,          // Platform GST (e.g. ₹3.67)
                'platform_fee_collected' => $platformFeeCollected, // Platform Fee Total Collection (e.g. ₹24.07)
                'platform_fee'           => $platformFeeCollected,
                'gst_amount'             => $totalGst,             // Total GST (e.g. ₹10.80)
                'total_gst'              => $totalGst,
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

        $owner = User::findOrFail($id);
        $owner->role = 'owner';
        $owner->save();
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

        \Illuminate\Support\Facades\DB::statement("UPDATE owner_profiles SET is_profile_complete = false, status = 'rejected', rejection_reason = 'Admin reset your KYC details. Please submit fresh valid KYC details.', aadhaar_number = NULL, pan_number = NULL, aadhaar_front = NULL, aadhaar_back = NULL, pan_card = NULL, fssai_license = NULL, gst_image = NULL, business_proof = NULL, gst_number = NULL, fssai_number = NULL, bank_name = NULL, account_number = NULL, ifsc_code = NULL, updated_at = NOW() WHERE user_id = ?", [$owner->id]);

        Hotel::where('owner_id', $owner->id)->update([
            'status'           => 'rejected',
            'rejection_reason' => 'Admin reset your KYC details. Please submit fresh valid KYC details.',
        ]);

        return response()->json([
            'message' => "Owner KYC data has been removed and reset successfully. Account moved to Rejected Applications.",
            'owner'   => $owner->fresh('ownerProfile'),
        ]);
    }
}
