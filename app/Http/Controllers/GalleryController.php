<?php

namespace App\Http\Controllers;

use App\Models\GalleryImage;
use App\Models\Team;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Inertia;
use Inertia\Response;

class GalleryController extends Controller
{
    /**
     * Fixed size presets — we never build the upstream URL from raw request
     * values, only from this allow-list.
     *
     * @var array<string, array{int, int}>
     */
    private const SIZES = [
        '640x480' => [640, 480],
        '800x600' => [800, 600],
        '1200x800' => [1200, 800],
    ];

    public function index(Request $request, Team $current_team): Response
    {
        $images = $current_team->galleryImages()
            ->orderByDesc('created_at')
            ->get()
            ->map(fn (GalleryImage $image) => [
                'id' => $image->id,
                'caption' => $image->caption,
                'url' => $image->url,
                'source' => $image->source,
                'createdAt' => $image->created_at->toISOString(),
            ]);

        return Inertia::render('gallery/index', [
            'images' => $images,
            'sizes' => array_keys(self::SIZES),
        ]);
    }

    public function store(Request $request, Team $current_team): RedirectResponse
    {
        $validated = $request->validate([
            'image' => ['required', 'image', 'max:8192'],
            'caption' => ['nullable', 'string', 'max:255'],
        ]);

        $path = $request->file('image')->store('gallery', 'public');

        $current_team->galleryImages()->create([
            'caption' => $validated['caption'] ?? null,
            'path' => $path,
            'source' => 'upload',
        ]);

        return back();
    }

    /**
     * Fetch a random image of the requested size from loremflickr and store it
     * on the public disk — a quick way to populate the gallery for backup tests.
     */
    public function generate(Request $request, Team $current_team): RedirectResponse
    {
        $validated = $request->validate([
            'size' => ['nullable', 'string', 'in:'.implode(',', array_keys(self::SIZES))],
            'keyword' => ['nullable', 'alpha_dash', 'max:30'],
        ]);

        [$width, $height] = self::SIZES[$validated['size'] ?? '800x600'];
        $keyword = $validated['keyword'] ?? 'nature';

        try {
            $response = Http::timeout(20)
                ->retry(2, 500)
                ->get("https://loremflickr.com/{$width}/{$height}/{$keyword}");
        } catch (\Throwable $e) {
            return back()->withErrors(['generate' => 'Не удалось скачать картинку: '.$e->getMessage()]);
        }

        if (! $response->successful() || $response->body() === '') {
            return back()->withErrors(['generate' => 'Источник вернул пустой ответ ('.$response->status().').']);
        }

        $path = 'gallery/'.Str::uuid()->toString().'.jpg';
        Storage::disk('public')->put($path, $response->body());

        $current_team->galleryImages()->create([
            'caption' => "{$keyword} {$width}×{$height}",
            'path' => $path,
            'source' => 'generated',
            'width' => $width,
            'height' => $height,
        ]);

        return back();
    }

    public function destroy(Request $request, Team $current_team, GalleryImage $image): RedirectResponse
    {
        abort_unless($image->team_id === $current_team->id, 403);

        Storage::disk('public')->delete($image->path);
        $image->delete();

        return back();
    }
}
