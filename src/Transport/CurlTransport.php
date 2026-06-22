<?php

namespace Tensorbuzz\Transport;

/**
 * Default transport: posts the payload with ext-curl.
 *
 * Synchronous (PHP request lifecycle), so it applies a bounded timeout and
 * never throws — a failed report returns null instead of interrupting the
 * host application.
 */
class CurlTransport implements TransportInterface
{
    /** @var int Total request timeout in seconds. */
    private $timeoutSeconds;

    /** @var int Connection timeout in seconds. */
    private $connectTimeoutSeconds;

    /**
     * @param array $options {
     *     @var int $timeoutSeconds        Total request timeout. Default 5.
     *     @var int $connectTimeoutSeconds Connection timeout. Default 5.
     * }
     */
    public function __construct(array $options = array())
    {
        $this->timeoutSeconds = isset($options['timeoutSeconds']) ? (int) $options['timeoutSeconds'] : 5;
        $this->connectTimeoutSeconds = isset($options['connectTimeoutSeconds'])
            ? (int) $options['connectTimeoutSeconds']
            : 5;
    }

    /**
     * @param string $postUrl
     * @param array  $postData
     *
     * @return string|null
     */
    public function send($postUrl, array $postData)
    {
        $body = json_encode($postData);

        if ($body === false) {
            return null;
        }

        $handle = curl_init($postUrl);

        if ($handle === false) {
            return null;
        }

        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, $body);
        curl_setopt($handle, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($handle, CURLOPT_CONNECTTIMEOUT, $this->connectTimeoutSeconds);
        curl_setopt($handle, CURLOPT_TIMEOUT, $this->timeoutSeconds);
        curl_setopt($handle, CURLOPT_HTTPHEADER, array(
            'Content-Type: application/json',
            'Content-Length: ' . strlen($body),
        ));

        $response = curl_exec($handle);
        $errorNumber = curl_errno($handle);
        curl_close($handle);

        if ($errorNumber !== 0 || $response === false) {
            return null;
        }

        return $response;
    }
}
