<?php
declare(strict_types=1);

namespace App\Service\Extension\AutoPost\Internal;

use App\Service\BaseService;
use DateTime;
use Symfony\Component\DependencyInjection\ParameterBag\ParameterBagInterface;
use Throwable;

class TimelineContextManager extends BaseService
{
    private string $timelineMemoryFile;

    public function __construct(ParameterBagInterface $params)
    {
        parent::__construct($params);
        $this->timelineMemoryFile = __DIR__ . '/../../../../config/timeline_memory.txt';
    }

    /**
     * Builds an extended context by combining recent threads and persistent timeline memory.
     *
     * @return string The extended timeline context.
     */
    public function buildExtendedContext(): string
    {
        $recentContext = $this->getRecentThreadsContext();
        $persistentContext = $this->getPersistentTimelineContext();
        return trim($recentContext . " " . $persistentContext);
    }

    /**
     * Retrieves recent threads posted by the user and formats them as context.
     *
     * @param int $limit Number of recent threads to include (default 3).
     * @return string A formatted string of recent thread details.
     */
    public function getRecentThreadsContext(int $limit = 3): string
    {
        try {
            $threadsSummary = [];
            $threads = $this->getThreads();
            if (empty($threads)) {
                return "No previous threads available.";
            }
            foreach ($threads as $thread) {
                try {
                    $threadDetails = $this->getThreadById($thread['id']);
                    if (!empty($threadDetails['timestamp']) && !empty($threadDetails['text'])) {
                        $threadsSummary[] = $threadDetails;
                    }
                } catch (Throwable $e) {
                    continue;
                }
            }
            if (empty($threadsSummary)) {
                return "No valid thread details available.";
            }
            usort($threadsSummary, function ($a, $b) {
                return strtotime($b['timestamp']) - strtotime($a['timestamp']);
            });
            $recentThreads = array_slice($threadsSummary, 0, $limit);
            $summaries = [];
            foreach ($recentThreads as $thread) {
                $text = $thread['text'] ?? ($thread['caption'] ?? "No content");
                $timestamp = $thread['timestamp'] ?? "Unknown time";
                $summaries[] = "ID {$thread['id']} at {$timestamp}: " . $text;
            }
            return implode("; ", $summaries);
        } catch (Throwable $e) {
            return "Failed to retrieve previous threads: " . $e->getMessage();
        }
    }

    /**
     * Retrieves the persistent timeline memory from a local file.
     *
     * @param int $limit Number of lines to return.
     * @return string A concatenated string of timeline entries.
     */
    public function getPersistentTimelineContext(int $limit = 7): string
    {
        if (!file_exists($this->timelineMemoryFile)) {
            return "";
        }
        $lines = file($this->timelineMemoryFile, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        $recentLines = array_slice($lines, -$limit);
        return implode(" ", $recentLines);
    }

    /**
     * Updates the persistent timeline memory file by appending details of the new post.
     *
     * @param string $threadId The ID of the new thread.
     * @param string $threadText The content of the new thread.
     */
    public function updatePersistentTimeline(string $threadId, string $threadText): void
    {
        $timestamp = (new DateTime())->format('Y-m-d H:i:s');
        $entry = "ID {$threadId} at {$timestamp}: {$threadText}";
        file_put_contents($this->timelineMemoryFile, $entry . PHP_EOL, FILE_APPEND);
    }
}
