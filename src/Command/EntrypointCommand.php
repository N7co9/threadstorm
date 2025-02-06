<?php
declare(strict_types=1);

namespace App\Command;

use App\Common\DTO\MoodConfiguration;
use App\Service\BaseService;
use App\Service\Extension\AutoPost\AutoPost;
use App\Service\Extension\AutoPost\External\AuditGenerationService;
use App\Service\Extension\AutoPost\Internal\ConfigurationService;
use App\Service\Extension\AutoReply\AutoReply;
use App\Service\Extension\AutoReply\External\AuditReplyGenerationService;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Question\ChoiceQuestion;
use Symfony\Component\Console\Question\Question;

#[AsCommand(name: 'app:threads', description: 'Interacts with the Threads API via Threadstorm CLI')]
class EntrypointCommand extends Command
{
    private BaseService $threadsService;
    private AutoPost $autoPostService;
    private AutoReply $autoReplyService;
    private ConfigurationService $configurationService;
    private AuditGenerationService $auditGenerationService;
    private AuditReplyGenerationService $auditReplyGenerationService;

    public function __construct(
        BaseService                 $threadsService,
        AutoPost                    $autoPostService,
        AutoReply                   $autoReplyService,
        ConfigurationService        $configurationService,
        AuditGenerationService      $auditGenerationService,
        AuditReplyGenerationService $auditReplyGenerationService
    )
    {
        parent::__construct();
        $this->threadsService = $threadsService;
        $this->autoPostService = $autoPostService;
        $this->autoReplyService = $autoReplyService;
        $this->configurationService = $configurationService;
        $this->auditGenerationService = $auditGenerationService;
        $this->auditReplyGenerationService = $auditReplyGenerationService;
    }

    protected function configure(): void
    {
        $this
            ->addArgument(
                'action',
                InputArgument::REQUIRED,
                'Action to perform: list, post, status, get, delete, auto-post, auto-reply, config, audit, help'
            )
            ->addArgument(
                'value',
                InputArgument::OPTIONAL,
                'For "config": the configuration parameter name (e.g. subreddits, moods); for "audit": optional thread id or mode.'
            )
            ->addArgument(
                'context',
                InputArgument::OPTIONAL,
                'For "config": the operation to perform (get, add, remove); for other actions, optional context.'
            )
            ->addArgument(
                'extra',
                InputArgument::OPTIONAL,
                'For "config": the value to add or remove.'
            )
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
                    $output->writeln('<info>🚀  Starting persistent auto-post process via Threadstorm. Press Ctrl+C to terminate.</info>');
                    $this->autoPostService->autoPost($value, $context);
                case 'auto-reply':
                    $output->writeln('<info>🚀  Starting persistent auto-reply process. Press Ctrl+C to terminate.</info>');
                    $this->autoReplyService->autoReply($context);
                    break;
                case 'audit':
                    $helper = $this->getHelper('question');
                    $auditChoices = ['auto-reply', 'auto-post'];
                    $auditQuestion = new ChoiceQuestion(
                        'Which service would you like to audit? (Select one)',
                        $auditChoices,
                        0
                    );
                    $auditQuestion->setErrorMessage('Selection %s is invalid.');
                    $serviceChoice = $helper->ask($input, $output, $auditQuestion);

                    if ($serviceChoice === 'auto-reply') {
                        $report = $this->auditReplyGenerationService->auditReply($value, $context);
                        $output->writeln($report);
                    } elseif ($serviceChoice === 'auto-post') {
                        $mode = $value ? strtolower($value) : 'text';
                        $result = $this->auditGenerationService->auditGenerate($mode, $context);
                        $output->writeln($result);
                    } else {
                        $output->writeln('<error>❌  Invalid service selection.</error>');
                        return Command::FAILURE;
                    }
                    break;
                case 'config':
                    return $this->handleConfigAction($input, $output);
                default:
                    $output->writeln('<error>❌  Unknown action. Use "help" to see available commands.</error>');
                    return Command::FAILURE;
            }
            return Command::SUCCESS;
        } catch (\Throwable $e) {
            $output->writeln("<error>❌  An error occurred: " . $e->getMessage() . "</error>");
            $output->writeln("<error>Stack trace: " . $e->getTraceAsString() . "</error>");
            return Command::FAILURE;
        }
    }

    private function handleConfigAction(InputInterface $input, OutputInterface $output): int
    {
        $helper = $this->getHelper('question');
        $configOptions = $this->configurationService->getConfigurationOptions();

        $parameter = $input->getArgument('value');
        if (empty($parameter)) {
            $choices = [];
            foreach ($configOptions as $key => $option) {
                $choices[$key] = "{$option['label']} (key: {$key})";
            }
            $questionParam = new ChoiceQuestion(
                'Select configuration parameter:',
                $choices,
                array_key_first($choices)
            );
            $questionParam->setErrorMessage('Selection %s is invalid.');
            $selected = $helper->ask($input, $output, $questionParam);
            preg_match('/\(key: ([^)]+)\)/', $selected, $matches);
            $parameter = $matches[1] ?? null;
            if ($parameter === null) {
                $output->writeln('<error>Could not determine configuration parameter.</error>');
                return Command::FAILURE;
            }
        }

        $operation = $input->getArgument('context');
        if (empty($operation)) {
            $operationChoices = ['get', 'add', 'remove'];
            $questionOp = new ChoiceQuestion(
                'Select operation:',
                $operationChoices,
                0
            );
            $questionOp->setErrorMessage('Selection %s is invalid.');
            $operation = strtolower($helper->ask($input, $output, $questionOp));
        } else {
            $operation = strtolower($operation);
        }

        $extra = $input->getArgument('extra');

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
                    foreach ($moods as $mood) {
                        $output->writeln("Name: {$mood->getName()}");
                        $output->writeln("Modifier: {$mood->getModifier()}");
                        $output->writeln("Temperature: {$mood->getTemperature()}");
                        $output->writeln("Chance: {$mood->getChance()}%");
                        $output->writeln('----------------------');
                    }
                    return Command::SUCCESS;
                }
                if ($operation === 'add') {
                    if (!empty($extra)) {
                        $defaultConfig = new MoodConfiguration(
                            $extra,
                            'Default mood modifier.',
                            0.5,
                            10
                        );
                        $this->configurationService->addMood($defaultConfig);
                        $output->writeln("<info>Mood '{$extra}' added with default configuration.</info>");
                        return Command::SUCCESS;
                    }
                    $questionName = new Question('Enter the name for the new mood: ');
                    $name = $helper->ask($input, $output, $questionName);
                    if (!$name) {
                        $output->writeln('<error>Mood name cannot be empty.</error>');
                        return Command::FAILURE;
                    }
                    $questionTemp = new Question('Enter the temperature for this mood (float): ');
                    $temperatureInput = $helper->ask($input, $output, $questionTemp);
                    if (!is_numeric($temperatureInput)) {
                        $output->writeln('<error>Invalid temperature value.</error>');
                        return Command::FAILURE;
                    }
                    $temperature = (float)$temperatureInput;
                    $questionModifier = new Question('Enter the modifier text for this mood: ');
                    $modifier = $helper->ask($input, $output, $questionModifier);
                    if (!$modifier) {
                        $output->writeln('<error>Modifier cannot be empty.</error>');
                        return Command::FAILURE;
                    }
                    $questionChance = new Question('Enter the chance of occurrence for this mood (integer percentage): ');
                    $chanceInput = $helper->ask($input, $output, $questionChance);
                    if (!is_numeric($chanceInput)) {
                        $output->writeln('<error>Invalid chance value.</error>');
                        return Command::FAILURE;
                    }
                    $chance = (int)$chanceInput;
                    $moodConfig = new MoodConfiguration($name, $modifier, $temperature, $chance);
                    $this->configurationService->addMood($moodConfig);
                    $output->writeln("<info>Mood '{$name}' added successfully.</info>");
                    return Command::SUCCESS;
                }
                if ($operation === 'remove') {
                    if (empty($extra)) {
                        $output->writeln('<error>❌  A mood name is required to remove.</error>');
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
            📜 list         - List all existing threads.
            📡 post         - Create a new thread. Example: `app:threads post "Your message here"`
            🔌 status       - Check API connection status.
            🔎 get          - Retrieve details of a thread.
            🛑 delete       - Delete a thread.
            🤖 auto-post    - Start the auto-post process.
            🤖 auto-reply   - Start the persistent auto-reply process.
            ⚙️ config       - Configure auto-post parameters.
            ✍️ audit       - Manually trigger an audit reply or audit post.
            🤔 help        - Show this help message.
            
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
