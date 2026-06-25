<?php

namespace Tensorbuzz;

use InvalidArgumentException;
use Tensorbuzz\Transport\CurlTransport;
use Tensorbuzz\Transport\TransportInterface;

/**
 * Reports PHP exceptions and errors to TensorBuzz.
 *
 * PHP port of the JS `BugReporting` client. Configure it with an auth token,
 * optionally register collectors for per-report parameters/environment, then
 * either call connect() to capture uncaught exceptions and fatal errors
 * automatically, or call report() yourself from a framework exception handler.
 *
 * Reporting is fail-safe: a transport/HTTP failure is logged and swallowed so
 * that reporting an error can never crash the host application.
 */
class BugReporting
{
    /** @var string */
    const DEFAULT_POST_URL = 'https://server.tensorbuzz.com/errors/reports';

    /** @var string */
    const DEFAULT_RUNTIME_ENVIRONMENT = 'php';

    /** @var array Option keys accepted by the constructor. */
    private static $allowedOptions = array(
        'hostname',
        'postUrl',
        'runtimeEnvironment',
        'transport',
        'timeoutSeconds',
        'caInfoPath',
    );

    /** @var string */
    private $authToken;

    /** @var string|null */
    private $hostname;

    /** @var string */
    private $postUrl;

    /** @var string */
    private $runtimeEnvironment;

    /** @var TransportInterface */
    private $transport;

    /** @var callable[] */
    private $environmentCollectors = array();

    /** @var callable[] */
    private $parameterCollectors = array();

    /** @var callable|null */
    private $previousExceptionHandler;

    /** @var callable|null */
    private $previousErrorHandler;

    /** @var bool Whether an uncaught exception was already reported and re-thrown. */
    private $reportedUncaughtException = false;

    /**
     * @param string $authToken Required TensorBuzz auth token.
     * @param array  $options {
     *     @var string|null        $hostname           Domain for domain-restricted tokens.
     *     @var string|null        $postUrl            Override the reports endpoint.
     *     @var string|null        $runtimeEnvironment Override the runtime label (default "php").
     *     @var TransportInterface $transport          Custom HTTP transport.
     *     @var int                $timeoutSeconds     Default cURL transport timeout.
     *     @var string|null        $caInfoPath         CA bundle path for the default cURL transport (CURLOPT_CAINFO).
     * }
     *
     * @throws InvalidArgumentException When the token is missing or an option is unknown/invalid.
     */
    public function __construct($authToken, array $options = array())
    {
        $unknownOptions = array_diff(array_keys($options), self::$allowedOptions);

        if (count($unknownOptions) > 0) {
            throw new InvalidArgumentException(
                'Unknown BugReporting options: ' . implode(', ', $unknownOptions) . '.'
            );
        }

        if (!is_string($authToken) || $authToken === '') {
            throw new InvalidArgumentException('BugReporting requires an authToken.');
        }

        $this->authToken = $authToken;
        $this->hostname = isset($options['hostname']) ? $options['hostname'] : null;
        $this->postUrl = isset($options['postUrl']) && $options['postUrl'] !== null
            ? $options['postUrl']
            : self::DEFAULT_POST_URL;
        $this->runtimeEnvironment = isset($options['runtimeEnvironment']) && $options['runtimeEnvironment'] !== null
            ? $options['runtimeEnvironment']
            : self::DEFAULT_RUNTIME_ENVIRONMENT;

        if (isset($options['transport'])) {
            if (!($options['transport'] instanceof TransportInterface)) {
                throw new InvalidArgumentException(
                    'transport must implement ' . TransportInterface::class . '.'
                );
            }

            $this->transport = $options['transport'];
        } else {
            $transportOptions = array();

            if (isset($options['timeoutSeconds'])) {
                $transportOptions['timeoutSeconds'] = $options['timeoutSeconds'];
            }

            if (isset($options['caInfoPath'])) {
                $transportOptions['caInfoPath'] = $options['caInfoPath'];
            }

            $this->transport = new CurlTransport($transportOptions);
        }
    }

    /**
     * Registers a callback returning an associative array merged into every
     * report's environment.
     *
     * @param callable $callback
     *
     * @return void
     */
    public function collectEnvironment($callback)
    {
        $this->environmentCollectors[] = $callback;
    }

    /**
     * Registers a callback returning an associative array merged into every
     * report's parameters.
     *
     * @param callable $callback
     *
     * @return void
     */
    public function collectParams($callback)
    {
        $this->parameterCollectors[] = $callback;
    }

    /**
     * Captures uncaught exceptions and fatal errors automatically.
     *
     * Does not capture warnings/notices by default (that would be noisy); call
     * connectErrorHandler() explicitly to also report PHP errors.
     *
     * @return void
     */
    public function connect()
    {
        $this->connectExceptionHandler();
        $this->connectShutdownHandler();
    }

    /**
     * @return void
     */
    public function connectExceptionHandler()
    {
        $this->previousExceptionHandler = set_exception_handler(array($this, 'handleException'));
    }

    /**
     * @return void
     */
    public function connectShutdownHandler()
    {
        register_shutdown_function(array($this, 'handleShutdown'));
    }

    /**
     * Reports raised PHP errors (warnings, notices, recoverable errors) as
     * `ErrorException`s. Opt-in because it can be noisy.
     *
     * @return void
     */
    public function connectErrorHandler()
    {
        $this->previousErrorHandler = set_error_handler(array($this, 'handlePhpError'));
    }

    /**
     * Exception handler for set_exception_handler().
     *
     * @param \Exception|\Throwable $error
     *
     * @return void
     */
    public function handleException($error)
    {
        $this->report($error);

        if ($this->previousExceptionHandler !== null) {
            call_user_func($this->previousExceptionHandler, $error);

            return;
        }

        // No prior handler: re-throw so PHP's default uncaught-exception
        // handling still runs (visible fatal output + non-zero exit code).
        // Otherwise installing a handler that simply returns would turn an
        // uncaught exception into a silent successful exit (exit code 0),
        // hiding startup/CLI failures that happen outside a try/catch.
        //
        // The re-throw becomes a fatal error, which would otherwise be reported
        // a second time by the shutdown handler — flag it so that is skipped.
        $this->reportedUncaughtException = true;

        throw $error;
    }

    /**
     * Error handler for set_error_handler().
     *
     * @param int    $errorNumber
     * @param string $errorMessage
     * @param string $errorFile
     * @param int    $errorLine
     *
     * @return bool False so PHP's normal error handling still runs.
     */
    public function handlePhpError($errorNumber, $errorMessage, $errorFile = null, $errorLine = null)
    {
        if (!(error_reporting() & $errorNumber)) {
            return false;
        }

        $this->report(new \ErrorException($errorMessage, 0, $errorNumber, (string) $errorFile, (int) $errorLine));

        if ($this->previousErrorHandler !== null) {
            return call_user_func($this->previousErrorHandler, $errorNumber, $errorMessage, $errorFile, $errorLine);
        }

        return false;
    }

    /**
     * Shutdown handler that reports the last fatal error, if any.
     *
     * @return void
     */
    public function handleShutdown()
    {
        // The exception handler already reported this fatal (it re-threw an
        // uncaught exception it had reported in full); don't double-report it.
        if ($this->reportedUncaughtException) {
            return;
        }

        $error = error_get_last();

        if ($error === null) {
            return;
        }

        // E_RECOVERABLE_ERROR is included because on PHP 5.x an uncaught
        // catchable fatal (e.g. a type-hint violation) is reported with this
        // type and still terminates execution.
        $fatalTypes = E_ERROR | E_PARSE | E_CORE_ERROR | E_COMPILE_ERROR | E_USER_ERROR | E_RECOVERABLE_ERROR;

        if (!($error['type'] & $fatalTypes)) {
            return;
        }

        $errorClass = $this->fatalErrorClass($error['type']);
        $location = $error['file'] . ':' . $error['line'];
        $backtrace = array($errorClass . ': ' . $error['message'], $location);

        $this->sendReport($errorClass, $error['message'], $backtrace, array());
    }

    /**
     * Reports a throwable, returning the decoded TensorBuzz response (or null).
     *
     * @param \Exception|\Throwable $error
     * @param array                 $options {
     *     @var array       $parameters  Extra parameters for this report.
     *     @var array       $environment Extra environment for this report.
     *     @var string|null $url
     *     @var string|null $userAgent
     *     @var string|null $hostname
     * }
     *
     * @return array|null
     */
    public function report($error, array $options = array())
    {
        if (is_object($error) && method_exists($error, 'getMessage')) {
            $errorClass = get_class($error);
            $message = $error->getMessage();
        } else {
            $errorClass = 'UnknownError';
            $message = (string) $error;
        }

        return $this->sendReport($errorClass, $message, $this->backtraceFor($error, $errorClass, $message), $options);
    }

    /**
     * @param string $errorClass
     * @param string $message
     * @param array  $backtrace
     * @param array  $options
     *
     * @return array|null
     */
    private function sendReport($errorClass, $message, array $backtrace, array $options)
    {
        $postData = array(
            'auth_token' => $this->authToken,
            'error' => array(
                'backtrace' => $backtrace,
                'error_class' => $errorClass,
                'message' => $message,
                'url' => $this->resolveUrl($options),
                'user_agent' => $this->resolveUserAgent($options),
                'parameters' => $this->resolveParameters($options),
                'environment' => $this->resolveEnvironment($options),
            ),
            'hostname' => $this->resolveHostname($options),
            'runtimeEnvironment' => $this->runtimeEnvironment,
        );

        $responseText = $this->sendSafely($postData);

        if ($responseText === null) {
            return null;
        }

        $response = json_decode($responseText, true);

        if (!is_array($response)) {
            return null;
        }

        return $response;
    }

    /**
     * @param array $postData
     *
     * @return string|null
     */
    private function sendSafely(array $postData)
    {
        try {
            return $this->transport->send($this->postUrl, $postData);
        } catch (\Exception $exception) {
            error_log('TensorBuzz: failed to send bug report: ' . $exception->getMessage());

            return null;
        }
    }

    /**
     * @param \Exception|\Throwable $error
     * @param string                $errorClass
     * @param string                $message
     *
     * @return array
     */
    private function backtraceFor($error, $errorClass, $message)
    {
        if (is_object($error) && method_exists($error, 'getTraceAsString')) {
            $lines = explode("\n", $error->getTraceAsString());

            return array_merge(array($errorClass . ': ' . $message), $lines);
        }

        return array($errorClass . ': ' . $message);
    }

    /**
     * @param array $options
     *
     * @return object Sanitized parameters as a JSON object.
     */
    private function resolveParameters(array $options)
    {
        $merged = $this->mergeCollectors($this->parameterCollectors);

        if (isset($options['parameters']) && is_array($options['parameters'])) {
            $merged = array_merge($merged, $options['parameters']);
        }

        return $this->asObject(PayloadSanitizer::sanitize($merged));
    }

    /**
     * @param array $options
     *
     * @return object Sanitized environment as a JSON object.
     */
    private function resolveEnvironment(array $options)
    {
        $merged = $this->mergeCollectors($this->environmentCollectors);

        if (isset($options['environment']) && is_array($options['environment'])) {
            $merged = array_merge($merged, $options['environment']);
        }

        return $this->asObject(PayloadSanitizer::sanitize($merged));
    }

    /**
     * @param callable[] $collectors
     *
     * @return array
     */
    private function mergeCollectors(array $collectors)
    {
        $merged = array();

        foreach ($collectors as $collector) {
            $result = call_user_func($collector);

            if (is_array($result)) {
                $merged = array_merge($merged, $result);
            }
        }

        return $merged;
    }

    /**
     * Forces an empty array to encode as a JSON object ("{}") rather than "[]".
     *
     * @param mixed $value
     *
     * @return mixed
     */
    private function asObject($value)
    {
        if (is_array($value) && count($value) === 0) {
            return new \stdClass();
        }

        return $value;
    }

    /**
     * @param array $options
     *
     * @return string|null
     */
    private function resolveUrl(array $options)
    {
        if (isset($options['url'])) {
            return $options['url'];
        }

        if (!isset($_SERVER['REQUEST_URI'])) {
            return null;
        }

        $scheme = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== '' && $_SERVER['HTTPS'] !== 'off')
            ? 'https'
            : 'http';
        $host = isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : 'localhost';

        return $scheme . '://' . $host . $_SERVER['REQUEST_URI'];
    }

    /**
     * @param array $options
     *
     * @return string|null
     */
    private function resolveUserAgent(array $options)
    {
        if (isset($options['userAgent'])) {
            return $options['userAgent'];
        }

        return isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;
    }

    /**
     * @param array $options
     *
     * @return string|null
     */
    private function resolveHostname(array $options)
    {
        if (isset($options['hostname'])) {
            return $options['hostname'];
        }

        if ($this->hostname !== null) {
            return $this->hostname;
        }

        return isset($_SERVER['HTTP_HOST']) ? $_SERVER['HTTP_HOST'] : null;
    }

    /**
     * @param int $type
     *
     * @return string
     */
    private function fatalErrorClass($type)
    {
        $classes = array(
            E_ERROR => 'PHPFatalError',
            E_PARSE => 'PHPParseError',
            E_CORE_ERROR => 'PHPCoreError',
            E_COMPILE_ERROR => 'PHPCompileError',
            E_USER_ERROR => 'PHPUserError',
            E_RECOVERABLE_ERROR => 'PHPRecoverableError',
        );

        return isset($classes[$type]) ? $classes[$type] : 'PHPFatalError';
    }
}
