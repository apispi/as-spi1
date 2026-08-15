<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    /**
     * Environments per user. Keeps the picker usable and the table bounded.
     */
    public const MAX_PER_USER = 20;

    /**
     * Variables per environment.
     */
    public const MAX_VARIABLES = 100;

    protected $fillable = [
        'user_id',
        'name',
        'variables',
        'is_default',
    ];

    protected $casts = [
        'variables' => 'array',
        'is_default' => 'boolean',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Variables as a flat key => value map, ready for substitution.
     */
    public function map(): array
    {
        return collect($this->variables ?? [])
            ->filter(fn ($v) => is_array($v) && isset($v['key']) && $v['key'] !== '')
            ->mapWithKeys(fn ($v) => [(string) $v['key'] => (string) ($v['value'] ?? '')])
            ->all();
    }

    /**
     * The values of variables flagged secret — masked wherever we persist or
     * echo a resolved request.
     */
    public function secretValues(): array
    {
        return collect($this->variables ?? [])
            ->filter(fn ($v) => is_array($v) && ! empty($v['secret']) && ($v['value'] ?? '') !== '')
            ->map(fn ($v) => (string) $v['value'])
            ->values()
            ->all();
    }

    /**
     * The environment as the API returns it: secret values are never sent back
     * to the client, only the fact that they are set.
     */
    public function toClientArray(): array
    {
        $variables = collect($this->variables ?? [])->map(function ($v) {
            $secret = ! empty($v['secret']);

            return [
                'key' => (string) ($v['key'] ?? ''),
                'value' => $secret ? '' : (string) ($v['value'] ?? ''),
                'secret' => $secret,
                'has_value' => ($v['value'] ?? '') !== '',
            ];
        })->values()->all();

        return [
            'id' => $this->id,
            'name' => $this->name,
            'is_default' => (bool) $this->is_default,
            'variables' => $variables,
            'updated_at' => $this->updated_at,
        ];
    }
}
