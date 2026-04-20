<?php

use App\Jobs\SimulateWork;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Queue;

test('guests cannot access tasks page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->get(route('tasks.index', $team))
        ->assertRedirect(route('login'));
});

test('authenticated users can view tasks for their team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    Task::factory()->count(3)->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->get(route('tasks.index', $team));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('tasks/index')
        ->has('tasks', 3)
    );
});

test('tasks page includes hostname in shared props', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->get(route('tasks.index', $team));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->where('hostname', gethostname())
    );
});

test('user can create a task and dispatch a job', function () {
    Queue::fake();

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->post(route('tasks.store', $team), [
            'name' => 'Test Task',
            'description' => 'A test description',
        ]);

    $response->assertRedirect();

    $this->assertDatabaseHas('tasks', [
        'team_id' => $team->id,
        'name' => 'Test Task',
        'description' => 'A test description',
        'status' => 'pending',
        'progress' => 0,
    ]);

    Queue::assertPushed(SimulateWork::class, function ($job) {
        return $job->task->name === 'Test Task';
    });
});

test('task creation requires a name', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->post(route('tasks.store', $team), [
            'name' => '',
        ]);

    $response->assertSessionHasErrors('name');
});

test('user can delete a task', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $task = Task::factory()->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->delete(route('tasks.destroy', [$team, $task]));

    $response->assertRedirect();
    $this->assertDatabaseMissing('tasks', ['id' => $task->id]);
});

test('user cannot delete a task from another team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->currentTeam;

    $task = Task::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->delete(route('tasks.destroy', [$team, $task]));

    $response->assertForbidden();
    $this->assertDatabaseHas('tasks', ['id' => $task->id]);
});
