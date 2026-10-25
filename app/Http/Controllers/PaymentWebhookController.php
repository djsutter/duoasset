<?php

namespace App\Http\Controllers;

use App\Models\SubscriptionPayment;
use App\Services\Payments\PaymentGatewayInterface;
use App\Services\Payments\SubscriptionPaymentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class PaymentWebhookController extends Controller
{
    /**
     * Handle incoming payment webhooks from card checkout providers (e.g. Swipe/Stripe).
     */
    public function __invoke(
        Request $request,
        PaymentGatewayInterface $gateway,
        SubscriptionPaymentService $paymentService
    ): JsonResponse {
        $payload = $request->getContent();
        $signature = $request->header('Swipe-Signature')
            ?? $request->header('Stripe-Signature')
            ?? $request->header('X-Signature')
            ?? '';

        if (! $gateway->verifyWebhookSignature($payload, $signature)) {
            Log::warning('payment.webhook_invalid_signature', [
                'ip' => $request->ip(),
                'signature' => $signature,
            ]);

            return response()->json(['error' => 'Invalid webhook signature.'], 400);
        }

        $event = $gateway->parseWebhookPayload($request->all());

        if (! in_array($event->eventType, ['checkout.session.completed', 'payment.succeeded'], true)) {
            Log::info('payment.webhook_unhandled_event_type', [
                'event_type' => $event->eventType,
            ]);

            return response()->json([
                'status' => 'ignored',
                'message' => 'Event type not handled.',
            ], 200);
        }

        if (! $event->sessionId) {
            return response()->json(['error' => 'Missing session identifier.'], 400);
        }

        /** @var SubscriptionPayment|null $payment */
        $payment = SubscriptionPayment::where('provider_session_id', $event->sessionId)->first();

        if (! $payment) {
            Log::warning('payment.webhook_payment_not_found', [
                'session_id' => $event->sessionId,
            ]);

            return response()->json(['error' => 'Payment record not found.'], 404);
        }

        // Fulfill payment idempotently
        $paymentService->fulfillPayment($payment, $event->paymentId, $event->metadata);

        return response()->json([
            'status' => 'success',
            'payment_id' => $payment->id,
            'plan' => $payment->plan,
            'role' => $payment->user?->role?->value,
        ], 200);
    }
}
