<?php

declare(strict_types = 1);

namespace Centrex\Inventory\Concerns;

use Spatie\MediaLibrary\InteractsWithMedia;

trait HasPrimaryImage
{
    use InteractsWithMedia;

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection($this->primaryImageCollectionName())->singleFile();
    }

    public function primaryImageCollectionName(): string
    {
        return 'images';
    }

    public function getPrimaryImageUrlAttribute(): string
    {
        return (string) $this->getFirstMediaUrl($this->primaryImageCollectionName());
    }
}
