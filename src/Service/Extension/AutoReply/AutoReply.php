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
     * @param ParameterBagInterface $params
     * @param ReplySchedulingService $replySchedulingService
     * @param PostGenerationService $postGenerationService
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
     * Dabei werden zuerst alle eigenen Threads ermittelt und ein Thread gesucht,
     * der mindestens drei Replies enthält. Wird ein geeigneter Thread gefunden, so
     * werden periodisch dessen Replies abgefragt, und auf einen zufällig ausgewählten
     * Reply (aus den ersten 10) wird geantwortet.
     *
     * @param string|null $context Optionaler Kontext für die Reply-Generierung.
     */
    public function autoReply(?string $context = null): void
    {
        $this->replySchedulingService->runLoop(function () use ($context) {
            try {
                $threadSelector = new ThreadSelector($this);
                $selectedThread = $threadSelector->selectEligibleThread(3);
                if ($selectedThread === null) {
                    echo "ℹ️ Kein eigener Thread mit mindestens 3 Replies gefunden." . PHP_EOL;
                    return;
                }
                $threadId = $selectedThread['id'];
                echo "ℹ️ Ausgewählter Thread für AutoReply: {$threadId}" . PHP_EOL;

                $repliesData = $this->getRepliesById($threadId);
                if (!empty($repliesData['data'])) {
                    $replies = $repliesData['data'];
                    $selectedReply = $this->selectRandomReply($replies);
                    if ($selectedReply === null) {
                        echo "⚠️ Kein geeigneter Reply in den ersten 10 Antworten gefunden." . PHP_EOL;
                        return;
                    }
                    $replyContext = $this->buildReplyContext($threadId, $selectedReply);
                    if ($context !== null) {
                        $replyContext .= " " . $context;
                    }
                    $replyText = $this->postGenerationService->generateReply($replyContext);
                    if ($replyText === null) {
                        echo "⚠️ Reply-Text konnte nicht generiert werden." . PHP_EOL;
                        return;
                    }
                    $replyId = $this->postReply($replyText, $selectedReply['id']);
                    echo "🎉 Reply erfolgreich gepostet, Reply-ID: {$replyId}." . PHP_EOL;
                } else {
                    echo "ℹ️ Es wurden noch keine Replies für Thread {$threadId} gefunden." . PHP_EOL;
                }
            } catch (\Throwable $e) {
                echo "🚫 Fehler im AutoReply-Prozess: " . $e->getMessage() . PHP_EOL;
            }
        });
    }

    /**
     * Baut einen Kontext-String für die Reply-Generierung auf.
     *
     * @param string $rootThreadId ID des Ursprungs-Threads.
     * @param array $replyData Daten des ausgewählten Replies.
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
        return "Ursprungsbeitrag: {$rootText} Kommentar: {$replyText}";
    }

    /**
     * Wählt zufällig einen Reply aus den ersten 10 Einträgen aus.
     *
     * @param array $replies Array mit Reply-Daten.
     * @return array|null
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
