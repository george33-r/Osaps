<?php

declare(strict_types=1);

namespace Tests\Unit\Domain;

use App\Domain\Negotiation\JobRequestStatus;
use App\Domain\Negotiation\OfferStatus;
use App\Domain\Orders\OrderStatus;
use App\Domain\Support\InvalidTransition;
use PHPUnit\Framework\TestCase;

final class StateMachinesTest extends TestCase
{
    public function test_only_the_customer_can_accept_an_offer(): void
    {
        $this->assertTrue(OfferStatus::can('awaiting_customer', 'accepted', 'customer'));
        $this->assertFalse(OfferStatus::can('awaiting_customer', 'accepted', 'tailor'));
        $this->assertFalse(OfferStatus::can('awaiting_customer', 'accepted', 'system'));
    }

    public function test_customer_cannot_accept_while_waiting_on_tailor(): void
    {
        // The customer countered; the tailor has not responded, so there are no tailor terms to accept.
        $this->assertFalse(OfferStatus::can('awaiting_tailor', 'accepted', 'customer'));
    }

    public function test_terminal_offer_states_cannot_change(): void
    {
        foreach (['accepted', 'declined', 'withdrawn', 'expired', 'not_selected'] as $s) {
            $this->assertTrue(OfferStatus::isTerminal($s), $s);
        }
        $this->expectException(InvalidTransition::class);
        OfferStatus::assert('withdrawn', 'awaiting_customer', 'tailor');
    }

    public function test_job_request_can_only_be_awarded_from_open(): void
    {
        $this->assertTrue(JobRequestStatus::can('open', 'awarded', 'customer'));
        $this->assertFalse(JobRequestStatus::can('cancelled', 'awarded', 'customer'));
        $this->assertFalse(JobRequestStatus::can('expired', 'awarded', 'customer'));
    }

    public function test_order_happy_path_is_driven_by_the_right_actors(): void
    {
        $path = [
            ['confirmed', 'in_progress', 'tailor'],
            ['in_progress', 'fitting', 'tailor'],
            ['fitting', 'ready', 'tailor'],
            ['ready', 'delivered', 'tailor'],
            ['delivered', 'completed', 'customer'],
        ];
        foreach ($path as [$from, $to, $actor]) {
            $this->assertTrue(OrderStatus::can($from, $to, $actor), "$from -> $to by $actor");
        }
    }

    public function test_tailor_cannot_mark_an_order_completed(): void
    {
        $this->assertFalse(OrderStatus::can('delivered', 'completed', 'tailor'));
    }

    public function test_orders_cannot_skip_stages(): void
    {
        $this->assertFalse(OrderStatus::can('confirmed', 'delivered', 'tailor'));
        $this->assertFalse(OrderStatus::can('confirmed', 'completed', 'customer'));
    }

    public function test_disputes_are_only_resolved_by_admin(): void
    {
        $this->assertSame([], OrderStatus::nextFor('disputed', 'customer'));
        $this->assertSame([], OrderStatus::nextFor('disputed', 'tailor'));
        $this->assertCount(3, OrderStatus::nextFor('disputed', 'admin'));
    }

    public function test_once_work_starts_only_admin_can_cancel(): void
    {
        $this->assertTrue(OrderStatus::can('confirmed', 'cancelled', 'customer'));
        $this->assertFalse(OrderStatus::can('in_progress', 'cancelled', 'customer'));
        $this->assertFalse(OrderStatus::can('in_progress', 'cancelled', 'tailor'));
        $this->assertTrue(OrderStatus::can('in_progress', 'cancelled', 'admin'));
    }

    public function test_cancellation_and_dispute_require_a_reason(): void
    {
        $this->assertTrue(OrderStatus::requiresReason('cancelled'));
        $this->assertTrue(OrderStatus::requiresReason('disputed'));
        $this->assertFalse(OrderStatus::requiresReason('ready'));
    }
}
