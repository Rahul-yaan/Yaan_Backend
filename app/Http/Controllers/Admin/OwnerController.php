<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\OwnerProfile;
use Illuminate\Http\Request;

class OwnerController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'owner')->with(['ownerProfile']);

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        if ($request->has('verified') && $request->verified !== '' && $request->verified !== null) {
            $isVerified = filter_var($request->verified, FILTER_VALIDATE_BOOLEAN);
            $query->whereHas('ownerProfile', function($q) use ($isVerified) {
                if ($isVerified) {
                    $q->whereRaw('("is_profile_complete" = true OR "is_profile_complete" IS TRUE)');
                } else {
                    $q->whereRaw('("is_profile_complete" = false OR "is_profile_complete" IS FALSE OR "is_profile_complete" IS NULL)');
                }
            });
        }

        $owners = $query->withCount('hotels')->latest()->paginate($request->input('per_page', 15));

        return response()->json($owners);
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
