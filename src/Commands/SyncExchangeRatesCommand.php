<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Carbon\{Carbon, CarbonPeriod};
use Centrex\Inventory\Enums\Currency;
use Centrex\LaravelOpenExchangeRates\Client;
use Illuminate\Console\Command;

class SyncExchangeRatesCommand extends Command
{
    public $signature = 'inventory:sync-exchange-rates
        {start_date? : Fetch historical rates for this date (YYYY-MM-DD). Omit for latest.}
        {end_date?   : End of date range (YYYY-MM-DD). Requires start_date.}
        {--currencies= : Comma-separated currency codes to sync (default: all common currencies)}
        {--dry-run : Print rates without persisting}';

    public $description = 'Fetch exchange rates from Open Exchange Rates and persist them. Defaults to latest; pass a date or date range for historical backfill.';

    private const RETRY_ATTEMPTS = 3;

    private const RETRY_SLEEP_SECONDS = 2;

    public function handle(Client $client): int
    {
        $symbols = $this->resolveSymbols();
        $dates = $this->resolveDates();
        $dryRun = (bool) $this->option('dry-run');

        $this->line('Currencies: ' . implode(', ', $symbols));

        if ($dates === null) {
            return $this->syncLatest($client, $symbols, $dryRun);
        }

        $failed = 0;

        foreach ($dates as $date) {
            $success = $this->syncHistoricalWithRetry($client, $date, $symbols, $dryRun);

            if (!$success) {
                $failed++;
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} date(s) could not be synced after " . self::RETRY_ATTEMPTS . ' attempts.');

            return self::FAILURE;
        }

        $this->info('All historical rates processed successfully.');

        return self::SUCCESS;
    }

    private function syncLatest(Client $client, array $symbols, bool $dryRun): int
    {
        $this->info('Fetching latest exchange rates…');

        try {
            $count = $client->syncLatest(implode(',', $symbols));
        } catch (\Throwable $e) {
            $this->error('Open Exchange Rates API error: ' . $e->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->line('Dry run — no rates persisted.');

            return self::SUCCESS;
        }

        $this->info("Synced {$count} exchange rate(s) successfully.");

        return self::SUCCESS;
    }

    private function syncHistoricalWithRetry(Client $client, string $date, array $symbols, bool $dryRun): bool
    {
        $attempt = 0;

        while ($attempt < self::RETRY_ATTEMPTS) {
            try {
                $this->info("Fetching rates for {$date}…");

                $response = $client->historical($date, implode(',', $symbols));
                $rates = $response['rates'] ?? [];
                $base = strtoupper($response['base'] ?? 'USD');
                $rateDate = Carbon::parse($response['timestamp'] ?? $date);

                if ($rates === []) {
                    throw new \RuntimeException('No rates returned for ' . $date);
                }

                if ($dryRun) {
                    $this->line("Dry run — {$date}: " . count($rates) . ' rate(s) (not persisted).');

                    return true;
                }

                $count = $client->importRates($rates, $base, $rateDate);
                $this->info("✓ {$date}: {$count} rate(s) saved.");

                return true;
            } catch (\Throwable $e) {
                $attempt++;
                $this->warn("Attempt {$attempt} failed for {$date}: " . $e->getMessage());

                if ($attempt < self::RETRY_ATTEMPTS) {
                    sleep(self::RETRY_SLEEP_SECONDS);
                } else {
                    $this->error("Failed to fetch rates for {$date} after " . self::RETRY_ATTEMPTS . ' attempts.');
                }
            }
        }

        return false;
    }

    /** @return string[]|null  null = fetch latest */
    private function resolveDates(): ?array
    {
        $startArg = $this->argument('start_date');

        if ($startArg === null) {
            return null;
        }

        $this->validateDateFormat((string) $startArg, 'start_date');

        $endArg = $this->argument('end_date');

        if ($endArg === null) {
            return [$startArg];
        }

        $this->validateDateFormat((string) $endArg, 'end_date');

        $period = CarbonPeriod::create($startArg, $endArg);

        return array_map(fn ($d) => $d->toDateString(), iterator_to_array($period));
    }

    private function validateDateFormat(string $value, string $name): void
    {
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            throw new \InvalidArgumentException("Invalid {$name} format. Use YYYY-MM-DD.");
        }
    }

    /** @return string[] */
    private function resolveSymbols(): array
    {
        $option = (string) ($this->option('currencies') ?? '');

        if ($option !== '') {
            return array_map('strtoupper', array_filter(array_map('trim', explode(',', $option))));
        }

        return array_column(Currency::cases(), 'value');
    }
}
