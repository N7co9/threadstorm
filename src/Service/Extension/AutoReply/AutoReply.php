<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoReply;

use App\Service\BaseService;
use App\Service\Extension\AutoReply\External\PostGenerationService;
use App\Service\Extension\AutoReply\Internal\ReplySchedulingService;
use App\Service\Extension\AutoReply\Internal\ThreadSelector;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AutoReply extends BaseService
{
    private ReplySchedulingService $replySchedulingService;
    private PostGenerationService $postGenerationService;

    /**
     * AutoReply constructor.
     *
     * @param ParameterBagInterface  $params
     * @param ReplySchedulingService $replySchedulingService
     * @param PostGenerationService  $postGenerationService
     */
    public function __construct(
        ParameterBagInterface  $params,
        ReplySchedulingService $replySchedulingService,
        PostGenerationService  $postGenerationService
    )
    {
        parent::__construct($params);
        $this->replySchedulingService = $replySchedulingService;
        $this->postGenerationService = $postGenerationService;
    }

    /**
     * Startet den persistierenden Auto-Reply-Prozess.
     *
     * Dabei wird mithilfe des ThreadSelector ein eigener Thread ermittelt, der entweder
     * eine einfache eligible Reply oder eine tiefergehende Gesprächskette (Conversation) liefert.
     * Je nach Rückgabetyp wird der entsprechende Kontext aufgebaut und an den GenerationService
     * zur Reply-Generierung übergeben.
     *
     * @param string|null $context Optionaler Kontext für die Reply-Generierung.
     */
    public function autoReply(?string $context = null): void
    {
        $this->replySchedulingService->runLoop(function () use ($context) {
            try {
                $threadSelector = new ThreadSelector($this);
                // 50/50 chance selection is now performed inside selectRandomEligibleTarget()
                $selection = $threadSelector->selectRandomEligibleTarget(3);
                if ($selection === null) {
                    echo "ℹ️ Kein eigener Thread mit mindestens 3 eligible Replies oder Conversation gefunden." . PHP_EOL;
                    return;
                }

                if (isset($selection['top_reply'])) {
                    $thread = $selection['thread'];
                    $threadId = $thread['id'] ?? null;
                    if ($threadId === null) {
                        echo "⚠️ Thread ohne ID gefunden." . PHP_EOL;
                        return;
                    }
                    echo "ℹ️ Ausgewählter Conversation-Thread für AutoReply: {$threadId}" . PHP_EOL;

                    $replyContext = $this->buildConversationContext($selection);
                    if ($context !== null) {
                        $replyContext .= " " . $context;
                    }
                    $replyText = $this->postGenerationService->generateConversationReply($replyContext);
                    if ($replyText === null) {
                        echo "⚠️ Conversation-Reply-Text konnte nicht generiert werden." . PHP_EOL;
                        return;
                    }
                    $replyId = $this->postReply($replyText, $selection['counter_reply']['id']);
                    echo "🎉 Conversation-Reply erfolgreich gepostet, Reply-ID: {$replyId}." . PHP_EOL;
                } else {
                    $thread = $selection['thread'];
                    $reply  = $selection['reply'];
                    $threadId = $thread['id'] ?? null;
                    if ($threadId === null) {
                        echo "⚠️ Thread ohne ID gefunden." . PHP_EOL;
                        return;
                    }
                    echo "ℹ️ Ausgewählter Thread für AutoReply: {$threadId}" . PHP_EOL;

                    $replyContext = $this->buildReplyContext($threadId, $reply);
                    if ($context !== null) {
                        $replyContext .= " " . $context;
                    }
                    $replyText = $this->postGenerationService->generateReply($replyContext);
                    if ($replyText === null) {
                        echo "⚠️ Reply-Text konnte nicht generiert werden." . PHP_EOL;
                        return;
                    }
                    $replyId = $this->postReply($replyText, $reply['id']);
                    echo "🎉 Reply erfolgreich gepostet, Reply-ID: {$replyId}." . PHP_EOL;
                }
            } catch (\Throwable $e) {
                echo "🚫 Fehler im AutoReply-Prozess: " . $e->getMessage() . PHP_EOL;
            }
        });
    }

    /**
     * Baut einen Kontext-String für die einfache Reply-Generierung auf.
     *
     * @param string $rootThreadId ID des Ursprungs-Threads.
     * @param array  $replyData    Daten des ausgewählten Replies.
     * @return string
     */
    private function buildReplyContext(string $rootThreadId, array $replyData): string
    {
        try {
            $threadData = $this->getThreadById($rootThreadId);
        } catch (\Throwable $e) {
            throw new \RuntimeException("Fehler beim Abrufen des Ursprungs-Threads: " . $e->getMessage(), 0, $e);
        }
        $rootText = $threadData['text'] ?? 'Unbekannter Ursprungsbeitrag';
        $replyText = $replyData['text'] ?? 'Kein Kommentartext vorhanden';
        return "Ursprungsbeitrag: {$rootText} | Kommentar: {$replyText}";
    }

    /**
     * Baut einen Kontext-String für die Conversation-Reply-Generierung auf.
     *
     * Erwartet ein Array, das folgende Schlüssel enthält:
     *   - 'thread': Der Thread
     *   - 'top_reply': Der ursprüngliche Kommentar
     *   - 'my_reply': Deine Antwort
     *   - 'counter_reply': Die Gegenantwort eines Dritten
     *
     * @param array $conversation Die komplette Gesprächskette.
     * @return string
     */
    private function buildConversationContext(array $conversation): string
    {
        $thread = $conversation['thread'];
        $threadText = $thread['text'] ?? 'Unbekannter Ursprungsbeitrag';
        $topReplyText = $conversation['top_reply']['text'] ?? 'Kein ursprünglicher Kommentartext';
        $myReplyText = $conversation['my_reply']['text'] ?? 'Keine Antwort von Dir gefunden';
        $counterReplyText = $conversation['counter_reply']['text'] ?? 'Kein Gegenkommentar gefunden';
        return "Ursprungsbeitrag: {$threadText} | Kommentar: {$topReplyText} | Deine Antwort: {$myReplyText} | Gegenantwort: {$counterReplyText}";
    }
}
