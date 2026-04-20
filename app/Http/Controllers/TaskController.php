<?php

namespace App\Http\Controllers;

use App\Jobs\SimulateWork;
use App\Models\Task;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class TaskController extends Controller
{
    public function index(Request $request, Team $current_team): Response
    {
        $tasks = $current_team->tasks()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (Task $task) => [
                'id' => $task->id,
                'name' => $task->name,
                'description' => $task->description,
                'status' => $task->status,
                'progress' => $task->progress,
                'workerHostname' => $task->worker_hostname,
                'startedAt' => $task->started_at?->toISOString(),
                'completedAt' => $task->completed_at?->toISOString(),
                'createdAt' => $task->created_at->toISOString(),
            ]);

        return Inertia::render('tasks/index', [
            'tasks' => $tasks,
        ]);
    }

    public function store(Request $request, Team $current_team): RedirectResponse
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);

        $task = $current_team->tasks()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => 'pending',
            'progress' => 0,
        ]);

        SimulateWork::dispatch($task);

        return back();
    }

    public function storeBatch(Request $request, Team $current_team): RedirectResponse
    {
        for ($i = 1; $i <= 10; $i++) {
            $task = $current_team->tasks()->create([
                'name' => "Batch task #{$i}",
                'description' => 'Auto-generated for deploy testing',
                'status' => 'pending',
                'progress' => 0,
            ]);

            SimulateWork::dispatch($task);
        }

        return back();
    }

    public function destroy(Request $request, Team $current_team, Task $task): RedirectResponse
    {
        abort_unless($task->team_id === $current_team->id, 403);

        $task->delete();

        return back();
    }
}
