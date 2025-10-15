<?php

namespace App\Console\Commands;

use App\Models\Hold;
use App\Services\HoldService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class ExpireHoldsCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'holds:expire';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Expire holds that have passed their expiration time and return stock';

    /**
     * Execute the console command.
     */
    public function handle(HoldService $holdService): int
    {
        $this->info('Starting hold expiration process...');

        $startTime = microtime(true);

        // Find all active holds that have expired
        $expiredHolds = Hold::where('status', 'active')
            ->where('expires_at', '<', now())
            ->get();

        $totalHolds = $expiredHolds->count();

        if ($totalHolds === 0) {
            $this->info('No expired holds found.');
            return Command::SUCCESS;
        }

        $this->info("Found {$totalHolds} expired hold(s) to process.");

        $successCount = 0;
        $failureCount = 0;

        foreach ($expiredHolds as $hold) {
            try {
                $released = $holdService->releaseExpiredHold($hold);

                if ($released) {
                    $successCount++;
                    $this->line("✓ Released hold #{$hold->id} (Product: {$hold->product_id}, Qty: {$hold->quantity})");
                } else {
                    $failureCount++;
                    $this->warn("✗ Failed to release hold #{$hold->id} (already processed)");
                }
            } catch (\Exception $e) {
                $failureCount++;
                $this->error("✗ Error releasing hold #{$hold->id}: {$e->getMessage()}");

                Log::error('Failed to expire hold', [
                    'hold_id' => $hold->id,
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $duration = round((microtime(true) - $startTime) * 1000, 2);

        $this->newLine();
        $this->info("Hold expiration completed in {$duration}ms");
        $this->info("Success: {$successCount}, Failed: {$failureCount}");

        Log::info('Hold expiration job completed', [
            'total_holds' => $totalHolds,
            'success_count' => $successCount,
            'failure_count' => $failureCount,
            'duration_ms' => $duration,
        ]);

        return Command::SUCCESS;
    }
}
