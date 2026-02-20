<?php

namespace App\Models\Traits;

use Illuminate\Database\Eloquent\Builder;

trait BelongsToAccount
{
    /**
     * Boot the trait: add global scope for account_id when current account is set.
     */
    protected static function bootBelongsToAccount(): void
    {
        static::addGlobalScope('account', function (Builder $builder) {
            $accountId = request()->attributes->get('current_account_id');
            if ($accountId !== null && $accountId !== '') {
                $builder->where($builder->getModel()->getTable() . '.account_id', $accountId);
            }
        });

        static::creating(function ($model) {
            $accountId = request()->attributes->get('current_account_id');
            if ($accountId !== null && $accountId !== '' && empty($model->account_id)) {
                $model->account_id = $accountId;
            }
        });
    }

    public function account()
    {
        return $this->belongsTo(\App\Models\Account::class);
    }
}
