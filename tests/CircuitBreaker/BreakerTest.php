<?php

namespace betandr\CircuitBreaker\Tests;

use betandr\CircuitBreaker\Breaker;
use betandr\CircuitBreaker\MockLogger;
use betandr\CircuitBreaker\Persistence\ArrayPersistence;

class BreakerTest extends \PHPUnit_Framework_TestCase
{

    public function testLoggerReceivesLogOutput()
    {
        $logger = new MockLogger();
        $breaker = new Breaker('testBreaker', new ArrayPersistence, $logger);
        $breaker->failure();

        $this->assertTrue($logger->numberOfResponses() > 0);
    }

    public function testIsClosedOnConstruction()
    {
        $breaker = new Breaker('testBreaker', new ArrayPersistence);
        $this->assertTrue($breaker->isClosed(), 'Breaker should be closed when constructed');
    }

    public function testBreakerOpensWhenThresholdReached()
    {
        $threshold = 1;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $breaker->failure();

        $this->assertTrue($breaker->isOpen(), 'Breaker should be open when threshold reached');
    }

    public function testBreakerDoesntOpenUntilThresholdReached()
    {
        $threshold = 2;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $breaker->failure();

        $this->assertTrue($breaker->isClosed(), 'Breaker should be closed before threshold reached');
    }

    public function testOpenBreakerClosesWhenSuccessRegistered()
    {
        $threshold = 1;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $breaker->failure();
        $this->assertTrue($breaker->isOpen(), 'Breaker should be open when threshold reached');

        $breaker->success();
        $this->assertTrue($breaker->isClosed(), 'Breaker should re-close when success registered');
    }

    public function testSettersOverrideParams()
    {
        $threshold = 99;
        $timeout = 999;
        $willRetry = false;

        $breaker = new Breaker(
            'testBreaker',
            new ArrayPersistence,
            null,
            array(
                'timeout' => $timeout,
                'threshold' => $threshold,
                'retry' => $willRetry
            )
        );

        $newThreshold = 1;
        $newTimeout = 1;
        $newWillRetry = true;

        $breaker->setTimeout($newThreshold);
        $breaker->setThreshold($newTimeout);
        $breaker->setWillRetryAfterTimeout($newWillRetry);

        $this->assertEquals($newTimeout, $breaker->getTimeout(), 'Timeout should be set in params');
        $this->assertEquals($newThreshold, $breaker->getThreshold(), 'Threshold should be set in params');
        $this->assertTrue($breaker->getWillRetryAfterTimeout(), 'Will Retry should be set in params');
    }

    public function testOpeningBreaker()
    {
        $breaker = new Breaker('testBreaker', new ArrayPersistence);

        $breaker->open();
        $this->assertTrue($breaker->isOpen(), 'Breaker should be open when explicitly opened');
    }

    public function testReClosingBreaker()
    {
        $breaker = new Breaker('testBreaker', new ArrayPersistence);

        $breaker->open();
        $this->assertTrue($breaker->isOpen(), 'Breaker should be open when explicitly opened');

        $breaker->close();
        $this->assertTrue($breaker->isClosed(), 'Breaker should be closed when explicitly closed');
    }


    /**
    * Check callbacks fire when the breaker changes state
    */
    public function testBreakerCallbacks()
    {
        $messages = array();
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array(
            "threshold" => 1,
            "onChange" => function($message) use (&$messages) {
                $messages[] = $message;
            })
        );

        $breaker->failure();

        $this->assertCount(1, $messages);
        $this->assertEquals("Closed To Open", $messages[0]);

        $breaker->success();

        $this->assertCount(2, $messages);
        $this->assertEquals("Open To Closed", $messages[1]);
    }

    public function testResettingBreaker()
    {
        $breaker = new Breaker('testBreaker', new ArrayPersistence);

        $breaker->open();
        $this->assertTrue($breaker->isOpen(), 'Breaker should be open when explicitly opened');

        $breaker->reset();
        $this->assertTrue($breaker->isClosed(), 'Breaker should be closed when reset');
    }

    public function testDefaultThresholdValue()
    {
        $breaker = new Breaker('testBreaker', new ArrayPersistence);

        $expectedThreshold = 5;
        $this->assertEquals(
            $expectedThreshold,
            $breaker->getThreshold(),
            'Threshold should be '.$expectedThreshold.' by default'
        );
    }

    public function testDefaultTimeoutValue()
    {
        $breaker = new Breaker('testBreaker', new ArrayPersistence);

        $expectedTimeout = 60;
        $this->assertEquals(
            $expectedTimeout,
            $breaker->getTimeout(),
            'Timeout should be '.$expectedTimeout.' by default'
        );
    }

    public function testSettingThresholdViaParams()
    {
        $threshold = 99;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $this->assertEquals($threshold, $breaker->getThreshold(), 'Threshold should be set in params');
    }

    public function testSettingTimeoutViaParams()
    {
        $timeout = 999;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('timeout' => $timeout));

        $this->assertEquals($timeout, $breaker->getTimeout(), 'Timeout should be set in params');
    }

    public function testNonIntegerValueWillNotAffectThreshold()
    {
        $defaultThreshold = 5;
        $threshold = "INVALID_VALUE";
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $this->assertEquals($defaultThreshold, $breaker->getThreshold(), 'Threshold be default if invalid value used');
    }

    public function testNonIntegerValueWillNotAffectTimeout()
    {
        $defaultTimeout = 60;
        $timeout = "INVALID_VALUE";
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('timeout' => $timeout));

        $this->assertEquals($defaultTimeout, $breaker->getTimeout(), 'Threshold be default if invalid value used');
    }

    public function testNameIsSetCorrectly()
    {
        $breakerName = 'testBreaker'.time();

        $breaker = new Breaker($breakerName, new ArrayPersistence);
        $this->assertEquals($breakerName, $breaker->getName(), 'Breaker name should be set by constructor');
    }

    public function testNonBooleanValueWillNotAffectWillRetry()
    {
        $willRetry = "INVALID_VALUE";

        $breaker = new Breaker(
            'testBreaker',
            new ArrayPersistence,
            null,
            array(
                'retry' => $willRetry
            )
        );

        $this->assertTrue($breaker->getWillRetryAfterTimeout(), 'Will Retry should be true if non-boolean value used');
    }

    public function testSettingAllParams()
    {
        $threshold = 99;
        $timeout = 999;
        $willRetry = false;

        $breaker = new Breaker(
            'testBreaker',
            new ArrayPersistence,
            null,
            array(
                'timeout' => $timeout,
                'threshold' => $threshold,
                'retry' => $willRetry
            )
        );

        $this->assertEquals($timeout, $breaker->getTimeout(), 'Timeout should be set in params');
        $this->assertEquals($threshold, $breaker->getThreshold(), 'Threshold should be set in params');
        $this->assertFalse($breaker->getWillRetryAfterTimeout(), 'Will Retry should be set in params');
    }

    public function testSettingWillRetryViaParams()
    {
        $willRetry = false;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('retry' => $willRetry));

        $this->assertFalse($breaker->getWillRetryAfterTimeout(), 'Will retry should be set in params');
    }

    public function testLastFailureTime()
    {
        $threshold = 1;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $this->assertNull($breaker->getLastFailureTime(), 'Last failure should be null for new breaker');

        $breaker->failure();

        $this->assertNotNull($breaker->getLastFailureTime(), 'Last failure should NOT be null after failure');
    }

    public function testThatOnClosedWillReturnTrueIfOpenAndTimeoutExpired()
    {
        $timeout = 0; // immediate timeout
        $threshold = 1;
        $breaker = new Breaker(
            'testBreaker',
            new ArrayPersistence,
            null,
            array(
                'timeout' => $timeout,
                'threshold' => $threshold
            )
        );

        $this->assertTrue($breaker->isClosed(), 'Breaker should be closed closed by default');

        $breaker->failure();

        $this->assertTrue($breaker->isClosed(), 'Breaker should appear to be closed on timeout');
    }

    public function testThatOnClosedWillNeverReturnTrueIfWillRetryIsFalse()
    {
        $threshold = 1;
        $willRetry = false;
        $breaker = new Breaker('testBreaker', new ArrayPersistence, null, array('threshold' => $threshold));

        $this->assertTrue($breaker->isClosed(), 'Breaker should be closed closed by default');

        $breaker->failure();

        $this->assertTrue(
            $breaker->isOpen(),
            'Breaker should be open when failure threshold reached and retry is false'
        );
    }

    // public function testRegisteringSuccessReducesTheFailureCount()
    // {
    //     $threshold = 10;
    //     $breaker = new Breaker('testBreaker', new ArrayPersistence, array('threshold' => $threshold));
    //
    //     $breaker
    // }
}
