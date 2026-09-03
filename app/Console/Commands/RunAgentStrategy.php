<?php

namespace App\Console\Commands;

use App\Models\AgentStrategy;
use Illuminate\Console\Command;
use Illuminate\Contracts\Console\PromptsForMissingInput;
use Illuminate\Process\Exceptions\ProcessFailedException;
use Illuminate\Support\Facades\Process;
use Symfony\Component\Console\Command\Command as SymfonyCommand;

use function Laravel\Prompts\select;

class RunAgentStrategy extends Command implements PromptsForMissingInput
{
    const COMMAND = 'buddy:agent-strategy-run';

    /**
     * The name and signature of the console command.
     */
    protected $signature = self::COMMAND.' {strategy? : The ID or name of the strategy}'.
        ' {--all : Run all strategies in sequence}'.
        ' {--dry-run : Show the command instead of executing it}';

    /**
     * The console command description.
     */
    protected $description = 'Run Hermes agent using a registered agent strategy';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $strategies = $this->resolveStrategies();

        if ($strategies->isEmpty()) {
            $this->warn('No agent strategy found.');

            return SymfonyCommand::FAILURE;
        }

        foreach ($strategies as $strategy) {
            if (! $this->runStrategy($strategy)) {
                return SymfonyCommand::FAILURE;
            }
        }

        return SymfonyCommand::SUCCESS;
    }

    /**
     * @return \Illuminate\Support\Collection<int, AgentStrategy>
     */
    protected function resolveStrategies(): \Illuminate\Support\Collection
    {
        if ($this->option('all')) {
            return AgentStrategy::with(['store', 'tags'])->get();
        }

        $identifier = $this->argument('strategy');


        if ($identifier === null) {
            $strategies = AgentStrategy::with(['store', 'tags'])->get();

            if ($strategies->isEmpty()) {
                return $strategies;
            }

            $selectedId = select(
                label: 'Which strategy would you like to run?',
                options: $strategies->mapWithKeys(fn (AgentStrategy $strategy) => [
                    $strategy->id => $strategy->name,
                ])->all(),
            );

            return $strategies->where('id', $selectedId);
        }

        $strategy = AgentStrategy::with(['store', 'tags'])
            ->where('id', $identifier)
            ->orWhere('name', $identifier)
            ->first();

        if ($strategy === null) {
            return collect();
        }

        return collect([$strategy]);
    }

    protected function runStrategy(AgentStrategy $strategy): bool
    {
        $this->info("Running strategy: {$strategy->name}");

        $urls = collect($strategy->urls)
            ->pluck('url')
            ->filter()
            ->values();

        if ($urls->isEmpty()) {
            $this->warn("Strategy [{$strategy->name}] has no URLs to visit.");

            return false;
        }

        $allowedHosts = $strategy->allowedHosts();
        $tagNames = $strategy->tags->pluck('name')->implode(',');
        $goal = "Encontre ofertas de {$tagNames} em {$strategy->store->name}";

        $envs = [
            'HERMES_STORE_ID' => (string) $strategy->store_id,
            'HERMES_MIN_DISCOUNT_PERCENTAGE' => (string) $strategy->min_discount_percentage,
            'HERMES_MAX_RAW_CANDIDATES' => (string) $strategy->max_products,
            'HERMES_MAX_SELECTED_CANDIDATES' => (string) $strategy->max_products,
            'HERMES_DEFAULT_TAG' => $tagNames,
            'HERMES_ALLOWED_HOSTS' => implode(',', $allowedHosts),
        ];

        $command = $this->buildDockerCommand($strategy, $goal, $urls);

        if ($this->option('dry-run')) {
            $this->info("Environment:");
            foreach ($envs as $key => $value) {
                $this->line("  {$key}={$value}");
            }
            $this->info("Command: {$command}");

            return true;
        }

        try {
            $result = Process::env($envs)->timeout(1200)->run($command, function (string $type, string $line) {
                $this->output->write($line);
            });

            if (! $result->successful()) {
                $this->error("Strategy [{$strategy->name}] failed with exit code {$result->exitCode()}");

                return false;
            }

            $this->info("Strategy [{$strategy->name}] completed.");

            return true;
        } catch (ProcessFailedException $exception) {
            $this->error("Strategy [{$strategy->name}] failed: {$exception->getMessage()}");

            return false;
        }
    }

    /**
     * @param  \Illuminate\Support\Collection<int, string>  $urls
     */
    protected function buildDockerCommand(AgentStrategy $strategy, string $goal, \Illuminate\Support\Collection $urls): string
    {
        $parts = [
            'docker',
            'compose',
            '--profile',
            'agent',
            'run',
            '--rm',
            'hermes_agent',
            'python',
            'src/agent.py',
            '--marketplace',
            escapeshellarg($strategy->store->name),
            '--goal',
            escapeshellarg($goal),
        ];

        foreach ($urls as $url) {
            $parts[] = '--urls';
            $parts[] = escapeshellarg($url);
        }

        $parts[] = '--max-raw-candidates';
        $parts[] = (string) $strategy->max_products;
        $parts[] = '--max-selected-candidates';
        $parts[] = (string) $strategy->max_products;
        $parts[] = '--min-discount-percentage';
        $parts[] = (string) $strategy->min_discount_percentage;

        return implode(' ', $parts);
    }
}
