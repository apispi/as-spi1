<?php

namespace App\Services\Amqp;

use PhpAmqpLib\Connection\AbstractConnection;
use PhpAmqpLib\Connection\AMQPSSLConnection;
use PhpAmqpLib\Connection\AMQPStreamConnection;
use PhpAmqpLib\Message\AMQPMessage;

/**
 * Drives a single, bounded AMQP (RabbitMQ) interaction for the tester: connect,
 * optionally publish a message to an exchange/routing-key and/or pull messages
 * from a queue, then close.
 *
 * Consuming uses basic_get (a synchronous pull) rather than a long-lived
 * consumer so the whole exchange fits in one short-lived request with no
 * blocking wait.
 */
class AmqpTester
{
    /** Hard ceiling on messages pulled from a queue in one run. */
    public const MAX_MESSAGES = 50;

    /**
     * @param  (callable(array): AbstractConnection)|null  $connectionFactory
     *   Optional factory (opts) => connection, for testing.
     */
    public function __construct(protected $connectionFactory = null)
    {
    }

    /**
     * @param  array{
     *   host: string, port?: int, tls?: bool, tls_verify?: bool,
     *   username?: ?string, password?: ?string, vhost?: string,
     *   action?: string, exchange?: string, routing_key?: string,
     *   queue?: ?string, message?: ?string, timeout?: int,
     *   max_messages?: int, auto_ack?: bool
     * }  $opts
     * @return array<string, mixed>
     */
    public function run(array $opts): array
    {
        $action = $opts['action'] ?? 'publish';
        $maxMessages = max(1, min(self::MAX_MESSAGES, (int) ($opts['max_messages'] ?? 10)));

        $connection = $this->makeConnection($opts);
        $channel = $connection->channel();

        $published = false;
        $messages = [];

        try {
            if (in_array($action, ['publish', 'publish_get'], true)) {
                $message = new AMQPMessage((string) ($opts['message'] ?? ''), [
                    'content_type' => 'text/plain',
                    'delivery_mode' => AMQPMessage::DELIVERY_MODE_NON_PERSISTENT,
                ]);
                $channel->basic_publish(
                    $message,
                    (string) ($opts['exchange'] ?? ''),
                    (string) ($opts['routing_key'] ?? ''),
                );
                $published = true;
            }

            if (in_array($action, ['get', 'publish_get'], true)) {
                $queue = (string) ($opts['queue'] ?? '');
                $autoAck = (bool) ($opts['auto_ack'] ?? true);

                while (count($messages) < $maxMessages) {
                    $msg = $channel->basic_get($queue, $autoAck);
                    if ($msg === null) {
                        break;
                    }

                    $messages[] = [
                        'body' => $msg->getBody(),
                        'routing_key' => $msg->getRoutingKey(),
                        'exchange' => $msg->delivery_info['exchange'] ?? null,
                        'redelivered' => (bool) ($msg->delivery_info['redelivered'] ?? false),
                    ];

                    if (! $autoAck) {
                        $channel->basic_ack($msg->getDeliveryTag());
                    }
                }
            }
        } finally {
            $channel->close();
            $connection->close();
        }

        return [
            'broker' => ($opts['host']).':'.($opts['port'] ?? ($opts['tls'] ?? false ? 5671 : 5672)),
            'vhost' => $opts['vhost'] ?? '/',
            'action' => $action,
            'exchange' => $opts['exchange'] ?? '',
            'routing_key' => $opts['routing_key'] ?? '',
            'queue' => $opts['queue'] ?? null,
            'published' => $published,
            'messages' => $messages,
            'message_count' => count($messages),
        ];
    }

    protected function makeConnection(array $opts): AbstractConnection
    {
        if ($this->connectionFactory !== null) {
            return ($this->connectionFactory)($opts);
        }

        $tls = (bool) ($opts['tls'] ?? false);
        $host = $opts['host'];
        $port = (int) ($opts['port'] ?? ($tls ? 5671 : 5672));
        $user = (string) ($opts['username'] ?? 'guest');
        $password = (string) ($opts['password'] ?? 'guest');
        $vhost = (string) ($opts['vhost'] ?? '/');
        $timeout = max(1, min(15, (int) ($opts['timeout'] ?? 5)));

        if ($tls) {
            $sslOptions = ($opts['tls_verify'] ?? true)
                ? []
                : ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true];

            return new AMQPSSLConnection($host, $port, $user, $password, $vhost, $sslOptions, [
                'connection_timeout' => $timeout,
                'read_write_timeout' => $timeout,
            ]);
        }

        return new AMQPStreamConnection(
            $host, $port, $user, $password, $vhost,
            false, 'AMQPLAIN', null, 'en_US', $timeout, $timeout,
        );
    }
}
