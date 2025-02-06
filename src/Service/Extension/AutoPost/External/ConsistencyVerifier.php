<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost\External;

use App\Service\Extension\AutoPost\Internal\MoodService;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ConsistencyVerifier
{
    private HttpClientInterface $clientAnthropic;
    private string $anthropicApiKey;
    private MoodService $moodService;

    public function __construct(
        HttpClientInterface   $clientAnthropic,
        MoodService           $moodService,
        ParameterBagInterface $params,
    )
    {
        $this->anthropicApiKey = $params->get('ANTHROPIC_API_KEY');
        $this->clientAnthropic = $clientAnthropic;
        $this->moodService = $moodService;
    }

    /**
     * Verifies that the generated thread text is consistent with the extended timeline context.
     *
     * @param string $newText The newly generated thread text.
     * @param string $extendedContext The extended timeline context.
     * @param string $currentMood The mood of the current post.
     * @param string|null $previousMood The previous post mood (if any).
     * @return bool True if the similarity meets or exceeds the threshold, false otherwise.
     * @throws \RuntimeException If the API call fails.
     */
    public function verifyConsistency(
        string  $newText,
        string  $extendedContext,
        string  $currentMood,
        ?string $previousMood
    ): bool
    {
        $threshold = $this->moodService->getConsistencyThreshold($previousMood, $currentMood);
        $userPrompt = PromptProvider::getConsistencyUserPrompt($extendedContext, $newText);

        $messages = [
            ['role' => 'user', 'content' => $userPrompt]
        ];
        $requestData = [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 10,
            'temperature' => 0,
            'messages' => $messages
        ];

        try {
            $response = $this->clientAnthropic->request('POST', 'https://api.anthropic.com/v1/messages', [
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
                echo "ℹ️🔗 Coherence Metric™ --> {$similarity}%." . PHP_EOL;
                return $similarity >= $threshold;
            }
            return false;
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Error during consistency check via Claude: " . $e->getMessage(), 0, $e);
        }
    }

    public function verifyAuthenticity(
        string $newText,
    ): bool
    {
        $threshold = 76;
        $userPrompt = PromptProvider::getAuthenticityUserPrompt($newText);

        $messages = [
            ['role' => 'user', 'content' => $userPrompt]
        ];
        $requestData = [
            'model' => 'claude-3-5-haiku-20241022',
            'max_tokens' => 15,
            'temperature' => 0,
            'messages' => $messages
        ];

        try {
            $response = $this->clientAnthropic->request('POST', 'https://api.anthropic.com/v1/messages', [
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
                echo "ℹ️🗿 Authenticity Metric™ --> {$similarity}%." . PHP_EOL;
                return $similarity <= $threshold;
            }
            return false;
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Error during authenticity check via Claude: " . $e->getMessage(), 0, $e);
        }
    }
}
