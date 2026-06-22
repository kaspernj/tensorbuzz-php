<?php

namespace Tensorbuzz;

/**
 * Builds a bounded, redacted payload for bug-report metadata fields.
 *
 * Port of the JS client's `sanitizeBugReportPayload`: it redacts sensitive
 * keys, truncates long strings, caps array sizes, summarises binary/resource
 * values without their bytes, guards against circular object references, and
 * compacts the whole payload when it is still too large to serialize.
 */
class PayloadSanitizer
{
    /** @var int */
    const DEFAULT_MAX_SERIALIZED_LENGTH = 12000;

    /** @var int */
    const DEFAULT_STRING_MAX_LENGTH = 1000;

    /** @var int */
    const DEFAULT_ARRAY_MAX_ITEMS = 50;

    /** @var string */
    const REDACTED_VALUE = '[redacted]';

    /**
     * @param mixed $value   Payload value.
     * @param array $options {
     *     @var int $maxArrayItems
     *     @var int $maxSerializedLength
     *     @var int $maxStringLength
     * }
     *
     * @return mixed Sanitized payload.
     */
    public static function sanitize($value, array $options = array())
    {
        $settings = array(
            'maxArrayItems' => isset($options['maxArrayItems'])
                ? $options['maxArrayItems']
                : self::DEFAULT_ARRAY_MAX_ITEMS,
            'maxSerializedLength' => isset($options['maxSerializedLength'])
                ? $options['maxSerializedLength']
                : self::DEFAULT_MAX_SERIALIZED_LENGTH,
            'maxStringLength' => isset($options['maxStringLength'])
                ? $options['maxStringLength']
                : self::DEFAULT_STRING_MAX_LENGTH,
        );

        $seen = array();
        $sanitized = self::sanitizeValue($value, '', $settings, $seen);
        $sanitizedLength = self::serializedLength($sanitized);

        if ($sanitizedLength <= $settings['maxSerializedLength']) {
            return $sanitized;
        }

        return self::compactPayload($sanitized, $sanitizedLength, $settings);
    }

    /**
     * @param mixed  $value
     * @param string $key
     * @param array  $settings
     * @param array  $seen     Object hashes currently being walked (by reference).
     *
     * @return mixed
     */
    private static function sanitizeValue($value, $key, array $settings, array &$seen)
    {
        if (self::isSensitiveKey($key)) {
            return self::REDACTED_VALUE;
        }

        if ($value === null || is_bool($value) || is_int($value) || is_float($value)) {
            return $value;
        }

        if (is_resource($value)) {
            return self::binarySummary('resource', null, array('resourceType' => get_resource_type($value)));
        }

        if (is_string($value)) {
            if (strlen($value) <= $settings['maxStringLength']) {
                return $value;
            }

            return substr($value, 0, $settings['maxStringLength'])
                . '... [truncated ' . (strlen($value) - $settings['maxStringLength']) . ' chars]';
        }

        if (is_array($value)) {
            if (self::isList($value)) {
                return self::sanitizeList($value, $settings, $seen);
            }

            return self::sanitizeMap($value, $settings, $seen);
        }

        if (is_object($value)) {
            if (self::isUploadedFile($value)) {
                return self::uploadedFileSummary($value);
            }

            $hash = spl_object_hash($value);

            if (isset($seen[$hash])) {
                return '[circular]';
            }

            $seen[$hash] = true;
            $sanitized = self::sanitizeMap(get_object_vars($value), $settings, $seen);
            unset($seen[$hash]);

            return $sanitized;
        }

        return (string) $value;
    }

    /**
     * @param array $value
     * @param array $settings
     * @param array $seen
     *
     * @return array
     */
    private static function sanitizeList(array $value, array $settings, array &$seen)
    {
        $entries = array();
        $kept = array_slice($value, 0, $settings['maxArrayItems']);

        foreach ($kept as $entry) {
            $entries[] = self::sanitizeValue($entry, '', $settings, $seen);
        }

        if (count($value) > $settings['maxArrayItems']) {
            $entries[] = array('__omittedItems' => count($value) - $settings['maxArrayItems']);
        }

        return $entries;
    }

    /**
     * @param array $value
     * @param array $settings
     * @param array $seen
     *
     * @return array
     */
    private static function sanitizeMap(array $value, array $settings, array &$seen)
    {
        $sanitized = array();

        foreach ($value as $entryKey => $entryValue) {
            $sanitized[$entryKey] = self::sanitizeValue($entryValue, (string) $entryKey, $settings, $seen);
        }

        return $sanitized;
    }

    /**
     * A "list" is a sequentially 0-indexed array (the PHP analogue of a JS Array);
     * anything else is treated as a key/value map (the analogue of a JS object).
     *
     * @param array $value
     *
     * @return bool
     */
    private static function isList(array $value)
    {
        if (count($value) === 0) {
            return true;
        }

        return array_keys($value) === range(0, count($value) - 1);
    }

    /**
     * @param string $key
     *
     * @return bool
     */
    private static function isSensitiveKey($key)
    {
        return preg_match('/authorization|contentBase64|password|secret|sessionToken|token/i', $key) === 1;
    }

    /**
     * @param object $value
     *
     * @return bool
     */
    private static function isUploadedFile($value)
    {
        // PSR-7 UploadedFileInterface and similar upload abstractions.
        return method_exists($value, 'getSize') && method_exists($value, 'getClientFilename');
    }

    /**
     * @param object $value
     *
     * @return array
     */
    private static function uploadedFileSummary($value)
    {
        $summary = array(
            '__redacted' => true,
            '__type' => 'UploadedFile',
            'size' => $value->getSize(),
            'filename' => $value->getClientFilename(),
        );

        if (method_exists($value, 'getClientMediaType')) {
            $summary['contentType'] = $value->getClientMediaType();
        }

        return $summary;
    }

    /**
     * @param string   $typeName
     * @param int|null $byteLength
     * @param array    $extra
     *
     * @return array
     */
    private static function binarySummary($typeName, $byteLength, array $extra = array())
    {
        $summary = array(
            '__redacted' => true,
            '__type' => $typeName,
        );

        if ($byteLength !== null) {
            $summary['byteLength'] = $byteLength;
        }

        return array_merge($summary, $extra);
    }

    /**
     * @param mixed $value
     * @param int   $originalSerializedLength
     * @param array $settings
     *
     * @return array
     */
    private static function compactPayload($value, $originalSerializedLength, array $settings)
    {
        $topLevelKeys = array();

        if (is_array($value) && !self::isList($value)) {
            $topLevelKeys = array_slice(array_keys($value), 0, $settings['maxArrayItems']);
        }

        return array(
            '__truncated' => true,
            'originalSerializedLength' => $originalSerializedLength,
            'topLevelKeys' => $topLevelKeys,
        );
    }

    /**
     * @param mixed $value
     *
     * @return int Serialized byte length, or PHP_INT_MAX when not serializable.
     */
    private static function serializedLength($value)
    {
        $encoded = json_encode($value);

        if ($encoded === false) {
            return PHP_INT_MAX;
        }

        return strlen($encoded);
    }
}
