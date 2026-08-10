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

        // Filter by Transaction Type / Status (Strict Success vs Temporary vs Cancelled/Refunded)
        if ($request->has('type') && !empty($request->type)) {
            $type = strtolower($request->type);
            if ($type === 'confirmed' || $type === 'success') {
                // Confirmed / Success Block: Strictly 100% verified paid/confirmed payments
                $query->where(function($q) {
                    $q->where('payment_status', 'paid')
                      ->orWhere(function($q2) {
                          $q2->whereIn('status', ['confirmed', 'completed'])
                             ->whereNotIn('payment_status', ['pending', 'failed', 'refunded']);
                      });
                });
            } elseif ($type === 'temporary' || $type === 'temp') {
                // Temporary / Incomplete Block: Strictly pending/unconfirmed active attempts (excluding cancelled & refunded)
                $query->where('status', 'pending')
                      ->whereNotIn('payment_status', ['paid', 'refunded']);
            } elseif ($type === 'cancelled' || $type === 'failed') {
                // Cancelled / Failed / Refunded Block: Strictly all cancelled, refunded, or failed records
                $query->where(function($q) {
                    $q->where('status', 'cancelled')
                      ->orWhere('payment_status', 'refunded')
                      ->orWhere('payment_status', 'failed');
                });
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
        $confirmedBookings = $allBookings->filter(fn($b) => $b->payment_status === 'paid' || (($b->status === 'confirmed' || $b->status === 'completed') && !in_array($b->payment_status, ['pending', 'failed', 'refunded'])));
        $temporaryBookings = $allBookings->filter(fn($b) => $b->status === 'pending' && !in_array($b->payment_status, ['paid', 'refunded']));
        $cancelledBookings = $allBookings->filter(fn($b) => $b->status === 'cancelled' || $b->payment_status === 'refunded' || $b->payment_status === 'failed');

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

                    if (($razorpayData['amount_refunded'] ?? 0) > 0 || $liveStatus === 'refunded') {
                        $transaction->payment_status = 'refunded';
                        $transaction->status = 'cancelled';
                        $transaction->cancellation_reason = 'Payment refunded at Razorpay';
                        $transaction->gateway_response = json_encode($razorpayData);
                        $transaction->save();
                    } elseif (in_array($liveStatus, ['captured', 'authorized'])) {
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
                        
                        if (($latestPayment['amount_refunded'] ?? 0) > 0 || $liveStatus === 'refunded') {
                            $transaction->payment_status = 'refunded';
                            $transaction->status = 'cancelled';
                            $transaction->cancellation_reason = 'Payment refunded at Razorpay';
                            $transaction->gateway_response = json_encode($latestPayment);
                            $transaction->save();
                        } elseif (in_array($liveStatus, ['captured', 'authorized'])) {
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
     * Initiate a real refund via Razorpay Refund API (Prompt 2 Fix).
     * Endpoint: POST /api/admin/transactions/{id}/refund
     */
    public function refundTransaction(Request $request, $id)
    {
        $request->validate([
            'reason' => 'required|string|min:5',
        ]);

        $transaction = Booking::with(['user', 'hotel'])->findOrFail($id);

        if (empty($transaction->razorpay_payment_id)) {
            return response()->json([
                'error'   => 'Missing Razorpay Payment ID',
                'message' => 'Cannot issue refund because no Razorpay Payment ID (razorpay_payment_id) is associated with this booking record.'
            ], 422);
        }

        if ($transaction->payment_status === 'refunded') {
            return response()->json([
                'error'   => 'Already Refunded',
                'message' => 'This payment has already been marked as refunded.'
            ], 422);
        }

        $razorpayKeyId     = config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID');
        $razorpayKeySecret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');

        if (empty($razorpayKeyId) || empty($razorpayKeySecret)) {
            return response()->json([
                'error'   => 'Configuration Error',
                'message' => 'Razorpay API credentials missing in server environment.'
            ], 500);
        }

        try {
            $amountInPaise = (int) round(($transaction->total_payable ?? $transaction->total_amount) * 100);

            // POST /v1/payments/{payment_id}/refund
            $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                ->post("https://api.razorpay.com/v1/payments/{$transaction->razorpay_payment_id}/refund", [
                    'amount' => $amountInPaise,
                    'notes'  => [
                        'admin_id'    => $request->user() ? $request->user()->id : null,
                        'booking_id'  => $transaction->id,
                        'reason'      => $request->reason,
                    ]
                ]);

            if (!$response->successful()) {
                $errorMsg = $response->json('error.description') ?? 'Razorpay refund request failed';
                Log::error("Razorpay Refund API Error for Booking #{$id}: " . $response->body());

                // If already refunded at Razorpay, update local database gracefully
                if (str_contains(strtolower($errorMsg), 'refunded')) {
                    $transaction->payment_status      = 'refunded';
                    $transaction->status              = 'cancelled';
                    $transaction->cancellation_reason = 'Payment was fully refunded already with Razorpay';
                    $transaction->save();

                    return response()->json([
                        'success'     => true,
                        'message'     => 'Payment is already fully refunded at Razorpay. Status updated in system.',
                        'transaction' => $transaction,
                    ]);
                }

                return response()->json([
                    'error'   => 'Razorpay Refund Failed',
                    'message' => $errorMsg,
                ], 400);
            }

            $refundData = $response->json();
            $refundId   = $refundData['id'] ?? 'REF_' . time();

            // Record audit log for status change
            \App\Models\TransactionStatusOverride::create([
                'booking_id'         => $transaction->id,
                'admin_id'           => $request->user()->id,
                'old_status'         => $transaction->status,
                'new_status'         => 'cancelled',
                'old_payment_status' => $transaction->payment_status,
                'new_payment_status' => 'refund_initiated',
                'reason'             => 'Razorpay Refund Initiated: ' . $request->reason . ' (Refund ID: ' . $refundId . ')',
                'ip_address'         => $request->ip(),
            ]);

            // Update status to refund_initiated, defer final 'refunded' status to refund.processed webhook
            $transaction->payment_status      = 'refund_initiated';
            $transaction->cancellation_reason = 'Refund Initiated via Razorpay API: ' . $request->reason;
            $transaction->gateway_response    = json_encode($refundData);
            $transaction->save();

            return response()->json([
                'success'     => true,
                'message'     => "Refund of ₹{$transaction->total_payable} initiated successfully with Razorpay (Refund ID: {$refundId}). Status will finalize when refund.processed webhook fires.",
                'refund'      => $refundData,
                'transaction' => $transaction,
            ]);
        } catch (\Throwable $e) {
            Log::error("Refund Exception for Booking #{$id}: " . $e->getMessage());
            return response()->json([
                'error'   => 'Refund Exception',
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Update transaction status & cancellation reason (Admin Override with mandatory Audit Log - Prompt 3).
     */
    public function updateStatus(Request $request, $id)
    {
        $request->validate([
            'status'              => 'required|in:pending,confirmed,completed,cancelled',
            'payment_status'      => 'nullable|in:pending,paid,failed,refunded',
            'cancellation_reason' => 'required|string|min:5',
        ]);

        $admin = $request->user();
        if (!$admin || $admin->role !== 'admin') {
            return response()->json([
                'error' => 'Unauthorized access. Only admin accounts can override status.'
            ], 403);
        }

        $transaction = Booking::findOrFail($id);

        $oldStatus        = $transaction->status;
        $oldPaymentStatus = $transaction->payment_status;
        $newStatus        = $request->status;
        $newPaymentStatus = $request->input('payment_status', $oldPaymentStatus);
        $reason           = $request->cancellation_reason;

        // If cancelling a confirmed/captured payment, route to Razorpay Refund API (Prompt 2)
        if ($newStatus === 'cancelled' && ($oldPaymentStatus === 'paid' || $transaction->is_confirmed) && !empty($transaction->razorpay_payment_id)) {
            return $this->refundTransaction($request, $id);
        }

        // Log manual override audit entry in transaction_status_overrides table
        \App\Models\TransactionStatusOverride::create([
            'booking_id'         => $transaction->id,
            'admin_id'           => $admin->id,
            'old_status'         => $oldStatus,
            'new_status'         => $newStatus,
            'old_payment_status' => $oldPaymentStatus,
            'new_payment_status' => $newPaymentStatus,
            'reason'             => $reason,
            'ip_address'         => $request->ip(),
        ]);

        $transaction->status              = $newStatus;
        $transaction->payment_status      = $newPaymentStatus;
        $transaction->cancellation_reason = $reason;

        // If updated to confirmed and no transaction_id exists, assign one
        if (($newStatus === 'confirmed' || $newPaymentStatus === 'paid') && empty($transaction->transaction_id)) {
            $transaction->transaction_id = $transaction->razorpay_payment_id ?? ('TXN-' . time() . '-' . $transaction->id);
        }

        $transaction->save();

        return response()->json([
            'message'     => 'Transaction status updated and logged to audit table successfully.',
            'transaction' => $transaction,
        ]);
    }

    /**
     * Generate structured invoice data for a transaction/booking.
     * Endpoint: GET /api/admin/transactions/{id}/invoice
     */
    public function getInvoice($id)
    {
        $transaction = Booking::with(['user', 'hotel.owner'])->findOrFail($id);

        $invoiceNumber = 'INV-' . date('Y', strtotime($transaction->created_at ?? now())) . '-' . str_pad($transaction->id, 6, '0', STR_PAD_LEFT);
        $invoiceDate   = $transaction->created_at ? $transaction->created_at->format('d M Y') : date('d M Y');

        $basePrice    = (float) ($transaction->price_per_night ?? ($transaction->total_amount / max(1, $transaction->total_nights)));
        $gstAmount    = (float) ($transaction->gst_amount ?? ($transaction->total_amount * 0.18));
        $totalPayable = (float) ($transaction->total_payable ?? ($transaction->total_amount + $gstAmount));

        return response()->json([
            'invoice' => [
                'invoice_number' => $invoiceNumber,
                'invoice_date'   => $invoiceDate,
                'company' => [
                    'name'     => 'Yaan Logistics & Hotel Platform',
                    'address'  => 'Tech Hub, Corporate Towers, Cyber City',
                    'city'     => 'Bangalore, India',
                    'email'    => 'support@yaan.com',
                    'gstin'    => '29AAAAA0000A1Z5',
                ],
                'customer' => [
                    'name'  => $transaction->user ? $transaction->user->name : 'Guest User',
                    'email' => $transaction->user ? $transaction->user->email : 'N/A',
                    'phone' => $transaction->user ? $transaction->user->phone : 'N/A',
                ],
                'hotel' => [
                    'name'    => $transaction->hotel ? $transaction->hotel->name : 'N/A',
                    'city'    => $transaction->hotel ? $transaction->hotel->city : 'N/A',
                    'address' => $transaction->hotel ? $transaction->hotel->address : 'N/A',
                ],
                'stay' => [
                    'check_in'     => $transaction->check_in ? $transaction->check_in->format('Y-m-d') : 'N/A',
                    'check_out'    => $transaction->check_out ? $transaction->check_out->format('Y-m-d') : 'N/A',
                    'total_nights' => $transaction->total_nights ?? 1,
                    'guests'       => $transaction->guests ?? 1,
                ],
                'logistics' => [
                    'truck_type'       => $transaction->truck_type ?? 'N/A',
                    'truck_no'         => $transaction->truck_no ?? 'N/A',
                    'logistics_name'   => $transaction->logistics_name ?? 'N/A',
                    'logistics_number' => $transaction->logistics_number ?? 'N/A',
                ],
                'payment' => [
                    'status'                 => strtoupper($transaction->payment_status === 'paid' || $transaction->is_confirmed ? 'PAID' : $transaction->status),
                    'payment_method'         => $transaction->payment_method ?? 'Razorpay / Online',
                    'display_transaction_id' => $transaction->display_transaction_id,
                    'transaction_id'         => $transaction->transaction_id,
                    'temp_transaction_id'    => $transaction->temp_transaction_id,
                    'razorpay_order_id'      => $transaction->razorpay_order_id,
                    'razorpay_payment_id'    => $transaction->razorpay_payment_id,
                    'region_time'            => $transaction->region_time_formatted,
                    'cancellation_reason'    => $transaction->cancellation_reason,
                ],
                'pricing' => [
                    'price_per_night'   => $basePrice,
                    'subtotal'          => (float) $transaction->total_amount,
                    'promotion_applied' => (float) ($transaction->promotion_applied ?? 0.00),
                    'gst_amount'        => $gstAmount,
                    'total_payable'     => $totalPayable,
                    'currency'          => 'INR',
                ],
                'booking_raw' => $transaction,
            ]
        ]);
    }
}

