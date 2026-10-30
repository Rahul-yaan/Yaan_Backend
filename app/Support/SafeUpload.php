<?php

namespace App\Support;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use InvalidArgumentException;

class SafeUpload
{
    public const ALLOWED_IMAGE_MIMES = [
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
    ];

    public const ALLOWED_KYC_MIMES = [
        'image/jpeg'      => 'jpg',
        'image/png'       => 'png',
        'image/webp'      => 'webp',
        'application/pdf' => 'pdf',
    ];

    /**
     * Store an uploaded image or base64 image on the public disk.
     * Allowed directories: 'hotels', 'avatars', 'banners'
     */
    public static function storePublicImage(UploadedFile|string $input, string $folder = 'hotels'): ?string
    {
        $folder = trim($folder, '/');
        if (!in_array($folder, ['hotels', 'avatars', 'banners'], true)) {
            $folder = 'hotels';
        }

        if ($input instanceof UploadedFile) {
            return self::storeUploadedFile($input, $folder, 'public', self::ALLOWED_IMAGE_MIMES, 10 * 1024 * 1024);
        }

        if (is_string($input)) {
            return self::storeBase64String($input, $folder, 'public', self::ALLOWED_IMAGE_MIMES, 10 * 1024 * 1024);
        }

        return null;
    }

    /**
     * Store a KYC document on the private disk ('local') for data privacy.
     */
    public static function storeKycDocument(UploadedFile|string $input, string $folder = 'kyc_docs'): ?string
    {
        $folder = trim($folder, '/');

        if ($input instanceof UploadedFile) {
            return self::storeUploadedFile($input, $folder, 'local', self::ALLOWED_KYC_MIMES, 15 * 1024 * 1024);
        }

        if (is_string($input)) {
            return self::storeBase64String($input, $folder, 'local', self::ALLOWED_KYC_MIMES, 15 * 1024 * 1024);
        }

        return null;
    }

    /**
     * Save one hotel photo after checking its real content (JPG/PNG/WEBP only).
     * Returns [public path, data URI copy for the database] or null if it is not a valid image.
     * The database copy lets /storage restore the photo after a Render deploy wipes the disk.
     */
    public static function storeHotelPhoto(UploadedFile|string $input): ?array
    {
        if (is_string($input) && !str_starts_with(trim($input), 'data:')) {
            return null; // only real uploads or data: images are stored here
        }

        try {
            $path = self::storePublicImage($input, 'hotels');
        } catch (\Throwable $e) {
            return null;
        }
        if (!$path || !Storage::disk('public')->exists($path)) {
            return null;
        }

        $mime = self::detectMimeType(Storage::disk('public')->path($path));

        return [$path, 'data:' . $mime . ';base64,' . base64_encode(Storage::disk('public')->get($path))];
    }

    /**
     * Convenience wrapper for storePublicImage.
     */
    public static function uploadImage(UploadedFile|string $input, string $folder = 'hotels'): ?string
    {
        return self::storePublicImage($input, $folder);
    }

    /**
     * Convenience wrapper for storeKycDocument.
     */
    public static function uploadKycDoc(UploadedFile|string $input, int|string $userId = ''): ?string
    {
        $folder = $userId ? 'kyc_docs/' . $userId : 'kyc_docs';
        return self::storeKycDocument($input, $folder);
    }

    /**
     * Inspect file content and store safely.
     */
    protected static function storeUploadedFile(UploadedFile $file, string $folder, string $disk, array $allowedMimes, int $maxBytes): string
    {
        if (!$file->isValid()) {
            throw new InvalidArgumentException('Uploaded file is corrupted or incomplete.');
        }

        if ($file->getSize() > $maxBytes) {
            throw new InvalidArgumentException('Uploaded file exceeds maximum allowed size.');
        }

        $realPath = $file->getRealPath();
        $mime = self::detectMimeType($realPath);

        if (!array_key_exists($mime, $allowedMimes)) {
            throw new InvalidArgumentException('Invalid file type: ' . $mime);
        }

        $ext = $allowedMimes[$mime];
        $filename = Str::random(40) . '.' . $ext;
        $path = $folder . '/' . $filename;

        Storage::disk($disk)->put($path, file_get_contents($realPath));

        return $path;
    }

    /**
     * Decode base64 data, inspect content mime, and store.
     */
    protected static function storeBase64String(string $data, string $folder, string $disk, array $allowedMimes, int $maxBytes): ?string
    {
        $clean = trim($data);
        if (empty($clean)) {
            return null;
        }

        // If it's already an existing relative path or URL, return it
        if (!str_starts_with($clean, 'data:')) {
            if (str_starts_with($clean, 'http://') || str_starts_with($clean, 'https://') || str_starts_with($clean, $folder . '/')) {
                return $clean;
            }
        }

        // Extract raw base64 data
        $commaPos = strpos($clean, ',');
        $raw = $commaPos !== false ? substr($clean, $commaPos + 1) : $clean;
        $decoded = base64_decode($raw, true);

        if ($decoded === false) {
            return null;
        }

        if (strlen($decoded) > $maxBytes) {
            throw new InvalidArgumentException('Decoded file payload exceeds maximum allowed size.');
        }

        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->buffer($decoded);

        if (!array_key_exists($mime, $allowedMimes)) {
            throw new InvalidArgumentException('Invalid decoded file type: ' . $mime);
        }

        $ext = $allowedMimes[$mime];
        $filename = Str::random(40) . '.' . $ext;
        $path = $folder . '/' . $filename;

        Storage::disk($disk)->put($path, $decoded);

        return $path;
    }

    /**
     * Detect MIME type from physical file using finfo.
     */
    public static function detectMimeType(string $filePath): string
    {
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        return $finfo->file($filePath) ?: 'application/octet-stream';
    }
}
