<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordResetToken;
use App\Support\BusinessDate;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Log;
use Carbon\Carbon;

class ForgotPasswordController extends Controller
{
    // ============================================================
    // 1. SEND RESET LINK
    //    URL:  POST /api/forgot-password
    //    Body: { email }
    // ============================================================
    public function sendResetLink(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
        ]);

        $email = strtolower(trim($request->email));
        $user  = User::whereRaw('LOWER(email) = ?', [$email])->first();

        $genericResponse = response()->json([
            'message'       => 'If your email is registered, a password reset link has been sent.',
            'attempts_left' => 3,
        ]);

        // Security: return generic response if user doesn't exist
        if (!$user) {
            return $genericResponse;
        }

        // Allow password reset if account is verified OR has phone verified via firebase_uid (e.g. pending owner)
        $isEligible = (bool) $user->is_verified || !empty($user->firebase_uid);
        if (!$isEligible) {
            return $genericResponse;
        }

        $today = BusinessDate::today();
        $existing = PasswordResetToken::where('user_id', $user->id)->first();

        if ($existing) {
            $isToday = $existing->attempts_date === $today;

            if ($isToday && $existing->attempts_today >= 3) {
                return response()->json([
                    'error'          => 'Daily limit reached.',
                    'message'        => 'Daily limit reached for password reset requests. Please try again tomorrow.',
                    'attempts_left'  => 0,
                    'retry_tomorrow' => true,
                ], 429);
            }
        }

        $attemptsToday = ($existing && $existing->attempts_date === $today)
            ? $existing->attempts_today + 1
            : 1;

        if ($existing) {
            $existing->delete();
        }

        $plainToken = Str::random(64);

        // Security fix: Increase link validity to 30 minutes
        PasswordResetToken::create([
            'user_id'        => $user->id,
            'token'          => Hash::make($plainToken),
            'expires_at'     => now()->addMinutes(30),
            'attempts_today' => $attemptsToday,
            'attempts_date'  => $today,
        ]);

        $resetLink = config('app.frontend_url')
            . '/reset-password?token=' . $plainToken
            . '&email=' . urlencode($user->email);

        try {
            Mail::send(
                'emails.reset_password',
                ['user' => $user, 'resetLink' => $resetLink],
                function ($message) use ($user) {
                    $message->to($user->email)
                            ->subject('Reset Your Yaan Password');
                }
            );
        } catch (\Throwable $e) {
            Log::error('Password reset email sending failed: ' . $e->getMessage());
        }

        return response()->json([
            'message'       => 'If your email is registered, a password reset link has been sent.',
            'attempts_left' => max(0, 3 - $attemptsToday),
        ]);
    }

    // ============================================================
    // 2. RESET PASSWORD
    //    URL:  POST /api/reset-password
    //    Body: { token, email, password, password_confirmation }
    // ============================================================
    public function resetPassword(Request $request)
    {
        $request->validate([
            'token'    => 'required|string',
            'email'    => 'required|email',
            'password' => 'required|min:6|confirmed',
        ]);

        $email = strtolower(trim($request->email));
        $user  = User::whereRaw('LOWER(email) = ?', [$email])->first();

        if (!$user) {
            return response()->json([
                'error'   => 'Invalid or expired reset link.',
                'message' => 'Invalid or expired reset link.',
            ], 422);
        }

        $record = PasswordResetToken::where('user_id', $user->id)->first();

        if (!$record) {
            return response()->json([
                'error'   => 'Invalid or expired reset link.',
                'message' => 'Invalid or expired reset link.',
            ], 422);
        }

        if (Carbon::now()->isAfter($record->expires_at)) {
            $record->delete();
            return response()->json([
                'error'   => 'Reset link has expired. Please request a new one.',
                'message' => 'Reset link has expired. Please request a new one.',
            ], 422);
        }

        if (!Hash::check($request->token, $record->token)) {
            return response()->json([
                'error'   => 'Invalid reset token.',
                'message' => 'Invalid reset token.',
            ], 422);
        }

        $user->update([
            'password' => Hash::make($request->password),
        ]);

        $user->tokens()->delete();
        $record->delete();

        return response()->json([
            'message' => 'Password reset successfully. Please login with your new password.',
        ]);
    }
}