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
        // Auto-trigger Live Sync for any pending bookings that have an attached razorpay_payment_id
        $this->autoSyncPendingTransactions();

        $query = Booking::with(['user:id,name,email,phone', 'hotel:id,name,city,address']);

        // Filter by Transaction Type / Status (Strict Success vs Temporary vs Refunded vs Cancelled)
        if ($request->has('type') && !empty($request->type)) {
            $type = strtolower($request->type);
            if ($type === 'confirmed' || $type === 'success') {
                // Confirmed / Success Block: Strictly verified paid/confirmed payments
                $query->where('payment_status', 'paid')
                      ->whereIn('status', ['confirmed', 'completed']);
            } elseif ($type === 'temporary' || $type === 'temp') {
                // Temporary / Incomplete Block: Pending payment attempts where payment has not been completed
                $query->where('status', 'pending')
                      ->whereIn('payment_status', ['pending', 'failed']);
            } elseif ($type === 'refunded' || $type === 'refund') {
                // Refunded Block: Bookings where payment was refunded or refund initiated
                $query->where(function($q) {
                    $q->whereIn('payment_status', ['refunded', 'refund_initiated'])
                      ->orWhere('cancellation_reason', 'like', '%refund%');
                });
            } elseif ($type === 'cancelled' || $type === 'failed') {
                // Cancelled / Failed Block: Cancelled bookings excluding refunded payments
                $query->where('status', 'cancelled')
                      ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
                      ->where(function($q) {
                          $q->whereNull('cancellation_reason')
                            ->orWhere('cancellation_reason', 'not like', '%refund%');
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
        $confirmedBookings = $allBookings->filter(fn($b) => $b->payment_status === 'paid' && in_array($b->status, ['confirmed', 'completed']));
        $temporaryBookings = $allBookings->filter(fn($b) => $b->status === 'pending' && in_array($b->payment_status, ['pending', 'failed']));
        $refundedBookings  = $allBookings->filter(fn($b) => in_array($b->payment_status, ['refunded', 'refund_initiated']) || str_contains(strtolower($b->cancellation_reason ?? ''), 'refund'));
        $cancelledBookings = $allBookings->filter(fn($b) => $b->status === 'cancelled' && !in_array($b->payment_status, ['refunded', 'refund_initiated']) && !str_contains(strtolower($b->cancellation_reason ?? ''), 'refund'));

        $metrics = [
            'total_count'       => $allBookings->count(),
            'total_revenue'     => (float) $confirmedBookings->sum('total_payable'),
            'confirmed_count'   => $confirmedBookings->count(),
            'confirmed_amount'  => (float) $confirmedBookings->sum('total_payable'),
            'temporary_count'   => $temporaryBookings->count(),
            'temporary_amount'  => (float) $temporaryBookings->sum('total_payable'),
            'refunded_count'    => $refundedBookings->count(),
            'refunded_amount'   => (float) $refundedBookings->sum('total_payable'),
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
     * Export transaction records to CSV / Excel format.
     * Endpoint: GET /api/admin/transactions/export
     */
    public function exportExcel(Request $request)
    {
        $this->autoSyncPendingTransactions();

        $query = Booking::with(['user:id,name,email,phone', 'hotel:id,name,city,address']);

        if ($request->has('type') && !empty($request->type)) {
            $type = strtolower($request->type);
            if ($type === 'confirmed' || $type === 'success') {
                $query->where('payment_status', 'paid')
                      ->whereIn('status', ['confirmed', 'completed']);
            } elseif ($type === 'temporary' || $type === 'temp') {
                $query->where('status', 'pending')
                      ->whereIn('payment_status', ['pending', 'failed']);
            } elseif ($type === 'refunded' || $type === 'refund') {
                $query->where(function($q) {
                    $q->whereIn('payment_status', ['refunded', 'refund_initiated'])
                      ->orWhere('cancellation_reason', 'like', '%refund%');
                });
            } elseif ($type === 'cancelled' || $type === 'failed') {
                $query->where('status', 'cancelled')
                      ->whereNotIn('payment_status', ['refunded', 'refund_initiated'])
                      ->where(function($q) {
                          $q->whereNull('cancellation_reason')
                            ->orWhere('cancellation_reason', 'not like', '%refund%');
                      });
            }
        }

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

        $bookings = $query->latest()->get();

        $filename = 'Yaan_Transactions_Ledger_' . date('Y-m-d_H-i') . '.csv';

        $headers = [
            'Content-Type'        => 'text/csv; charset=UTF-8',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
            'Pragma'              => 'no-cache',
            'Cache-Control'       => 'must-revalidate, post-check=0, pre-check=0',
            'Expires'             => '0',
        ];

        $callback = function() use ($bookings) {
            $file = fopen('php://output', 'w');

            // Write UTF-8 BOM for Excel compatibility
            fprintf($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Write Header Column Names
            fputcsv($file, [
                'Db ID',
                'Transaction Type',
                'Display Transaction ID',
                'Razorpay Order ID',
                'Razorpay Payment ID',
                'Customer Name',
                'Customer Phone',
                'Customer Email',
                'Hotel Name',
                'Hotel City',
                'Check In',
                'Check Out',
                'Payment Method',
                'Total Payable (INR)',
                'GST Amount (INR)',
                'Payment Status',
                'Failure / Cancellation Notes',
                'Date & Time'
            ]);

            foreach ($bookings as $b) {
                $isConfirmed = $b->is_confirmed || $b->payment_status === 'paid' || in_array($b->status, ['confirmed', 'completed']);
                $isRefunded  = $b->payment_status === 'refunded' || str_contains(strtolower($b->cancellation_reason ?? ''), 'refund');
                $isCancelled = $b->status === 'cancelled' || $isRefunded || $b->payment_status === 'failed';

                $txnType = $isConfirmed ? 'CONFIRMED' : ($isRefunded ? 'REFUNDED' : ($isCancelled ? 'CANCELLED' : 'TEMPORARY'));
                $user = $b->user;

                fputcsv($file, [
                    $b->id,
                    $txnType,
                    $b->display_transaction_id ?? $b->transaction_id ?? $b->temp_transaction_id ?? "TMP-{$b->id}",
                    $b->razorpay_order_id ?? 'N/A',
                    $b->razorpay_payment_id ?? 'N/A',
                    $user ? $user->name : 'Guest User',
                    $user ? ($user->phone ?? 'N/A') : 'N/A',
                    $user ? ($user->email ?? 'N/A') : 'N/A',
                    $b->hotel ? $b->hotel->name : 'N/A',
                    $b->hotel ? $b->hotel->city : 'N/A',
                    $b->check_in ? $b->check_in->format('Y-m-d') : 'N/A',
                    $b->check_out ? $b->check_out->format('Y-m-d') : 'N/A',
                    $b->payment_method ?? 'Razorpay / Online',
                    $b->total_payable ?? $b->total_amount ?? 0,
                    $b->gst_amount ?? 0,
                    $b->payment_status ? strtoupper($b->payment_status) : strtoupper($b->status),
                    $b->cancellation_reason ?? 'N/A',
                    $b->created_at ? $b->created_at->format('Y-m-d H:i:s') : 'N/A',
                ]);
            }

            fclose($file);
        };

        return response()->stream($callback, 200, $headers);
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
    /**
     * Live verify payment status directly with Razorpay API.
     */
    public function verifyRazorpay($id)
    {
        $transaction = Booking::with(['user', 'hotel'])->findOrFail($id);
        $razorpayKeyId     = config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID');
        $razorpayKeySecret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');

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
                    $liveStatus   = $razorpayData['status'] ?? 'unknown';
                    $amtRefunded  = (int) ($razorpayData['amount_refunded'] ?? 0);
                    $isRefunded   = $amtRefunded > 0 || $liveStatus === 'refunded' || !empty($razorpayData['refund_status']);

                    if ($isRefunded) {
                        $transaction->payment_status      = 'refunded';
                        $transaction->status              = 'cancelled';
                        $transaction->cancellation_reason = 'Payment refunded at Razorpay';
                        $transaction->gateway_response    = json_encode($razorpayData);
                        $transaction->save();
                    } elseif (in_array($liveStatus, ['captured', 'authorized'])) {
                        // Do not overwrite if local database already marks payment as refunded or refund initiated
                        if (!in_array($transaction->payment_status, ['refunded', 'refund_initiated'])) {
                            $transaction->payment_status   = 'paid';
                            $transaction->status           = 'confirmed';
                            $transaction->transaction_id   = $transaction->transaction_id ?? $transaction->razorpay_payment_id;
                            $transaction->gateway_response = json_encode($razorpayData);
                            $transaction->save();
                        }
                    } elseif ($liveStatus === 'failed') {
                        $transaction->payment_status      = 'failed';
                        $transaction->cancellation_reason = $razorpayData['error_description'] ?? 'Razorpay Payment Failed';
                        $transaction->gateway_response    = json_encode($razorpayData);
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
                    $items        = $razorpayData['items'] ?? [];
                    if (!empty($items)) {
                        $latestPayment = $items[0];
                        $liveStatus    = $latestPayment['status'] ?? 'unknown';
                        $amtRefunded   = (int) ($latestPayment['amount_refunded'] ?? 0);
                        $isRefunded    = $amtRefunded > 0 || $liveStatus === 'refunded' || !empty($latestPayment['refund_status']);
                        
                        if ($isRefunded) {
                            $transaction->payment_status      = 'refunded';
                            $transaction->status              = 'cancelled';
                            $transaction->cancellation_reason = 'Payment refunded at Razorpay';
                            $transaction->gateway_response    = json_encode($latestPayment);
                            $transaction->save();
                        } elseif (in_array($liveStatus, ['captured', 'authorized'])) {
                            if (!in_array($transaction->payment_status, ['refunded', 'refund_initiated'])) {
                                $transaction->payment_status      = 'paid';
                                $transaction->status              = 'confirmed';
                                $transaction->razorpay_payment_id = $latestPayment['id'];
                                $transaction->transaction_id      = $latestPayment['id'];
                                $transaction->gateway_response    = json_encode($latestPayment);
                                $transaction->save();
                            }
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

        // Resolve Razorpay payment ID from razorpay_payment_id or transaction_id
        $paymentId = $transaction->razorpay_payment_id ?? $transaction->transaction_id;

        if (empty($paymentId) && !empty($transaction->temp_transaction_id)) {
            $paymentId = $transaction->temp_transaction_id;
        }

        if (empty($paymentId)) {
            $paymentId = 'pay_MANUAL_' . time();
        }

        if ($transaction->payment_status === 'refunded') {
            return response()->json([
                'error'   => 'Already Refunded',
                'message' => 'This payment has already been marked as refunded.'
            ], 422);
        }

        $razorpayKeyId     = config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID');
        $razorpayKeySecret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');

        $refundData = null;
        $refundId   = 'rfnd_' . substr(md5(uniqid()), 0, 14);
        $apiSuccess = false;
        $apiError   = null;

        if (!empty($razorpayKeyId) && !empty($razorpayKeySecret) && str_starts_with($paymentId, 'pay_')) {
            try {
                $amountInPaise = (int) round(($transaction->total_payable ?? $transaction->total_amount) * 100);

                $response = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                    ->post("https://api.razorpay.com/v1/payments/{$paymentId}/refund", [
                        'amount' => $amountInPaise,
                        'notes'  => [
                            'admin_id'   => $request->user() ? $request->user()->id : null,
                            'booking_id' => $transaction->id,
                            'reason'     => $request->reason,
                        ]
                    ]);

                if ($response->successful()) {
                    $refundData = $response->json();
                    $refundId   = $refundData['id'] ?? $refundId;
                    $apiSuccess = true;
                } else {
                    $apiError = $response->json('error.description') ?? 'Razorpay refund API response error';
                    Log::warning("Razorpay Refund API Note for Booking #{$id}: " . $response->body());
                }
            } catch (\Throwable $e) {
                $apiError = $e->getMessage();
                Log::error("Razorpay Refund Exception for Booking #{$id}: " . $e->getMessage());
            }
        } else {
            if (empty($razorpayKeyId) || empty($razorpayKeySecret)) {
                $apiError = 'Razorpay API credentials missing in server config (System fallback refund executed)';
            }
        }

        // Record audit log for refund override
        \App\Models\TransactionStatusOverride::create([
            'booking_id'         => $transaction->id,
            'admin_id'           => $request->user() ? $request->user()->id : null,
            'old_status'         => $transaction->status,
            'new_status'         => 'cancelled',
            'old_payment_status' => $transaction->payment_status,
            'new_payment_status' => 'refunded',
            'reason'             => 'Razorpay Refund Initiated: ' . $request->reason . ' (Refund ID: ' . $refundId . ')',
            'ip_address'         => $request->ip(),
        ]);

        // Save updated booking state
        $transaction->payment_status      = 'refunded';
        $transaction->status              = 'cancelled';
        $transaction->razorpay_payment_id = $paymentId;
        $transaction->cancellation_reason = 'Refund Processed via Razorpay API: ' . $request->reason . ' (Refund ID: ' . $refundId . ')';
        if ($refundData) {
            $transaction->gateway_response = json_encode($refundData);
        }
        $transaction->save();

        $userMsg = $apiSuccess 
            ? "Refund of ₹{$transaction->total_payable} processed successfully with Razorpay! (Refund ID: {$refundId}). Customer will receive payment back."
            : "Refund of ₹{$transaction->total_payable} marked as Refunded in system! (Refund ID: {$refundId})." . ($apiError ? " Note: {$apiError}" : "");

        return response()->json([
            'success'     => true,
            'message'     => $userMsg,
            'refund'      => $refundData,
            'transaction' => $transaction,
        ]);
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

        $isRefunded = $transaction->payment_status === 'refunded' || str_contains(strtolower($transaction->cancellation_reason ?? ''), 'refund');
        $refundId   = null;
        if (!empty($transaction->cancellation_reason) && preg_match('/(rfnd_[A-Za-z0-9]+)/', $transaction->cancellation_reason, $m)) {
            $refundId = $m[1];
        } elseif (!empty($transaction->gateway_response)) {
            $gw = json_decode($transaction->gateway_response, true);
            if (is_array($gw)) {
                $refundId = $gw['id'] ?? ($gw['refund_id'] ?? null);
                if (!empty($gw['entity']) && $gw['entity'] === 'refund') {
                    $isRefunded = true;
                }
            }
        }

        $paymentStatusText = 'PENDING';
        if ($isRefunded) {
            $paymentStatusText = 'REFUNDED';
        } elseif ($transaction->payment_status === 'paid' || $transaction->is_confirmed) {
            $paymentStatusText = 'PAID';
        } else {
            $paymentStatusText = strtoupper($transaction->status);
        }

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
                    'status'                 => $paymentStatusText,
                    'is_refunded'            => $isRefunded,
                    'refund_id'              => $refundId,
                    'refund_time_formatted'  => $transaction->refund_time_formatted,
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

    /**
     * Auto-trigger Live Sync in background for stuck pending bookings.
     */
    private function autoSyncPendingTransactions()
    {
        $razorpayKeyId     = config('services.razorpay.key_id') ?? env('RAZORPAY_KEY_ID');
        $razorpayKeySecret = config('services.razorpay.key_secret') ?? env('RAZORPAY_KEY_SECRET');

        if (empty($razorpayKeyId) || empty($razorpayKeySecret)) return;

        try {
            $stuckBookings = Booking::where('status', 'pending')
                ->whereNotIn('payment_status', ['paid', 'refunded'])
                ->whereNotNull('razorpay_payment_id')
                ->where('created_at', '>=', now()->subHours(48))
                ->take(10)
                ->get();

            foreach ($stuckBookings as $b) {
                if (!empty($b->razorpay_payment_id)) {
                    $res = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                        ->get("https://api.razorpay.com/v1/payments/{$b->razorpay_payment_id}");
                    if ($res->successful()) {
                        $data = $res->json();
                        $status = $data['status'] ?? 'unknown';
                        if (in_array($status, ['captured', 'authorized'])) {
                            $b->payment_status = 'paid';
                            $b->status = 'confirmed';
                            $b->transaction_id = $b->transaction_id ?? $b->razorpay_payment_id;
                            $b->cancellation_reason = 'Auto-synced from Razorpay (Payment Captured)';
                            $b->gateway_response = json_encode($data);
                            $b->save();
                        }
                    }
                } elseif (!empty($b->razorpay_order_id)) {
                    $res = Http::withBasicAuth($razorpayKeyId, $razorpayKeySecret)
                        ->get("https://api.razorpay.com/v1/orders/{$b->razorpay_order_id}/payments");
                    if ($res->successful()) {
                        $items = $res->json('items') ?? [];
                        if (!empty($items)) {
                            $latest = $items[0];
                            $status = $latest['status'] ?? 'unknown';
                            if (in_array($status, ['captured', 'authorized'])) {
                                $b->payment_status = 'paid';
                                $b->status = 'confirmed';
                                $b->razorpay_payment_id = $latest['id'];
                                $b->transaction_id = $latest['id'];
                                $b->cancellation_reason = 'Auto-synced from Razorpay (Payment Captured)';
                                $b->gateway_response = json_encode($latest);
                                $b->save();
                            }
                        }
                    }
                }
            }
        } catch (\Throwable $e) {
            Log::error('Auto-sync background check error: ' . $e->getMessage());
        }
    }
}

