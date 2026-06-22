<?php

namespace Tensorbuzz\Tests;

use PHPUnit\Framework\TestCase;
use Tensorbuzz\PayloadSanitizer;

class PayloadSanitizerTest extends TestCase
{
    public function testRedactsSensitiveKeysCaseInsensitively()
    {
        $sanitized = PayloadSanitizer::sanitize(array(
            'password' => 'hunter2',
            'authToken' => 'abc',
            'sessionToken' => 'xyz',
            'API_SECRET' => 'shh',
            'name' => 'visible',
        ));

        $this->assertSame('[redacted]', $sanitized['password']);
        $this->assertSame('[redacted]', $sanitized['authToken']);
        $this->assertSame('[redacted]', $sanitized['sessionToken']);
        $this->assertSame('[redacted]', $sanitized['API_SECRET']);
        $this->assertSame('visible', $sanitized['name']);
    }

    public function testRedactsSensitiveKeysNestedInMapsAndLists()
    {
        $sanitized = PayloadSanitizer::sanitize(array(
            'user' => array('email' => 'a@b.test', 'password' => 'secretpw'),
            'items' => array(array('token' => 't1'), array('token' => 't2')),
        ));

        $this->assertSame('a@b.test', $sanitized['user']['email']);
        $this->assertSame('[redacted]', $sanitized['user']['password']);
        $this->assertSame('[redacted]', $sanitized['items'][0]['token']);
        $this->assertSame('[redacted]', $sanitized['items'][1]['token']);
    }

    public function testPassesThroughScalars()
    {
        $sanitized = PayloadSanitizer::sanitize(array(
            'i' => 7,
            'f' => 1.5,
            'b' => true,
            'n' => null,
        ));

        $this->assertSame(7, $sanitized['i']);
        $this->assertSame(1.5, $sanitized['f']);
        $this->assertSame(true, $sanitized['b']);
        $this->assertNull($sanitized['n']);
    }

    public function testTruncatesLongStrings()
    {
        $value = str_repeat('a', 1200);
        $sanitized = PayloadSanitizer::sanitize(array('text' => $value), array('maxStringLength' => 1000));

        $this->assertStringStartsWith(str_repeat('a', 1000) . '... [truncated 200 chars]', $sanitized['text']);
    }

    public function testCapsArrayItemsAndRecordsOmittedCount()
    {
        $list = range(1, 60);
        $sanitized = PayloadSanitizer::sanitize(array('list' => $list), array('maxArrayItems' => 50));

        // 50 kept items + 1 omitted-count marker.
        $this->assertCount(51, $sanitized['list']);
        $this->assertSame(array('__omittedItems' => 10), $sanitized['list'][50]);
    }

    public function testSummarizesResourcesWithoutContent()
    {
        $handle = fopen('php://memory', 'rb');
        $sanitized = PayloadSanitizer::sanitize(array('stream' => $handle));
        fclose($handle);

        $this->assertTrue($sanitized['stream']['__redacted']);
        $this->assertSame('resource', $sanitized['stream']['__type']);
        $this->assertArrayHasKey('resourceType', $sanitized['stream']);
    }

    public function testHandlesCircularObjectReferences()
    {
        $a = new \stdClass();
        $b = new \stdClass();
        $a->name = 'a';
        $a->other = $b;
        $b->name = 'b';
        $b->back = $a;

        $sanitized = PayloadSanitizer::sanitize(array('root' => $a));

        $this->assertSame('a', $sanitized['root']['name']);
        $this->assertSame('b', $sanitized['root']['other']['name']);
        $this->assertSame('[circular]', $sanitized['root']['other']['back']);
    }

    public function testCompactsOversizedPayloads()
    {
        $big = array();

        for ($i = 0; $i < 40; $i++) {
            $big['key' . $i] = str_repeat('x', 900);
        }

        $sanitized = PayloadSanitizer::sanitize($big, array('maxSerializedLength' => 12000));

        $this->assertTrue($sanitized['__truncated']);
        $this->assertGreaterThan(12000, $sanitized['originalSerializedLength']);
        $this->assertNotEmpty($sanitized['topLevelKeys']);
    }
}
