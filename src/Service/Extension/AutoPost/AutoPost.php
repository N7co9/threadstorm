<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost;

use App\Service\BaseService;
use App\Service\Extension\AutoPost\External\ConsistencyVerifier;
use App\Service\Extension\AutoPost\External\MediaService;
use App\Service\Extension\AutoPost\External\PostGenerationService;
use App\Service\Extension\AutoPost\Internal\MoodService;
use App\Service\Extension\AutoPost\Internal\SchedulingService;
use App\Service\Extension\AutoPost\Internal\TimelineContextManager;
use Random\RandomException;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;

class AutoPost extends BaseService
{
    private SchedulingService $schedulingService;
    private PostGenerationService $postGenerationService;
    private MoodService $moodService;
    private MediaService $mediaService;
    private int $maxRegenerationAttempts = 3;
    private ?string $previousMood = null;
    private ConsistencyVerifier $consistencyVerifier;
    private TimelineContextManager $timelineContextManager;

    public function __construct(
        ParameterBagInterface  $params,
        MoodService            $moodService,
        MediaService           $mediaService,
        SchedulingService      $schedulingService,
        PostGenerationService  $postGenerationService,
        ConsistencyVerifier    $consistencyVerifier,
        TimelineContextManager $timelineContextManager
    )
    {
        parent::__construct($params);
        $this->moodService = $moodService;
        $this->mediaService = $mediaService;
        $this->schedulingService = $schedulingService;
        $this->postGenerationService = $postGenerationService;
        $this->consistencyVerifier = $consistencyVerifier;
        $this->timelineContextManager = $timelineContextManager;
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
            $this->schedulingService->runCycle($min, $max, function () use ($context) {
                if (random_int(1, 100) <= 33 && $this->attemptMediaPost()) {
                    return;
                }

                $moodData = $this->moodService->chooseMood();
                $generatedText = $this->generateTextPost($context, $moodData);
                if ($generatedText === null) {
                    return;
                }

                try {
                    $threadId = $this->postThread($generatedText);
                    echo "🎉 Regular post published, ID: {$threadId} with mood '{$moodData['mood']}'." . PHP_EOL;
                    $this->timelineContextManager->updatePersistentTimeline($threadId, $generatedText);
                    $this->previousMood = $moodData['mood'];
                } catch (\Throwable $e) {
                    echo "🚫 Error posting regular post: " . $e->getMessage() . PHP_EOL;
                }

                $this->attemptFollowUpPost($moodData);
            });
        }
    }

    /**
     * Attempts to generate and post a media-based post.
     *
     * If the generated media-based text does not pass the authenticity check,
     * a new image is fetched and the process is retried, up to 3 attempts.
     *
     * @return bool True if a media-based post was successfully attempted, false otherwise.
     */
    private function attemptMediaPost(): bool
    {
        echo "ℹ️ Attempting media-based post." . PHP_EOL;
        $attempt = 0;
        do {
            $media = $this->mediaService->fetchRandomImageAsBase64();
            if ($media === null) {
                echo "⚠️ Failed to fetch media." . PHP_EOL;
                return false;
            }

            try {
                $mediaData = $this->postGenerationService->generateMediaBasedText($media);
                $isAuthentic = $this->consistencyVerifier->verifyAuthenticity($mediaData['text']);
                if (!$isAuthentic) {
                    echo "⚠️ Authenticity check failed for the generated media-based text." . PHP_EOL;
                    sleep(3);
                    $attempt++;
                    continue;
                }

                $threadId = $this->postThread($mediaData['text'], $mediaData['imageUrl']);
                echo "🎉 Media-based post published, ID: {$threadId}." . PHP_EOL;
                $this->timelineContextManager->updatePersistentTimeline($threadId, $mediaData['text']);
                return true;
            } catch (\Throwable $e) {
                echo "🚫 Error posting media-based post: " . $e->getMessage() . PHP_EOL;
                return false;
            }
        } while ($attempt < 3);

        echo "⚠️ Failed to generate an authentic media-based post after {$attempt} attempts." . PHP_EOL;
        return false;
    }


    /**
     * Generates a text-only post. Randomly chooses between a chaotic post and a regular consistent post.
     *
     * @param string|null $context
     * @param array $moodData
     * @return string|null Generated text or null if generation failed.
     * @throws RandomException
     */
    private function generateTextPost(?string $context, array $moodData): ?string
    {
        $chaoticChance = 30;
        if (random_int(1, 100) <= $chaoticChance) {
            echo "ℹ️ Generating chaotic thread post." . PHP_EOL;
            $extendedContext = $this->timelineContextManager->buildExtendedContext();
            return $this->postGenerationService->generateChaoticThreadText(
                $context,
                $extendedContext,
                $moodData['modifier'],
                $moodData['mood'],
                $moodData['temperature']
            );
        }

        return $this->generateConsistentTextPost($context, $moodData);
    }

    /**
     * Generates a consistent text post with consistency verification.
     *
     * @param string|null $context
     * @param array $moodData
     * @return string|null
     */
    private function generateConsistentTextPost(?string $context, array $moodData): ?string
    {
        $attempt = 0;
        do {
            $extendedContext = $this->timelineContextManager->buildExtendedContext();
            $generatedText = $this->postGenerationService->generateThreadText(
                $context,
                $extendedContext,
                $moodData['modifier'],
                $moodData['mood'],
                $moodData['temperature']
            );

            $isConsistent = $this->consistencyVerifier->verifyConsistency(
                $generatedText,
                $extendedContext,
                $moodData['mood'],
                $this->previousMood
            );
            $attempt++;
            if (!$isConsistent) {
                echo "ℹ️ Regenerating post (attempt {$attempt}) due to inconsistency..." . PHP_EOL;
            }
        } while (!$isConsistent && $attempt < $this->maxRegenerationAttempts);

        if (!$isConsistent) {
            echo "⚠️ Could not generate a consistent post after {$attempt} attempts. Skipping this post." . PHP_EOL;
            return null;
        }
        return $generatedText;
    }

    /**
     * Attempts to generate and post a follow-up post.
     *
     * @param array $moodData
     */
    private function attemptFollowUpPost(array $moodData): void
    {
        if (random_int(1, 100) > 5) {
            return;
        }

        echo "ℹ️ Triggered follow-up post." . PHP_EOL;
        $attemptFollow = 0;
        do {
            $extendedContext = $this->timelineContextManager->buildExtendedContext();
            $followUpText = $this->postGenerationService->generateThreadText(
                'Knüpfe stringent an den Inhalt des aktuellsten Posts an!',
                $extendedContext,
                $moodData['modifier'],
                $moodData['mood'],
                $moodData['temperature']
            );
            $isFollowConsistent = $this->consistencyVerifier->verifyConsistency(
                $followUpText,
                $extendedContext,
                $moodData['mood'],
                $this->previousMood
            );
            $attemptFollow++;
            if (!$isFollowConsistent) {
                echo "ℹ️ Regenerating follow-up post (attempt {$attemptFollow}) due to inconsistency..." . PHP_EOL;
            }
        } while (!$isFollowConsistent && $attemptFollow < $this->maxRegenerationAttempts);

        if ($isFollowConsistent) {
            try {
                $followUpThreadId = $this->postThread($followUpText);
                echo "🎉 Follow-up post published, ID: {$followUpThreadId} with mood '{$moodData['mood']}'." . PHP_EOL;
                $this->timelineContextManager->updatePersistentTimeline($followUpThreadId, $followUpText);
            } catch (\Throwable $e) {
                echo "🚫 Error posting follow-up post: " . $e->getMessage() . PHP_EOL;
            }
        } else {
            echo "🚫 Follow-up post could not be generated consistently after {$attemptFollow} attempts. Skipping." . PHP_EOL;
        }
    }
}
