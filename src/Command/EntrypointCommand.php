<?php
declare(strict_types=1);

namespace App\Command;

use App\Service\BaseService;
use App\Service\Extension\AutoPost\AutoPostService;
use App\Service\Extension\AutoPost\ConfigurationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(name: 'app:threads', description: 'Interacts with the Threads API via Threadstorm CLI')]
class EntrypointCommand extends Command
{
    private BaseService $threadsService;
    private AutoPostService $autoPostService;
    private ConfigurationService $configurationService;

    public function __construct(
        BaseService          $threadsService,
        AutoPostService      $autoPostService,
        ConfigurationService $configurationService
    )
    {
        parent::__construct();
        $this->threadsService = $threadsService;
        $this->autoPostService = $autoPostService;
        $this->configurationService = $configurationService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument('action', InputArgument::REQUIRED, 'Action to perform: list, post, status, get, delete, auto-post, config, audit, help')
            ->addArgument('value', InputArgument::OPTIONAL, 'For "config": the configuration parameter name (e.g. subreddits, moods); for other actions, see documentation.')
            ->addArgument('context', InputArgument::OPTIONAL, 'For "config": the operation to perform (get, add, remove); for other actions, optional context.')
            ->addArgument('extra', InputArgument::OPTIONAL, 'For "config": the value to add or remove.')
            ->setHelp($this->getDetailedHelpText());
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $action = $input->getArgument('action');
        $value = $input->getArgument('value');
        $context = $input->getArgument('context');
        $extra = $input->getArgument('extra');

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
                    $this->autoPostService->autoPost($value, $context);
                    return Command::SUCCESS;
                case 'audit':
                    $mode = $value ? strtolower($value) : 'text';
                    $result = $this->autoPostService->auditGenerate($mode, $context);
                    $output->writeln($result);
                    return Command::SUCCESS;
                case 'config':
                    return $this->handleConfigAction($value, $context, $extra, $output);
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

    /**
     * Handles configuration related actions.
     *
     * Command syntax:
     * - `app:threads config` -> Lists available configuration parameters.
     * - `app:threads config <parameter>` -> Shows the current value of that parameter.
     * - `app:threads config <parameter> get` -> Same as above.
     * - `app:threads config <parameter> add <value>` -> Adds a value.
     * - `app:threads config <parameter> remove <value>` -> Removes a value.
     */
    private function handleConfigAction(?string $parameter, ?string $operation, ?string $extra, OutputInterface $output): int
    {
        // If no parameter is selected, list all available configuration parameters.
        if (empty($parameter)) {
            $configOptions = $this->configurationService->getConfigurationOptions();
            $output->writeln('<info>Available Configuration Parameters:</info>');
            $i = 1;
            foreach ($configOptions as $key => $option) {
                $output->writeln("{$i}. {$option['label']} (key: {$key}) - {$option['description']}");
                $i++;
            }
            return Command::SUCCESS;
        }

        // Normalize operation to lower-case, defaulting to "get" if not provided.
        $operation = $operation ? strtolower($operation) : 'get';

        // Handle operations based on the selected parameter.
        switch ($parameter) {
            case 'subreddits':
                if ($operation === 'get') {
                    $subs = $this->configurationService->getConfiguration()['subreddits'];
                    $output->writeln('<info>Current Subreddits:</info>');
                    $output->writeln(print_r($subs, true));
                    return Command::SUCCESS;
                }
                if ($operation === 'add') {
                    if (empty($extra)) {
                        $output->writeln('<error>❌  A subreddit name is required to add.</error>');
                        return Command::FAILURE;
                    }
                    $this->configurationService->addSubreddit($extra);
                    $output->writeln("<info>Subreddit '{$extra}' added successfully.</info>");
                    return Command::SUCCESS;
                }
                if ($operation === 'remove') {
                    if (empty($extra)) {
                        $output->writeln('<error>❌  A subreddit name is required to remove.</error>');
                        return Command::FAILURE;
                    }
                    $this->configurationService->removeSubreddit($extra);
                    $output->writeln("<info>Subreddit '{$extra}' removed successfully.</info>");
                    return Command::SUCCESS;
                }
                break;
            case 'moods':
                if ($operation === 'get') {
                    $moods = $this->configurationService->getConfiguration()['moods'];
                    $output->writeln('<info>Current Mood Configurations:</info>');
                    $output->writeln(print_r($moods, true));
                    return Command::SUCCESS;
                }
                if ($operation === 'add') {
                    if (empty($extra)) {
                        $output->writeln('<error>❌  A mood key is required to add.</error>');
                        return Command::FAILURE;
                    }
                    // For demonstration, add with a default configuration.
                    $defaultConfig = [
                        'modifier' => 'Default mood modifier.',
                        'temperature' => 0.5,
                    ];
                    $this->configurationService->addMood($extra, $defaultConfig);
                    $output->writeln("<info>Mood '{$extra}' added successfully with default configuration.</info>");
                    return Command::SUCCESS;
                }
                if ($operation === 'remove') {
                    if (empty($extra)) {
                        $output->writeln('<error>❌  A mood key is required to remove.</error>');
                        return Command::FAILURE;
                    }
                    $this->configurationService->removeMood($extra);
                    $output->writeln("<info>Mood '{$extra}' removed successfully.</info>");
                    return Command::SUCCESS;
                }
                break;
            default:
                $output->writeln('<error>❌  Unknown configuration parameter. Allowed parameters: subreddits, moods.</error>');
                return Command::FAILURE;
        }

        $output->writeln('<error>❌  Unknown operation. Allowed operations: get, add, remove.</error>');
        return Command::FAILURE;
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
            📜 list         - List all existing threads along with meta-data.
            📡 post         - Create a new thread. Example: `app:threads post "Your message here"`
            🔌 status       - Check API connection status and profile details.
            🔎 get          - Retrieve details of a specific thread. Example: `app:threads get THREAD_ID`
            🛑 delete       - Delete a specific thread. Example: `app:threads delete THREAD_ID`
            🤖 auto-post    - Start the AI-driven auto-post process. Example: `app:threads auto-post [range] [optional context]`
            ⚙️ config       - Configure auto-post parameters.
                              Examples:
                                • `app:threads config` 
                                    - Lists available configuration parameters.
                                • `app:threads config subreddits get`
                                    - Displays current subreddits.
                                • `app:threads config subreddits add redditName`
                                    - Adds a subreddit.
                                • `app:threads config subreddits remove redditName`
                                    - Removes a subreddit.
                                • `app:threads config moods get`
                                    - Displays current mood configurations.
                                • `app:threads config moods add moodKey`
                                    - Adds a mood with a default configuration.
                                • `app:threads config moods remove moodKey`
                                    - Removes a mood.
            ✍️ audit       - Audit AI generation capabilities.
            🤔 help         - Show this help message.
            
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
