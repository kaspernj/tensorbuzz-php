<?php

namespace Tensorbuzz\Transport;

/**
 * Sends a JSON bug-report payload to a TensorBuzz endpoint.
 *
 * Implementations are the PHP equivalent of the JS client's pluggable
 * `RequestClass`. A custom transport (for tests, a PSR-18 client, a queue,
 * etc.) only needs this one method.
 */
interface TransportInterface
{
    /**
     * Posts the payload as JSON and returns the raw response body.
     *
     * Must never throw on a network/HTTP failure; return null instead so that
     * reporting an error can never crash the host application.
     *
     * @param string $postUrl  Absolute endpoint URL.
     * @param array  $postData Associative array to JSON-encode as the request body.
     *
     * @return string|null Raw response body, or null when the request failed.
     */
    public function send($postUrl, array $postData);
}
