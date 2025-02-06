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

class PostGenerationService
{
    private HttpClientInterface $clientAnthropic;
    private string $anthropicApiKey;
    private MoodService $moodService;

    public function __construct(
        HttpClientInterface   $clientAnthropic,
        MoodService           $moodService,
        ParameterBagInterface $params
    )
    {
        $this->anthropicApiKey = $params->get('ANTHROPIC_API_KEY');
        $this->clientAnthropic = $clientAnthropic;
        $this->moodService = $moodService;
    }

    /**
     * Generates a thread text based on the provided context and mood.
     */
    public function generateThreadText(
        ?string $context,
        string  $extendedContext,
        string  $moodModifier,
        string  $currentMood,
        float   $temperature
    ): string
    {
        $userPrompt = PromptProvider::getThreadTextUserPrompt(
            $context,
            $extendedContext,
            $moodModifier,
            $currentMood,
            $this->moodService
        );
        $systemPrompt = PromptProvider::getThreadTextSystemPrompt();

        $messages = [
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 200,
            'system' => $systemPrompt,
            'temperature' => $temperature,
            'messages' => $messages
        ];

        return $this->executeRequest($requestData, "thread text");
    }

    /**
     * Generates a chaotic thread text with strict word limits and a specific tone.
     */
    public function generateChaoticThreadText(
        ?string $context,
        string  $extendedContext,
        string  $moodModifier,
        string  $currentMood,
        float   $temperature
    ): string
    {
        $userPrompt = PromptProvider::getChaoticThreadTextUserPrompt(
            $context,
            $extendedContext,
            $moodModifier,
            $currentMood,
            $this->moodService
        );
        $systemPrompt = PromptProvider::getChaoticThreadTextSystemPrompt();

        $messages = [
            ['role' => 'user', 'content' => $userPrompt]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 125,
            'system' => $systemPrompt,
            'temperature' => $temperature,
            'messages' => $messages
        ];

        return $this->executeRequest($requestData, "chaotic thread text");
    }

    /**
     * Generates a media-based thread text using an image.
     *
     * @param array $media An associative array with keys 'type', 'base64', and 'imageUrl'.
     * @return array An array containing 'imageUrl' and generated 'text'.
     */
    public function generateMediaBasedText(array $media): array
    {
        $userPrompt = PromptProvider::getMediaBasedUserPrompt();
        $systemPrompt = PromptProvider::getMediaBasedSystemPrompt();

        $imageContent = [
            "type" => "image",
            "source" => [
                "type" => "base64",
                "media_type" => "image/" . $media['type'],
                "data" => $media['base64']
            ]
        ];

        $textContent = [
            "type" => "text",
            "text" => $userPrompt
        ];

        $messages = [
            [
                'role' => 'user',
                'content' => [
                    $imageContent,
                    $textContent
                ]
            ]
        ];

        $requestData = [
            'model' => 'claude-3-5-sonnet-20241022',
            'max_tokens' => 200,
            'system' => $systemPrompt,
            'temperature' => 0.7,
            'messages' => $messages
        ];

        $text = $this->executeRequest($requestData, "media-based text");

        $text = preg_replace([
            '/\[[^]]*]/',
            '/\([^)]*\)/',
            '/\*[^*]*\*/'
        ], '', $text);

        $text = trim(preg_replace('/\s+/', ' ', $text));

        return [
            'imageUrl' => $media['imageUrl'],
            'text' => $text
        ];
    }


    /**
     * Executes an HTTP request to the Anthropics Claude API with the provided data.
     *
     * @param array $requestData The JSON payload for the request.
     * @param string $contextInfo Informational context for error messages.
     * @return string The trimmed text response from the API.
     * @throws \RuntimeException If the API call fails.
     */
    private function executeRequest(array $requestData, string $contextInfo): string
    {
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
            return trim($data['content'][0]['text'] ?? '');
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        ServerExceptionInterface|
        RedirectionExceptionInterface|
        \Exception $e) {
            throw new \RuntimeException("Error generating {$contextInfo} via Claude: " . $e->getMessage(), 0, $e);
        }
    }
}
