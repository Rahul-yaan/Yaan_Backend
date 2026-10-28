<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PurgeUnverifiedUsers extends Command
{
    protected $signature   = 'users:purge-unverified';
    protected $description = 'Safely delete abandoned unverified user and owner accounts older than 24 hours';

    public function handle(): void
    {
        $cutoff = now()->subHours(24)->toDateTimeString();

        $abandonedUsers = User::whereIn('role', ['user', 'owner'])
            ->where(function ($q) {
                $q->where('is_verified', false)
                  ->orWhereNull('is_verified');
            })
            ->where(function ($q) {
                $q->whereNull('firebase_uid')
                  ->orWhere('firebase_uid', '');
            })
            ->where('created_at', '<', $cutoff)
            ->whereDoesntHave('bookings')
            ->whereDoesntHave('hotels')
            ->whereDoesntHave('ownerProfile')
            ->get();

        $count = 0;
        foreach ($abandonedUsers as $user) {
            $user->tokens()->delete();
            $user->delete();
            $count++;
        }

        $this->info("Purged {$count} abandoned unverified user(s).");
    }
}