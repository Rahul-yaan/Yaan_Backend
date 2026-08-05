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

    public function toggleStatus(Request $request, $id)
    {
        $request->validate([
            'is_verified' => 'required|boolean',
        ]);

        $user = User::where('role', 'user')->findOrFail($id);
        $user->is_verified = $request->is_verified;
        $user->save();

        return response()->json([
            'message' => "User status updated successfully.",
            'user'    => $user,
        ]);
    }
}
