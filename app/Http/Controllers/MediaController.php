<?php

namespace App\Http\Controllers;

use App\Models\HotelImage;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MediaController extends Controller
{
    /**
     * Safely serve public media images (hotels, avatars, banners)
     * strictly blocking path traversal (..) and private KYC documents.
     */
    public function serve(Request $request, string $path)
    {
        // 1. Sanitize path: block directory traversal attacks
        if (str_contains($path, '..') || str_contains($path, "\0") || str_starts_with($path, '/') || str_starts_with($path, '\\')) {
            abort(403, 'Invalid media path.');
        }

        // 2. Strictly whitelist allowed public directories
        $allowedPrefixes = ['hotels/', 'avatars/', 'banners/'];
        $isAllowed = false;
        foreach ($allowedPrefixes as $prefix) {
            if (str_starts_with($path, $prefix)) {
                $isAllowed = true;
                break;
            }
        }

        if (!$isAllowed) {
            abort(403, 'Media path not accessible.');
        }

        $publicStoragePath = storage_path('app/public/' . $path);

        // 3. Prevent path escape outside public directory
        $publicRoot = realpath(storage_path('app/public'));
        if (file_exists($publicStoragePath)) {
            $real = realpath($publicStoragePath);
            if ($real === false || $publicRoot === false || !str_starts_with($real, $publicRoot)) {
                abort(404, 'File not found.');
            }

            $mime = mime_content_type($real) ?: 'image/jpeg';
            if (!in_array($mime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                abort(403, 'Requested media type is not an image.');
            }

            return response()->file($real, [
                'Content-Type'  => $mime,
                'Cache-Control' => 'public, max-age=86400',
            ]);
        }

        // 4. If missing from disk, attempt to restore from HotelImage DB backup if applicable
        //    (exact path match only: a LIKE on the file name could return another hotel's photo)
        try {
            $hotelImg = HotelImage::where('image_path', $path)
                ->orWhere('image_path', 'storage/' . $path)
                ->orWhere('image_path', url('storage/' . $path))
                ->first();

            if ($hotelImg && !empty($hotelImg->image_data) && str_starts_with($hotelImg->image_data, 'data:image/')) {
                $commaPos = strpos($hotelImg->image_data, ',');
                $raw = $commaPos !== false ? substr($hotelImg->image_data, $commaPos + 1) : $hotelImg->image_data;
                $decoded = base64_decode($raw, true);

                // Old rows may hold any file: only restore real images
                $decodedMime = $decoded !== false ? ((new \finfo(FILEINFO_MIME_TYPE))->buffer($decoded) ?: '') : '';
                if ($decoded !== false && in_array($decodedMime, ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true)) {
                    @mkdir(dirname($publicStoragePath), 0775, true);
                    file_put_contents($publicStoragePath, $decoded);

                    $mime = $decodedMime;
                    return response()->file($publicStoragePath, [
                        'Content-Type'  => $mime,
                        'Cache-Control' => 'public, max-age=86400',
                    ]);
                }
            }
        } catch (\Throwable $e) {}

        // 5. Fallback for hotel images to a high-res default photo so UI never breaks
        if (str_starts_with($path, 'hotels/')) {
            return redirect('https://images.unsplash.com/photo-1566073771259-6a8506099945?auto=format&fit=crop&w=1000&q=80');
        }

        abort(404, 'Media not found.');
    }
}
