<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost;

class ConfigurationService
{
    private MediaService $mediaService;
    private MoodService $moodService;

    public function __construct(MediaService $mediaService, MoodService $moodService)
    {
        $this->mediaService = $mediaService;
        $this->moodService = $moodService;
    }

    /**
     * Retrieves the current configuration for the auto-post feature.
     *
     * @return array The configuration parameters.
     */
    public function getConfiguration(): array
    {
        return [
            'subreddits' => $this->mediaService->getSubreddits(),
            'moods' => $this->moodService->getMoods(),
        ];
    }

    /**
     * Updates the configuration for the auto-post feature.
     *
     * @param array $config An associative array of configuration parameters.
     *                      Example: ['subreddits' => [...], 'moods' => [...]]
     */
    public function updateConfiguration(array $config): void
    {
        if (isset($config['subreddits']) && is_array($config['subreddits'])) {
            $this->mediaService->setSubreddits($config['subreddits']);
        }
        if (isset($config['moods']) && is_array($config['moods'])) {
            $this->moodService->setMoods($config['moods']);
        }
    }

    /**
     * Adds a subreddit to the current configuration.
     *
     * @param string $subreddit The subreddit to add.
     */
    public function addSubreddit(string $subreddit): void
    {
        $subreddits = $this->mediaService->getSubreddits();
        if (!in_array($subreddit, $subreddits, true)) {
            $subreddits[] = $subreddit;
            $this->mediaService->setSubreddits($subreddits);
        }
    }

    /**
     * Removes a subreddit from the current configuration.
     *
     * @param string $subreddit The subreddit to remove.
     */
    public function removeSubreddit(string $subreddit): void
    {
        $subreddits = $this->mediaService->getSubreddits();
        $key = array_search($subreddit, $subreddits, true);
        if ($key !== false) {
            unset($subreddits[$key]);
            $this->mediaService->setSubreddits(array_values($subreddits));
        }
    }

    /**
     * Adds a mood configuration.
     *
     * @param string $mood The mood key to add.
     * @param array{modifier: string, temperature: float} $configuration The configuration for the mood.
     */
    public function addMood(string $mood, array $configuration): void
    {
        $this->moodService->addMood($mood, $configuration);
    }

    /**
     * Removes a mood configuration.
     *
     * @param string $mood The mood key to remove.
     */
    public function removeMood(string $mood): void
    {
        $this->moodService->removeMood($mood);
    }

    /**
     * Retrieves an ordered list of available configuration options.
     *
     * @return array An associative array of configuration options.
     */
    public function getConfigurationOptions(): array
    {
        return [
            'subreddits' => [
                'label' => 'Subreddits',
                'value' => $this->mediaService->getSubreddits(),
                'description' => 'List of subreddits to fetch images from.'
            ],
            'moods' => [
                'label' => 'Moods',
                'value' => $this->moodService->getMoods(),
                'description' => 'Mood configurations for auto-post feature.'
            ],
        ];
    }
}
