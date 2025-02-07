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
     * (i.e. one with at least three eligible replies or a deep conversation chain). The method then
     * builds the context for reply generation and generates the reply text. Instead of posting the reply,
     * it returns a report containing:
     *  - The original thread text.
     *  - The comment text the reply refers to (or, in deep conversation, the full conversation).
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
            $selection = $threadSelector->selectRandomEligibleTarget(3);
            if ($selection === null) {
                return "ℹ️ Kein eigener Thread mit mindestens 3 eligible Replies oder Conversation gefunden.";
            }
            if (isset($selection['top_reply'])) {
                $threadId = $selection['thread']['id'] ?? null;
                if ($threadId === null) {
                    return "⚠️ Thread ohne ID gefunden.";
                }
                echo "ℹ️ Ausgewählter Conversation-Thread für AuditReply: {$threadId}" . PHP_EOL;
                $auditContext = $this->buildConversationContext($selection);
                if ($context !== null) {
                    $auditContext .= " " . $context;
                }
                $proposedReply = $this->postGenerationService->generateConversationReply($auditContext);
                if ($proposedReply === null) {
                    return "⚠️ Audit Conversation Reply-Text konnte nicht generiert werden.";
                }
                $threadData = $this->baseService->getThreadById($threadId);
                $rootText = $threadData['text'] ?? 'Unbekannter Ursprungsbeitrag';
                $topReplyText = $selection['top_reply']['text'] ?? 'Kein Kommentartext vorhanden';
                $myReplyText = $selection['my_reply']['text'] ?? 'Keine Antwort von Dir gefunden';
                $counterReplyText = $selection['counter_reply']['text'] ?? 'Kein Gegenkommentar gefunden';

                $report = "Audit Conversation Reply Generation Report:" . PHP_EOL;
                $report .= "────────────────────────────" . PHP_EOL;
                $report .= "Ursprungsbeitrag: " . $rootText . PHP_EOL;
                $report .= "Kommentar: " . $topReplyText . PHP_EOL;
                $report .= "Meine Antwort: " . $myReplyText . PHP_EOL;
                $report .= "Gegenantwort: " . $counterReplyText . PHP_EOL;
                $report .= "Proposed Conversation Reply: " . $proposedReply . PHP_EOL;
                $report .= "────────────────────────────" . PHP_EOL;
                return $report;
            }

            $threadId = $selection['thread']['id'] ?? null;
            if ($threadId === null) {
                return "⚠️ Thread ohne ID gefunden.";
            }
            echo "ℹ️ Ausgewählter Thread für AuditReply: {$threadId}" . PHP_EOL;
            $reply = $selection['reply'];
            $auditContext = $this->buildReplyContext($threadId, $reply);
            if ($context !== null) {
                $auditContext .= " " . $context;
            }
            $proposedReply = $this->postGenerationService->generateReply($auditContext);
            if ($proposedReply === null) {
                return "⚠️ Audit Reply-Text konnte nicht generiert werden.";
            }
            $threadData = $this->baseService->getThreadById($threadId);
            $rootText = $threadData['text'] ?? 'Unbekannter Ursprungsbeitrag';
            $commentText = $reply['text'] ?? 'Kein Kommentartext vorhanden';

            $report = "Audit Reply Generation Report:" . PHP_EOL;
            $report .= "────────────────────────────" . PHP_EOL;
            $report .= "Ursprungsbeitrag: " . $rootText . PHP_EOL;
            $report .= "Kommentar: " . $commentText . PHP_EOL;
            $report .= "Proposed Reply: " . $proposedReply . PHP_EOL;
            $report .= "────────────────────────────" . PHP_EOL;
            return $report;
        }

        echo "ℹ️ Verwende manuell übergebenen Thread: {$threadId}" . PHP_EOL;
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
     * Builds a context string for conversation reply generation based on a conversation chain.
     *
     * Expects an array with keys: 'thread', 'top_reply', 'my_reply', 'counter_reply'.
     *
     * @param array $conversation The conversation chain.
     * @return string
     */
    private function buildConversationContext(array $conversation): string
    {
        $thread = $conversation['thread'];
        try {
            $threadData = $this->baseService->getThreadById($thread['id'] ?? '');
        } catch (\Throwable $e) {
            $threadData = [];
        }
        $threadText = $threadData['text'] ?? 'Unbekannter Ursprungsbeitrag';
        $topReplyText = $conversation['top_reply']['text'] ?? 'Kein Kommentartext vorhanden';
        $myReplyText = $conversation['my_reply']['text'] ?? 'Keine Antwort von Dir gefunden';
        $counterText = $conversation['counter_reply']['text'] ?? 'Kein Gegenkommentar gefunden';
        return "Ursprungsbeitrag: {$threadText} | Kommentar: {$topReplyText} | Deine Antwort: {$myReplyText} | Gegenantwort: {$counterText}";
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
