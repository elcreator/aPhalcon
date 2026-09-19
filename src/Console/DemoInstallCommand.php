<?php

declare(strict_types=1);

namespace Elcreator\aPhalcon\Console;

use Elcreator\aPhalcon\Demo\DemoSeeder;
use Illuminate\Console\Command;

class DemoInstallCommand extends Command
{
    /** @var string */
    protected $signature = 'aphalcon:demo:install {--force : Skip the confirmation prompt}';

    /** @var string */
    protected $description = 'Install the aPhalcon demo: a Phalcon-backed page, a Phalcon route, and their config';

    public function handle(): int
    {
        if (!$this->option('force') && $this->input->isInteractive()) {
            $this->line('This writes one template, one document and four view files into this site,');
            $this->line('and core/custom/config/aphalcon.php and alattex.php if they do not exist.');

            if (!$this->confirm('Install the aPhalcon demo?', true)) {
                $this->warn('Nothing was installed.');

                return self::SUCCESS;
            }
        }

        foreach ((new DemoSeeder())->install() as $line) {
            $this->line('  ' . $line);
        }

        $this->newLine();
        $this->info('Demo installed.');
        $this->line('CMS page with Phalcon services:   /' . DemoSeeder::DOCUMENT_ALIAS . '.html');
        $this->line('Phalcon route rendering a CMS view: /app/');
        $this->line('Remove it again with: php artisan aphalcon:demo:remove');

        return self::SUCCESS;
    }
}
