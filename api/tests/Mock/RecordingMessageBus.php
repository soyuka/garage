<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * A {@see MessageBusInterface} double that records every dispatched message instead of routing it
 * anywhere — used wherever a test constructs {@see \App\Agent\Ui\RenderIntentTools} directly and
 * either doesn't care about the speech prewarm it dispatches on `toast()`, or wants to assert on it.
 */
final class RecordingMessageBus implements MessageBusInterface
{
    /** @var list<object> */
    public array $dispatched = [];

    public function dispatch(object $message, array $stamps = []): Envelope
    {
        $this->dispatched[] = $message;

        return new Envelope($message);
    }
}
