<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost\Internal;

class SchedulingService
{
    /**
     * Runs a single 24-hour scheduling cycle.
     *
     * This method generates a random schedule (based on a minimum and maximum number
     * of posts) over the next 24 hours. For each scheduled time, it waits until that
     * time is reached and then calls the provided callback.
     *
     * @param int $minPosts Minimum number of posts in the cycle.
     * @param int $maxPosts Maximum number of posts in the cycle.
     * @param callable $postCallback A callback function to execute for each scheduled post.
     *        The callback will be invoked with no arguments.
     */
    public function runCycle(int $minPosts, int $maxPosts, callable $postCallback): void
    {
        $cycleStart = new \DateTime();
        $cycleEndTimestamp = $cycleStart->getTimestamp() + 86400;

        $quotaPosts = random_int($minPosts, $maxPosts);
        echo "ℹ️ Scheduled {$quotaPosts} regular posts in the next 24 hours." . PHP_EOL;

        $schedule = [];
        for ($i = 0; $i < $quotaPosts; $i++) {
            $schedule[] = random_int(0, 86400);
        }
        sort($schedule);

        foreach ($schedule as $scheduledOffset) {
            $targetTime = $cycleStart->getTimestamp() + $scheduledOffset;
            $now = time();
            $sleepTime = $targetTime - $now;
            if ($sleepTime > 0) {
                echo "ℹ️ Waiting {$sleepTime} seconds until the next scheduled post..." . PHP_EOL;
                sleep($sleepTime);
            }
            $postCallback();
        }

        $now = time();
        $remaining = $cycleEndTimestamp - $now;
        if ($remaining > 0) {
            echo "ℹ️ 24-hour cycle completed. Waiting {$remaining} seconds until the next cycle." . PHP_EOL;
            sleep($remaining);
        }
    }
}