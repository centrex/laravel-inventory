<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Illuminate\Console\Command;

class InventoryCommand extends Command
{
    public $signature = 'laravel-inventory';

    public $description = 'My command';

    public function handle(): int
    {
        $this->comment('All done');

        return self::SUCCESS;
    }
}
