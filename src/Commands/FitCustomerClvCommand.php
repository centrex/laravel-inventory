<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Commands;

use Centrex\Inventory\Models\{Customer, SaleOrder};
use Illuminate\Console\Command;
use InvalidArgumentException;

class FitCustomerClvCommand extends Command
{
    protected $signature = 'inventory:fit-clv
        {--horizon=12    : Horizon in months used for the preview table}
        {--dry-run       : Fit parameters but do not persist to database}
        {--min=10        : Minimum number of customers required}';

    protected $description = 'Fit BG/NBD + Gamma-Gamma CLV model from inventory sale order history';

    public function handle(): int
    {
        if (!class_exists(\Centrex\Btyd\Btyd::class)) {
            $this->error('centrex/laravel-btyd is not installed. Run: composer require centrex/laravel-btyd');

            return self::FAILURE;
        }

        $minCustomers = max(1, (int) $this->option('min'));
        $horizon = max(1, (int) $this->option('horizon'));
        $persist = !$this->option('dry-run');

        $this->info('Loading sale order history from inventory…');

        // Chunk through all customers to avoid loading everything into memory at once.
        $summaries = [];

        Customer::query()
            ->select('id')
            ->chunkById(500, function ($customers) use (&$summaries): void {
                $ids = $customers->pluck('id')->all();

                $orders = SaleOrder::query()
                    ->whereIn('customer_id', $ids)
                    ->where('document_type', 'order')
                    ->whereNotNull('ordered_at')
                    ->select(['customer_id', 'ordered_at', 'total_local'])
                    ->orderBy('customer_id')
                    ->orderBy('ordered_at')
                    ->get()
                    ->groupBy('customer_id');

                foreach ($ids as $customerId) {
                    $txs = ($orders[$customerId] ?? collect())->map(fn ($o): array => [
                        'date'   => $o->ordered_at,
                        'amount' => (float) $o->total_local,
                    ])->all();

                    $summary = \Centrex\Btyd\Btyd::transactionsToSummary($txs);

                    if ($summary['T'] > 0) {
                        $summaries[] = $summary;
                    }
                }
            });

        $this->line(sprintf('Built %d customer summaries.', count($summaries)));

        if (count($summaries) < $minCustomers) {
            $this->error(sprintf(
                'Only %d customers have purchase history. Minimum required: %d.',
                count($summaries),
                $minCustomers,
            ));

            return self::FAILURE;
        }

        $btyd = app(\Centrex\Btyd\Btyd::class);

        $this->line('Fitting BG/NBD…');

        try {
            $bgnbd = $btyd->fitBgNbd($summaries, null, $persist);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line('Fitting Gamma-Gamma…');

        try {
            $gg = $btyd->fitGammaGamma($summaries, null, $persist);
        } catch (InvalidArgumentException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->newLine();
        $this->info('Parameters fitted' . ($persist ? ' and saved to database.' : ' (dry-run, not saved).'));
        $this->newLine();

        $this->table(['Model', 'Parameter', 'Value'], [
            ['BG/NBD', 'r (transaction rate shape)',   round($bgnbd['r'], 6)],
            ['BG/NBD', 'alpha (transaction rate scale)', round($bgnbd['alpha'], 6)],
            ['BG/NBD', 'a (churn rate shape)',          round($bgnbd['a'], 6)],
            ['BG/NBD', 'b (churn rate scale)',          round($bgnbd['b'], 6)],
            ['Gamma-Gamma', 'p (spend shape)',          round($gg['p'], 6)],
            ['Gamma-Gamma', 'q (spend rate shape)',     round($gg['q'], 6)],
            ['Gamma-Gamma', 'v (spend rate scale)',     round($gg['v'], 6)],
        ]);

        // Preview top 10 customers by CLV
        $this->newLine();
        $this->line(sprintf('Top customers by predicted CLV (%d-month horizon):', $horizon));

        $previews = Customer::query()
            ->select(['id', 'name', 'code'])
            ->take(200)
            ->get()
            ->map(function (Customer $customer) use ($btyd, $horizon): array {
                $txs = SaleOrder::query()
                    ->where('customer_id', $customer->id)
                    ->where('document_type', 'order')
                    ->whereNotNull('ordered_at')
                    ->orderBy('ordered_at')
                    ->pluck('total_local', 'ordered_at')
                    ->map(fn ($amount, $date): array => ['date' => $date, 'amount' => (float) $amount])
                    ->values()
                    ->all();

                $summary = \Centrex\Btyd\Btyd::transactionsToSummary($txs);

                return [
                    'name'       => $customer->name,
                    'clv'        => $btyd->customerClv($summary, $horizon),
                    'prob_alive' => round($btyd->probabilityAlive($summary) * 100, 1),
                    'exp_tx'     => round($btyd->expectedTransactions($summary, $horizon), 2),
                ];
            })
            ->sortByDesc('clv')
            ->take(10);

        $this->table(
            ['Customer', "CLV ({$horizon}m)", 'P(alive) %', 'Exp. Tx'],
            $previews->map(fn ($r): array => [
                $r['name'],
                number_format($r['clv'], 2),
                $r['prob_alive'] . '%',
                $r['exp_tx'],
            ])->all(),
        );

        return self::SUCCESS;
    }
}
