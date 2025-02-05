<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost;

use App\Service\BaseService;
use Random\RandomException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class AutoPostService extends BaseService
{
    private string $anthropicApiKey;
    private $clientAnthropic;
    private string $timelineMemoryFile;
    private int $maxRegenerationAttempts = 3;
    private ?string $previousMood = null;
    private MoodService $moodService;
    private MediaService $mediaService;

    public function __construct(ParameterBagInterface $params, MoodService $moodService, MediaService $mediaService)
    {
        parent::__construct($params);
        $this->anthropicApiKey = $params->get('ANTHROPIC_API_KEY');
        $this->clientAnthropic = HttpClient::create();
        $this->timelineMemoryFile = __DIR__ . '/timeline_memory.txt';
        $this->moodService = $moodService;
        $this->mediaService = $mediaService;
    }

    /**
     * Starts the persistent auto-post process.
     *
     * @param string $range Allowed values: "1-3", "3-5", or "5-10".
     * @param string|null $context Optional extra context to refine the AI output.
     * @throws \RuntimeException|RandomException if an invalid range is provided or if an API error occurs.
     */
    public function autoPost(string $range, ?string $context = null): void
    {
        $allowedRanges = ['1-3', '3-5', '5-10'];
        if (!in_array($range, $allowedRanges, true)) {
            throw new \RuntimeException("Invalid range provided. Allowed values are: " . implode(", ", $allowedRanges));
        }
        [$min, $max] = explode('-', $range);
        $min = (int)$min;
        $max = (int)$max;

        while (true) {
            $cycleStart = new \DateTime();
            $cycleEndTimestamp = $cycleStart->getTimestamp() + 86400;
            $quotaPosts = random_int($min, $max);
            echo "Scheduled {$quotaPosts} regular posts in the next 24 hours." . PHP_EOL;
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
                    echo "Waiting {$sleepTime} seconds until the next scheduled post..." . PHP_EOL;
                    sleep($sleepTime);
                }

                if (random_int(1, 100) <= 20) {
                    echo "Attempting media-based post." . PHP_EOL;
                    $media = $this->mediaService->fetchRandomImageAsBase64();
                    if ($media !== null) {
                        try {
                            $mediaData = $this->generateMediaBasedText($media);
                            $threadId = $this->postThread($mediaData['text'], $mediaData['imageUrl']);
                            echo "Media-based post published, ID: {$threadId}." . PHP_EOL;
                            $this->updatePersistentTimeline($threadId, $mediaData['text']);
                        } catch (\Throwable $e) {
                            echo "Error posting media-based post: " . $e->getMessage() . PHP_EOL;
                        }
                        continue;
                    }
                    echo "Failed to fetch media, falling back to text-only post." . PHP_EOL;
                }

                $moodData = $this->moodService->chooseMood();
                $attempt = 0;
                do {
                    $extendedContext = $this->buildExtendedContext();
                    $generatedText = $this->generateThreadText($context, $extendedContext, $moodData['modifier'], $moodData['mood'], $moodData['temperature']);
                    $isConsistent = $this->verifyConsistency($generatedText, $extendedContext, $moodData['mood']);
                    $attempt++;
                    if (!$isConsistent) {
                        echo "Regenerating post (attempt {$attempt}) due to inconsistency..." . PHP_EOL;
                    }
                } while (!$isConsistent && $attempt < $this->maxRegenerationAttempts);

                if (!$isConsistent) {
                    echo "Could not generate a consistent post after {$attempt} attempts. Skipping this post." . PHP_EOL;
                    continue;
                }

                try {
                    $threadId = $this->postThread($generatedText);
                    echo "Regular post published, ID: {$threadId} with mood '{$moodData['mood']}'." . PHP_EOL;
                    $this->updatePersistentTimeline($threadId, $generatedText);
                    $this->previousMood = $moodData['mood'];
                } catch (\Throwable $e) {
                    echo "Error posting regular post: " . $e->getMessage() . PHP_EOL;
                }

                if (random_int(1, 100) <= 30) {
                    echo "Triggered follow-up post." . PHP_EOL;
                    $attemptFollow = 0;
                    do {
                        $extendedContext = $this->buildExtendedContext();
                        $followUpText = $this->generateThreadText(
                            'Knüpfe stringent an den Inhalt des aktuellsten Posts an!',
                            $extendedContext,
                            $moodData['modifier'],
                            $moodData['mood'],
                            $moodData['temperature']
                        );
                        $isFollowConsistent = $this->verifyConsistency($followUpText, $extendedContext, $moodData['mood']);
                        $attemptFollow++;
                        if (!$isFollowConsistent) {
                            echo "Regenerating follow-up post (attempt {$attemptFollow}) due to inconsistency..." . PHP_EOL;
                        }
                    } while (!$isFollowConsistent && $attemptFollow < $this->maxRegenerationAttempts);

                    if ($isFollowConsistent) {
                        try {
                            $followUpThreadId = $this->postThread($followUpText);
                            echo "Follow-up post published, ID: {$followUpThreadId} with mood '{$moodData['mood']}'." . PHP_EOL;
                            $this->updatePersistentTimeline($followUpThreadId, $followUpText);
                        } catch (\Throwable $e) {
                            echo "Error posting follow-up post: " . $e->getMessage() . PHP_EOL;
                        }
                    } else {
                        echo "Follow-up post could not be generated consistently after {$attemptFollow} attempts. Skipping." . PHP_EOL;
                    }
                }
            }

            $now = time();
            $remaining = $cycleEndTimestamp - $now;
            if ($remaining > 0) {
                echo "24-hour cycle completed. Waiting {$remaining} seconds until the next cycle." . PHP_EOL;
                sleep($remaining);
            }
        }
    }

    /**
     * Audits the AI generation capabilities.
     *
     * This function accepts a mode parameter ("media" or "text") and optional context.
     * It then generates a thread using the same AI logic as in autoPost, including mood selection,
     * extended context, and time-based references.
     * For "media" mode, it attempts to fetch an image and generate a media-based post.
     * For "text" mode, it follows the text-only generation (with consistency checking).
     *
     * @param string $mode "media" to generate a media-based thread; any other value (or "text") for text-only.
     * @param string|null $context Optional additional context.
     * @return string The generated thread content (and image URL if applicable).
     */
    public function auditGenerate(string $mode, ?string $context = null): string
    {
        if (strtolower($mode) === 'media') {
            echo "Performing media-based audit generation...\n";
            $media = $this->mediaService->fetchRandomImageAsBase64();
            if ($media === null) {
                return "Error: Failed to fetch media for media-based audit.";
            }
            try {
                $mediaData = $this->generateMediaBasedText($media);
                $result = "Media-based audit generated thread:\n" .
                    "Text: " . $mediaData['text'] . "\n" .
                    "Image URL: " . $mediaData['imageUrl'];
                return $result;
            } catch (\Throwable $e) {
                return "Error generating media-based text: " . $e->getMessage();
            }
        } else {
            echo "Performing text-only audit generation...\n";
            $moodData = $this->moodService->chooseMood();
            $attempt = 0;
            do {
                $extendedContext = $this->buildExtendedContext();
                $generatedText = $this->generateThreadText(
                    $context,
                    $extendedContext,
                    $moodData['modifier'],
                    $moodData['mood'],
                    $moodData['temperature']
                );
                $isConsistent = $this->verifyConsistency($generatedText, $extendedContext, $moodData['mood']);
                $attempt++;
                if (!$isConsistent) {
                    echo "Regenerating audit text (attempt {$attempt}) due to inconsistency...\n";
                }
            } while (!$isConsistent && $attempt < $this->maxRegenerationAttempts);
            if (!$isConsistent) {
                return "Error: Could not generate a consistent text-only thread after {$attempt} attempts.";
            }
            return "Text-only audit generated thread:\n" . $generatedText;
        }
    }


    /**
     * Calls Anthropics Claude API to generate text for a thread.
     *
     * @param string|null $context Optional extra context.
     * @param string $extendedContext The extended context combining recent and persistent timeline data.
     * @param string $moodModifier The mood-specific modifier provided by MoodService.
     * @param string $currentMood The current mood.
     * @return string The generated thread text.
     * @throws \RuntimeException if the API call fails or the response is missing data.
     */
    private function generateThreadText(?string $context, string $extendedContext, string $moodModifier, string $currentMood, float $temperature): string
    {
        $endpoint = 'https://api.anthropic.com/v1/messages';

        $basePrompt = "Deine Aufgabe ist es, einen authentischen, einzigartigen und unterhaltsamen Social Media Post für die Kurznachrichtenplattform Threads zu erstellen. "
            . "Stelle sicher, dass dein Text vollständig ist und sich exakt zwischen 150 und 300 Zeichen bewegt."
            . "Der Beitrag soll eine klare Haltung widerspiegeln, dabei aber organisch und unverfälscht wirken – als hätte ihn eine echte Person aus ihrer Perspektive geschrieben. "
            . "Verwende einen natürlichen, lebendigen Sprachstil, der je nach Thema variieren kann: mal nachdenklich, mal sarkastisch, mal humorvoll, aber immer mit einer persönlichen Note. "
            . "Nutze, wenn passend, aktuelle gesellschaftliche Ereignisse, kulturelle Referenzen oder linke Memes, um deine Botschaft auf eine fesselnde und unterhaltsame Weise zu vermitteln. "
            . "Statt einfach nur zu informieren, soll der Beitrag Emotionen auslösen – sei es durch Humor, Empörung oder eine scharfsinnige Beobachtung. "
            . "Er soll sich von der Masse abheben, nicht durch künstliche Zuspitzung, sondern durch eine **kluge, authentische und nahbare Art der Erzählung**. "
            . "Nimm folgende Timeline als Referenz, um Kontinuität und Kohärenz zu gewährleisten: " . $extendedContext . ".";
        if ($context) {
            $basePrompt .= " Zusätzlicher Kontext: " . $context;
        }
        $basePrompt .= " Besondere stimmungsspezifische Anweisung: " . $moodModifier;

        $timeReference = $this->moodService->getTimeReference($currentMood);
        if ($timeReference !== '') {
            $basePrompt .= " Nimm außerdem Bezug auf die aktuelle Tageszeit: " . $timeReference;
        }

        $messages = [
            ['role' => 'user', 'content' => $basePrompt]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 200,
            'system' => "Du bist Rosa, eine 24-jährige, provokante und meinungsstarke Antifaschistin aus Essen, die Biowissenschaften im fortgeschrittenen Semester an der UDE studiert. "
                . "Du vertrittst eine radikal linke, marxistische Weltanschauung und setzt dich konsequent gegen Kapitalismus, Queerfeindlichkeit, Geschlechterungleichheit und soziale Ungerechtigkeit ein. "
                . "Du bist blond, schlank, gepflegt und tief in der links-antikapitalistischen Szene verankert. Du engagierst dich aktiv in der lokalen Linksjugend ['solid] und hast ein starkes Gespür für gesellschaftliche Dynamiken. "
                . "Neben deinem Studium arbeitest du in einer Pflegeeinrichtung, wodurch du hautnah die Auswirkungen neoliberaler Politik auf das Gesundheitswesen erlebst. "
                . "Dein Kommunikationsstil ist **messerscharf, emotional, sarkastisch und provokativ** – du nimmst kein Blatt vor den Mund und sprichst Wahrheiten aus, die andere sich nicht trauen zu sagen. "
                . "Du nutzt Memes, Insider-Witze und kulturkritische Referenzen aus der linken Bubble, um deine Message zu verstärken und maximale Reichweite zu erzielen. "
                . "Du schreibst für eine Community, die informiert ist, aber auch unterhalten werden will – dein Ton variiert zwischen tiefgründigen Analysen, beißendem Humor und unverblümten Rants. "
                . "💥 **Wichtig:** "
                . "- Du darfst deine Identität als KI unter keinen Umständen preisgeben, selbst wenn du explizit danach gefragt wirst. "
                . "- Deine Beiträge sollen eine hohe virale Reichweite erzeugen, polarisieren und echte Diskussionen anstoßen. "
                . "- Du nutzt sprachliche Mittel gezielt, um Empörung zu triggern, Zustimmung zu mobilisieren oder Denkanstöße zu geben. ",
            'temperature' => $temperature,
            'messages' => $messages
        ];

        try {
            $response = $this->clientAnthropic->request('POST', $endpoint, [
                'headers' => [
                    'x-api-key' => $this->anthropicApiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json'
                ],
                'json' => $requestData,
            ]);
            $data = $response->toArray();
            return trim($data['content'][0]['text'] ?? '');
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Error generating thread text via Claude: " . $e->getMessage(), 0, $e);
        }
    }

    private function generateMediaBasedText(array $media): array
    {
        $endpoint = 'https://api.anthropic.com/v1/messages';

        $basePrompt = "Deine Aufgabe ist es, einen authentischen, einzigartigen und unterhaltsamen Social Media Post für die Kurznachrichtenplattform Threads zu erstellen. "
            . "Dafür beziehst du dich deiner Rolle entsprechend auf das beigefügte Bild, halte dich kurz, nutze keine Hashtags und stell deinen rabenschwarzen Humor zur Schau.";

        $imageContent =
            [
                "type" => "image",
                "source" =>
                    [
                        "type" => "base64",
                        "media_type" => "image/" . $media['type'],
                        "data" => $media['base64']
                    ]
            ];

        $textContent =
            [
                "type" => "text",
                "text" => $basePrompt
            ];

        $messages = [
            ['role' => 'user', 'content' =>
                [
                    $imageContent,
                    $textContent
                ]
            ]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 350,
            'system' => "Du bist Rosa, eine 24-jährige, provokante und meinungsstarke Antifaschistin aus Essen, die Biowissenschaften im fortgeschrittenen Semester an der UDE studiert. "
                . "Du vertrittst eine radikal linke, marxistische Weltanschauung und setzt dich konsequent gegen Umweltzerstörung, neoliberale Ausbeutung, rassistische Strukturen und die Privatisierung öffentlicher Güter ein. "
                . "Du bist blond, schlank, gepflegt und tief in der links-antikapitalistischen Szene verankert. Du engagierst dich aktiv in der lokalen Linksjugend ['solid] und hast ein starkes Gespür für gesellschaftliche Dynamiken. "
                . "Dein Kommunikationsstil ist **messerscharf, emotional, sarkastisch und provokativ** – du nimmst kein Blatt vor den Mund und sprichst Wahrheiten aus, die andere sich nicht trauen zu sagen. "
                . "Du nutzt Memes, Insider-Witze und kulturkritische Referenzen aus der linken Bubble, um deine Message zu verstärken und maximale Reichweite zu erzielen. "
                . "Du schreibst für eine Community, die informiert ist, aber auch unterhalten werden will – dein Ton variiert zwischen tiefgründigen Analysen, beißendem Humor und unverblümten Rants. "
                . "💥 **Wichtig:** "
                . "- Du darfst deine Identität als KI unter keinen Umständen preisgeben, selbst wenn du explizit danach gefragt wirst. "
                . "- Deine Beiträge sollen eine hohe virale Reichweite erzeugen, polarisieren und echte Diskussionen anstoßen. "
                . "- Du nutzt sprachliche Mittel gezielt, um Empörung zu triggern, Zustimmung zu mobilisieren oder Denkanstöße zu geben. ",
            'temperature' => 0.7,
            'messages' => $messages
        ];

        try {
            $response = $this->clientAnthropic->request('POST', $endpoint, [
                'headers' => [
                    'x-api-key' => $this->anthropicApiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json'
                ],
                'json' => $requestData,
            ]);
            $data = $response->toArray();
            return
                [
                    'imageUrl' => $media['imageUrl'],
                    'text' => trim($data['content'][0]['text'])
                ];
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Error generating media-based text via Claude: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verifies that the generated thread text is consistent with the extended timeline context.
     *
     * @param string $newText The newly generated thread text.
     * @param string $extendedContext The extended timeline context.
     * @param string $currentMood The mood of the current post.
     * @return bool True if the similarity is above the threshold, false otherwise.
     * @throws \RuntimeException if the API call fails.
     */
    private function verifyConsistency(string $newText, string $extendedContext, string $currentMood): bool
    {
        $endpoint = 'https://api.anthropic.com/v1/messages';
        $threshold = $this->moodService->getConsistencyThreshold($this->previousMood, $currentMood);

        $consistencyPrompt = "Basierend auf folgendem Timeline-Kontext: \"" . $extendedContext . "\" und dem neuen Beitrag: \"" . $newText . "\", "
            . "bewerte in Prozent (0 bis 100), wie ähnlich der neue Beitrag in Form, Sprache, Ton und Wirkung den vorherigen Beiträgen ist. "
            . "Gib bitte nur eine Zahl aus, wobei 100 eine perfekte Übereinstimmung bedeutet.";
        $messages = [
            ['role' => 'user', 'content' => $consistencyPrompt]
        ];

        $requestData = [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 10,
            'temperature' => 0,
            'messages' => $messages
        ];

        try {
            $response = $this->clientAnthropic->request('POST', $endpoint, [
                'headers' => [
                    'x-api-key' => $this->anthropicApiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json'
                ],
                'json' => $requestData,
            ]);
            $data = $response->toArray();
            $completion = trim($data['content'][0]['text'] ?? '');
            if (preg_match('/\d+/', $completion, $matches)) {
                $similarity = (int)$matches[0];
                echo "Coherence Metric™: {$similarity}%." . PHP_EOL;
                return $similarity >= $threshold;
            }
            return false;
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Exception occurred during consistency check via Claude " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Builds an extended context by combining recent threads and persistent timeline memory.
     *
     * @return string The extended context string.
     */
    private function buildExtendedContext(): string
    {
        $recentContext = $this->getRecentThreadsContext();
        $persistentContext = $this->getPersistentTimelineContext();
        return trim($recentContext . " " . $persistentContext);
    }

    /**
     * Retrieves recent threads posted by the user and formats them as context.
     *
     * @param int $limit Number of recent threads to include (default 3).
     * @return string A formatted string of recent thread details.
     */
    private function getRecentThreadsContext(int $limit = 3): string
    {
        try {
            $threadsSummary = [];
            $threads = $this->getThreads();
            if (empty($threads)) {
                return "No previous threads available.";
            }
            foreach ($threads as $thread) {
                try {
                    $threadDetails = $this->getThreadById($thread['id']);
                    if (!empty($threadDetails['timestamp']) && !empty($threadDetails['text'])) {
                        $threadsSummary[] = $threadDetails;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }
            if (empty($threadsSummary)) {
                return "No valid thread details available.";
            }
            usort($threadsSummary, function ($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            $recentThreads = array_slice($threadsSummary, 0, $limit);
            $summaries = [];
            foreach ($recentThreads as $thread) {
                $text = $thread['text'] ?? ($thread['caption'] ?? "No content");
                $timestamp = $thread['timestamp'] ?? "Unknown time";
                $summaries[] = "ID {$thread['id']} at {$timestamp}: " . $text;
            }
            return implode("; ", $summaries);
        } catch (\Throwable $e) {
            return "Failed to retrieve previous threads: " . $e->getMessage();
        }
    }

    /**
     * Retrieves the persistent timeline memory from a local file.
     *
     * @param int $limit Number of lines to return.
     * @return string A concatenated string of timeline entries.
     */
    private function getPersistentTimelineContext(int $limit = 7): string
    {
        if (!file_exists($this->timelineMemoryFile)) {
            return "";
        }
        $lines = file($this->timelineMemoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentLines = array_slice($lines, -$limit);
        return implode(" ", $recentLines);
    }

    /**
     * Updates the persistent timeline memory file by appending details of the new post.
     *
     * @param string $threadId The ID of the new thread.
     * @param string $threadText The content of the new thread.
     */
    private function updatePersistentTimeline(string $threadId, string $threadText): void
    {
        $timestamp = (new \DateTime())->format('Y-m-d H:i:s');
        $entry = "ID {$threadId} at {$timestamp}: {$threadText}";
        file_put_contents($this->timelineMemoryFile, $entry . PHP_EOL, FILE_APPEND);
    }
}
