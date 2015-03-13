<?php
/**
 * Circuit Breaker implementation
 *
 * PHP version 5.4
 *
 * @package  CircuitBreaker
 * @author   Elisabeth Anderson <bet@andr.io>
 * @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 * @link     https://github.com/bbc-rmp/deploy-pipeline
 */

namespace betandr\CircuitBreaker;

use Psr\Log\LoggerInterface;
use betandr\CircuitBreaker\Persistence\PersistenceInterface;

/**
 * Circuit Breaker
 *
 * PHP version 5.4
 *
 * @package  CircuitBreaker
 * @author   Elisabeth Anderson <bet@andr.io>
 * @license  http://www.php.net/license/3_01.txt  PHP License 3.01
 * @link     https://github.com/bbc-rmp/deploy-pipeline
 */
class Breaker
{
    protected $name;
    protected $threshold = 5;
    protected $timeout = 60;
    protected $persistence;
    protected $breakerClosed = true;
    protected $willRetry = true;
    protected $failureKey;
    protected $lastFailureTimeKey;
    protected $logger;
    protected $onChange;
    const OPEN_TO_CLOSED = "Open To Closed";
    const CLOSED_TO_OPEN = "Closed To Open";

    /**
     * Construct a new Breaker instance.
     *
     * @param $name a string value to identify this breaker
     *
     * @return a boolean
     */
    public function __construct(
        $name,
        PersistenceInterface $persistence,
        LoggerInterface $logger = null,
        $params = null
    ) {
        $this->log("Instantiating breaker: ".$name);

        $this->persistence = $persistence;
        $this->name = $name;
        $this->logger = $logger;

        $this->failureKey = $name.'_failure_count_';
        $this->lastFailureTimeKey = $name.'_last_failure_time_';

        if (isset($params['threshold']) && is_int($params['threshold'])) {
            $this->threshold = $params['threshold'];
        }

        if (isset($params['timeout']) && is_int($params['timeout'])) {
            $this->timeout = $params['timeout'];
        }

        if (isset($params['retry']) && is_bool($params['retry'])) {
            $this->willRetry = $params['retry'];
        }

        if (isset($params['onChange']) && is_callable($params['onChange'])) {
            $this->onChange = $params['onChange'];
        }
    }

    public function setThreshold($threshold)
    {
        $this->threshold = $threshold;
    }

    public function getThreshold()
    {
        return $this->threshold;
    }

    public function setName($name)
    {
        $this->name = $name;
    }

    public function getName()
    {
        return $this->name;
    }

    public function setTimeout($timeout)
    {
        $this->timeout = $timeout;
    }

    public function getTimeout()
    {
        return $this->timeout;
    }

    public function setWillRetryAfterTimeout($willRetry)
    {
        if ($willRetry) {
            $this->willRetry = true;
        }
    }

    public function getWillRetryAfterTimeout()
    {
        return $this->willRetry;
    }

    public function getLastFailureTime()
    {
        return $this->persistence->get($this->lastFailureTimeKey);
    }

    public function getNumFailures()
    {
        $numFails = $this->persistence->get($this->failureKey);
        return ($numFails == null) ? 0 : $numFails;
    }

    /**
     * Check if circuit breaker is currently closed. If 'will retry' is set to true,
     * any checks for isClosed can return a true if the timeout has expired since the
     * last failure. This is so systems are given the chance to recover and the
     * circuit breaker can re-close itself if upstream services begin working again.
     * In this 'half-open' mode, the client can try another request, if it fails then
     * the timeout will be reset. If successful, the client can register a success
     * which will close the breaker properly.
     *
     * @return a boolean
     */
    public function isClosed()
    {
        $this->log("isClosed() called");
        $lastFailureTime = $this->persistence->get($this->lastFailureTimeKey);

        if (!$this->breakerClosed && $this->willRetry && isset($lastFailureTime)) {
            // If threshold reached and we want to retry, return true
            // Don't reset breaker though, let a registered success do that.
            $now = time();
            $lastFailurePlusTimeout = $lastFailureTime + $this->timeout;

            if ($now >= $lastFailurePlusTimeout) {
                $this->log("Last failure exceeds timeout, breaker 'half-open'");
                return true;
            }
        }

        return $this->breakerClosed;
    }

    /**
     * Check if circuit breaker is currently open. This is an antonym for isClosed()
     * and always has the opposite boolean value.
     *
     * @return a boolean
     */
    public function isOpen()
    {
        $this->log("isOpen() called");
        return !$this->isClosed();
    }

    /**
     * Log a successful transaction
     */
    public function success()
    {
        $this->log("success() called");

        if ($this->breakerClosed === false) {
            $this->breakerClosed = true;
            if (isset($this->onChange)) {
                call_user_func_array($this->onChange, array(self::OPEN_TO_CLOSED));
            }

        }

        $numFails = $this->persistence->get($this->failureKey);

        if ($this->threshold > 0) {
            $numFails--;
        }

        $this->log("Current number of fails: ".$numFails);

        $this->persistence->set($this->failureKey, $numFails);
    }

    /**
     * Log an unsuccessful transaction
     */
    public function failure()
    {
        $this->log("failure() called");

        $numFails = $this->persistence->get($this->failureKey);

        if ($numFails === null) {
            $numFails = 0;
        }

        if ($numFails < $this->threshold) {
            $numFails++;
        }

        $this->log("Current number of fails: ".$numFails);

        if ($numFails >= $this->threshold) {
            $this->log("Threshold reached, breaker opening");
            $this->breakerClosed = false;
            $this->persistence->set($this->lastFailureTimeKey, time());
            if (isset($this->onChange)) {
                call_user_func_array($this->onChange, array(self::CLOSED_TO_OPEN));
            }
        }

        $this->persistence->set($this->failureKey, $numFails);
    }

    /**
     * Immediately open the circuit breaker
     */
    public function open()
    {
        $this->log("open() called.");
        $this->breakerClosed = false;
    }

    /**
     * Immediately open the circuit breaker
     */
    public function close()
    {
        $this->log("close() called.");
        $this->breakerClosed = true;
    }

    /**
     * Close the circuit breaker and reset the failure count
     */
    public function reset()
    {
        $this->log("reset() called. Reseting failures.");
        $this->close();
        $this->persistence->set($this->failureKey, 0);
    }

    /**
     * To string
     *
     * @return a string value representing the object
     */
    public function __toString()
    {
        return ($this->isClosed()) ? "CircuitBreaker [CLOSED]" : "CircuitBreaker [OPEN]";
    }

    /**
     * Log message if a logger has been specified
     */
    private function log($message)
    {
        if (isset($this->logger)) {
            $this->logger->info($message);
        }
    }
}
