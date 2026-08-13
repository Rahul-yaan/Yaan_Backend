<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

class BannerController extends Controller
{
    public function index(Request $request)
    {
        $banners = Banner::latest()->paginate($request->input('per_page', 20));
        return response()->json($banners);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title'               => 'required|string|max:255',
            'description'         => 'nullable|string',
            'target_audience'     => 'required|in:all,user,owner',
            'discount_code'       => 'nullable|string|max:50',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'expires_at'          => 'nullable|date',
            'image'               => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'image_url'           => 'nullable|string',
        ]);

        $imagePath = null;
        if ($request->hasFile('image')) {
            $imagePath = $request->file('image')->store('banners', 'public');
        } elseif ($request->filled('image_url')) {
            $imagePath = $request->image_url;
        }

        $banner = Banner::create([
            'title'               => $request->title,
            'description'         => $request->description,
            'image_path'          => $imagePath,
            'target_audience'     => $request->target_audience ?? 'all',
            'discount_code'       => $request->discount_code,
            'discount_percentage' => $request->discount_percentage,
            'is_active'           => $request->boolean('is_active', true),
            'expires_at'          => $request->expires_at,
        ]);

        return response()->json([
            'message' => 'Banner created successfully.',
            'banner'  => $banner,
        ], 201);
    }

    public function update(Request $request, $id)
    {
        $banner = Banner::findOrFail($id);

        $request->validate([
            'title'               => 'sometimes|required|string|max:255',
            'description'         => 'nullable|string',
            'target_audience'     => 'sometimes|required|in:all,user,owner',
            'discount_code'       => 'nullable|string|max:50',
            'discount_percentage' => 'nullable|numeric|min:0|max:100',
            'expires_at'          => 'nullable|date',
            'image'               => 'nullable|file|mimes:jpeg,png,jpg,gif,webp|max:5120',
            'image_url'           => 'nullable|string',
        ]);

        if ($request->hasFile('image')) {
            if ($banner->image_path && !str_starts_with($banner->image_path, 'http')) {
                Storage::disk('public')->delete($banner->image_path);
            }
            $banner->image_path = $request->file('image')->store('banners', 'public');
        } elseif ($request->has('image_url') && !empty($request->image_url)) {
            $banner->image_path = $request->image_url;
        }

        if ($request->has('title'))               $banner->title = $request->title;
        if ($request->has('description'))         $banner->description = $request->description;
        if ($request->has('target_audience'))     $banner->target_audience = $request->target_audience;
        if ($request->has('discount_code'))       $banner->discount_code = $request->discount_code;
        if ($request->has('discount_percentage')) $banner->discount_percentage = $request->discount_percentage;
        if ($request->has('expires_at'))          $banner->expires_at = $request->expires_at;
        if ($request->has('is_active'))           $banner->is_active = $request->boolean('is_active');

        $banner->save();

        return response()->json([
            'message' => 'Banner updated successfully.',
            'banner'  => $banner,
        ]);
    }

    public function toggleStatus($id)
    {
        $banner = Banner::findOrFail($id);
        $banner->is_active = !$banner->is_active;
        $banner->save();

        return response()->json([
            'message'   => 'Banner status updated.',
            'is_active' => $banner->is_active,
            'banner'    => $banner,
        ]);
    }

    public function destroy($id)
    {
        $banner = Banner::findOrFail($id);
        if ($banner->image_path && !str_starts_with($banner->image_path, 'http')) {
            Storage::disk('public')->delete($banner->image_path);
        }
        $banner->delete();

        return response()->json([
            'message' => 'Banner deleted successfully.',
        ]);
    }
}
