<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\AiAutoPostService;
use App\Service\ThreadsApiService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:threads', description: 'Interacts with the Threads API via Threadstorm CLI')]
class ThreadsCommand extends Command
{
    private ThreadsApiService $threadsService;
    private AiAutoPostService $aiAutoPostService;

    public function __construct(ThreadsApiService $threadsService, AiAutoPostService $aiAutoPostService)
    {
        parent::__construct();
        $this->threadsService = $threadsService;
        $this->aiAutoPostService = $aiAutoPostService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform: list, post, status, get, delete, auto-post, help')
            ->addArgument('value', InputArgument::OPTIONAL, 'Message for "post", thread ID for "get"/"delete", or range for "auto-post" (e.g. "1-3", "3-5", or "5-10")')
            ->addArgument('context', InputArgument::OPTIONAL, 'Optional context for AI-generated content (only used with auto-post)')
            ->setHelp($this->getDetailedHelpText());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $value = $input->getArgument('value');
        $context = $input->getArgument('context');

        try {
            switch ($action) {
                case 'help':
                    return $this->showHelp($output);
                case 'list':
                    return $this->listThreads($output);
                case 'post':
                    if (!$value) {
                        $output->writeln('<error>❌  A message is required for posting a thread.</error>');
                        return Command::FAILURE;
                    }
                    return $this->postThread($value, $output);
                case 'status':
                    return $this->showStatus($output);
                case 'get':
                    if (!$value) {
                        $output->writeln('<error>❌  A thread ID is required to retrieve a thread.</error>');
                        return Command::FAILURE;
                    }
                    return $this->getThread($value, $output);
                case 'delete':
                    if (!$value) {
                        $output->writeln('<error>❌  A thread ID is required to delete a thread.</error>');
                        return Command::FAILURE;
                    }
                    return $this->deleteThread($value, $output);
                case 'auto-post':
                    if (!$value) {
                        $output->writeln('<error>❌  A range is required for auto-post (allowed: 1-3, 3-5, 5-10).</error>');
                        return Command::FAILURE;
                    }
                    $output->writeln('<info>🚀  Starting auto-post process via Threadstorm. Press Ctrl+C to terminate.</info>');
                    $this->aiAutoPostService->autoPost($value, $context);
                    return Command::SUCCESS;
                default:
                    $output->writeln('<error>❌  Unknown action. Use "help" to see available commands.</error>');
                    return Command::FAILURE;
            }
        } catch (\Throwable $e) {
            $output->writeln("<error>❌  An error occurred: " . $e->getMessage() . "</error>");
            $output->writeln("<error>Stack trace: " . $e->getTraceAsString() . "</error>");
            return Command::FAILURE;
        }
    }

    private function getDetailedHelpText(): string
    {
        $asciiArt = <<<ASCII
         ___________  __    __    _______    _______       __       ________    ________  ___________  ______     _______   ___      ___ 
        ("     _   ")/" |  | "\  /"      \  /"     "|     /""\     |"      "\  /"       )("     _   ")/    " \   /"      \ |"  \    /"  |
         )__/  \\__/(:  (__)  :)|:        |(: ______)    /    \    (.  ___  :)(:   \___/  )__/  \\__/// ____  \ |:        | \   \  //   |
            \\_ /    \/      \/ |_____/   ) \/    |     /' /\  \   |: \   ) || \___  \       \\_ /  /  /    ) :)|_____/   ) /\\  \/.    |
            |.  |    //  __  \\  //      /  // ___)_   //  __'  \  (| (___\ ||  __/  \\      |.  | (: (____/ //  //      / |: \.        |
            \:  |   (:  (  )  :)|:  __   \ (:      "| /   /  \\  \ |:       :) /" \   :)     \:  |  \        /  |:  __   \ |.  \    /:  |
             \__|    \__|  |__/ |__|  \___) \_______)(___/    \___)(________/ (_______/       \__|   \"_____/   |__|  \___)|___|\__/|___|                                                                                                                                                  
       Welcome to Threadstorm CLI!
    ASCII;

        $helpText = <<<HELP
            $asciiArt
            
            Available Commands:
            ────────────────────────────
            📜 list         - List all existing threads along with meta-data (ID, Caption, Timestamp, Permalink).
            ✍️  post         - Create a new thread. Example: `app:threads post "Your message here"`
            🔌 status       - Check API connection status and profile details.
            🔍 get          - Retrieve details of a specific thread. Example: `app:threads get THREAD_ID`
            ❌ delete       - Delete a specific thread. Example: `app:threads delete THREAD_ID`
            🤖 auto-post    - Start the AI-driven auto-post process (24h quota). Example: `app:threads auto-post [range] [optional context]`
                               * Allowed ranges: 1-3, 3-5, 5-10 posts per 24 hours.
            ❓ help         - Show this help message.
            
            ────────────────────────────
            Threadstorm - Powered by Threads API
            
            HELP;
        return $helpText;
    }


    private function showHelp(OutputInterface $output): int
    {
        $output->writeln($this->getDetailedHelpText());
        return Command::SUCCESS;
    }

    private function listThreads(OutputInterface $output): int
    {
        $threads = $this->threadsService->getThreads();
        if (empty($threads)) {
            $output->writeln('<info>No threads found.</info>');
            return Command::SUCCESS;
        }
        $output->writeln('<info>Existing Threads:</info>');
        $tableFormat = "┌──────────────────────────────┬──────────────────────────────────────────────────────────────┐";
        $tableHeader = "│ <comment>ID</comment>                        │ <comment>Caption / Meta Data</comment>                              │";
        $tableSep = "├──────────────────────────────┼──────────────────────────────────────────────────────────────┤";
        $tableFooter = "└──────────────────────────────┴──────────────────────────────────────────────────────────────┘";
        $rows = [];
        foreach ($threads as $thread) {
            $id = $thread['id'] ?? 'N/A';
            $caption = $thread['caption'] ?? 'No caption';
            $meta = "Timestamp: " . ($thread['timestamp'] ?? "N/A") . "\nPermalink: " . ($thread['permalink'] ?? "N/A");
            $rows[] = "│ <info>{$id}</info>" . str_repeat(" ", 30 - strlen($id)) . "│ {$caption}\n{$meta}";
        }
        $output->writeln($tableFormat);
        $output->writeln($tableHeader);
        $output->writeln($tableSep);
        foreach ($rows as $row) {
            $output->writeln($row);
            $output->writeln($tableSep);
        }
        $output->writeln($tableFooter);
        return Command::SUCCESS;
    }

    private function postThread(string $message, OutputInterface $output): int
    {
        $threadId = $this->threadsService->postThread($message);
        $output->writeln("<info>Thread successfully posted. ID: {$threadId}</info>");
        return Command::SUCCESS;
    }

    private function showStatus(OutputInterface $output): int
    {
        $status = $this->threadsService->checkApiStatus();
        $output->writeln("<info>API Connection: {$status['status']}</info>");
        $output->writeln("<info>Profile Details: " . json_encode($status['data'], JSON_PRETTY_PRINT) . "</info>");
        return Command::SUCCESS;
    }

    private function getThread(string $threadId, OutputInterface $output): int
    {
        $thread = $this->threadsService->getThreadById($threadId);
        $output->writeln("<info>Thread Details for ID {$threadId}:</info>");
        foreach ($thread as $key => $value) {
            $output->writeln("• <comment>{$key}:</comment> {$value}");
        }
        return Command::SUCCESS;
    }

    private function deleteThread(string $threadId, OutputInterface $output): int
    {
        $this->threadsService->deleteThread($threadId);
        $output->writeln("<info>Thread with ID {$threadId} was successfully deleted.</info>");
        return Command::SUCCESS;
    }
}
