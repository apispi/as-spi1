<?php

namespace App\Models;

use App\Models\CatalogItem;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A saved, revisitable result of a connector inspection — an agent-in-the-loop
 * run, a conformance grade, or a security scan. Reports are private to their
 * owner until shared, at which point a random token grants read-only public
 * access. Two reports of the same type and connector can be diffed over time.
 */
class InspectionReport extends Model
{
    public const TYPES = ['agent_loop', 'conformance', 'security', 'collection_run', 'mcp_drift'];

    protected $fillable = [
        'user_id',
        'catalog_item_id',
        'connector_slug',
        'connector_name',
        'type',
        'summary',
        'data',
        'share_token',
    ];

    protected $casts = [
        'data' => 'array',
    ];

    protected $hidden = ['share_token'];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function catalogItem()
    {
        return $this->belongsTo(CatalogItem::class);
    }

    public function isShared(): bool
    {
        return ! empty($this->share_token);
    }

    /**
     * Generate a fresh share token (idempotent — reuses an existing one) and
     * return it, persisting the change.
     */
    public function share(): string
    {
        if (empty($this->share_token)) {
            $this->share_token = Str::random(40);
            $this->save();
        }

        return $this->share_token;
    }

    public function revokeShare(): void
    {
        $this->share_token = null;
        $this->save();
    }

    /**
     * Persist a report for a connector inspection, deriving a one-line summary
     * from the result payload by type.
     */
    public static function record(int $userId, CatalogItem $connector, string $type, array $data): self
    {
        return static::create([
            'user_id' => $userId,
            'catalog_item_id' => $connector->id,
            'connector_slug' => $connector->slug,
            'connector_name' => $connector->name,
            'type' => $type,
            'summary' => static::summarize($type, $data),
            'data' => $data,
        ]);
    }

    protected static function summarize(string $type, array $data): string
    {
        return match ($type) {
            'conformance' => 'Grade '.($data['grade'] ?? '?').' ('.($data['score'] ?? 0).'/100)',
            'security' => 'Risk '.ucfirst($data['risk'] ?? 'unknown'),
            'agent_loop' => ($data['completed'] ?? false ? 'Completed' : 'Incomplete')
                .' · '.($data['tool_call_count'] ?? 0).' tool call(s)',
            default => '',
        };
    }
}
