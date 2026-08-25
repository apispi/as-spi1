<?php

namespace App\Services\Mqtt;

use PhpMqtt\Client\ConnectionSettings;
use PhpMqtt\Client\Contracts\MqttClient as MqttClientContract;
use App\Services\Security\SsrfGuard;
use PhpMqtt\Client\MqttClient;

/**
 * Drives a single, bounded MQTT interaction against a broker for the tester:
 * connect, optionally publish a message and/or subscribe to a topic and
 * collect whatever arrives within a timeout, then disconnect.
 *
 * Everything happens synchronously inside one request — there is no persistent
 * worker — so subscribe loops are always bounded by a timeout and a max message
 * count to keep the request short.
 */
class MqttTester
{
    /** Hard ceiling on how long a subscribe loop may run, in seconds. */
    public const MAX_TIMEOUT = 15;

    /** Hard ceiling on messages collected from a subscribe loop. */
    public const MAX_MESSAGES = 50;

    /**
     * @param  (callable(string, int, string): MqttClientContract)|null  $clientFactory
     *   Optional factory (host, port, clientId) => client, for testing.
     */
    public function __construct(
        protected $clientFactory = null,
        protected ?SsrfGuard $guard = null,
    ) {
        $this->guard ??= new SsrfGuard;
    }

    /**
     * @param  array{
     *   host: string, port?: int, tls?: bool, tls_verify?: bool,
     *   username?: ?string, password?: ?string, client_id?: ?string,
     *   action?: string, topic: string, message?: ?string, qos?: int,
     *   retain?: bool, timeout?: int, max_messages?: int
     * }  $opts
     * @return array<string, mixed>
     */
    public function run(array $opts): array
    {
        $host = $opts['host'];

        // Validate before anything else: nothing about an unsafe request
        // should be prepared, let alone connected.
        //
        // Connect to the address the guard validated rather than the name, so
        // the broker's DNS cannot point somewhere internal between validation
        // and connection. Null means pinning does not apply (IP literal, or
        // DNS resolution disabled).
        $address = $this->guard->validatedAddress($host);

        $port = (int) ($opts['port'] ?? ($opts['tls'] ?? false ? 8883 : 1883));
        $action = $opts['action'] ?? 'publish';
        $topic = $opts['topic'];
        $qos = max(0, min(2, (int) ($opts['qos'] ?? 0)));
        $timeout = max(1, min(self::MAX_TIMEOUT, (int) ($opts['timeout'] ?? 5)));
        $maxMessages = max(1, min(self::MAX_MESSAGES, (int) ($opts['max_messages'] ?? 10)));
        $clientId = ($opts['client_id'] ?? null) ?: 'apispi-'.bin2hex(random_bytes(6));

        $client = $this->makeClient($host, $port, $clientId, $address, [
            'enabled' => (bool) ($opts['tls'] ?? false),
            'verify' => (bool) ($opts['tls_verify'] ?? true),
        ], $timeout);

        $settings = (new ConnectionSettings)
            ->setConnectTimeout($timeout)
            ->setSocketTimeout($timeout)
            ->setUseTls((bool) ($opts['tls'] ?? false));

        if (! ($opts['tls_verify'] ?? true)) {
            $settings = $settings
                ->setTlsVerifyPeer(false)
                ->setTlsVerifyPeerName(false)
                ->setTlsSelfSignedAllowed(true);
        }

        if (! empty($opts['username'])) {
            $settings = $settings->setUsername($opts['username']);
        }
        if (! empty($opts['password'])) {
            $settings = $settings->setPassword($opts['password']);
        }

        $messages = [];
        $published = false;

        $client->connect($settings, true);

        try {
            $subscribing = in_array($action, ['subscribe', 'publish_subscribe'], true);
            $publishing = in_array($action, ['publish', 'publish_subscribe'], true);

            if ($subscribing) {
                $client->subscribe($topic, function (string $t, string $m) use (&$messages, $client, $maxMessages) {
                    $messages[] = ['topic' => $t, 'message' => $m];
                    if (count($messages) >= $maxMessages) {
                        $client->interrupt();
                    }
                }, $qos);
            }

            if ($publishing) {
                $client->publish($topic, (string) ($opts['message'] ?? ''), $qos, (bool) ($opts['retain'] ?? false));
                $published = true;
            }

            if ($subscribing) {
                // Bound the receive loop by wall-clock only. exitWhenQueuesEmpty
                // is intentionally false: a QoS-0 subscribe has no pending
                // internal queues, so it would otherwise return before any
                // message arrived. The deadline handler and the max-message
                // interrupt in the subscribe callback are the exit conditions.
                $deadline = microtime(true) + $timeout;
                $client->registerLoopEventHandler(function ($client) use ($deadline) {
                    if (microtime(true) >= $deadline) {
                        $client->interrupt();
                    }
                });
                $client->loop(true);
            }
        } finally {
            $client->disconnect();
        }

        return [
            'broker' => $host.':'.$port,
            'client_id' => $clientId,
            'action' => $action,
            'topic' => $topic,
            'qos' => $qos,
            'published' => $published,
            'messages' => $messages,
            'message_count' => count($messages),
        ];
    }

    protected function makeClient(string $host, int $port, string $clientId): MqttClientContract
    {
        if ($this->clientFactory !== null) {
            return ($this->clientFactory)($host, $port, $clientId);
        }

        return new MqttClient($host, $port, $clientId);
    }
}
