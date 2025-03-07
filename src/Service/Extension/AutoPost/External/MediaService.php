<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost\External;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class MediaService
{
    private array $subreddits = [];
    private string $redditApiBaseUrl = 'https://oauth.reddit.com/r/';
    private $httpClient;
    private ParameterBagInterface $params;
    private string $clientId;
    private string $clientSecret;
    private string $userAgent;
    private ?string $accessToken = null;

    private const SUBREDDITS_FILE = __DIR__ . '/../../../../config/subreddits.json';

    public function __construct(ParameterBagInterface $params)
    {
        $this->params = $params;
        $this->clientId = $this->params->get('REDDIT_CLIENT_ID');
        $this->clientSecret = $this->params->get('REDDIT_CLIENT_SECRET');
        $this->userAgent = $this->params->get('REDDIT_USER_AGENT');

        $this->httpClient = HttpClient::create();
        $this->loadSubredditsFromFile();
    }

    /**
     * Loads subreddits from a JSON file.
     */
    private function loadSubredditsFromFile(): void
    {
        if (file_exists(self::SUBREDDITS_FILE)) {
            $json = file_get_contents(self::SUBREDDITS_FILE);
            $data = json_decode($json, true);
            if (is_array($data)) {
                $this->subreddits = $data;
                return;
            }
        }
        $this->subreddits = [
            'ich_iel',
            'ich_politik',
            'Staiy',
            'PoliticalHumor',
            'MemeEconomy',
            'HistoryMemes'
        ];
        $this->saveSubredditsToFile();
    }

    /**
     * Persists the current subreddits to the JSON file.
     */
    private function saveSubredditsToFile(): void
    {
        file_put_contents(self::SUBREDDITS_FILE, json_encode($this->subreddits, JSON_PRETTY_PRINT));
    }

    /**
     * Gets the current list of subreddits.
     *
     * @return array The array of subreddits.
     */
    public function getSubreddits(): array
    {
        return $this->subreddits;
    }

    /**
     * Sets the list of subreddits and persists the change.
     *
     * @param array $subreddits The new array of subreddits.
     */
    public function setSubreddits(array $subreddits): void
    {
        $this->subreddits = $subreddits;
        $this->saveSubredditsToFile();
    }

    /**
     * Always requests a new access token using the client credentials grant.
     *
     * @return string|null
     */
    private function getAccessToken(): ?string
    {
        $tokenUrl = 'https://www.reddit.com/api/v1/access_token';
        $basicAuth = base64_encode($this->clientId . ':' . $this->clientSecret);

        try {
            $response = $this->httpClient->request('POST', $tokenUrl, [
                'headers' => [
                    'Authorization' => 'Basic ' . $basicAuth,
                    'User-Agent' => $this->userAgent,
                ],
                'body' => [
                    'grant_type' => 'client_credentials'
                ]
            ]);

            $data = $response->toArray();
            if (isset($data['access_token'], $data['expires_in'])) {
                $this->accessToken = $data['access_token'];
                return $this->accessToken;
            }
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        RedirectionExceptionInterface|
        ServerExceptionInterface $e) {
            echo 'Error fetching access token: ' . $e->getMessage();
        }
        return null;
    }

    /**
     * Fetches an image from a random subreddit and returns its Base64 representation along with its type.
     * If the image cannot be fetched or processed, it retries with a different subreddit (up to 3 attempts).
     *
     * @return array|null The image details (URL, Base64 string, and type), or null if no image could be fetched.
     */
    public function fetchRandomImageAsBase64(): ?array
    {
        $maxAttempts = 3;
        $attempt = 0;
        while ($attempt < $maxAttempts) {
            $attempt++;
            $randomSubreddit = $this->getRandomSubreddit();
            $url = $this->redditApiBaseUrl . $randomSubreddit . '/top.json?limit=10&t=day';

            $accessToken = $this->getAccessToken();
            if (!$accessToken) {
                return null;
            }

            try {
                $response = $this->httpClient->request('GET', $url, [
                    'headers' => [
                        'User-Agent' => $this->userAgent,
                        'Authorization' => 'Bearer ' . $accessToken,
                    ]
                ]);
                $data = $response->toArray();

                if (empty($data['data']['children'])) {
                    continue;
                }

                $imageUrl = $this->getImageFromPosts($data['data']['children']);
                if (!$imageUrl) {
                    continue;
                }

                $imageType = $this->getImageTypeFromUrl($imageUrl);
                $base64Image = $this->convertImageToBase64($imageUrl);

                if ($base64Image === null) {
                    continue;
                }

                return [
                    'imageUrl' => $imageUrl,
                    'base64' => $base64Image,
                    'type' => $imageType
                ];
            } catch (TransportExceptionInterface|
            ClientExceptionInterface|
            RedirectionExceptionInterface|
            ServerExceptionInterface $e) {
                continue;
            }
        }
        return null;
    }

    /**
     * Selects a random subreddit from the list.
     *
     * @return string The selected subreddit name.
     */
    private function getRandomSubreddit(): string
    {
        return $this->subreddits[array_rand($this->subreddits)];
    }

    /**
     * Extracts an image URL from the fetched Reddit posts.
     *
     * @param array $posts An array of Reddit posts.
     * @return string|null The image URL, or null if no valid image is found.
     */
    private function getImageFromPosts(array $posts): ?string
    {
        foreach ($posts as $post) {
            $postData = $post['data'] ?? [];
            if (isset($postData['url']) && $this->isImageUrl($postData['url'])) {
                return $postData['url'];
            }
        }
        return null;
    }

    /**
     * Checks if the given URL points to an image.
     *
     * @param string $url The URL to check.
     * @return bool True if the URL points to an image, false otherwise.
     */
    private function isImageUrl(string $url): bool
    {
        return preg_match('/\.(webp|jpeg|png|gif)$/i', $url) === 1;
    }

    /**
     * Extracts the image type (file extension) from the given URL.
     *
     * @param string $imageUrl The URL of the image.
     * @return string|null The image type (e.g., png, jpeg), or null if not found.
     */
    private function getImageTypeFromUrl(string $imageUrl): ?string
    {
        if (preg_match('/\.(webp|jpeg|png|gif)$/i', $imageUrl, $matches)) {
            return strtolower($matches[1]);
        }
        return null;
    }

    /**
     * Converts an image from a given URL to its Base64 representation.
     *
     * @param string $imageUrl The URL of the image.
     * @return string|null The Base64-encoded image, or null if the conversion fails.
     */
    private function convertImageToBase64(string $imageUrl): ?string
    {
        try {
            $response = $this->httpClient->request('GET', $imageUrl);
            $imageContent = $response->getContent();
            return base64_encode($imageContent);
        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        RedirectionExceptionInterface|
        ServerExceptionInterface $e) {
            echo 'Error converting image to Base64: ' . $e->getMessage();
            return null;
        }
    }
}
