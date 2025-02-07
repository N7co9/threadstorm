<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoReply\Internal;

class ReplySchedulingService
{
    /**
     * Runs an infinite scheduling loop to periodically check for replies and post a reply.
     *
     * This method waits for a random duration between 7.5 minutes (450 seconds) and 300 minutes (18,000 seconds),
     * then calls the provided callback. The loop repeats indefinitely.
     *
     * @param callable $callback A callback function to execute after each wait period.
     */
    public function runLoop(callable $callback): void
    {
        while (true) {
            $sleepTime = random_int(450, 18000);
            echo "ℹ️ Waiting {$sleepTime} seconds until the next reply check..." . PHP_EOL;
            sleep($sleepTime);
            $callback();
        }
    }
}
