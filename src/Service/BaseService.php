<?php
declare(strict_types=1);

namespace App\Service;

use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Symfony\Component\HttpClient\HttpClient;

class BaseService
{
    public $client;
    private string $accessToken;
    private string $apiBaseUrl;
    private string $threadsUserId;

    public function __construct(ParameterBagInterface $params)
    {
        $this->client = HttpClient::create();
        $this->accessToken = $params->get('THREADS_ACCESS_TOKEN');
        $this->threadsUserId = $params->get('THREADS_USER_ID');
        $this->apiBaseUrl = 'https://graph.threads.net/v1.0';
    }

    /**
     * Retrieves all threads for the configured user with additional meta-data.
     *
     * @return array List of threads.
     * @throws \RuntimeException if the API call fails.
     */
    public function getThreads(): array
    {
        $url = "{$this->apiBaseUrl}/{$this->threadsUserId}/threads";

        try {
            $response = $this->client->request('GET', $url, [
                'query' => [
                    'fields' => 'id,caption,timestamp,username,permalink',
                    'access_token' => $this->accessToken,
                ],
            ]);
            $data = $response->toArray();
            return $data['data'] ?? [];
        } catch (\Throwable $e) {
            throw new \RuntimeException("⚠️  Failed to retrieve threads: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Creates a new text-only thread with the provided message.
     *
     * @param string $message The message (caption) for the thread.
     * @return string The ID of the created thread.
     * @throws \RuntimeException if the API call fails.
     */
    public function postThread(string $message, ?string $mediaUrl = null): string
    {
        $createUrl = "{$this->apiBaseUrl}/{$this->threadsUserId}/threads";

        if (isset($mediaUrl)) {
            try {
                $response = $this->client->request('POST', $createUrl, [
                    'query' => [
                        'media_type' => 'IMAGE',
                        'image_url' => $mediaUrl,
                        'text' => $message,
                        'access_token' => $this->accessToken,
                    ],
                ]);
                $data = $response->toArray();
            } catch (\Throwable $e) {
                throw new \RuntimeException("❌  Failed to create thread (creation phase): " . $e->getMessage(), 0, $e);
            }
        } else {
            try {
                $response = $this->client->request('POST', $createUrl, [
                    'query' => [
                        'media_type' => 'text',
                        'text' => $message,
                        'access_token' => $this->accessToken,
                    ],
                ]);
                $data = $response->toArray();
            } catch (\Throwable $e) {
                throw new \RuntimeException("❌  Failed to create thread (creation phase): " . $e->getMessage(), 0, $e);
            }
        }

        if (empty($data['id'])) {
            throw new \RuntimeException("❌  Thread creation response did not contain an ID.");
        }

        $creationId = $data['id'];
        $publishUrl = "{$this->apiBaseUrl}/{$this->threadsUserId}/threads_publish";

        try {
            $response = $this->client->request('POST', $publishUrl, [
                'query' => [
                    'creation_id' => $creationId,
                    'access_token' => $this->accessToken,
                ],
            ]);
            $publishData = $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException("❌  Failed to publish thread: " . $e->getMessage(), 0, $e);
        }

        if (empty($publishData['id'])) {
            throw new \RuntimeException("❌  Thread publish response did not contain an ID.");
        }

        return $publishData['id'];
    }

    public function postReply(string $message, string $replyId): string
    {
        $createUrl = "{$this->apiBaseUrl}/{$this->threadsUserId}/threads";

        try {
            $response = $this->client->request('POST', $createUrl, [
                'query' => [
                    'media_type' => 'text',
                    'text' => $message,
                    'reply_to_id' => $replyId,
                    'access_token' => $this->accessToken,
                ],
            ]);
            $data = $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException("❌  Failed to create thread (creation phase): " . $e->getMessage(), 0, $e);
        }

        if (empty($data['id'])) {
            throw new \RuntimeException("❌  Thread creation response did not contain an ID.");
        }

        $creationId = $data['id'];
        $publishUrl = "{$this->apiBaseUrl}/{$this->threadsUserId}/threads_publish";

        try {
            $response = $this->client->request('POST', $publishUrl, [
                'query' => [
                    'creation_id' => $creationId,
                    'access_token' => $this->accessToken,
                ],
            ]);
            $publishData = $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException("❌  Failed to publish thread: " . $e->getMessage(), 0, $e);
        }

        if (empty($publishData['id'])) {
            throw new \RuntimeException("❌  Thread publish response did not contain an ID.");
        }

        return $publishData['id'];
    }


    /**
     * Retrieves details of a specific thread by its ID.
     *
     * @param string $threadId
     * @return array Thread details.
     * @throws \RuntimeException if the API call fails.
     */
    public function getThreadById(string $threadId): array
    {
        $url = "{$this->apiBaseUrl}/{$threadId}";

        try {
            $response = $this->client->request('GET', $url, [
                'query' => [
                    'fields' => 'id,timestamp,username,text,permalink',
                    'access_token' => $this->accessToken,
                ],
            ]);
            return $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException("⚠️  Failed to retrieve thread with ID {$threadId}: " . $e->getMessage(), 0, $e);
        }
    }

    public function getRepliesById(string $threadId): array
    {
        $url = "{$this->apiBaseUrl}/{$threadId}/replies";

        try {
            $response = $this->client->request('GET', $url, [
                'query' => [
                    'fields' => 'id,timestamp,username,text,permalink,replied_to,is_reply_owned_by_me',
                    'access_token' => $this->accessToken,
                ],
            ]);
            return $response->toArray();
        } catch (\Throwable $e) {
            throw new \RuntimeException("⚠️  Failed to retrieve thread replies with ID {$threadId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Deletes a thread by its ID.
     *
     * @param string $threadId
     * @return bool True if deletion was successful.
     * @throws \RuntimeException if the API call fails.
     */
    public function deleteThread(string $threadId): bool
    {
        $url = "{$this->apiBaseUrl}/{$threadId}";

        try {
            $response = $this->client->request('DELETE', $url, [
                'query' => [
                    'access_token' => $this->accessToken,
                ],
            ]);
            $data = $response->toArray();
            if (isset($data['success']) && $data['success'] === true) {
                return true;
            }
            throw new \RuntimeException("❌  API deletion response did not confirm success.");
        } catch (\Throwable $e) {
            throw new \RuntimeException("❌  Failed to delete thread with ID {$threadId}: " . $e->getMessage(), 0, $e);
        }
    }

    /**
     * Checks the API connection status by retrieving basic profile information.
     *
     * @return array Associative array with status and data.
     * @throws \RuntimeException if the API call fails.
     */
    public function checkApiStatus(): array
    {
        $url = "{$this->apiBaseUrl}/{$this->threadsUserId}";

        try {
            $response = $this->client->request('GET', $url, [
                'query' => [
                    'access_token' => $this->accessToken,
                ],
            ]);
            $data = $response->toArray();
            return [
                'status' => '✅ OK',
                'data' => $data,
            ];
        } catch (\Throwable $e) {
            throw new \RuntimeException("❌  Failed to check API status: " . $e->getMessage(), 0, $e);
        }
    }
}
