<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserController extends Controller
{
    public function index(Request $request)
    {
        $query = User::where('role', 'user');

        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                  ->orWhere('email', 'like', "%{$search}%")
                  ->orWhere('phone', 'like', "%{$search}%");
            });
        }

        $users = $query->withCount('bookings')->latest()->paginate($request->input('per_page', 15));

        return response()->json($users);
    }

    public function show($id)
    {
        $user = User::where('role', 'user')
            ->with(['bookings' => function($b) {
                $b->with('hotel:id,name,city')->latest()->take(10);
            }])
            ->withCount('bookings')
            ->findOrFail($id);

        return response()->json(['user' => $user]);
    }

    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'is_verified' => 'required',
        ]);

        $user = User::where('role', 'user')->findOrFail($id);
        $newStatus = filter_var($request->is_verified, FILTER_VALIDATE_BOOLEAN);

        $boolStr = $newStatus ? 'true' : 'false';
        \Illuminate\Support\Facades\DB::statement("UPDATE users SET is_verified = {$boolStr}, updated_at = NOW() WHERE id = ?", [$user->id]);

        if (!$newStatus) {
            $user->tokens()->delete();
        }

        $freshUser = User::where('role', 'user')->withCount('bookings')->find($id);

        $statusText = $newStatus ? 'Activated' : 'Disabled';

        return response()->json([
            'success'      => true,
            'message'      => "Customer #{$user->id} ({$user->name}) status updated to {$statusText}.",
            'is_verified'  => $newStatus,
            'user'         => $freshUser,
        ]);
    }
}
