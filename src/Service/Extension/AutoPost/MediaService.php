<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost;

use Symfony\Component\HttpClient\HttpClient;
use Symfony\Contracts\HttpClient\Exception\ClientExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\RedirectionExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\ServerExceptionInterface;
use Symfony\Contracts\HttpClient\Exception\TransportExceptionInterface;

class MediaService
{
    private array $subreddits = [
        'ich_iel',
        'ich_politik',
        'Staiy',
        'PoliticalHumor',
        'MemeEconomy',
        'HistoryMemes'
    ];

    private string $redditApiBaseUrl = 'https://www.reddit.com/r/';
    private $httpClient;

    public function __construct()
    {
        $this->httpClient = HttpClient::create();
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
     * Sets the list of subreddits.
     *
     * @param array $subreddits The new array of subreddits.
     */
    public function setSubreddits(array $subreddits): void
    {
        $this->subreddits = $subreddits;
    }

    /**
     * Fetches an image from a random subreddit and returns its Base64 representation along with its type.
     *
     * @return array|null The image details (URL, Base64 string, and type), or null if no image could be fetched.
     */
    public function fetchRandomImageAsBase64(): ?array
    {
        $randomSubreddit = $this->getRandomSubreddit();
        $url = $this->redditApiBaseUrl . $randomSubreddit . '/top.json?limit=10&t=day';

        try {
            $response = $this->httpClient->request('GET', $url, [
                'headers' => [
                    'User-Agent' => 'generic-user/1.0'
                ]
            ]);

            $data = $response->toArray();

            if (empty($data['data']['children'])) {
                return null;
            }

            $imageUrl = $this->getImageFromPosts($data['data']['children']);
            if (!$imageUrl) {
                return null;
            }

            $imageType = $this->getImageTypeFromUrl($imageUrl);

            return [
                'imageUrl' => $imageUrl,
                'base64' => $this->convertImageToBase64($imageUrl),
                'type' => $imageType
            ];

        } catch (TransportExceptionInterface|
        ClientExceptionInterface|
        RedirectionExceptionInterface|
        ServerExceptionInterface $e) {
            echo 'Error fetching image from subreddit: ' . $e->getMessage();
            return null;
        }
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
