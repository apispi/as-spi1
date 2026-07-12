<?php

namespace App\Console\Commands;

use App\Services\Mcp\McpClient;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ad-hoc test client for poking at a remote MCP server over Streamable HTTP.
 *
 * Examples:
 *   php artisan mcp:test https://example.com/mcp
 *   php artisan mcp:test https://example.com/mcp --token=secret --method=tools/list
 *   php artisan mcp:test https://example.com/mcp --method=tools/call --params='{"name":"echo","arguments":{"text":"hi"}}'
 */
class McpTestCommand extends Command
{
    protected $signature = 'mcp:test
        {url : Base URL of the MCP server endpoint}
        {--token= : Bearer token for Authorization header}
        {--method= : Run a single JSON-RPC method instead of the interactive REPL}
        {--params= : JSON object of params for --method}';

    protected $description = 'Connect to an MCP server (Streamable HTTP) and exercise it for testing';

    public function handle(): int
    {
        $client = new McpClient($this->argument('url'), $this->option('token'));

        $this->info('Initializing MCP session with '.$this->argument('url').'...');

        try {
            $init = $client->initialize();
        } catch (Throwable $e) {
            $this->error('Initialize failed: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->line('Server: '.($init['serverInfo']['name'] ?? 'unknown').' '.($init['serverInfo']['version'] ?? ''));
        $this->line('Protocol: '.($client->protocolVersion() ?? 'unknown'));
        if ($client->sessionId()) {
            $this->line('Session: '.$client->sessionId());
        }
        $this->newLine();

        if ($method = $this->option('method')) {
            return $this->runOnce($client, $method, $this->option('params'));
        }

        return $this->repl($client);
    }

    protected function runOnce(McpClient $client, string $method, ?string $paramsJson): int
    {
        $params = [];

        if ($paramsJson) {
            $params = json_decode($paramsJson, true);

            if (! is_array($params)) {
                $this->error('--params must be a valid JSON object.');

                return self::FAILURE;
            }
        }

        try {
            $result = $client->request($method, $params);
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));

        return self::SUCCESS;
    }

    protected function repl(McpClient $client): int
    {
        $this->info('Interactive MCP test session. Commands: tools, call <name> <json-args>, resources, read <uri>, prompts, ping, raw <method> <json-params>, exit');

        while (true) {
            $line = trim((string) $this->ask('mcp>'));

            if ($line === '' ) {
                continue;
            }

            if (in_array($line, ['exit', 'quit'], true)) {
                break;
            }

            [$cmd, $rest] = array_pad(explode(' ', $line, 2), 2, '');

            try {
                match ($cmd) {
                    'tools' => $this->printJson($client->listTools()),
                    'call' => $this->handleCall($client, $rest),
                    'resources' => $this->printJson($client->listResources()),
                    'read' => $this->printJson($client->readResource(trim($rest))),
                    'prompts' => $this->printJson($client->listPrompts()),
                    'ping' => $this->printJson($client->ping()),
                    'raw' => $this->handleRaw($client, $rest),
                    default => $this->warn("Unknown command: {$cmd}"),
                };
            } catch (Throwable $e) {
                $this->error($e->getMessage());
            }
        }

        return self::SUCCESS;
    }

    protected function handleCall(McpClient $client, string $rest): void
    {
        [$name, $argsJson] = array_pad(explode(' ', trim($rest), 2), 2, '');

        $args = $argsJson ? json_decode($argsJson, true) : [];

        if (! is_array($args)) {
            $this->error('Arguments must be valid JSON, e.g. call echo {"text":"hi"}');

            return;
        }

        $this->printJson($client->callTool($name, $args));
    }

    protected function handleRaw(McpClient $client, string $rest): void
    {
        [$method, $paramsJson] = array_pad(explode(' ', trim($rest), 2), 2, '');

        $params = $paramsJson ? json_decode($paramsJson, true) : [];

        if (! is_array($params)) {
            $this->error('Params must be valid JSON.');

            return;
        }

        $this->printJson($client->request($method, $params));
    }

    protected function printJson(array $data): void
    {
        $this->line(json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
