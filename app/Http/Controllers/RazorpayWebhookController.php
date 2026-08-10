<?php

namespace App\Http\Controllers;

use App\Models\Booking;
use App\Models\RazorpayWebhook;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class RazorpayWebhookController extends Controller
{
    /**
     * Handle incoming Razorpay webhooks.
     * Webhook URL: POST /api/webhooks/razorpay
     */
    public function handleWebhook(Request $request)
    {
        $rawPayload = $request->getContent();
        $signature  = $request->header('X-Razorpay-Signature');
        $secret     = config('services.razorpay.webhook_secret') ?? env('RAZORPAY_WEBHOOK_SECRET');

        // Decode JSON payload
        $data = json_decode($rawPayload, true) ?? [];
        $eventId = $data['event_id'] ?? ($data['id'] ?? md5($rawPayload));
        $event = $data['event'] ?? 'unknown';

        // 1. VERIFY WEBHOOK SIGNATURE
        $isSignatureValid = false;
        if (!empty($signature) && !empty($secret)) {
            $expectedSignature = hash_hmac('sha256', $rawPayload, $secret);
            $isSignatureValid  = hash_equals($expectedSignature, $signature);
        }

        if (!$isSignatureValid) {
            Log::warning("Razorpay Webhook: Invalid Signature for Event [{$event}] ID [{$eventId}]");
            
            RazorpayWebhook::create([
                'event_id'           => $eventId . '_invalid_' . time(),
                'event'              => $event,
                'payload'            => $rawPayload,
                'signature_verified' => false,
                'status'             => 'invalid_signature',
                'error_message'      => 'HMAC SHA256 signature verification failed or secret missing.',
            ]);

            return response()->json([
                'error'   => 'Invalid webhook signature',
                'message' => 'The provided signature does not match RAZORPAY_WEBHOOK_SECRET.'
            ], 400);
        }

        // 2. IDEMPOTENCY CHECK
        $existingWebhook = RazorpayWebhook::where('event_id', $eventId)
            ->where('status', 'processed')
            ->first();

        if ($existingWebhook) {
            Log::info("Razorpay Webhook: Duplicate event [{$eventId}] ignored (Idempotent call).");
            
            return response()->json([
                'message'  => 'Event already processed (Idempotent request)',
                'event_id' => $eventId,
            ], 200);
        }

        // 3. PROCESS EVENT BASED ON EVENT TYPE
        $errorMessage = null;
        $processStatus = 'processed';

        try {
            $this->processEvent($event, $data['payload'] ?? []);
        } catch (\Throwable $e) {
            Log::error("Razorpay Webhook Processing Error for Event [{$event}]: " . $e->getMessage());
            $processStatus = 'failed';
            $errorMessage  = $e->getMessage();
        }

        // 4. LOG AUDIT ENTRY TO DB
        RazorpayWebhook::create([
            'event_id'           => $eventId,
            'event'              => $event,
            'payload'            => $rawPayload,
            'signature_verified' => true,
            'status'             => $processStatus,
            'error_message'      => $errorMessage,
        ]);

        if ($processStatus === 'failed') {
            return response()->json([
                'error'   => 'Webhook processing error',
                'message' => $errorMessage
            ], 500);
        }

        return response()->json([
            'status'   => 'success',
            'event'    => $event,
            'event_id' => $eventId,
        ], 200);
    }

    /**
     * Internal processor for supported Razorpay events.
     */
    protected function processEvent(string $event, array $payload)
    {
        switch ($event) {
            case 'payment.captured':
            case 'order.paid':
                $payment = $payload['payment']['entity'] ?? [];
                $orderId = $payment['order_id'] ?? ($payload['order']['entity']['id'] ?? null);
                $paymentId = $payment['id'] ?? null;

                $booking = null;
                if ($orderId) {
                    $booking = Booking::where('razorpay_order_id', $orderId)->first();
                }
                if (!$booking && $paymentId) {
                    $booking = Booking::where('razorpay_payment_id', $paymentId)->first();
                }

                if ($booking) {
                    $booking->update([
                        'payment_status'      => 'paid',
                        'status'              => 'confirmed',
                        'razorpay_payment_id' => $paymentId ?? $booking->razorpay_payment_id,
                        'transaction_id'      => $paymentId ?? $booking->transaction_id ?? $orderId,
                        'gateway_response'    => json_encode($payload),
                    ]);
                    Log::info("Booking #{$booking->id} confirmed via Razorpay webhook [{$event}]");
                }
                break;

            case 'payment.failed':
                $payment = $payload['payment']['entity'] ?? [];
                $orderId = $payment['order_id'] ?? null;
                $paymentId = $payment['id'] ?? null;
                $errorDesc = $payment['error_description'] ?? 'Razorpay payment failed';

                $booking = null;
                if ($orderId) {
                    $booking = Booking::where('razorpay_order_id', $orderId)->first();
                }
                if (!$booking && $paymentId) {
                    $booking = Booking::where('razorpay_payment_id', $paymentId)->first();
                }

                if ($booking) {
                    $booking->update([
                        'payment_status'      => 'failed',
                        'cancellation_reason' => $errorDesc,
                        'gateway_response'    => json_encode($payload),
                    ]);
                    Log::info("Booking #{$booking->id} payment failed via Razorpay webhook");
                }
                break;

            case 'payment.authorized':
                $payment = $payload['payment']['entity'] ?? [];
                $orderId = $payment['order_id'] ?? null;

                if ($orderId) {
                    $booking = Booking::where('razorpay_order_id', $orderId)->first();
                    if ($booking) {
                        $booking->update([
                            'payment_status'   => 'authorized',
                            'gateway_response' => json_encode($payload),
                        ]);
                    }
                }
                break;

            case 'refund.processed':
                $refund = $payload['refund']['entity'] ?? [];
                $paymentId = $refund['payment_id'] ?? null;
                $refundId = $refund['id'] ?? null;

                $booking = null;
                if ($paymentId) {
                    $booking = Booking::where('razorpay_payment_id', $paymentId)
                        ->orWhere('transaction_id', $paymentId)
                        ->first();
                }

                if ($booking) {
                    $booking->update([
                        'payment_status'      => 'refunded',
                        'status'              => 'cancelled',
                        'cancellation_reason' => "Refund Processed by Razorpay (Refund ID: {$refundId})",
                        'gateway_response'    => json_encode($payload),
                    ]);
                    Log::info("Booking #{$booking->id} marked refunded via Razorpay webhook [refund.processed]");
                }
                break;

            default:
                Log::info("Unhandled Razorpay webhook event: {$event}");
                break;
        }
    }
}
