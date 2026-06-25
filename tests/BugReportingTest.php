<?php

namespace Tensorbuzz\Tests;

use PHPUnit\Framework\TestCase;
use Tensorbuzz\BugReporting;
use Tensorbuzz\Tests\Support\FakeTransport;

class BugReportingTest extends TestCase
{
    public function testRequiresAnAuthToken()
    {
        $this->expectException('InvalidArgumentException');

        new BugReporting('');
    }

    public function testRejectsUnknownOptions()
    {
        $this->expectException('InvalidArgumentException');

        new BugReporting('token', array('nope' => true));
    }

    public function testRejectsAnInvalidTransport()
    {
        $this->expectException('InvalidArgumentException');

        new BugReporting('token', array('transport' => new \stdClass()));
    }

    public function testReportsTheExactWirePayload()
    {
        $transport = new FakeTransport('{"url":"https://tb.test/bug-reports/1","status":"ok"}');
        $reporting = new BugReporting('the-token', array(
            'transport' => $transport,
            'hostname' => 'app.test',
        ));

        $response = $reporting->report(new \RuntimeException('boom'));

        $this->assertSame(1, $transport->callCount);
        $this->assertSame('https://server.tensorbuzz.com/errors/reports', $transport->lastPostUrl);

        $data = $transport->lastPostData;
        $this->assertSame('the-token', $data['auth_token']);
        $this->assertSame('app.test', $data['hostname']);
        $this->assertSame('php', $data['runtimeEnvironment']);

        $error = $data['error'];
        $this->assertSame('RuntimeException', $error['error_class']);
        $this->assertSame('boom', $error['message']);
        $this->assertSame('RuntimeException: boom', $error['backtrace'][0]);
        $this->assertArrayHasKey('url', $error);
        $this->assertArrayHasKey('user_agent', $error);
        $this->assertArrayHasKey('parameters', $error);
        $this->assertArrayHasKey('environment', $error);

        $this->assertSame('https://tb.test/bug-reports/1', $response['url']);
    }

    public function testUsesAConfiguredPostUrl()
    {
        $transport = new FakeTransport('{}');
        $reporting = new BugReporting('t', array(
            'transport' => $transport,
            'postUrl' => 'https://example.test/errors/reports',
        ));

        $reporting->report(new \Exception('x'));

        $this->assertSame('https://example.test/errors/reports', $transport->lastPostUrl);
    }

    public function testMergesAndSanitizesCollectorsAndPerReportMetadata()
    {
        $transport = new FakeTransport('{}');
        $reporting = new BugReporting('t', array('transport' => $transport));

        $reporting->collectParams(function () {
            return array('accountId' => 'acc-1', 'password' => 'leak');
        });
        $reporting->collectEnvironment(function () {
            return array('application' => 'my-php-app');
        });

        $reporting->report(new \Exception('x'), array('parameters' => array('userId' => 'u-1')));

        $params = $transport->lastPostData['error']['parameters'];
        $this->assertSame('acc-1', $params['accountId']);
        $this->assertSame('u-1', $params['userId']);
        $this->assertSame('[redacted]', $params['password']);
        $this->assertSame('my-php-app', $transport->lastPostData['error']['environment']['application']);
    }

    public function testEncodesEmptyMetadataAsJsonObjects()
    {
        $transport = new FakeTransport('{}');
        $reporting = new BugReporting('t', array('transport' => $transport));

        $reporting->report(new \Exception('x'));

        $encoded = json_encode($transport->lastPostData['error']);
        $this->assertStringContainsString('"parameters":{}', $encoded);
        $this->assertStringContainsString('"environment":{}', $encoded);
    }

    public function testSwallowsTransportFailuresAndReturnsNull()
    {
        $transport = new FakeTransport(null, new \RuntimeException('network down'));
        $reporting = new BugReporting('t', array('transport' => $transport));

        $result = $reporting->report(new \Exception('x'));

        $this->assertNull($result);
        $this->assertSame(1, $transport->callCount);
    }

    public function testReturnsNullWhenResponseIsNotJson()
    {
        $transport = new FakeTransport('not json');
        $reporting = new BugReporting('t', array('transport' => $transport));

        $this->assertNull($reporting->report(new \Exception('x')));
    }

    public function testHandleExceptionReportsThenRethrowsWhenNoPreviousHandler()
    {
        $transport = new FakeTransport('{}');
        $reporting = new BugReporting('t', array('transport' => $transport));
        $exception = new \RuntimeException('boom');

        // With no previous handler, the exception is re-thrown so PHP's default
        // uncaught-exception handling (visible fatal + non-zero exit) still runs.
        try {
            $reporting->handleException($exception);
            $this->fail('Expected handleException() to re-throw the exception');
        } catch (\RuntimeException $caught) {
            $this->assertSame($exception, $caught);
        }

        $this->assertSame(1, $transport->callCount, 'The exception should still be reported before re-throwing');
    }

    public function testAcceptsACaInfoPathOption()
    {
        // caInfoPath is forwarded to the default cURL transport; it must be an
        // accepted option (constructing must not raise "Unknown options").
        $reporting = new BugReporting('t', array('caInfoPath' => '/etc/ssl/certs/ca.pem'));

        $this->assertInstanceOf('Tensorbuzz\BugReporting', $reporting);
    }
}
