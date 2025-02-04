<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class AiAutoPostService extends ThreadsApiService
{
    private string $anthropicApiKey;
    private $clientAnthropic;
    private string $timelineMemoryFile;
    private int $maxRegenerationAttempts = 3;
    private ?string $previousMood = null;

    public function __construct(ParameterBagInterface $params)
    {
        parent::__construct($params);
        $this->anthropicApiKey = $params->get('ANTHROPIC_API_KEY');
        $this->clientAnthropic = HttpClient::create();
        $this->timelineMemoryFile = __DIR__ . '/timeline_memory.txt';
    }

    /**
     * Starts the persistent auto-post process.
     *
     * @param string $range Allowed values: "1-3", "3-5", or "5-10".
     * @param string|null $context Optional extra context to refine the AI output.
     * @throws \RuntimeException if an invalid range is provided or if an API error occurs.
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
            echo "In den nächsten 24 Stunden sind {$quotaPosts} reguläre Posts vorgesehen." . PHP_EOL;

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
                    echo "Warte {$sleepTime} Sekunden bis zum nächsten geplanten Post..." . PHP_EOL;
                    sleep($sleepTime);
                }

                $moodData = $this->chooseMood();
                $attempt = 0;
                do {
                    $extendedContext = $this->buildExtendedContext();
                    $generatedText = $this->generateThreadText($context, $extendedContext, $moodData['modifier'], $moodData['mood']);
                    $isConsistent = $this->verifyConsistency($generatedText, $extendedContext, $moodData['mood']);
                    $attempt++;
                    if (!$isConsistent) {
                        echo "Regeneriere Beitrag (Versuch {$attempt}) wegen Inkonsistenz..." . PHP_EOL;
                    }
                } while (!$isConsistent && $attempt < $this->maxRegenerationAttempts);

                if (!$isConsistent) {
                    echo "Konnte nach {$attempt} Versuchen keinen konsistenten Beitrag generieren. Überspringe diesen geplanten Post." . PHP_EOL;
                    continue;
                }

                try {
                    $threadId = $this->postThread($generatedText);
                    echo "Regulärer Post veröffentlicht, ID: {$threadId} mit Stimmung '{$moodData['mood']}'." . PHP_EOL;
                    $this->updatePersistentTimeline($threadId, $generatedText);
                    $this->previousMood = $moodData['mood'];
                } catch (\Throwable $e) {
                    echo "Fehler beim Posten des regulären Beitrags: " . $e->getMessage() . PHP_EOL;
                }

                if (random_int(1, 100) <= 30) {
                    echo "Follow-Up-Post ausgelöst." . PHP_EOL;
                    $attemptFollow = 0;
                    do {
                        $extendedContext = $this->buildExtendedContext();
                        $followUpText = $this->generateThreadText('Knüpfe stringent an den Inhalt des aktuellsten Posts an!', $extendedContext, $moodData['modifier'], $moodData['mood']);
                        $isFollowConsistent = $this->verifyConsistency($followUpText, $extendedContext, $moodData['mood']);
                        $attemptFollow++;
                        if (!$isFollowConsistent) {
                            echo "Regeneriere Follow-Up-Beitrag (Versuch {$attemptFollow}) wegen Inkonsistenz..." . PHP_EOL;
                        }
                    } while (!$isFollowConsistent && $attemptFollow < $this->maxRegenerationAttempts);

                    if ($isFollowConsistent) {
                        try {
                            $followUpThreadId = $this->postThread($followUpText);
                            echo "Follow-Up-Post veröffentlicht, ID: {$followUpThreadId} mit Stimmung '{$moodData['mood']}'." . PHP_EOL;
                            $this->updatePersistentTimeline($followUpThreadId, $followUpText);
                        } catch (\Throwable $e) {
                            echo "Fehler beim Posten des Follow-Up-Beitrags: " . $e->getMessage() . PHP_EOL;
                        }
                    } else {
                        echo "Follow-Up-Post konnte nach {$attemptFollow} Versuchen nicht konsistent generiert werden. Überspringe Follow-Up." . PHP_EOL;
                    }
                }
            }

            $now = time();
            $remaining = $cycleEndTimestamp - $now;
            if ($remaining > 0) {
                echo "24h-Zyklus beendet. Warte {$remaining} Sekunden bis zum nächsten Zyklus." . PHP_EOL;
                sleep($remaining);
            }
        }
    }

    /**
     * Chooses one of three different moods for a post with additional parameters.
     *
     * @return array Contains keys 'mood' and 'modifier'.
     */
    private function chooseMood(): array
    {
        $rand = random_int(1, 100);
        if ($rand <= 40) {
            return [
                'mood' => 'politisch-engagiert',
                'modifier' => "Erstelle einen prägnanten, formellen Beitrag, der sich intensiv und engagiert mit aktuellen politischen Ereignissen auseinandersetzt. Vermeide Emojis und halte den Text kurz und informativ."
            ];
        }

        if ($rand <= 70) {
            return [
                'mood' => 'persönlich-emotional',
                'modifier' => "Erstelle einen persönlichen, emotionalen und humorvollen oder traurigen Beitrag, der sehr intime Erlebnisse im Zusammenhang mit politischen Themen beschreibt. Verwende passende Emojis und variierende Satzlängen, mit leicht inkonsistenter Grammatik, regionaler, persönlicher & authentischer Sprachweise."
            ];
        }

        return [
            'mood' => 'ausgewogen',
            'modifier' => "Erstelle einen ausgewogenen Beitrag, der politische Themen mit persönlichen Einblicken kombiniert. Der Text soll moderat lang sein, gelegentlich Emojis enthalten und einen authentischen, sachlichen Ton bewahren."
        ];
    }

    /**
     * Calls Anthropics Claude API to generate text for a thread.
     * It uses the internal timeline context, additional context, and a mood-specific modifier.
     * The final output is instructed to be in German.
     *
     * @param string|null $context Optional extra context.
     * @param string $extendedContext The extended context combining recent and persistent timeline data.
     * @param string $moodModifier The mood-specific modifier to be appended to the prompt.
     * @param string $currentMood The current mood.
     * @return string The generated thread text.
     * @throws \RuntimeException if the API call fails or the response is missing data.
     */
    private function generateThreadText(?string $context, string $extendedContext, string $moodModifier, string $currentMood): string
    {
        $endpoint = 'https://api.anthropic.com/v1/messages';

        $basePrompt = "Generate a single authentic, stringent, and provocative text-based social media post for a short message sharing plattform similar to twitter. "
            . "The content should represent Marxist worldviews, anti-capitalism, gender equality, LGBTQ+ acceptance, and offer captivating insights into real life. "
            . "The threads should reflect the personality behind the profile with personal experiences or politically relevant events. "
            . "Use the following timeline context for continuity: " . $extendedContext . ".";
        if ($context) {
            $basePrompt .= " Additional context: " . $context;
        }
        $basePrompt .= " Mood modifier: " . $moodModifier;
        if ($currentMood === 'persönlich-emotional' && random_int(1, 100) <= 20) {
            $hour = (int)(new \DateTime())->format('H');
            if ($hour < 12) {
                $timeReference = "Es ist jetzt Morgen.";
            } elseif ($hour < 18) {
                $timeReference = "Es ist jetzt Nachmittag.";
            } else {
                $timeReference = "Es ist jetzt Abend.";
            }
            $basePrompt .= " " . $timeReference;
        }
        $basePrompt .= " Please provide the final answer exclusively in German.";

        $messages = [
            ['role' => 'user', 'content' => $basePrompt]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 150,
            'temperature' => 0.8,
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
            throw new \RuntimeException("Fehler beim Generieren des Thread-Texts via Claude: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Verifies that the generated thread text is consistent with the extended timeline context.
     * The API is asked to return a similarity percentage (0-100).
     * If the current mood differs from the previous one, a lower threshold is applied.
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
        $threshold = ($this->previousMood !== null && $this->previousMood !== $currentMood) ? 50 : 70;

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
                echo "Ähnlichkeitswert: {$similarity}%." . PHP_EOL;
                return $similarity >= $threshold;
            }
            return false;
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Fehler bei der Konsistenzprüfung via Claude: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Builds an extended context by combining recent threads (via API) and persistent timeline memory.
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
