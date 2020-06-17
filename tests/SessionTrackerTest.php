<?php

namespace Bugsnag\Tests;

use Bugsnag\Configuration;
use Bugsnag\HttpClient;
use Bugsnag\SessionTracker;
use GuzzleHttp;
use InvalidArgumentException;
use PHPUnit\Framework\MockObject\MockObject;
use RuntimeException;
use stdClass;

class SessionTrackerTest extends TestCase
{
    /** @var SessionTracker */
    private $sessionTracker;
    /** @var Configuration&MockObject */
    private $config;
    /** @var HttpClient&MockObject */
    private $client;

    public function setUp()
    {
        /** @var Configuration&MockObject */
        $this->config = new Configuration('example-api-key');

        /** @var HttpClient&MockObject */
        $this->client = $this->getMockBuilder(HttpClient::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->sessionTracker = new SessionTracker($this->config, $this->client);
    }

    public function testSendSessionsDoesNothingWhenThereAreNoSessionsToSend()
    {
        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->sendSessions();
    }

    public function testHttpClientCanBeObtainedViaConfig()
    {
        /** @var GuzzleHttp\Client&MockObject */
        $guzzle = $this->getMockBuilder(GuzzleHttp\Client::class)
            ->disableOriginalConstructor()
            ->disableProxyingToOriginalMethods()
            ->getMock();

        /** @var Configuration&MockObject */
        $config = $this->getMockBuilder(Configuration::class)
            ->setConstructorArgs(['example-api-key'])
            ->getMock();

        $config->expects($this->once())
            ->method('getSessionClient')
            ->willReturn($guzzle);

        $config->expects($this->once())
            ->method('getSessionEndpoint')
            ->willReturn(Configuration::SESSION_ENDPOINT);

        $config->expects($this->once())->method('shouldNotify')->willReturn(true);
        $config->expects($this->once())->method('getNotifier')->willReturn('test_notifier');
        $config->expects($this->once())->method('getDeviceData')->willReturn('device_data');
        $config->expects($this->once())->method('getAppData')->willReturn('app_data');

        $expectCallback = function ($payload) {
            $this->assertArrayHasKey('json', $payload);
            $this->assertArrayHasKey('headers', $payload);

            $json = $payload['json'];

            $this->assertArrayHasKey('notifier', $json);
            $this->assertArrayHasKey('device', $json);
            $this->assertArrayHasKey('app', $json);
            $this->assertArrayHasKey('sessionCounts', $json);

            $this->assertSame('test_notifier', $json['notifier']);
            $this->assertSame('device_data', $json['device']);
            $this->assertSame('app_data', $json['app']);
            $this->assertCount(1, $json['sessionCounts']);
            $this->assertSame('2000-01-01T00:00:00', $json['sessionCounts'][0]['startedAt']);
            $this->assertSame(1, $json['sessionCounts'][0]['sessionsStarted']);

            return true;
        };

        $method = self::getGuzzleMethod();
        $mock = $guzzle->expects($this->once())->method($method);

        if ($method === 'request') {
            $mock->with('POST', Configuration::SESSION_ENDPOINT, $this->callback($expectCallback));
        } else {
            $mock->with(Configuration::SESSION_ENDPOINT, $this->callback($expectCallback));
        }

        $sessionTracker = new SessionTracker($config);

        $sessionTracker->setStorageFunction(function () {
            return ['2000-01-01T00:00:00' => 1];
        });

        $sessionTracker->sendSessions();
    }

    public function testSessionsShouldNotSendWhenTheReleaseStageIsIgnored()
    {
        $this->config->setReleaseStage('development');
        $this->config->setNotifyReleaseStages(['production']);

        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->startSession();
    }

    public function testConfigurationCanBeChanged()
    {
        // The 'newConfig' prevents sessions from being sent because of the
        // release stage (as proven above). If the original config was used here
        // then the 'sendSessions' call would be made
        $newConfig = new Configuration('a different api key');
        $newConfig->setReleaseStage('development');
        $newConfig->setNotifyReleaseStages(['production']);

        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->setConfig($newConfig);
        $this->sessionTracker->startSession();
    }

    public function testSessionsShouldNotSendWhenTheReleaseStageIsIgnoredWithStorageFunction()
    {
        $numberOfCalls = 0;

        $this->sessionTracker->setStorageFunction(function ($key, $value = null) use (&$numberOfCalls) {
            $numberOfCalls++;

            if ($value === null) {
                $this->assertSame(1, $numberOfCalls, 'Expected the first call to be a read ($value === null)');

                return ['2000-01-01T00:00:00' => 1];
            }

            $this->assertSame(2, $numberOfCalls, 'Expected the second call to be a write ($value === [])');
            $this->assertSame([], $value, 'Expected the second call to be a write ($value === [])');
        });

        $this->config->setReleaseStage('development');
        $this->config->setNotifyReleaseStages(['production']);

        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->sendSessions();

        $this->assertSame(2, $numberOfCalls, 'Expected there to be two calls to the session storage function');
    }

    public function testSendSessionsDoesNotDeliverSessionsWhenThereAreNoSessions()
    {
        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->sendSessions();
    }

    /**
     * @param mixed $returnValue
     *
     * @return void
     *
     * @dataProvider storageFunctionEmptyReturnValueProvider
     */
    public function testSendSessionsDoesNotDeliverSessionsWhenGetSessionCountsReturnsAValueThatsNotAPopulatedArray($returnValue)
    {
        $this->sessionTracker->setStorageFunction(function () use ($returnValue) {
            return $returnValue;
        });

        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->sendSessions();
    }

    /**
     * @param mixed $returnValue
     *
     * @return void
     *
     * @dataProvider storageFunctionEmptyReturnValueProvider
     */
    public function testStartSessionDoesNotDeliverSessionsWhenLastSentIsNotAnIntegerWithStorageFunction($returnValue)
    {
        $this->sessionTracker->setStorageFunction(function ($key) use ($returnValue) {
            // We only care about the "last sent" value here
            if ($key === 'bugsnag-sessions-last-sent') {
                return $returnValue;
            }

            return null;
        });

        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->startSession();
    }

    /**
     * @param mixed $returnValue
     *
     * @return void
     *
     * @dataProvider storageFunctionEmptyReturnValueProvider
     */
    public function testStartSessionDoesNotDeliverSessionsWhenGetSessionCountsReturnsAValueThatsNotAPopulatedArrayWithStorageFunction($returnValue)
    {
        $this->sessionTracker->setStorageFunction(function ($key) use ($returnValue) {
            return $returnValue;
        });

        $this->client->expects($this->never())->method('sendSessions');

        $this->sessionTracker->startSession();
    }

    public function storageFunctionEmptyReturnValueProvider()
    {
        return [
            'null' => [null],
            'empty array' => [[]],
            'int' => [1],
            'float' => [1.2],
            'bool (true)' => [true],
            'bool (false)' => [false],
            'object' => [new stdClass()],
        ];
    }

    public function testSendSessionsSendsSessionsWhenThereAreSessionsToSendFromTheStorageFunction()
    {
        $this->sessionTracker->setStorageFunction(function () {
            return ['2000-01-01T00:00:00' => 1];
        });

        $this->client->expects($this->once())
            ->method('sendSessions')
            ->with($this->singleSessionExpectationCallback());

        $this->sessionTracker->sendSessions();
    }

    public function testStartSessionSendsTheSessionItStartsImmediately()
    {
        $this->client->expects($this->once())
            ->method('sendSessions')
            ->with($this->singleSessionExpectationCallback());

        $this->sessionTracker->startSession();
    }

    public function testStartSessionSendsTheSessionItStartsImmediatelyWithStorageFunction()
    {
        $session = [];

        $this->sessionTracker->setStorageFunction(function ($key, $value = null) use (&$session) {
            if (!isset($session[$key])) {
                $session[$key] = null;
            }

            if ($value === null) {
                return $session[$key];
            }

            $session[$key] = $value;
        });

        $this->client->expects($this->once())
            ->method('sendSessions')
            ->with($this->singleSessionExpectationCallback());

        $this->sessionTracker->startSession();
    }

    public function testSetLockFunctionsThrowsWhenBothFunctionsAreNotCallable()
    {
        $this->expectedException(InvalidArgumentException::class, 'Both lock and unlock functions must be callable');

        $this->sessionTracker->setLockFunctions(null, function () {});
    }

    public function testSetLockFunctionsThrowsWhenLockIsNotCallable()
    {
        $this->expectedException(InvalidArgumentException::class, 'Both lock and unlock functions must be callable');

        $this->sessionTracker->setLockFunctions(null, function () {});
    }

    public function testSetLockFunctionsThrowsWhenUnlockIsNotCallable()
    {
        $this->expectedException(InvalidArgumentException::class, 'Both lock and unlock functions must be callable');

        $this->sessionTracker->setLockFunctions(function () {}, null);
    }

    public function testLockFunctionsAreCalledWhenStartingSessions()
    {
        $locked = false;
        $lockWasCalled = false;
        $unlockWasCalled = false;

        $this->sessionTracker->setLockFunctions(
            function () use (&$locked, &$lockWasCalled) {
                $locked = true;
                $lockWasCalled = true;
            },
            function () use (&$locked, &$unlockWasCalled) {
                $locked = false;
                $unlockWasCalled = true;
            }
        );

        $this->sessionTracker->startSession();

        $this->assertFalse($locked, 'Expected not to be locked after sending sessions');
        $this->assertTrue($lockWasCalled, 'Expected the `lockFunction` to be called');
        $this->assertTrue($unlockWasCalled, 'Expected the `unlockFunction` to be called');
    }

    public function testLockFunctionsAreCalledWhenStartingSessionsWithStorageFunction()
    {
        $locked = false;
        $lockWasCalled = false;
        $unlockWasCalled = false;

        $this->sessionTracker->setLockFunctions(
            function () use (&$locked, &$lockWasCalled) {
                $locked = true;
                $lockWasCalled = true;
            },
            function () use (&$locked, &$unlockWasCalled) {
                $locked = false;
                $unlockWasCalled = true;
            }
        );

        $session = [];

        $this->sessionTracker->setStorageFunction(function ($key, $value = null) use (&$session) {
            if (!isset($session[$key])) {
                $session[$key] = null;
            }

            if ($value === null) {
                return $session[$key];
            }

            $session[$key] = $value;
        });

        $this->sessionTracker->startSession();

        $this->assertFalse($locked, 'Expected not to be locked after sending sessions');
        $this->assertTrue($lockWasCalled, 'Expected the `lockFunction` to be called');
        $this->assertTrue($unlockWasCalled, 'Expected the `unlockFunction` to be called');
    }

    public function testLockFunctionsAreCalledWhenSendingSessions()
    {
        $locked = false;
        $lockWasCalled = false;
        $unlockWasCalled = false;

        $this->sessionTracker->setLockFunctions(
            function () use (&$locked, &$lockWasCalled) {
                $locked = true;
                $lockWasCalled = true;
            },
            function () use (&$locked, &$unlockWasCalled) {
                $locked = false;
                $unlockWasCalled = true;
            }
        );

        $this->sessionTracker->sendSessions();

        $this->assertFalse($locked, 'Expected not to be locked after sending sessions');
        $this->assertTrue($lockWasCalled, 'Expected the `lockFunction` to be called');
        $this->assertTrue($unlockWasCalled, 'Expected the `unlockFunction` to be called');
    }

    public function testLockFunctionsAreCalledWhenSendingSessionsWithStorageFunction()
    {
        $locked = false;
        $lockWasCalled = false;
        $unlockWasCalled = false;

        $this->sessionTracker->setLockFunctions(
            function () use (&$locked, &$lockWasCalled) {
                $locked = true;
                $lockWasCalled = true;
            },
            function () use (&$locked, &$unlockWasCalled) {
                $locked = false;
                $unlockWasCalled = true;
            }
        );

        $session = [];

        $this->sessionTracker->setStorageFunction(function ($key, $value = null) use (&$session) {
            if (!isset($session[$key])) {
                $session[$key] = null;
            }

            if ($value === null) {
                return $session[$key];
            }

            $session[$key] = $value;
        });

        $this->sessionTracker->sendSessions();

        $this->assertFalse($locked, 'Expected not to be locked after sending sessions');
        $this->assertTrue($lockWasCalled, 'Expected the `lockFunction` to be called');
        $this->assertTrue($unlockWasCalled, 'Expected the `unlockFunction` to be called');
    }

    public function testSessionShouldBeUnlockedAfterAnException()
    {
        $locked = false;
        $lockWasCalled = false;
        $unlockWasCalled = false;

        $this->sessionTracker->setLockFunctions(
            function () use (&$locked, &$lockWasCalled) {
                $locked = true;
                $lockWasCalled = true;
            },
            function () use (&$locked, &$unlockWasCalled) {
                $locked = false;
                $unlockWasCalled = true;
            }
        );

        $this->sessionTracker->setStorageFunction(function () {
            throw new RuntimeException('Something went wrong!');
        });

        $e = null;

        try {
            $this->sessionTracker->startSession();
        } catch (RuntimeException $e) {
            $this->assertSame('Something went wrong!', $e->getMessage());
        }

        $this->assertNotNull($e, 'Expected a RuntimeException to be thrown');
        $this->assertFalse($locked, 'Expected not to be locked after failing to send sessions');
        $this->assertTrue($lockWasCalled, 'Expected the `lockFunction` to be called');
        $this->assertTrue($unlockWasCalled, 'Expected the `unlockFunction` to be called');
    }

    /**
     * @return void
     *
     * @dataProvider storageFunctionEmptyReturnValueProvider
     */
    public function testSetRetryFunctionThrowsWhenNotGivenACallable($value)
    {
        $this->expectedException(InvalidArgumentException::class, 'The retry function must be callable');

        $this->sessionTracker->setRetryFunction($value);
    }

    /**
     * @return void
     *
     * @dataProvider storageFunctionEmptyReturnValueProvider
     */
    public function testSetStorageFunctionThrowsWhenNotGivenACallable($value)
    {
        $this->expectedException(InvalidArgumentException::class, 'Storage function must be callable');

        $this->sessionTracker->setStorageFunction($value);
    }

    /**
     * @return void
     *
     * @dataProvider storageFunctionEmptyReturnValueProvider
     */
    public function testSetSessionFunctionThrowsWhenNotGivenACallable($value)
    {
        $this->expectedException(InvalidArgumentException::class, 'Session function must be callable');

        $this->sessionTracker->setSessionFunction($value);
    }

    /**
     * Get an expectation callback for the typical test case where there is a
     * single session that is being sent.
     *
     * @return \PHPUnit\Framework\Constraint\Callback
     */
    private function singleSessionExpectationCallback()
    {
        return $this->callback(function ($payload) {
            $this->assertArrayHasKey('notifier', $payload);
            $this->assertArrayHasKey('device', $payload);
            $this->assertArrayHasKey('app', $payload);
            $this->assertArrayHasKey('sessionCounts', $payload);

            $this->assertSame($this->config->getNotifier(), $payload['notifier']);
            $this->assertSame($this->config->getDeviceData(), $payload['device']);
            $this->assertSame($this->config->getAppData(), $payload['app']);

            $this->assertCount(1, $payload['sessionCounts']);

            $session = $payload['sessionCounts'][0];

            $this->assertArrayHasKey('startedAt', $session);
            $this->assertArrayHasKey('sessionsStarted', $session);

            $this->assertRegExp('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}$/', $session['startedAt']);
            $this->assertSame(1, $session['sessionsStarted']);

            return true;
        });
    }
}
