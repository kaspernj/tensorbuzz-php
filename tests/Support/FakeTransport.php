<?php

namespace Tensorbuzz\Tests\Support;

use Tensorbuzz\Transport\TransportInterface;

/**
 * In-memory transport that records what would be sent, for assertions.
 */
class FakeTransport implements TransportInterface
{
    /** @var string|null */
    public $lastPostUrl;

    /** @var array|null */
    public $lastPostData;

    /** @var int */
    public $callCount = 0;

    /** @var string|null Response returned to the caller. */
    private $response;

    /** @var \Exception|null Thrown on send() when set, to simulate a transport failure. */
    private $throwable;

    /**
     * @param string|null    $response
     * @param \Exception|null $throwable
     */
    public function __construct($response = null, $throwable = null)
    {
        $this->response = $response;
        $this->throwable = $throwable;
    }

    /**
     * @param string $postUrl
     * @param array  $postData
     *
     * @return string|null
     */
    public function send($postUrl, array $postData)
    {
        $this->callCount++;
        $this->lastPostUrl = $postUrl;
        $this->lastPostData = $postData;

        if ($this->throwable !== null) {
            throw $this->throwable;
        }

        return $this->response;
    }
}
