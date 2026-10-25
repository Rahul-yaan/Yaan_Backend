<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Setting;
use Illuminate\Http\Request;

class SettingController extends Controller
{
    /**
     * Get all settings
     * GET /api/admin/settings
     */
    public function index()
    {
        $settings = Setting::all()->pluck('value', 'key')->toArray();

        // Ensure defaults if not set yet
        if (!isset($settings['support_phone'])) {
            $settings['support_phone'] = '+91 7777991598';
        }

        return response()->json([
            'success'  => true,
            'settings' => $settings,
            'data'     => $settings,
        ]);
    }

    /**
     * Update settings
     * POST /api/admin/settings
     * PUT  /api/admin/settings
     */
    public function update(Request $request)
    {
        $data = $request->all();

        // Support either { key: '...', value: '...' } or { support_phone: '...', ... }
        if ($request->has('key') && $request->has('value')) {
            $key = $request->input('key');
            $value = $request->input('value');
            Setting::set($key, $value);
        } else {
            foreach ($data as $key => $val) {
                if (is_scalar($val) || is_null($val)) {
                    Setting::set($key, (string) $val);
                }
            }
        }

        $all = Setting::all()->pluck('value', 'key')->toArray();
        if (!isset($all['support_phone'])) {
            $all['support_phone'] = '+91 7777991598';
        }

        return response()->json([
            'success'  => true,
            'message'  => 'Settings updated successfully.',
            'settings' => $all,
            'data'     => $all,
        ]);
    }
}
