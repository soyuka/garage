<?php

declare(strict_types=1);

namespace App\Tests\Mock;

use App\Agent\Ui\DashboardChannel;

final class TestDashboardChannel
{
    public static function create(?string $sessionTopic = null): DashboardChannel
    {
        $channel = new DashboardChannel();
        $channel->bindSession($sessionTopic);

        return $channel;
    }
}
