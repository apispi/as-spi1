<?php

namespace App\Models;

use App\Models\Concerns\RecordsActivity;
use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;

class Environment extends Model
{
    use RecordsActivity, SharedInWorkspace;

    /**
     * Environments per user. Keeps the picker usable and the table bounded.
     */
    public const MAX_PER_USER = 20;

    /**
     * Variables per environment.
     */
    public const MAX_VARIABLES = 100;

    /** Variables carry secret values and auth carries a credential; both are restorable but never echoed. */
    protected array $revisionRedacted = ['variables', 'auth'];

    protected $fillable = [
        'user_id',
        'name',
        'variables',
        'auth',
        'is_default',
    ];

    protected $casts = [
        'variables' => 'array',
        'auth' => 'array',
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
     * The environment's auth config with its credential fields blanked, plus a
     * has_* marker for each — the same treatment secret variables get, so an
     * OAuth client secret typed in literally is not handed back to the browser.
     */
    public function authForClient(): ?array
    {
        if (! is_array($this->auth) || $this->auth === []) {
            return null;
        }

        $auth = $this->auth;

        foreach (\App\Services\Auth\RequestAuthenticator::SECRET_FIELDS as $field) {
            if (array_key_exists($field, $auth)) {
                $auth['has_'.$field] = (string) $auth[$field] !== '';
                $auth[$field] = '';
            }
        }

        return $auth;
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
            'auth' => $this->authForClient(),
            'owner' => $this->relationLoaded('owner') && $this->owner
                ? ['id' => $this->owner->id, 'name' => $this->owner->name]
                : null,
            'updated_at' => $this->updated_at,
        ];
    }
}
