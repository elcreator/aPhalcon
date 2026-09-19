<?php

declare(strict_types=1);

namespace Elcreator\aPhalcon\Console;

use Elcreator\aPhalcon\Demo\DemoSeeder;
use Illuminate\Console\Command;

class DemoRemoveCommand extends Command
{
    /** @var string */
    protected $signature = 'aphalcon:demo:remove {--force : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Remove everything the aPhalcon demo installed';

    public function handle(): int
    {
        if (!$this->option('force') && $this->input->isInteractive()) {
            $this->line('This permanently deletes the demo document and template, and the view and');
            $this->line('config files the demo wrote - those still identical to what it shipped.');

            if (!$this->confirm('Remove the aPhalcon demo?', true)) {
                $this->warn('Nothing was removed.');

                return self::SUCCESS;
            }
        }

        foreach ((new DemoSeeder())->remove() as $line) {
            $this->line('  ' . $line);
        }

        $this->newLine();
        $this->info('Demo removed.');
        $this->line('Reinstall it with: php artisan aphalcon:demo:install');

        return self::SUCCESS;
    }
}
