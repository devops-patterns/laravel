import { Head, router, useForm, usePage } from '@inertiajs/react';
import {
    ImagePlus,
    Loader2,
    Server,
    Sparkles,
    Trash2,
    Upload,
} from 'lucide-react';
import { useState } from 'react';
import Heading from '@/components/heading';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardFooter } from '@/components/ui/card';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
    DialogTrigger,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { destroy, generate, index, store } from '@/routes/gallery';
import type { GalleryImage } from '@/types';

type Props = {
    images: GalleryImage[];
    sizes: string[];
};

export default function GalleryIndex({ images, sizes }: Props) {
    const page = usePage();
    const { hostname, currentTeam } = page.props;
    const [open, setOpen] = useState(false);

    const uploadForm = useForm<{ caption: string; image: File | null }>({
        caption: '',
        image: null,
    });

    const generateForm = useForm({
        size: sizes[1] ?? sizes[0] ?? '800x600',
        keyword: 'nature',
    });

    function handleUpload(e: React.FormEvent) {
        e.preventDefault();

        if (!currentTeam) {
            return;
        }

        uploadForm.post(store.url(currentTeam.slug), {
            forceFormData: true,
            onSuccess: () => {
                uploadForm.reset();
                setOpen(false);
            },
        });
    }

    function handleGenerate(e: React.FormEvent) {
        e.preventDefault();

        if (!currentTeam) {
            return;
        }

        generateForm.post(generate.url(currentTeam.slug), {
            preserveScroll: true,
        });
    }

    function handleDelete(imageId: number) {
        if (!currentTeam) {
            return;
        }

        router.delete(
            destroy.url({ current_team: currentTeam.slug, image: imageId }),
            {
                preserveScroll: true,
            },
        );
    }

    // `generate` is a session error from withErrors(), not a form field, so it
    // isn't in the data-keyed errors type.
    const generateError = (
        generateForm.errors as Record<string, string | undefined>
    ).generate;

    return (
        <>
            <Head title="Gallery" />

            <h1 className="sr-only">Gallery</h1>

            <div className="flex flex-col space-y-6 p-4 lg:p-6">
                <div className="flex flex-wrap items-center justify-between gap-3">
                    <Heading
                        variant="small"
                        title="Gallery"
                        description="Upload or generate images stored on the local disk — used to test file backup & restore"
                    />

                    <div className="flex items-center gap-3">
                        <Badge
                            variant="outline"
                            className="gap-1.5 font-mono text-xs"
                        >
                            <Server className="size-3" />
                            {hostname}
                        </Badge>

                        <Dialog open={open} onOpenChange={setOpen}>
                            <DialogTrigger asChild>
                                <Button>
                                    <ImagePlus /> Upload
                                </Button>
                            </DialogTrigger>
                            <DialogContent>
                                <DialogHeader>
                                    <DialogTitle>Upload an image</DialogTitle>
                                    <DialogDescription>
                                        Stored on the public disk
                                        (storage/app/public) — the path included
                                        in backups.
                                    </DialogDescription>
                                </DialogHeader>
                                <form
                                    onSubmit={handleUpload}
                                    className="space-y-4"
                                >
                                    <div className="space-y-2">
                                        <Label htmlFor="image">Image</Label>
                                        <Input
                                            id="image"
                                            type="file"
                                            accept="image/*"
                                            onChange={(e) =>
                                                uploadForm.setData(
                                                    'image',
                                                    e.target.files?.[0] ?? null,
                                                )
                                            }
                                            required
                                        />
                                        {uploadForm.errors.image && (
                                            <p className="text-sm text-destructive">
                                                {uploadForm.errors.image}
                                            </p>
                                        )}
                                    </div>
                                    <div className="space-y-2">
                                        <Label htmlFor="caption">
                                            Caption (optional)
                                        </Label>
                                        <Input
                                            id="caption"
                                            value={uploadForm.data.caption}
                                            onChange={(e) =>
                                                uploadForm.setData(
                                                    'caption',
                                                    e.target.value,
                                                )
                                            }
                                            placeholder="A short description…"
                                        />
                                        {uploadForm.errors.caption && (
                                            <p className="text-sm text-destructive">
                                                {uploadForm.errors.caption}
                                            </p>
                                        )}
                                    </div>
                                    <DialogFooter>
                                        <Button
                                            type="submit"
                                            disabled={uploadForm.processing}
                                        >
                                            {uploadForm.processing ? (
                                                <Loader2 className="animate-spin" />
                                            ) : (
                                                <Upload />
                                            )}
                                            Upload
                                        </Button>
                                    </DialogFooter>
                                </form>
                            </DialogContent>
                        </Dialog>
                    </div>
                </div>

                <form
                    onSubmit={handleGenerate}
                    className="flex flex-wrap items-end gap-3 rounded-lg border p-4"
                >
                    <div className="space-y-2">
                        <Label htmlFor="keyword">Keyword</Label>
                        <Input
                            id="keyword"
                            value={generateForm.data.keyword}
                            onChange={(e) =>
                                generateForm.setData('keyword', e.target.value)
                            }
                            placeholder="nature"
                            className="h-9 w-44"
                        />
                    </div>
                    <div className="space-y-2">
                        <Label>Size</Label>
                        {/* Radix Select renders a hidden <select> next to the
                            trigger; wrapping isolates it so space-y-2 doesn't add
                            a bottom margin to the trigger (misaligns the row). */}
                        <div>
                            <Select
                                value={generateForm.data.size}
                                onValueChange={(value) =>
                                    generateForm.setData('size', value)
                                }
                            >
                                <SelectTrigger className="h-9 w-44">
                                    <SelectValue />
                                </SelectTrigger>
                                <SelectContent>
                                    {sizes.map((size) => (
                                        <SelectItem key={size} value={size}>
                                            {size}
                                        </SelectItem>
                                    ))}
                                </SelectContent>
                            </Select>
                        </div>
                    </div>
                    <Button
                        type="submit"
                        variant="outline"
                        disabled={generateForm.processing}
                    >
                        {generateForm.processing ? (
                            <Loader2 className="animate-spin" />
                        ) : (
                            <Sparkles />
                        )}
                        Generate
                    </Button>
                    {generateError && (
                        <p className="w-full text-sm text-destructive">
                            {generateError}
                        </p>
                    )}
                </form>

                {images.length === 0 ? (
                    <div className="rounded-lg border px-4 py-16 text-center text-muted-foreground">
                        No images yet. Upload one or hit Generate to pull a
                        random photo.
                    </div>
                ) : (
                    <div className="grid grid-cols-2 gap-4 sm:grid-cols-3 lg:grid-cols-4">
                        {images.map((image) => (
                            <Card
                                key={image.id}
                                className="overflow-hidden pt-0"
                            >
                                <div className="aspect-[4/3] w-full overflow-hidden bg-muted">
                                    <img
                                        src={image.url}
                                        alt={image.caption ?? 'Gallery image'}
                                        loading="lazy"
                                        className="h-full w-full object-cover"
                                    />
                                </div>
                                <CardContent className="px-4">
                                    <div
                                        className="truncate text-sm font-medium"
                                        title={image.caption ?? undefined}
                                    >
                                        {image.caption ?? 'Untitled'}
                                    </div>
                                    <Badge
                                        variant={
                                            image.source === 'generated'
                                                ? 'default'
                                                : 'secondary'
                                        }
                                        className="mt-1"
                                    >
                                        {image.source}
                                    </Badge>
                                </CardContent>
                                <CardFooter className="justify-between px-4 text-xs text-muted-foreground">
                                    {new Date(
                                        image.createdAt,
                                    ).toLocaleDateString()}
                                    <Button
                                        variant="ghost"
                                        size="sm"
                                        onClick={() => handleDelete(image.id)}
                                    >
                                        <Trash2 className="size-4" />
                                    </Button>
                                </CardFooter>
                            </Card>
                        ))}
                    </div>
                )}
            </div>
        </>
    );
}

GalleryIndex.layout = (props: { currentTeam?: { slug: string } | null }) => ({
    breadcrumbs: [
        {
            title: 'Gallery',
            href: props.currentTeam ? index.url(props.currentTeam.slug) : '/',
        },
    ],
});
