<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;

class PurgeUnverifiedUsers extends Command
{
    protected $signature   = 'users:purge-unverified';
    protected $description = 'Delete unverified user accounts older than 24 hours';

    public function handle(): void
    {
        $deleted = User::where('is_verified', false)
            ->where('created_at', '<', now()->subHours(24))
            ->delete();

        $this->info("Purged {$deleted} unverified user(s).");
    }
}