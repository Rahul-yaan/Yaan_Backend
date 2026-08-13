<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Hotel;
use App\Models\Booking;
use App\Models\OwnerProfile;
use App\Models\Review;
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

        // Current Month Revenue & Target Goal Calculation
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

        // Dynamic Monthly Target Goal (Default ₹5,00,000 or scaled to active hotels/bookings)
        $targetGoal = max(500000, ceil($totalRevenue * 1.25)); // Target Goal
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
     * AI Strategic Agent Intelligence Analysis
     * GET /api/admin/dashboard/ai-analysis
     */
    public function getAiAnalysis()
    {
        $pendingHotels = Hotel::where('status', 'pending')->with('owner:id,name,email')->get();
        $pendingOwners = User::where('role', 'owner')
            ->where(function($q) {
                $q->whereRaw('("is_verified" = false OR "is_verified" IS FALSE OR "is_verified" IS NULL)')
                  ->orWhereDoesntHave('ownerProfile');
            })->get();

        $activeBannersCount = \App\Models\Banner::whereRaw('("is_active" = true OR "is_active" IS TRUE)')->count();
        $avgPricePerNight   = (float) Hotel::whereIn('status', ['approved', 'active'])->avg('price_per_night');
        $approvedCount      = Hotel::whereIn('status', ['approved', 'active'])->count();

        $prioritySteps = [];
        $stepNumber = 1;

        // Step 1: Pending Hotel Approvals Analysis
        if ($pendingHotels->isNotEmpty()) {
            $topPending = $pendingHotels->sortByDesc('total_rooms')->first();
            $topCity = $topPending ? ($topPending->city ?? 'Primary City') : 'Key Market';
            $potentialRev = $pendingHotels->sum('total_rooms') * 1500 * 15; // Estimated monthly unlock

            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Approve {$pendingHotels->count()} Pending Hotel Listings First",
                'category'        => 'Inventory & Capacity',
                'priority'        => 'HIGH',
                'rationale'       => "High priority: Approve {$topPending->name} in {$topCity} ({$topPending->total_rooms} rooms). Approving these pending listings immediately expands active inventory by {$pendingHotels->count()} hotels.",
                'estimated_value' => "₹" . number_format($potentialRev, 0),
                'action_tab'      => 'hotels',
                'action_label'    => 'Review & Approve Hotels Now',
            ];
        }

        // Step 2: Owner KYC Verification Analysis
        if ($pendingOwners->isNotEmpty()) {
            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Verify {$pendingOwners->count()} Pending Hotel Owner Accounts",
                'category'        => 'Partner Onboarding',
                'priority'        => 'HIGH',
                'rationale'       => "Verifying owner accounts enables partners to publish new hotel slots and participate in promotional campaigns.",
                'estimated_value' => "₹" . number_format($pendingOwners->count() * 35000, 0),
                'action_tab'      => 'owners',
                'action_label'    => 'Verify Owner KYCs Now',
            ];
        }

        // Step 3: Promotional Offer Banner Campaign Analysis
        if ($activeBannersCount < 2) {
            $prioritySteps[] = [
                'step'            => $stepNumber++,
                'title'           => "Launch a Targeted Discount Offer Banner",
                'category'        => 'Marketing & Conversion',
                'priority'        => 'MEDIUM',
                'rationale'       => "Currently you have {$activeBannersCount} active banners. Creating a 15%-20% discount offer banner (e.g. YAANFAMILY5) boosts customer app booking conversions by ~25%.",
                'estimated_value' => "₹50,000 - ₹1,20,000",
                'action_tab'      => 'banners',
                'action_label'    => 'Create Offer Banner Now',
            ];
        }

        // Step 4: Pricing Tier Optimization
        $suggestedPriceMin = $avgPricePerNight > 0 ? round($avgPricePerNight * 0.9, 0) : 1200;
        $suggestedPriceMax = $avgPricePerNight > 0 ? round($avgPricePerNight * 1.2, 0) : 3500;
        $prioritySteps[] = [
            'step'            => $stepNumber++,
            'title'           => "Optimize Hotel Room Price Slots (₹{$suggestedPriceMin} - ₹{$suggestedPriceMax})",
            'category'        => 'Pricing Strategy',
            'priority'        => 'MEDIUM',
            'rationale'       => "Current average approved hotel price is ₹" . number_format($avgPricePerNight, 0) . "/night. Approving price slots in the sweet spot (₹{$suggestedPriceMin} - ₹{$suggestedPriceMax}) captures max customer bookings.",
            'estimated_value' => "+18% Net Yield",
            'action_tab'      => 'hotels',
            'action_label'    => 'Inspect Hotel Slots',
        ];

        // Overall Estimated Reward Potential
        $totalRewardEstimate = ($pendingHotels->count() * 45000) + ($pendingOwners->count() * 30000) + 60000;

        return response()->json([
            'success' => true,
            'summary' => [
                'status'                 => 'OPTIMAL_STRATEGY_READY',
                'total_reward_estimate'  => "₹" . number_format($totalRewardEstimate, 0),
                'pending_hotels_count'   => $pendingHotels->count(),
                'pending_owners_count'   => $pendingOwners->count(),
                'active_banners_count'   => $activeBannersCount,
                'avg_approved_price'     => round($avgPricePerNight, 2),
            ],
            'priority_steps' => $prioritySteps,
            'market_conditions' => [
                'season_trend'         => 'High Urban Travel & Weekend Getaway Surge',
                'demand_surge_cities'  => ['Ahmedabad', 'Surat', 'Vadodara', 'Mumbai'],
                'digital_adoption'     => 'Razorpay online payments account for the majority of completed bookings.',
                'pricing_sweet_spot'   => "₹{$suggestedPriceMin} - ₹{$suggestedPriceMax} per night",
                'strategic_recommendation' => "Approve high-capacity pending hotels first, then launch a 15% user offer banner under Banners tab to maximize target goal achievement.",
            ],
        ]);
    }
}
