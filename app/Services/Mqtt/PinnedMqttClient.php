<?php

namespace App\Services\Mqtt;

use PhpMqtt\Client\Exceptions\ConnectingToBrokerFailedException;
use PhpMqtt\Client\MqttClient;

/**
 * An MqttClient that connects to a pre-validated IP address while still
 * verifying TLS against the original hostname.
 *
 * php-mqtt builds its own stream context and offers no way to set `peer_name`,
 * so connecting to an address with certificate verification on would compare
 * the certificate against an IP and always fail. Overriding socket creation is
 * the only seam the library provides.
 *
 * The parent's ConnectionSettings are private, so the TLS configuration this
 * needs is passed in explicitly rather than read back off the parent.
 */
class PinnedMqttClient extends MqttClient
{
    /**
     * @param  string  $address     validated IP to connect to
     * @param  string  $peerName    hostname the certificate must match
     * @param  array   $tls         {enabled: bool, verify: bool}
     */
    public function __construct(
        string $address,
        int $port,
        string $clientId,
        private readonly string $peerName,
        private readonly array $tls,
        private readonly int $connectTimeout = 5,
        private readonly int $socketTimeout = 5,
    ) {
        parent::__construct($address, $port, $clientId);
    }

    /**
     * Mirrors MqttClient::establishSocketConnection, with two differences: the
     * TLS context carries `peer_name` (the hostname) while the connection goes
     * to the pinned address, and the options come from this class rather than
     * the parent's private settings.
     */
    protected function establishSocketConnection(): void
    {
        $contextOptions = [];

        if ($this->tls['enabled']) {
            $verify = $this->tls['verify'];

            $contextOptions['ssl'] = [
                'verify_peer' => $verify,
                'verify_peer_name' => $verify,
                'allow_self_signed' => ! $verify,
                // The connection is to an IP; the certificate is still checked
                // against the name the user asked for.
                'peer_name' => $this->peerName,
                'SNI_enabled' => true,
            ];
        }

        $connectionString = 'tcp://'.$this->getHost().':'.$this->getPort();

        $socket = @stream_socket_client(
            $connectionString,
            $errorCode,
            $errorMessage,
            $this->connectTimeout,
            STREAM_CLIENT_CONNECT,
            stream_context_create($contextOptions)
        );

        if ($socket === false) {
            throw new ConnectingToBrokerFailedException(
                ConnectingToBrokerFailedException::EXCEPTION_CONNECTION_SOCKET_ERROR,
                sprintf('Socket error [%d]: %s', $errorCode, $errorMessage),
                (string) $errorCode,
                (string) $errorMessage
            );
        }

        if ($this->tls['enabled']) {
            error_clear_last();

            if (@stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_ANY_CLIENT) === false) {
                $error = error_get_last()['message'] ?? 'Unknown TLS error';
                @fclose($socket);

                throw new ConnectingToBrokerFailedException(
                    ConnectingToBrokerFailedException::EXCEPTION_CONNECTION_TLS_ERROR,
                    sprintf('TLS error: %s', $error),
                    'TLS',
                    $error
                );
            }
        }

        stream_set_timeout($socket, $this->socketTimeout);
        stream_set_blocking($socket, false);

        $this->socket = $socket;
    }
}
