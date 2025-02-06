<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost\Internal;

use App\Common\DTO\MoodConfiguration;
use App\Service\Extension\AutoPost\External\MediaService;

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
        $subs = $this->mediaService->getSubreddits();
        if (!in_array($subreddit, $subs, true)) {
            $subs[] = $subreddit;
            $this->mediaService->setSubreddits($subs);
        }
    }

    /**
     * Removes a subreddit from the current configuration.
     *
     * @param string $subreddit The subreddit to remove.
     */
    public function removeSubreddit(string $subreddit): void
    {
        $subs = $this->mediaService->getSubreddits();
        $key = array_search($subreddit, $subs, true);
        if ($key !== false) {
            unset($subs[$key]);
            $this->mediaService->setSubreddits(array_values($subs));
        }
    }

    /**
     * Adds a mood configuration.
     *
     * @param MoodConfiguration $moodConfiguration The new mood configuration.
     */
    public function addMood(MoodConfiguration $moodConfiguration): void
    {
        $this->moodService->addMood($moodConfiguration);
    }

    /**
     * Removes a mood configuration.
     *
     * @param string $moodName The name of the mood to remove.
     */
    public function removeMood(string $moodName): void
    {
        $this->moodService->removeMood($moodName);
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
