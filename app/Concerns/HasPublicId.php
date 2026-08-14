<?php

namespace App\Concerns;

use Illuminate\Support\Str;

trait HasPublicId
{
    public static function bootHasPublicId(): void
    {
        static::creating(function ($model): void {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::uuid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }
}
