<?php

use App\Models\GalleryImage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

test('guests cannot access gallery page', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $this->get(route('gallery.index', $team))
        ->assertRedirect(route('login'));
});

test('authenticated users can view their team gallery', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    GalleryImage::factory()->count(3)->create(['team_id' => $team->id]);

    $response = $this->actingAs($user)
        ->get(route('gallery.index', $team));

    $response->assertOk();
    $response->assertInertia(fn ($page) => $page
        ->component('gallery/index')
        ->has('images', 3)
    );
});

test('user can upload an image to the public disk', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->post(route('gallery.store', $team), [
            'image' => UploadedFile::fake()->create('photo.jpg', 200, 'image/jpeg'),
            'caption' => 'A nice shot',
        ]);

    $response->assertRedirect();

    $image = GalleryImage::where('team_id', $team->id)->first();
    expect($image)->not->toBeNull()
        ->and($image->source)->toBe('upload')
        ->and($image->caption)->toBe('A nice shot');
    Storage::disk('public')->assertExists($image->path);
});

test('upload requires an image file', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->post(route('gallery.store', $team), [
            'caption' => 'no file',
        ]);

    $response->assertSessionHasErrors('image');
});

test('user can generate an image fetched from the internet', function () {
    Storage::fake('public');
    Http::fake([
        'loremflickr.com/*' => Http::response('FAKEJPEGBYTES', 200),
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->post(route('gallery.generate', $team), [
            'size' => '640x480',
            'keyword' => 'nature',
        ]);

    $response->assertRedirect();

    $image = GalleryImage::where('team_id', $team->id)->first();
    expect($image)->not->toBeNull()
        ->and($image->source)->toBe('generated')
        ->and($image->width)->toBe(640)
        ->and($image->height)->toBe(480);
    Storage::disk('public')->assertExists($image->path);
});

test('generate reports an error when the source fails', function () {
    Storage::fake('public');
    Http::fake([
        'loremflickr.com/*' => Http::response('', 500),
    ]);

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $response = $this->actingAs($user)
        ->post(route('gallery.generate', $team), [
            'size' => '800x600',
        ]);

    $response->assertSessionHasErrors('generate');
    expect(GalleryImage::count())->toBe(0);
});

test('user can delete an image and its file', function () {
    Storage::fake('public');

    $user = User::factory()->create();
    $team = $user->currentTeam;

    $path = UploadedFile::fake()->create('p.jpg', 100, 'image/jpeg')->store('gallery', 'public');
    $image = GalleryImage::factory()->create(['team_id' => $team->id, 'path' => $path]);

    $response = $this->actingAs($user)
        ->delete(route('gallery.destroy', [$team, $image]));

    $response->assertRedirect();
    $this->assertDatabaseMissing('gallery_images', ['id' => $image->id]);
    Storage::disk('public')->assertMissing($path);
});

test('user cannot delete an image from another team', function () {
    $user = User::factory()->create();
    $team = $user->currentTeam;

    $otherUser = User::factory()->create();
    $otherTeam = $otherUser->currentTeam;

    $image = GalleryImage::factory()->create(['team_id' => $otherTeam->id]);

    $response = $this->actingAs($user)
        ->delete(route('gallery.destroy', [$team, $image]));

    $response->assertForbidden();
    $this->assertDatabaseHas('gallery_images', ['id' => $image->id]);
});
