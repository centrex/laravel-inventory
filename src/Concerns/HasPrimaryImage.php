<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

trait HasPrimaryImage
{
    use InteractsWithMedia;

    public function registerMediaConversions(?Media $media = null): void
    {
        $collection = $this->primaryImageCollectionName();

        $this
            ->addMediaConversion($this->primaryImageThumbConversionName())
            ->fit(Fit::Crop, 160, 160)
            ->format('webp')
            ->quality(82)
            ->performOnCollections($collection)
            ->nonQueued();

        $this
            ->addMediaConversion($this->primaryImageRegularConversionName())
            ->width(1200)
            ->format('webp')
            ->quality(86)
            ->withResponsiveImages()
            ->performOnCollections($collection)
            ->nonQueued();
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection($this->primaryImageCollectionName())->singleFile();
    }

    public function primaryImageCollectionName(): string
    {
        return 'images';
    }

    public function primaryImageThumbConversionName(): string
    {
        return 'thumb';
    }

    public function primaryImageRegularConversionName(): string
    {
        return 'regular';
    }

    public function getPrimaryImageUrlAttribute(): string
    {
        return $this->getPrimaryImageRegularUrlAttribute();
    }

    public function getPrimaryImageThumbUrlAttribute(): string
    {
        return $this->primaryImageConversionUrl($this->primaryImageThumbConversionName());
    }

    public function getPrimaryImageRegularUrlAttribute(): string
    {
        return $this->primaryImageConversionUrl($this->primaryImageRegularConversionName());
    }

    public function getPrimaryImageSrcsetAttribute(): string
    {
        return $this->primaryImageSrcset($this->primaryImageRegularConversionName());
    }

    public function getPrimaryImageThumbSrcsetAttribute(): string
    {
        return $this->primaryImageSrcset($this->primaryImageThumbConversionName());
    }

    public function getPrimaryImageRegularSrcsetAttribute(): string
    {
        return $this->primaryImageSrcset($this->primaryImageRegularConversionName());
    }

    private function primaryImageSrcset(string $conversionName): string
    {
        $media = $this->getFirstMedia($this->primaryImageCollectionName());

        if (!$media || !$media->hasGeneratedConversion($conversionName)) {
            return '';
        }

        return (string) $media->getSrcset($conversionName);
    }

    private function primaryImageConversionUrl(string $conversionName): string
    {
        return (string) $this->getFirstMediaUrl($this->primaryImageCollectionName(), $conversionName);
    }
}
