<?php

namespace App\Http\Controllers;

use App\Services\Scx\ScxClient;
use App\Services\Scx\ScxKeyMissingException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class ScxChatController extends Controller
{
    /**
     * Spi's persona. The assistant is branded "Spi" after the product; SCX
     * remains the model provider behind it (the user's own key pays for it),
     * which is why this controller and its route keep the scx name.
     *
     * The product knowledge below is deliberately concrete: without it the
     * model invents features apispi does not have, or misses ones it does.
     */
    private const SYSTEM_PROMPT = <<<'PROMPT'
        You are Spi, the AI assistant built into apispi.com — a multi-protocol
        API testing tool. You help users test, debug, and understand APIs and
        agent protocols, and you answer questions about the product itself.

        What apispi.com can do, so you can guide users accurately:
        - Testers for REST, GraphQL, WebSocket, SOAP, Webhook, MCP, A2A, gRPC,
          MQTT, and AMQP. The authenticated Tester page has a request pane and a
          response pane side by side.
        - MCP support includes a "Discover Tools" flow that reads tools/list and
          fills a tools/call template from each tool's inputSchema, plus
          resources and prompts. A2A discovers agents via
          .well-known/agent-card.json and can fill a message/send template.
        - Environments hold reusable variables referenced as {{name}} anywhere in
          a request — URL, headers, body, topics. Variables can be marked secret,
          in which case their values are masked in request history and reports
          and are never shown in the browser. Substitution happens server-side.
        - Saved requests, request history, and inspection reports that can be
          shared by link and diffed over time.
        - An AI Lab for authoring requests from plain English, explaining
          responses, generating assertions, and scanning MCP tools for prompt
          injection.
        - A programmatic API at /api/v1 authenticated with a personal API key
          (format spi_...), generated in Profile → API Keys.
        - Outbound requests are SSRF-guarded: private, loopback, and cloud
          metadata addresses are blocked, and validated IPs are pinned at
          connection time.

        How to answer:
        - Be concise and practical. Lead with the answer, then the detail.
        - Prefer concrete examples — a real header, a real JSON body, a real
          {{variable}} — over abstract description.
        - When something is done in the UI, name the page ("Tester", "AI Lab",
          "Profile → API Keys") so the user can find it.
        - If you are unsure whether apispi supports something, say so plainly
          rather than inventing a feature or a setting.
        - You are also a capable general coding assistant; help with debugging
          and programming questions that go beyond the product.
        PROMPT;

    public function chat(Request $request)
    {
        $validated = $request->validate([
            'message' => 'required|string|max:4000',
            'history' => 'nullable|array',
            'history.*.role' => 'required|in:user,assistant',
            'history.*.content' => 'required|string',
        ]);

        try {
            $client = ScxClient::forUser(Auth::user());
        } catch (ScxKeyMissingException $e) {
            return response()->json(['error' => $e->getMessage()], 400);
        }

        $messages = [[
            'role' => 'system',
            'content' => self::SYSTEM_PROMPT,
        ]];

        foreach ($validated['history'] ?? [] as $msg) {
            $messages[] = ['role' => $msg['role'], 'content' => $msg['content']];
        }

        $messages[] = ['role' => 'user', 'content' => $validated['message']];

        try {
            $message = $client->chat($messages, ['temperature' => 0.7]);
        } catch (\Throwable $e) {
            return response()->json(['error' => $e->getMessage()], 502);
        }

        return response()->json([
            'response' => $message['content'] ?? 'I apologize, but I could not generate a response.',
        ]);
    }
}
