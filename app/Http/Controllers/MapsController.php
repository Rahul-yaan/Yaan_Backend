<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class MapsController extends Controller
{
    /**
     * Get Place Autocomplete Suggestions via Google Places API (server-side proxy).
     * Endpoint: GET /api/maps/place-suggestions?input=...
     */
    public function placeSuggestions(Request $request)
    {
        $input = trim((string) $request->query('input', ''));
        if (empty($input)) {
            return response()->json([
                'status'      => 'OK',
                'predictions' => [],
            ]);
        }

        $apiKey = config('services.google.maps_key') ?? env('GOOGLE_MAPS_API_KEY');
        if (empty($apiKey)) {
            return response()->json([
                'status'      => 'REQUEST_DENIED',
                'predictions' => [],
                'message'     => 'Google Maps API key not configured on server.',
            ], 200);
        }

        try {
            $response = Http::timeout(8)->get('https://maps.googleapis.com/maps/api/place/autocomplete/json', [
                'input'      => $input,
                'components' => 'country:in',
                'key'        => $apiKey,
            ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::error('Maps placeSuggestions error: ' . $e->getMessage());
            return response()->json([
                'status'  => 'ERROR',
                'message' => 'Unable to fetch place suggestions at this time.',
            ], 500);
        }
    }

    /**
     * Get Driving Directions via Google Directions API (server-side proxy).
     * Endpoint: GET /api/maps/directions?origin=...&destination=...
     */
    public function directions(Request $request)
    {
        $origin      = trim((string) ($request->query('origin') ?? $request->query('from') ?? ''));
        $destination = trim((string) ($request->query('destination') ?? $request->query('to') ?? ''));

        if (empty($origin) || empty($destination)) {
            return response()->json([
                'error'   => 'Origin and destination are required.',
                'message' => 'Origin and destination are required.',
            ], 422);
        }

        $apiKey = config('services.google.maps_key') ?? env('GOOGLE_MAPS_API_KEY');
        if (empty($apiKey)) {
            return response()->json([
                'status'  => 'REQUEST_DENIED',
                'routes'  => [],
                'message' => 'Google Maps API key not configured on server.',
            ], 200);
        }

        try {
            $response = Http::timeout(10)->get('https://maps.googleapis.com/maps/api/directions/json', [
                'origin'      => $origin,
                'destination' => $destination,
                'mode'        => 'driving',
                'key'         => $apiKey,
            ]);

            return response()->json($response->json());
        } catch (\Throwable $e) {
            Log::error('Maps directions error: ' . $e->getMessage());
            return response()->json([
                'error'   => 'Unable to fetch directions.',
                'message' => 'Unable to fetch directions at this time.',
            ], 500);
        }
    }
}
