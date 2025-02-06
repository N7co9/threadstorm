<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost\External;

use App\Service\Extension\AutoPost\Internal\MoodService;
use App\Service\Extension\AutoPost\Internal\TimelineContextManager;

class AuditGenerationService
{
    private MoodService $moodService;
    private MediaService $mediaService;
    private PostGenerationService $postGenerationService;
    private ConsistencyVerifier $consistencyVerifier;
    private int $maxRegenerationAttempts = 3;
    private TimelineContextManager $timelineContextManager;

    public function __construct(
        MoodService            $moodService,
        MediaService           $mediaService,
        PostGenerationService  $postGenerationService,
        ConsistencyVerifier    $consistencyVerifier,
        TimelineContextManager $timelineContextManager,
    )
    {
        $this->moodService = $moodService;
        $this->mediaService = $mediaService;
        $this->postGenerationService = $postGenerationService;
        $this->consistencyVerifier = $consistencyVerifier;
        $this->timelineContextManager = $timelineContextManager;
    }

    /**
     * Audits the AI generation capabilities.
     *
     * For "media" mode, it attempts to fetch an image and generate a media-based post.
     * For "text" mode, it generates a text-only post (with consistency checking).
     *
     * @param string $mode "media" for media-based, any other value (or "text") for text-only.
     * @param string|null $context Optional additional context.
     * @return string The generated audit content.
     */
    public function auditGenerate(string $mode, ?string $context = null): string
    {
        if (strtolower($mode) === 'media') {
            echo "ℹ️🧪 Performing media-based audit generation...\n";
            $media = $this->mediaService->fetchRandomImageAsBase64();
            if ($media === null) {
                return "🚫 Error: Failed to fetch media for media-based audit.";
            }
            try {
                $mediaData = $this->postGenerationService->generateMediaBasedText($media);
                $result = "🆗 Media-based audit generated thread:\n" .
                    "Text: " . $mediaData['text'] . "\n" .
                    "Image URL: " . $mediaData['imageUrl'];
                return $result;
            } catch (\Throwable $e) {
                return "🚫 Error generating media-based text: " . $e->getMessage();
            }
        } else {
            echo "ℹ️🧪 Performing text-only audit generation...\n";
            $moodData = $this->moodService->chooseMood();
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
                $isConsistent = $this->consistencyVerifier->verifyConsistency($generatedText, $extendedContext, $moodData['mood'], null);
                $attempt++;
                if (!$isConsistent) {
                    echo "ℹ️ Regenerating audit text (attempt {$attempt}) due to inconsistency...\n";
                }
            } while (!$isConsistent && $attempt < $this->maxRegenerationAttempts);
            if (!$isConsistent) {
                return "🚫 Error: Could not generate a consistent text-only thread after {$attempt} attempts.";
            }
            return "🆗 Text-only audit generated thread:\n" . $generatedText;
        }
    }
}
