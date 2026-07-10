<?php

namespace App\Models\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

trait BelongsToTenant
{
    protected static function bootBelongsToTenant(): void
    {
        static::creating(function (Model $model): void {
            if (! $model->getAttribute('tenant_id') && Auth::check()) {
                $model->setAttribute('tenant_id', Auth::user()->tenant_id);
            }
        });

        static::addGlobalScope('tenant', function (Builder $builder): void {
            if (Auth::check() && Auth::user()?->tenant_id) {
                $builder->where(
                    $builder->getModel()->getTable().'.tenant_id',
                    Auth::user()->tenant_id
                );
            }
        });
    }
}
