<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoReply\External;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class PostGenerationService
{
    private HttpClientInterface $clientAnthropic;
    private string $anthropicApiKey;

    public function __construct(
        HttpClientInterface   $clientAnthropic,
        ParameterBagInterface $params
    )
    {
        $this->anthropicApiKey = $params->get('ANTHROPIC_API_KEY');
        if (empty($this->anthropicApiKey)) {
            throw new \InvalidArgumentException('ANTHROPIC_API_KEY is not configured.');
        }
        $this->clientAnthropic = $clientAnthropic;
    }

    /**
     * Generates a reply text based on den übergebenen Kontext.
     *
     * @param string $replyContext
     * @return string|null
     */
    public function generateReply(string $replyContext): ?string
    {
        $userPrompt = PromptProvider::getReplyBasedUserPrompt($replyContext);
        $systemPrompt = PromptProvider::getReplyBasedSystemPrompt();

        $messages = [
            [
                'role' => 'user',
                'content' => $userPrompt,
            ]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 50,
            'system' => $systemPrompt,
            'temperature' => 1.0,
            'messages' => $messages,
        ];

        $text = $this->executeRequest($requestData, "reply text");
        $text = preg_replace(
            [
                '/\[[^]]*]/',
                '/\([^)]*\)/',
                '/\*[^*]*\*/'
            ],
            '',
            $text
        );

        $text = trim(preg_replace('/\s+/', ' ', $text));
        return $text !== '' ? $text : null;
    }

    /**
     * Führt einen HTTP-Request an die Anthropics Claude API aus.
     *
     * @param array $requestData JSON-Payload für den Request.
     * @param string $contextInfo Zusätzliche Kontext-Informationen für Fehlermeldungen.
     * @return string Der getrimmte Text aus der API-Antwort.
     * @throws \RuntimeException Wenn der API-Call fehlschlägt.
     */
    private function executeRequest(array $requestData, string $contextInfo): string
    {
        try {
            $response = $this->clientAnthropic->request('POST', 'https://api.anthropic.com/v1/messages', [
                'headers' => [
                    'x-api-key' => $this->anthropicApiKey,
                    'anthropic-version' => '2023-06-01',
                    'Content-Type' => 'application/json',
                ],
                'json' => $requestData,
            ]);
            $data = $response->toArray();

            if (empty($data['content']) || !is_array($data['content'])) {
                throw new \RuntimeException("Unexpected API response structure: 'content' key missing or invalid.");
            }
            $text = $data['content'][0]['text'] ?? '';
            $text = trim((string)$text);
            if ($text === '') {
                throw new \RuntimeException("Received empty reply text from API.");
            }
            return $text;
        } catch (
        TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e
        ) {
            throw new \RuntimeException("Error generating {$contextInfo} via Claude: " . $e->getMessage(), 0, $e);
        }
    }
}
