export type GalleryImageSource = 'upload' | 'generated';

export type GalleryImage = {
    id: number;
    caption: string | null;
    url: string;
    source: GalleryImageSource;
    createdAt: string;
};
