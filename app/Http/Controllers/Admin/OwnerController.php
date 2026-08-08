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

        $owner = User::where('role', 'owner')->findOrFail($id);
        
        $profile = OwnerProfile::firstOrCreate(['user_id' => $owner->id]);
        $profile->is_profile_complete = $request->is_verified;
        $profile->save();

        $owner->is_verified = $request->is_verified;
        $owner->save();

        $statusText = $request->is_verified ? 'Verified' : 'Unverified';

        return response()->json([
            'message' => "Owner verification updated to {$statusText}.",
            'owner'   => $owner->load('ownerProfile'),
        ]);
    }
}
