<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoReply\Internal;

use App\Service\BaseService;

class ThreadSelector
{
    private BaseService $baseService;

    public function __construct(BaseService $baseService)
    {
        $this->baseService = $baseService;
    }

    /**
     * Checks if a reply is eligible for an automatic response.
     *
     * A reply is not eligible if:
     * - It does not contain an 'id'
     * - It is already from you, or
     * - Any of its direct child replies (one level deeper) are already from you.
     *
     * @param array $reply Data of a reply.
     * @return bool True if the reply is eligible, otherwise false.
     */
    private function isReplyEligible(array $reply): bool
    {
        if (!isset($reply['id'])) {
            return false;
        }

        if (!empty($reply['is_reply_owned_by_me']) && $reply['is_reply_owned_by_me'] === true) {
            return false;
        }

        $childRepliesData = $this->baseService->getRepliesById($reply['id']);
        if (!empty($childRepliesData['data'])) {
            foreach ($childRepliesData['data'] as $childReply) {
                if (!empty($childReply['is_reply_owned_by_me']) && $childReply['is_reply_owned_by_me'] === true) {
                    return false;
                }
            }
        }

        return true;
    }

    /**
     * Selects a random eligible reply from a thread that has at least $minReplies eligible replies.
     * This method retrieves all threads, then checks each thread’s replies. For a thread to qualify,
     * it must have at least $minReplies replies that are eligible (i.e. we haven’t already replied to them).
     * Then, from a randomly selected thread, a weighted random selection (favoring more recent replies) is performed.
     *
     * @param int $minReplies Minimum number of eligible replies required (default: 3).
     * @return array|null An array with keys 'thread' and 'reply', or null if no suitable candidate is found.
     */
    public function selectEligibleThread(int $minReplies = 3): ?array
    {
        $threads = $this->baseService->getThreads();
        if (empty($threads)) {
            return null;
        }

        $eligibleThreads = [];

        foreach ($threads as $thread) {
            if (!isset($thread['id'])) {
                continue;
            }

            $repliesData = $this->baseService->getRepliesById($thread['id']);
            if (empty($repliesData['data'])) {
                continue;
            }

            $eligibleReplies = array_filter(
                $repliesData['data'],
                fn(array $reply) => $this->isReplyEligible($reply)
            );

            if (count($eligibleReplies) >= $minReplies) {
                $thread['eligible_replies'] = array_values($eligibleReplies);
                $eligibleThreads[] = $thread;
            }
        }

        if (empty($eligibleThreads)) {
            return null;
        }

        $selectedThread = $eligibleThreads[array_rand($eligibleThreads)];
        $eligibleReplies = $selectedThread['eligible_replies'];

        $timestamps = array_map(
            fn($reply) => isset($reply['timestamp']) ? (float)$reply['timestamp'] : 0.0,
            $eligibleReplies
        );
        $minTimestamp = min($timestamps);
        $maxTimestamp = max($timestamps);

        $biasFactor = 0.2;
        $weightedReplies = [];
        $totalWeight = 0;

        foreach ($eligibleReplies as $reply) {
            $replyTimestamp = isset($reply['timestamp']) ? (float)$reply['timestamp'] : 0.0;
            if ($maxTimestamp === $minTimestamp) {
                $weight = 1;
            } else {
                $normalized = ($replyTimestamp - $minTimestamp) / ($maxTimestamp - $minTimestamp);
                $weight = 1 + $biasFactor * $normalized;
            }
            $weightedReplies[] = ['reply' => $reply, 'weight' => $weight];
            $totalWeight += $weight;
        }

        $rand = (mt_rand() / mt_getrandmax()) * $totalWeight;
        $selectedReply = null;
        foreach ($weightedReplies as $entry) {
            $rand -= $entry['weight'];
            if ($rand <= 0) {
                $selectedReply = $entry['reply'];
                break;
            }
        }

        if ($selectedReply === null) {
            $selectedReply = $eligibleReplies[array_rand($eligibleReplies)];
        }

        return [
            'thread' => $selectedThread,
            'reply' => $selectedReply,
        ];
    }

    /**
     * Selects a conversation chain that is one level deeper.
     *
     * Searches for a chain of replies in which:
     * - A top-level reply (comment) in a thread is made by someone else.
     * - You have replied to that comment.
     * - Someone (not you) has replied to your reply.
     *
     * Only the first $topLevelLimit replies per thread and at most $childLimit replies per level
     * are checked for performance reasons.
     *
     * @param int $topLevelLimit Maximum number of top-level replies per thread (default: 10).
     * @param int $childLimit Maximum number of child replies per level (default: 10).
     * @return array|null An array with keys:
     *   - 'thread': The thread where the conversation occurs.
     *   - 'top_reply': The original comment (by someone else).
     *   - 'my_reply': Your reply to that comment.
     *   - 'counter_reply': A reply by someone else to your reply.
     *   Or null if no suitable conversation chain is found.
     */
    public function selectDeepEligibleConversation(int $topLevelLimit = 10, int $childLimit = 10): ?array
    {
        $threads = $this->baseService->getThreads();
        if (empty($threads)) {
            return null;
        }

        $candidateChains = [];

        foreach ($threads as $thread) {
            if (!isset($thread['id'])) {
                continue;
            }

            $topRepliesData = $this->baseService->getRepliesById($thread['id']);
            if (empty($topRepliesData['data'])) {
                continue;
            }
            $topReplies = array_slice($topRepliesData['data'], 0, $topLevelLimit);

            foreach ($topReplies as $topReply) {
                if (!isset($topReply['id'])) {
                    continue;
                }
                // The top-level comment should not be yours.
                if (!empty($topReply['is_reply_owned_by_me']) && $topReply['is_reply_owned_by_me'] === true) {
                    continue;
                }
                // Look for your reply to this comment.
                $childRepliesData = $this->baseService->getRepliesById($topReply['id']);
                if (empty($childRepliesData['data'])) {
                    continue;
                }
                $childReplies = array_slice($childRepliesData['data'], 0, $childLimit);
                $myReply = null;
                foreach ($childReplies as $childReply) {
                    if (!isset($childReply['id'])) {
                        continue;
                    }
                    if (!empty($childReply['is_reply_owned_by_me']) && $childReply['is_reply_owned_by_me'] === true) {
                        $myReply = $childReply;
                        break;
                    }
                }
                if ($myReply === null) {
                    continue;
                }
                $grandChildRepliesData = $this->baseService->getRepliesById($myReply['id']);
                if (empty($grandChildRepliesData['data'])) {
                    continue;
                }
                $grandChildReplies = array_slice($grandChildRepliesData['data'], 0, $childLimit);
                $counterReply = null;
                foreach ($grandChildReplies as $gReply) {
                    if (!isset($gReply['id'])) {
                        continue;
                    }
                    if (empty($gReply['is_reply_owned_by_me']) || $gReply['is_reply_owned_by_me'] !== true) {
                        $counterReply = $gReply;
                        break;
                    }
                }
                if ($counterReply === null) {
                    continue;
                }

                $candidateChains[] = [
                    'thread' => $thread,
                    'top_reply' => $topReply,
                    'my_reply' => $myReply,
                    'counter_reply' => $counterReply,
                ];
            }
        }

        if (empty($candidateChains)) {
            return null;
        }

        return $candidateChains[array_rand($candidateChains)];
    }

    /**
     * Randomly selects either a standard eligible reply (from selectEligibleThread)
     * or a deep conversation chain (from selectDeepEligibleConversation) with a 50/50 chance.
     * If the chosen method does not yield a result, it falls back to the other.
     *
     * @param int $minReplies Minimum number of eligible replies required for the standard selection (default: 3).
     * @return array|null An array representing the selected target (structure depends on selection type), or null if none found.
     */
    public function selectRandomEligibleTarget(int $minReplies = 3): ?array
    {
        $choice = random_int(0, 1);
        if ($choice === 0) {
            $result = $this->selectEligibleThread($minReplies);
            if ($result !== null) {
                return $result;
            }
            return $this->selectDeepEligibleConversation();
        }

        $result = $this->selectDeepEligibleConversation();
        if ($result !== null) {
            return $result;
        }
        return $this->selectEligibleThread($minReplies);
    }
}
