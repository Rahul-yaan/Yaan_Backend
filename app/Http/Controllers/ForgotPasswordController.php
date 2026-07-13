<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\PasswordResetToken;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
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
            'email' => 'required|email|exists:users,email',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user->is_verified) {
            return response()->json([
                'error' => 'Account not verified. Complete phone verification first.',
            ], 403);
        }

        $today = Carbon::today()->toDateString();
        $existing = PasswordResetToken::where('user_id', $user->id)->first();

        if ($existing) {
            $isToday = $existing->attempts_date === $today;

            if ($isToday && $existing->attempts_today >= 3) {
                return response()->json([
                    'error'          => 'Daily limit reached.',
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

        PasswordResetToken::create([
            'user_id'        => $user->id,
            'token'          => Hash::make($plainToken),
            'expires_at'     => now()->addMinutes(3),
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
            \Log::info('Email sent successfully to: ' . $user->email);
        } catch (\Exception $e) {
            \Log::error('Mail failed: ' . $e->getMessage());
            return response()->json([
                'error' => 'Mail failed: ' . $e->getMessage(),
            ], 500);
        }

        return response()->json([
            'message'       => 'Password reset link sent to your email.',
            'attempts_left' => 3 - $attemptsToday,
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
            'email'    => 'required|email|exists:users,email',
            'password' => 'required|min:6|confirmed',
        ]);

        $user = User::where('email', $request->email)->first();
        $record = PasswordResetToken::where('user_id', $user->id)->first();

        if (!$record) {
            return response()->json([
                'error' => 'Invalid or expired reset link.',
            ], 422);
        }

        if (Carbon::now()->isAfter($record->expires_at)) {
            $record->delete();
            return response()->json([
                'error' => 'Reset link has expired. Please request a new one.',
            ], 422);
        }

        if (!Hash::check($request->token, $record->token)) {
            return response()->json([
                'error' => 'Invalid reset token.',
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