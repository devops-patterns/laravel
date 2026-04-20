<?php

namespace App\Jobs;

use App\Models\Task;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SimulateWork implements ShouldQueue
{
    use Queueable;

    public int $timeout = 120;

    public int $tries = 1;

    public function __construct(
        public Task $task,
    ) {}

    public function handle(): void
    {
        $this->task->update([
            'status' => 'processing',
            'progress' => 0,
            'worker_hostname' => gethostname(),
            'started_at' => now(),
        ]);

        $steps = 10;
        $totalSeconds = random_int(30, 60);
        $sleepPerStep = (int) round($totalSeconds / $steps);

        for ($i = 1; $i <= $steps; $i++) {
            sleep($sleepPerStep);

            $fresh = $this->task->fresh();
            if (! $fresh) {
                return;
            }

            $fresh->update([
                'progress' => (int) round(($i / $steps) * 100),
            ]);
        }

        $this->task->fresh()?->update([
            'status' => 'completed',
            'progress' => 100,
            'completed_at' => now(),
        ]);
    }

    public function failed(?\Throwable $exception): void
    {
        $this->task->update(['status' => 'failed']);
    }
}
