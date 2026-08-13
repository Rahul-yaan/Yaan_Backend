<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Hotel;
use App\Models\Booking;
use App\Models\OwnerProfile;
use App\Models\Review;
use App\Models\Setting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Carbon\Carbon;

class DashboardController extends Controller
{
    public function index()
    {
        $totalUsers     = User::where('role', 'user')->count();
        $totalOwners    = User::where('role', 'owner')->count();
        $verifiedOwners = OwnerProfile::whereRaw('("is_profile_complete" = true OR "is_profile_complete" IS TRUE)')->count();
        $pendingOwners  = User::where('role', 'owner')
            ->where(function($q) {
                $q->whereRaw('("is_verified" = false OR "is_verified" IS FALSE OR "is_verified" IS NULL)')
                  ->orWhereHas('ownerProfile', function($sq) {
                      $sq->whereRaw('("is_profile_complete" = false OR "is_profile_complete" IS FALSE OR "is_profile_complete" IS NULL)');
                  })
                  ->orDoesntHave('ownerProfile');
            })
            ->count();

        $totalHotels    = Hotel::count();
        $approvedHotels = Hotel::where('status', 'approved')->count();
        $pendingHotels  = Hotel::where('status', 'pending')->count();
        $rejectedHotels = Hotel::where('status', 'rejected')->count();

        $allBookingsCount = Booking::count();

        // Confirmed / Active Bookings
        $confirmedBookingsCount = Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereIn('status', ['confirmed', 'completed']);
        })
        ->whereNotIn('status', ['cancelled'])
        ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
        ->where(function($q) {
            $q->whereNull('cancellation_reason')
              ->orWhere('cancellation_reason', 'not like', '%refund%');
        })
        ->count();

        // Cancelled or Refunded Bookings count
        $cancelledBookingsCount = Booking::where('status', 'cancelled')
            ->orWhereIn('payment_status', ['refunded', 'refund_initiated'])
            ->orWhere('cancellation_reason', 'like', '%refund%')
            ->count();

        // Pending Bookings
        $pendingBookingsCount = Booking::where('status', 'pending')
            ->whereIn('payment_status', ['pending', 'failed'])
            ->count();

        // Total Revenue: Sum of payable amounts for confirmed/completed non-refunded bookings
        $totalRevenue = Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereIn('status', ['confirmed', 'completed']);
        })
        ->whereNotIn('status', ['cancelled'])
        ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
        ->where(function($q) {
            $q->whereNull('cancellation_reason')
              ->orWhere('cancellation_reason', 'not like', '%refund%');
        })
        ->sum(DB::raw('COALESCE(total_payable, total_amount)'));

        // Current Month Revenue
        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth   = Carbon::now()->endOfMonth();

        $currentMonthRevenue = (float) Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereIn('status', ['confirmed', 'completed']);
        })
        ->whereNotIn('status', ['cancelled'])
        ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
        ->sum(DB::raw('COALESCE(total_payable, total_amount)'));

        // Saved Custom Target Goal (Default ₹5,00,000)
        $targetGoal = (float) Setting::get('monthly_target_goal', 500000);
        $goalPercentage = $targetGoal > 0 ? min(100, round(($currentMonthRevenue / $targetGoal) * 100, 1)) : 0;
        $remainingGoal  = max(0, $targetGoal - $currentMonthRevenue);

        // Monthly Revenue Trends (Past 6 Months) for Chart.js
        $monthlyLabels = [];
        $monthlyIncomeData = [];
        $monthlyBookingsData = [];

        for ($i = 5; $i >= 0; $i--) {
            $month = Carbon::now()->subMonths($i);
            $monthStart = $month->copy()->startOfMonth();
            $monthEnd   = $month->copy()->endOfMonth();

            $monthlyLabels[] = $month->format('M Y');

            $mRev = (float) Booking::where(function($q) {
                $q->where('payment_status', 'paid')
                  ->orWhereIn('status', ['confirmed', 'completed']);
            })
            ->whereNotIn('status', ['cancelled'])
            ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->sum(DB::raw('COALESCE(total_payable, total_amount)'));

            $mCount = Booking::where(function($q) {
                $q->where('payment_status', 'paid')
                  ->orWhereIn('status', ['confirmed', 'completed']);
            })
            ->whereNotIn('status', ['cancelled'])
            ->whereBetween('created_at', [$monthStart, $monthEnd])
            ->count();

            $monthlyIncomeData[]   = round($mRev, 2);
            $monthlyBookingsData[] = $mCount;
        }

        // Payment Source Distribution (Razorpay vs Pay-at-Hotel)
        $onlineRevenue = (float) Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereNotNull('razorpay_payment_id');
        })
        ->whereNotIn('status', ['cancelled'])
        ->sum(DB::raw('COALESCE(total_payable, total_amount)'));

        $offlineRevenue = max(0, (float)$totalRevenue - $onlineRevenue);

        // Conversion Rate
        $conversionRate = $allBookingsCount > 0 ? round(($confirmedBookingsCount / $allBookingsCount) * 100, 1) : 0;

        // Top 5 Performing Hotels
        $topHotels = Hotel::withCount(['bookings' => function($q) {
            $q->whereNotIn('status', ['cancelled']);
        }])
        ->orderBy('rating', 'desc')
        ->orderBy('bookings_count', 'desc')
        ->take(5)
        ->get();

        $recentBookings = Booking::with(['user:id,name,email,phone', 'hotel:id,name,city'])
            ->latest()
            ->take(5)
            ->get();

        $recentHotels = Hotel::with(['owner:id,name,email'])
            ->latest()
            ->take(5)
            ->get();

        return response()->json([
            'metrics' => [
                'users_count'        => $totalUsers,
                'owners_count'       => $totalOwners,
                'verified_owners'    => $verifiedOwners,
                'pending_owners'     => $pendingOwners,
                'total_hotels'       => $totalHotels,
                'approved_hotels'    => $approvedHotels,
                'pending_hotels'     => $pendingHotels,
                'rejected_hotels'    => $rejectedHotels,
                'total_bookings'     => $confirmedBookingsCount,
                'all_bookings'       => $allBookingsCount,
                'confirmed_bookings' => $confirmedBookingsCount,
                'active_bookings'    => $confirmedBookingsCount,
                'pending_bookings'   => $pendingBookingsCount,
                'cancelled_bookings' => $cancelledBookingsCount,
                'total_revenue'      => (float) $totalRevenue,
                'conversion_rate'    => $conversionRate,
            ],
            'goals' => [
                'target_goal'           => (float) $targetGoal,
                'current_month_revenue' => (float) $currentMonthRevenue,
                'goal_percentage'       => $goalPercentage,
                'remaining_goal'        => (float) $remainingGoal,
            ],
            'charts' => [
                'labels'           => $monthlyLabels,
                'income_series'    => $monthlyIncomeData,
                'bookings_series'  => $monthlyBookingsData,
                'payment_sources'  => [
                    'online_razorpay' => round($onlineRevenue, 2),
                    'pay_at_hotel'    => round($offlineRevenue, 2),
                ],
            ],
            'top_hotels'      => $topHotels,
            'recent_bookings' => $recentBookings,
            'recent_hotels'   => $recentHotels,
        ]);
    }

    /**
     * Save Custom Target Goal
     * POST /api/admin/dashboard/target-goal
     */
    public function updateTargetGoal(Request $request)
    {
        $request->validate([
            'target_goal' => 'required|numeric|min:10000|max:100000000',
        ]);

        $newGoal = (float) $request->input('target_goal');
        Setting::set('monthly_target_goal', $newGoal);

        return response()->json([
            'success'     => true,
            'message'     => 'Monthly target goal updated successfully.',
            'target_goal' => $newGoal,
        ]);
    }

    /**
     * AI Strategic Agent Intelligence Analysis Engine
     * GET /api/admin/dashboard/ai-analysis
     */
    public function getAiAnalysis()
    {
        $targetGoal = (float) Setting::get('monthly_target_goal', 500000);

        $startOfMonth = Carbon::now()->startOfMonth();
        $endOfMonth   = Carbon::now()->endOfMonth();
        $daysRemainingInMonth = max(1, Carbon::now()->daysInMonth - Carbon::now()->day);

        $currentMonthRevenue = (float) Booking::where(function($q) {
            $q->where('payment_status', 'paid')
              ->orWhereIn('status', ['confirmed', 'completed']);
        })
        ->whereNotIn('status', ['cancelled'])
        ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
        ->whereBetween('created_at', [$startOfMonth, $endOfMonth])
        ->sum(DB::raw('COALESCE(total_payable, total_amount)'));

        $remainingShortfall = max(0, $targetGoal - $currentMonthRevenue);
        $goalPercentage = $targetGoal > 0 ? min(100, round(($currentMonthRevenue / $targetGoal) * 100, 1)) : 0;

        $pendingHotels = Hotel::where('status', 'pending')->with('owner:id,name,email')->get();
        $pendingOwners = User::where('role', 'owner')
            ->where(function($q) {
                $q->whereRaw('("is_verified" = false OR "is_verified" IS FALSE OR "is_verified" IS NULL)')
                  ->orWhereDoesntHave('ownerProfile');
            })->get();

        $activeBannersCount = \App\Models\Banner::whereRaw('("is_active" = true OR "is_active" IS TRUE)')->count();
        $avgPricePerNight   = max(1000, (float) Hotel::whereIn('status', ['approved', 'active'])->avg('price_per_night'));
        if ($avgPricePerNight <= 0) $avgPricePerNight = 2000;

        // Mathematical Breakdown for Goal Achievement
        $requiredRoomNights = ceil($remainingShortfall / $avgPricePerNight);
        $dailyRevenueNeeded  = ceil($remainingShortfall / $daysRemainingInMonth);
        $dailyBookingsNeeded = ceil($dailyRevenueNeeded / $avgPricePerNight);

        $prioritySteps = [];
        $stepNumber = 1;

        // Goal Status Summary Step
        if ($remainingShortfall > 0) {
            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Target Goal Achievement Roadmap (Target: ₹" . number_format($targetGoal, 0) . ")",
                'category'        => 'Goal Milestone Strategy',
                'priority'        => 'CRITICAL',
                'rationale'       => "To achieve your custom target goal of ₹" . number_format($targetGoal, 0) . " this month, you need ₹" . number_format($remainingShortfall, 0) . " more in revenue. At an avg room rate of ₹" . number_format($avgPricePerNight, 0) . "/night, your ecosystem requires approximately {$requiredRoomNights} room night bookings over the remaining {$daysRemainingInMonth} days (~{$dailyBookingsNeeded} bookings/day).",
                'estimated_value' => "₹" . number_format($remainingShortfall, 0) . " Target Unlock",
                'action_tab'      => 'dashboard',
                'action_label'    => 'View Live Goal Roadmap',
            ];
        } else {
            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Target Goal Achieved! (₹" . number_format($targetGoal, 0) . ")",
                'category'        => 'Milestone Accomplished',
                'priority'        => 'HIGH',
                'rationale'       => "Congratulations! You have surpassed your monthly revenue target goal of ₹" . number_format($targetGoal, 0) . " by generating ₹" . number_format($currentMonthRevenue, 0) . " this month.",
                'estimated_value' => "₹" . number_format($currentMonthRevenue, 0) . " Achieved",
                'action_tab'      => 'dashboard',
                'action_label'    => 'Set Next Goal Target',
            ];
        }

        // Step 2: Pending Hotel Approvals & Room Capacity Strategy
        if ($pendingHotels->isNotEmpty()) {
            $topPending = $pendingHotels->sortByDesc('total_rooms')->first();
            $topCity = $topPending ? ($topPending->city ?? 'Primary City') : 'Key Market';
            $potentialRev = $pendingHotels->sum('total_rooms') * $avgPricePerNight * 10;

            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Approve {$pendingHotels->count()} Pending Hotel Listings (Unlocks Inventory)",
                'category'        => 'Inventory Capacity',
                'priority'        => 'HIGH',
                'rationale'       => "Approving {$topPending->name} in {$topCity} ({$topPending->total_rooms} rooms) and {$pendingHotels->count()} total pending hotels will add {$pendingHotels->sum('total_rooms')} new room slots, providing the room supply required to hit your target goal.",
                'estimated_value' => "₹" . number_format($potentialRev, 0),
                'action_tab'      => 'hotels',
                'action_label'    => 'Approve Pending Hotels',
            ];
        }

        // Step 3: Owner KYC Verification Strategy
        if ($pendingOwners->isNotEmpty()) {
            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Verify {$pendingOwners->count()} Pending Hotel Owner Accounts",
                'category'        => 'Partner Supply',
                'priority'        => 'HIGH',
                'rationale'       => "Verifying owner accounts enables partners to publish new hotel slots and participate in promotional campaigns.",
                'estimated_value' => "₹" . number_format($pendingOwners->count() * 35000, 0),
                'action_tab'      => 'owners',
                'action_label'    => 'Verify Owner KYCs',
            ];
        }

        // Step 4: Promotional Offer Banner Campaign Strategy
        if ($activeBannersCount < 2) {
            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Launch a 15% OFF Banner Offer to Drive ~{$dailyBookingsNeeded} Bookings/Day",
                'category'        => 'Marketing Velocity',
                'priority'        => 'MEDIUM',
                'rationale'       => "To achieve your required booking velocity of ~{$dailyBookingsNeeded} bookings per day, launch a 15%-20% promotional offer banner under Banners tab (e.g. YAANGOAL15). Offer banners boost customer app booking conversions by ~25%.",
                'estimated_value' => "₹" . number_format($remainingShortfall * 0.4, 0),
                'action_tab'      => 'banners',
                'action_label'    => 'Create Goal Banner Offer',
            ];
        }

        // Step 5: Pricing Strategy & Slot Optimization
        $suggestedPriceMin = round($avgPricePerNight * 0.9, 0);
        $suggestedPriceMax = round($avgPricePerNight * 1.25, 0);
        $prioritySteps[] = [
            'step'            => $stepNumber++,
            'title'           => "Optimize Hotel Room Price Slots (₹{$suggestedPriceMin} - ₹{$suggestedPriceMax}/night)",
            'category'        => 'Yield Optimization',
            'priority'        => 'MEDIUM',
            'rationale'       => "Average approved hotel room rate is ₹" . number_format($avgPricePerNight, 0) . "/night. Maintaining price slots in the sweet spot (₹{$suggestedPriceMin} - ₹{$suggestedPriceMax}) optimizes occupancy rates to achieve target goal revenue faster.",
            'estimated_value' => "+20% Booking Yield",
            'action_tab'      => 'hotels',
            'action_label'    => 'Inspect Hotel Slots',
        ];

        return response()->json([
            'success' => true,
            'summary' => [
                'status'                 => 'TARGET_GOAL_ROADMAP_READY',
                'target_goal'            => (float) $targetGoal,
                'current_month_revenue'  => (float) $currentMonthRevenue,
                'remaining_shortfall'    => (float) $remainingShortfall,
                'goal_percentage'        => $goalPercentage,
                'required_room_nights'   => $requiredRoomNights,
                'daily_bookings_needed'  => $dailyBookingsNeeded,
                'days_remaining'         => $daysRemainingInMonth,
                'total_reward_estimate'  => "₹" . number_format(max($remainingShortfall, $currentMonthRevenue), 0),
            ],
            'priority_steps' => $prioritySteps,
            'market_conditions' => [
                'season_trend'             => 'High Urban Travel & Weekend Getaway Surge',
                'demand_surge_cities'      => ['Ahmedabad', 'Surat', 'Vadodara', 'Mumbai'],
                'pricing_sweet_spot'       => "₹{$suggestedPriceMin} - ₹{$suggestedPriceMax} per night",
                'strategic_recommendation' => "To hit your target goal of ₹" . number_format($targetGoal, 0) . ", generate ~{$dailyBookingsNeeded} bookings/day by approving pending hotel listings and launching a targeted 15% offer banner.",
            ],
        ]);
    }
}
