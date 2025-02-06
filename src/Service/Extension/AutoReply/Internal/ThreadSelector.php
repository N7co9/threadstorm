<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoReply\Internal;

use App\Service\BaseService;

class ThreadSelector
{
    private BaseService $baseService;

    /**
     * ThreadSelector constructor.
     *
     * @param BaseService $baseService An instance of BaseService (or a derived class)
     *                                 that provides the methods getThreads() and getRepliesById().
     */
    public function __construct(BaseService $baseService)
    {
        $this->baseService = $baseService;
    }

    /**
     * Verifies if a given reply (comment) is eligible for generating a new reply.
     *
     * A reply is not eligible if it already has a reply from the owner.
     *
     * @param array $reply A single reply's data.
     * @return bool True if eligible, false otherwise.
     */
    private function isCommentEligible(array $reply): bool
    {
        return empty($reply['is_reply_owned_by_me']) || $reply['is_reply_owned_by_me'] !== true;
    }

    /**
     * Selects one of your own threads that has at least $minReplies eligible replies.
     *
     * First, it examines the three most recent threads. If none of these threads contain
     * enough eligible replies, it iterates over all threads.
     *
     * @param int $minReplies Minimum number of eligible replies required (default: 3).
     * @return array|null The selected thread as an array, or null if none is found.
     */
    public function selectEligibleThread(int $minReplies = 3): ?array
    {
        $threads = $this->baseService->getThreads();
        if (empty($threads)) {
            return null;
        }

        usort($threads, static function ($a, $b) {
            return $b['timestamp'] <=> $a['timestamp'];
        });

        $recentThreads = array_slice($threads, 0, 3);
        foreach ($recentThreads as $thread) {
            $repliesData = $this->baseService->getRepliesById($thread['id']);
            if (!empty($repliesData['data'])) {
                $eligibleReplies = array_filter($repliesData['data'], function ($reply) {
                    return $this->isCommentEligible($reply);
                });
                if (count($eligibleReplies) >= $minReplies) {
                    return $thread;
                }
            }
        }

        foreach ($threads as $thread) {
            $repliesData = $this->baseService->getRepliesById($thread['id']);
            if (!empty($repliesData['data'])) {
                $eligibleReplies = array_filter($repliesData['data'], function ($reply) {
                    return $this->isCommentEligible($reply);
                });
                if (count($eligibleReplies) >= $minReplies) {
                    return $thread;
                }
            }
        }

        return null;
    }
}
