<?php

namespace Waterhole\Import\Console\Concerns;

use Exception;
use Illuminate\Database\ConnectionInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Symfony\Component\Console\Input\InputOption;
use Waterhole\Waterhole;

trait ImportsFromDatabase
{
    protected function getOptions(): array
    {
        return [
            new InputOption(
                'connection',
                'c',
                InputOption::VALUE_OPTIONAL,
                'The database connection to import from',
                null,
                array_keys(config('database.connections')),
            ),
        ];
    }

    public function handle(): void
    {
        if (Waterhole::hasPendingMigrations()) {
            $this->error(
                'There are pending Waterhole migrations. Please run "php artisan migrate" before importing.',
            );
            return;
        }

        $connection = $this->getConnection();

        DB::statement('SET foreign_key_checks = 0');

        $this->import($connection);

        $this->info('Done.');
    }

    protected function getConnection(): ConnectionInterface
    {
        if (!($connectionName = $this->option('connection'))) {
            $connectionName = $this->choice(
                'Choose the database connection to import from',
                array_keys(config('database.connections')),
            );
        }

        return DB::connection($connectionName);
    }

    abstract private function import(ConnectionInterface $connection): void;

    protected function importFromDatabase(string $type, Builder $query, callable $callback): void
    {
        $count = $query->count();

        $this->info(sprintf('Importing %d %s...', $count, $type));

        $bar = $this->output->createProgressBar($count);
        $bar->start();

        $query->chunk(1000, function ($rows) use ($type, $bar, $callback) {
            foreach ($rows as $row) {
                try {
                    $callback($row);
                } catch (Exception $e) {
                    $prefix = !empty($row->id) ? "Error importing $type #$row->id: " : '';
                    $this->newLine();
                    $this->warn($prefix . $e->getMessage());
                }

                $bar->advance();
            }
        });

        $bar->finish();
        $this->newLine(2);
    }
}
