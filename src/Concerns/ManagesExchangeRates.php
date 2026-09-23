<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Centrex\LaravelOpenExchangeRates\Client as OpenExchangeRatesClient;
use Centrex\LaravelOpenExchangeRates\Models\ExchangeRate as OpenExchangeRate;
use Illuminate\Support\Carbon;

trait ManagesExchangeRates
{
    /**
     * Persist (or update) an exchange rate for a currency against the base currency.
     *
     * @param  string  $currency  ISO 4217 code, e.g. 'USD'
     * @param  float  $rate  Units of $currency per 1 base-currency unit
     * @param  string|null  $date  Effective date (defaults to today)
     * @param  string  $source  Origin of the rate ('manual', 'open_exchange_rates', …)
     */
    public function setExchangeRate(string $currency, float $rate, ?string $date = null, string $source = 'manual'): OpenExchangeRate
    {
        $baseCurrency = strtoupper(config('inventory.base_currency', 'BDT'));
        $currency = strtoupper($currency);
        $effectiveDate = $date ?? now()->toDateString();
        $fetchedAt = Carbon::parse($effectiveDate)->endOfDay();

        OpenExchangeRate::upsertRates([
            $currency => $rate,
        ], $baseCurrency, $fetchedAt, $effectiveDate);

        return OpenExchangeRate::query()
            ->where('base', $baseCurrency)
            ->where('date', '=', $effectiveDate)
            ->firstOrFail();
    }

    /**
     * Resolve the exchange rate (units of $currency per 1 base-currency unit) on or before $date.
     *
     * Lookup order (first non-null result wins):
     *   1. Stored rate: base → currency
     *   2. Stored rate (reversed): currency → base (uses the reciprocal)
     *   3. If $currency is the anchor (e.g. USD): anchor → base
     *   4. Cross-rate: (anchor → base) / (anchor → currency)
     *
     * When no stored rate covers the request and `inventory.exchange_rate_live_fetch` is enabled,
     * falls back to a live Open Exchange Rates API call, persists the result to `oer_exchange_rates`
     * for future lookups, and retries the resolution once.
     *
     * Throws \RuntimeException when no rate can be resolved.
     */
    public function getExchangeRate(string $currency, ?string $date = null): float
    {
        return $this->resolveExchangeRate(strtoupper($currency), $date, allowLiveFetch: true);
    }

    private function resolveExchangeRate(string $currency, ?string $date, bool $allowLiveFetch): float
    {
        if ($currency === strtoupper(config('inventory.base_currency', 'BDT'))) {
            return 1.0;
        }

        $date ??= now()->toDateString();
        $baseCurrency = strtoupper(config('inventory.base_currency', 'BDT'));
        $anchorCurrency = strtoupper(config('laravel-open-exchange-rates.default_base_currency', 'USD'));

        try {
            return $this->resolveStoredExchangeRate($currency, $baseCurrency, $anchorCurrency, $date);
        } catch (\RuntimeException $e) {
            // Reaching here means nothing usable is in the database at all (a stored-but-stale
            // rate is used as-is by resolveStoredExchangeRate() below, not treated as missing —
            // see warnIfRateStale()) — a live API call is only worth its latency for a genuinely
            // unknown currency. allowLiveFetch: false on the retry stops recursion.
            if ($allowLiveFetch && $this->fetchLiveExchangeRate($currency, $baseCurrency, $anchorCurrency, $date)) {
                return $this->resolveExchangeRate($currency, $date, allowLiveFetch: false);
            }

            throw $e;
        }
    }

    /** @throws \RuntimeException */
    private function resolveStoredExchangeRate(string $currency, string $baseCurrency, string $anchorCurrency, string $date): float
    {
        $asOf = Carbon::parse($date)->endOfDay();

        // 1. Direct stored rate: base → currency
        $direct = $this->lookupExchangeRate($baseCurrency, $currency, $asOf);

        if ($direct !== null) {
            $this->warnIfRateStale($currency, $asOf, $direct['date']);

            return $direct['rate'];
        }

        // 2. Reversed stored rate: currency → base (same pair, inverted direction)
        $reversed = $this->lookupExchangeRate($currency, $baseCurrency, $asOf);

        if ($reversed !== null) {
            $this->warnIfRateStale($currency, $asOf, $reversed['date']);

            return $reversed['rate'];
        }

        $anchorToBase = $this->lookupExchangeRate($anchorCurrency, $baseCurrency, $asOf);

        // 3. Currency IS the anchor (e.g. asking for USD when anchor is USD)
        if ($currency === $anchorCurrency && $anchorToBase !== null) {
            $this->warnIfRateStale($currency, $asOf, $anchorToBase['date']);

            return $anchorToBase['rate'];
        }

        // 4. Cross-rate via anchor: (anchor→base) / (anchor→currency)
        $anchorToCurrency = $this->lookupExchangeRate($anchorCurrency, $currency, $asOf);

        if ($anchorToBase !== null && $anchorToCurrency !== null && $anchorToCurrency['rate'] != 0.0) {
            // A cross-rate is only as fresh as whichever of its two legs is older.
            $staleAsOf = $anchorToBase['date']->lessThan($anchorToCurrency['date']) ? $anchorToBase['date'] : $anchorToCurrency['date'];
            $this->warnIfRateStale($currency, $asOf, $staleAsOf);

            return round($anchorToBase['rate'] / $anchorToCurrency['rate'], 8);
        }

        throw new \RuntimeException("No exchange rate found for currency [{$currency}] on or before [{$date}].");
    }

    /**
     * Fetch $currency and $baseCurrency rates (relative to $anchorCurrency) from the live Open
     * Exchange Rates API and persist them via {@see OpenExchangeRatesClient::importRates()} so
     * subsequent lookups for the same day resolve from the database. Returns false (without
     * throwing) on any failure — a missing/invalid app_id, network error, or empty response —
     * so the caller falls through to the standard "no rate found" exception.
     */
    private function fetchLiveExchangeRate(string $currency, string $baseCurrency, string $anchorCurrency, string $date): bool
    {
        if (!config('inventory.exchange_rate_live_fetch', true)) {
            return false;
        }

        $symbols = implode(',', array_unique([$currency, $baseCurrency, $anchorCurrency]));

        try {
            $client = app(OpenExchangeRatesClient::class);
            $isLatest = Carbon::parse($date)->isToday();

            $response = $isLatest
                ? $client->latest($symbols)
                : $client->historical($date, $symbols);

            $rates = $response['rates'] ?? [];

            if ($rates === []) {
                return false;
            }

            $responseBase = strtoupper((string) ($response['base'] ?? $anchorCurrency));
            $fetchedAt = $isLatest ? now() : Carbon::parse($response['timestamp'] ?? $date);

            return $client->importRates($rates, $responseBase, $fetchedAt, $isLatest ? null : $date) > 0;
        } catch (\Throwable $e) {
            logger()->warning('Live exchange rate fetch failed: ' . $e->getMessage(), [
                'currency' => $currency,
                'date'     => $date,
            ]);

            return false;
        }
    }

    /**
     * Warn (without blocking) when a stored rate is older than INVENTORY_EXCHANGE_RATE_STALE_DAYS
     * (default 1 day) — e.g. because the daily `inventory:sync-exchange-rates` job stopped
     * running. The stored rate is still used: a stale-but-present rate resolves from the
     * database immediately rather than falling through to a live API call, since blocking a
     * sale/purchase order request on third-party API latency is worse than converting at a
     * rate that's a day or two old — this log line is the only signal an operator needs to
     * notice and fix the sync job. A threshold of 0 disables the check (matches this
     * codebase's existing "0 = off" convention for other tolerances).
     */
    private function warnIfRateStale(string $currency, Carbon $asOf, Carbon $rateDate): void
    {
        $staleDays = (int) config('inventory.exchange_rate_stale_days', 1);

        if ($staleDays <= 0) {
            return;
        }

        $ageInDays = $rateDate->diffInDays($asOf);

        if ($ageInDays > $staleDays) {
            logger()->warning(
                "Exchange rate for [{$currency}] is {$ageInDays} day(s) old (last updated {$rateDate->toDateString()}), exceeding the configured staleness limit of {$staleDays} day(s). Using it anyway rather than blocking on a live API call. Run `php artisan inventory:sync-exchange-rates` to refresh rates, or raise INVENTORY_EXCHANGE_RATE_STALE_DAYS if this is expected.",
                ['currency' => $currency, 'age_in_days' => $ageInDays, 'rate_date' => $rateDate->toDateString()],
            );
        }
    }

    /** Convert an amount in $currency to the base currency (e.g. BDT). */
    public function convertToBase(float $amount, string $currency, ?string $date = null): float
    {
        return round($amount * $this->getExchangeRate($currency, $date), (int) config('inventory.wac_precision', 4));
    }

    /** Alias for {@see convertToBase()} — retained for backwards compatibility. */
    public function convertToBdt(float $amount, string $currency, ?string $date = null): float
    {
        return $this->convertToBase($amount, $currency, $date);
    }

    /** Convert an amount from the base currency to $currency. */
    public function convertFromBase(float $amount, string $currency, ?string $date = null): float
    {
        $rate = $this->getExchangeRate($currency, $date);

        if ($rate == 0.0) {
            return 0.0;
        }

        return round($amount / $rate, (int) config('inventory.wac_precision', 4));
    }

    /** Alias for {@see convertFromBase()} — retained for backwards compatibility. */
    public function convertFromBdt(float $amountBdt, string $currency, ?string $date = null): float
    {
        return $this->convertFromBase($amountBdt, $currency, $date);
    }

    /**
     * Return the most-recent stored rate for (base, currency) on or before $asOf, along with the
     * date it was recorded under (needed by warnIfRateStale()), or null if not found.
     *
     * @return array{rate: float, date: Carbon}|null
     */
    private function lookupExchangeRate(string $base, string $currency, Carbon $asOf): ?array
    {
        $row = OpenExchangeRate::asOf($currency, $base, $asOf->toDateString());
        $rate = $row?->rateFor($currency);

        if ($row === null || $rate === null) {
            return null;
        }

        return ['rate' => $rate, 'date' => Carbon::parse($row->date)];
    }
}
