<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoReply\External;

use App\Service\BaseService;
use App\Service\Extension\AutoReply\Internal\ThreadSelector;

class AuditReplyGenerationService
{
    private BaseService $baseService;
    private PostGenerationService $postGenerationService;

    /**
     * AuditReplyGenerationService constructor.
     *
     * @param BaseService $baseService An instance providing methods for threads and replies.
     * @param PostGenerationService $postGenerationService Service to generate reply text.
     */
    public function __construct(
        BaseService           $baseService,
        PostGenerationService $postGenerationService
    )
    {
        $this->baseService = $baseService;
        $this->postGenerationService = $postGenerationService;
    }

    /**
     * Manually triggers the audit reply generation process.
     *
     * If no thread ID is provided, a helper (ThreadSelector) is used to choose an eligible thread
     * (i.e. one with at least three replies). The method then fetches replies from the selected thread,
     * randomly selects one of the first 10 replies, builds the context for reply generation, and generates
     * the reply text. Instead of posting the reply, it returns a report containing:
     *  - The original thread text.
     *  - The comment text the reply refers to.
     *  - The proposed reply text.
     *
     * @param string|null $threadId Optional thread ID to use. If null, the service selects one automatically.
     * @param string|null $context Optional extra context to append to the reply generation prompt.
     * @return string The audit report.
     */
    public function auditReply(?string $threadId = null, ?string $context = null): string
    {
        if ($threadId === null) {
            $threadSelector = new ThreadSelector($this->baseService);
            $selectedThread = $threadSelector->selectEligibleThread(3);
            if ($selectedThread === null) {
                return "ℹ️ Kein eigener Thread mit mindestens 3 Replies gefunden.";
            }
            $threadId = $selectedThread['id'];
            echo "ℹ️ Ausgewählter Thread für AuditReply: {$threadId}" . PHP_EOL;
        } else {
            echo "ℹ️ Verwende manuell übergebenen Thread: {$threadId}" . PHP_EOL;
        }

        $repliesData = $this->baseService->getRepliesById($threadId);
        if (empty($repliesData['data'])) {
            return "ℹ️ Keine Replies im Thread {$threadId} gefunden.";
        }

        $selectedReply = $this->selectRandomReply($repliesData['data']);
        if ($selectedReply === null) {
            return "⚠️ Kein geeigneter Reply in den ersten 10 Antworten gefunden.";
        }

        $auditContext = $this->buildReplyContext($threadId, $selectedReply);
        if ($context !== null) {
            $auditContext .= " " . $context;
        }

        $proposedReply = $this->postGenerationService->generateReply($auditContext);
        if ($proposedReply === null) {
            return "⚠️ Audit Reply-Text konnte nicht generiert werden.";
        }

        $report = "Audit Reply Generation Report:" . PHP_EOL;
        $report .= "────────────────────────────" . PHP_EOL;
        $threadData = $this->baseService->getThreadById($threadId);
        $rootText = $threadData['text'] ?? 'Unbekannter Ursprungsbeitrag';
        $commentText = $selectedReply['text'] ?? 'Kein Kommentartext vorhanden';
        $report .= "Ursprungsbeitrag: " . $rootText . PHP_EOL;
        $report .= "Kommentar: " . $commentText . PHP_EOL;
        $report .= "Proposed Reply: " . $proposedReply . PHP_EOL;
        $report .= "────────────────────────────" . PHP_EOL;

        return $report;
    }

    /**
     * Builds a context string for reply generation based on the original thread and selected reply.
     *
     * @param string $rootThreadId ID of the original thread.
     * @param array $replyData Data of the selected reply.
     * @return string
     * @throws \RuntimeException if thread data cannot be retrieved.
     */
    private function buildReplyContext(string $rootThreadId, array $replyData): string
    {
        try {
            $threadData = $this->baseService->getThreadById($rootThreadId);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Fehler beim Abrufen des Ursprungs-Threads: " . $e->getMessage(), 0, $e);
        }
        $rootText = $threadData['text'] ?? 'Unbekannter Ursprungsbeitrag';
        $replyText = $replyData['text'] ?? 'Kein Kommentartext vorhanden';
        return "Ursprungsbeitrag: {$rootText} Kommentar: {$replyText}";
    }

    /**
     * Selects a random reply from the first 10 entries in the replies array.
     *
     * @param array $replies Array of reply data.
     * @return array|null A randomly selected reply or null if none is available.
     */
    private function selectRandomReply(array $replies): ?array
    {
        $limit = min(count($replies), 10);
        if ($limit === 0) {
            return null;
        }
        $index = random_int(0, $limit - 1);
        return $replies[$index];
    }
}
