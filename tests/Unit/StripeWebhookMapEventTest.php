<?php

namespace Tests\Unit;

use App\Http\Controllers\Webhooks\StripeWebhookController;
use PHPUnit\Framework\TestCase;

class StripeWebhookMapEventTest extends TestCase
{
    public function test_payment_intent_succeeded_is_paid(): void
    {
        $r = StripeWebhookController::mapEvent(['type' => 'payment_intent.succeeded', 'data' => ['object' => ['id' => 'pi_1']]]);
        $this->assertSame(['event' => 'payment_intent.succeeded', 'status' => 'paid', 'payment_intent' => 'pi_1'], $r);
    }

    public function test_full_refund_is_mapped_and_partial_is_ignored(): void
    {
        $full = StripeWebhookController::mapEvent(['type' => 'charge.refunded', 'data' => ['object' => ['amount' => 1990, 'amount_refunded' => 1990, 'refunded' => true, 'payment_intent' => 'pi_2']]]);
        $this->assertSame(['event' => 'payment.refunded', 'status' => 'refunded', 'payment_intent' => 'pi_2'], $full);

        $partial = StripeWebhookController::mapEvent(['type' => 'charge.refunded', 'data' => ['object' => ['amount' => 1990, 'amount_refunded' => 500, 'refunded' => false, 'payment_intent' => 'pi_2']]]);
        $this->assertNull($partial);
    }

    public function test_dispute_created_and_closed(): void
    {
        $created = StripeWebhookController::mapEvent(['type' => 'charge.dispute.created', 'data' => ['object' => ['payment_intent' => 'pi_3', 'status' => 'needs_response']]]);
        $this->assertSame(['event' => 'payment.disputed', 'status' => 'disputed', 'payment_intent' => 'pi_3'], $created);

        $won = StripeWebhookController::mapEvent(['type' => 'charge.dispute.closed', 'data' => ['object' => ['payment_intent' => 'pi_3', 'status' => 'won']]]);
        $this->assertSame('won', $won['status']);
        $this->assertSame('payment.dispute_closed', $won['event']);

        $lost = StripeWebhookController::mapEvent(['type' => 'charge.dispute.closed', 'data' => ['object' => ['payment_intent' => 'pi_3', 'status' => 'lost']]]);
        $this->assertSame('lost', $lost['status']);

        $other = StripeWebhookController::mapEvent(['type' => 'charge.dispute.closed', 'data' => ['object' => ['payment_intent' => 'pi_3', 'status' => 'under_review']]]);
        $this->assertNull($other);
    }

    public function test_unknown_events_and_missing_payment_intent_are_ignored(): void
    {
        $this->assertNull(StripeWebhookController::mapEvent(['type' => 'customer.created', 'data' => ['object' => ['id' => 'cus_1']]]));
        $this->assertNull(StripeWebhookController::mapEvent(['type' => 'charge.dispute.created', 'data' => ['object' => ['payment_intent' => null]]]));
        $this->assertNull(StripeWebhookController::mapEvent([]));
    }
}
