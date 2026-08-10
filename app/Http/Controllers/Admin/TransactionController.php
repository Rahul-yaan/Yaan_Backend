<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class TransactionController extends Controller
{
    /**
     * Display a listing of transactions (Confirmed & Temporary/Cancelled).
     */
    public function index(Request $request)
    {
        $query = Booking::with(['user:id,name,email,phone', 'hotel:id,name,city,address']);

        // Filter by Transaction Type / Status
        if ($request->has('type') && !empty($request->type)) {
            $type = strtolower($request->type);
            if ($type === 'confirmed') {
                $query->where(function($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere('status', 'confirmed')
                      ->orWhere('status', 'completed');
                });
            } elseif ($type === 'temporary') {
                $query->where(function($q) {
                    $q->where('status', 'pending')
                      ->orWhere('status', 'cancelled')
                      ->orWhere('payment_status', 'pending')
                      ->orWhere('payment_status', 'failed');
                });
            } elseif ($type === 'cancelled') {
                $query->where('status', 'cancelled');
            } elseif ($type === 'failed') {
                $query->where('payment_status', 'failed');
            }
        }

        // Filter by Payment Status specifically
        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $query->where('payment_status', $request->payment_status);
        }

        // Filter by Payment Method
        if ($request->has('payment_method') && !empty($request->payment_method)) {
            $query->where('payment_method', 'like', "%{$request->payment_method}%");
        }

        // Search query
        if ($request->has('search') && !empty($request->search)) {
            $search = $request->search;
            $query->where(function($q) use ($search) {
                $q->where('transaction_id', 'like', "%{$search}%")
                  ->orWhere('temp_transaction_id', 'like', "%{$search}%")
                  ->orWhere('razorpay_order_id', 'like', "%{$search}%")
                  ->orWhere('razorpay_payment_id', 'like', "%{$search}%")
                  ->orWhere('cancellation_reason', 'like', "%{$search}%")
                  ->orWhereHas('user', function($u) use ($search) {
                      $u->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%")
                        ->orWhere('phone', 'like', "%{$search}%");
                  })
                  ->orWhereHas('hotel', function($h) use ($search) {
                      $h->where('name', 'like', "%{$search}%")
                        ->orWhere('city', 'like', "%{$search}%");
                  });
            });
        }

        // Calculate Overview Metrics for Dashboard Widgets
        $allBookings = Booking::all();
        $confirmedBookings = $allBookings->filter(fn($b) => $b->payment_status === 'paid' || $b->status === 'confirmed' || $b->status === 'completed');
        $temporaryBookings = $allBookings->filter(fn($b) => $b->status === 'pending' || $b->status === 'cancelled' || $b->payment_status === 'pending' || $b->payment_status === 'failed');
        $cancelledBookings = $allBookings->filter(fn($b) => $b->status === 'cancelled');

        $metrics = [
            'total_count'       => $allBookings->count(),
            'total_revenue'     => (float) $confirmedBookings->sum('total_payable'),
            'confirmed_count'   => $confirmedBookings->count(),
            'confirmed_amount'  => (float) $confirmedBookings->sum('total_payable'),
            'temporary_count'   => $temporaryBookings->count(),
            'temporary_amount'  => (float) $temporaryBookings->sum('total_payable'),
            'cancelled_count'   => $cancelledBookings->count(),
            'cancelled_amount'  => (float) $cancelledBookings->sum('total_payable'),
        ];

        $transactions = $query->latest()->paginate($request->input('per_page', 15));

        return response()->json([
            'metrics'      => $metrics,
            'transactions' => $transactions,
        ]);
    }

    /**
     * Show detailed transaction info with Razorpay parameters.
     */
    public function show($id)
    {
        $transaction = Booking::with(['user', 'hotel.owner'])->findOrFail($id);

        return response()->json([
            'transaction' => $transaction,
            'razorpay' => [
                'key_id'          => env('RAZORPAY_KEY_ID'),
                'order_id'        => $transaction->razorpay_order_id,
                'payment_id'      => $transaction->razorpay_payment_id,
                'amount_in_paise' => (int) round(($transaction->total_payable ?? $transaction->total_amount) * 100),
                'currency'        => 'INR',
            ]
        ]);
    }

    /**
     * Live verify payment status directly with Razorpay API.
     */
    public function verifyRazorpay($id)
    {
        $transaction = Booking::with(['user', 'hotel'])->findOrFail($id);
        $razorpayKeyId = env('RAZORPAY_KEY_ID');
        $razorpayKeySecret = env('RAZORPAY_KEY_SECRET');

        if (empty($razorpayKeyId) || empty($razorpayKeySecret)) {
            return response()->json([
                'success' => false,
                'message' => 'Razorpay credentials (RAZORPAY_KEY_ID / RAZORPAY_KEY_SECRET) are missing in server environment.',
                'transaction' => $transaction
            ], 400);
        }

        $razorpayData = null;
        $liveStatus = 'unknown';

        try {
            // Check by Payment ID if available
            if (!empty($transaction->razorpay_payment_id)) {
                $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                    ->get("https://api.razorpay.com/v1/payments/{$transaction->razorpay_payment_id}");
                
                if ($response->successful()) {
                    $razorpayData = $response->json();
                    $liveStatus = $razorpayData['status'] ?? 'unknown';

                    if (in_array($liveStatus, ['captured', 'authorized'])) {
                        $transaction->payment_status = 'paid';
                        $transaction->status = 'confirmed';
                        $transaction->transaction_id = $transaction->transaction_id ?? $transaction->razorpay_payment_id;
                        $transaction->gateway_response = json_encode($razorpayData);
                        $transaction->save();
                    } elseif ($liveStatus === 'failed') {
                        $transaction->payment_status = 'failed';
                        $transaction->cancellation_reason = $razorpayData['error_description'] ?? 'Razorpay Payment Failed';
                        $transaction->gateway_response = json_encode($razorpayData);
                        $transaction->save();
                    }
                }
            } 
            // Else check by Order ID
            elseif (!empty($transaction->razorpay_order_id)) {
                $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                    ->get("https://api.razorpay.com/v1/orders/{$transaction->razorpay_order_id}/payments");

                if ($response->successful()) {
                    $razorpayData = $response->json();
                    $items = $razorpayData['items'] ?? [];
                    if (!empty($items)) {
                        $latestPayment = $items[0];
                        $liveStatus = $latestPayment['status'] ?? 'unknown';
                        if (in_array($liveStatus, ['captured', 'authorized'])) {
                            $transaction->payment_status = 'paid';
                            $transaction->status = 'confirmed';
                            $transaction->razorpay_payment_id = $latestPayment['id'];
                            $transaction->transaction_id = $latestPayment['id'];
                            $transaction->gateway_response = json_encode($latestPayment);
                            $transaction->save();
                        }
                    } else {
                        $liveStatus = 'created_unpaid';
                    }
                }
            } else {
                return response()->json([
                    'success' => false,
                    'message' => 'No Razorpay Order ID or Payment ID associated with this transaction record.',
                    'transaction' => $transaction
                ], 422);
            }

            return response()->json([
                'success'        => true,
                'message'        => "Razorpay live status synced: " . ucfirst($liveStatus),
                'live_status'    => $liveStatus,
                'razorpay_payload' => $razorpayData,
                'transaction'    => $transaction,
            ]);
        } catch (\Throwable $e) {
            Log::error('Razorpay verification error: ' . $e->getMessage());
            return response()->json([
                'success' => false,
                'message' => 'Failed to reach Razorpay API: ' . $e->getMessage(),
                'transaction' => $transaction
            ], 500);
        }
    }

    /**
     * Update transaction status & cancellation reason (Admin Override).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'              => 'required|in:pending,confirmed,completed,cancelled',
            'payment_status'      => 'nullable|in:pending,paid,failed,refunded',
            'cancellation_reason' => 'nullable|string',
        ]);

        $transaction = Booking::findOrFail($id);
        $transaction->status = $request->status;
        
        if ($request->has('payment_status') && !empty($request->payment_status)) {
            $transaction->payment_status = $request->payment_status;
        }

        if ($request->has('cancellation_reason')) {
            $transaction->cancellation_reason = $request->cancellation_reason;
        }

        // If updated to confirmed and no transaction_id exists, assign one
        if (($request->status === 'confirmed' || $request->payment_status === 'paid') && empty($transaction->transaction_id)) {
            $transaction->transaction_id = $transaction->razorpay_payment_id ?? ('TXN-' . time() . '-' . $transaction->id);
        }

        $transaction->save();

        return response()->json([
            'message'     => 'Transaction status updated successfully.',
            'transaction' => $transaction,
        ]);
    }
}
