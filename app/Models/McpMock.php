<?php

namespace App\Models;

use App\Models\Concerns\SharedInWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * A mock MCP server that Spi serves at /mcp-mock/{token}.
 *
 * Service virtualization for MCP: point an agent at the mock while the real
 * server does not exist yet, is rate-limited, or costs money. Tools are defined
 * by hand or seeded from a flight recorder's captured traffic (record once,
 * replay the observed shapes as a runnable stand-in).
 */
class McpMock extends Model
{
    use SharedInWorkspace;

    public const MAX_PER_USER = 10;

    public const MAX_TOOLS = 100;

    protected $fillable = [
        'user_id',
        'name',
        'token',
        'server_name',
        'server_version',
        'is_enabled',
        'tools',
    ];

    protected $casts = [
        'is_enabled' => 'boolean',
        'tools' => 'array',
    ];

    protected $attributes = [
        'server_name' => 'Spi Mock',
        'server_version' => '1.0.0',
        'is_enabled' => true,
    ];

    public static function generateToken(): string
    {
        return Str::lower(Str::random(40));
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function findTool(string $name): ?array
    {
        foreach ($this->tools ?? [] as $tool) {
            if (($tool['name'] ?? null) === $name) {
                return $tool;
            }
        }

        return null;
    }
}
