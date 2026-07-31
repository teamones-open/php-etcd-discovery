<?php

namespace teamones {
    class TestLogger
    {
        public $messages = [];

        public function error($message)
        {
            $this->messages[] = $message;
        }
    }

    class Log
    {
        public static $logger;
        public static $returnNull = false;

        public static function channel()
        {
            if (self::$returnNull) {
                return null;
            }

            if (!self::$logger) {
                self::$logger = new TestLogger();
            }
            return self::$logger;
        }
    }
}

namespace teamones\etcd {
    class Registry
    {
        public static function instance($host, $uuid)
        {
            return new self();
        }

        public function generateParam($name, $port)
        {
            return [
                'method' => 'register',
                'etcd_host' => '127.0.0.1:2379',
                'param' => json_encode(['name' => $name, 'port' => $port]),
            ];
        }
    }

    class Discovery
    {
        public static $instance;
        public $cached = [];

        public static function instance()
        {
            if (!self::$instance) {
                self::$instance = new self();
            }
            return self::$instance;
        }

        public function generateParam($name)
        {
            return [
                'method' => 'discovery',
                'etcd_host' => '127.0.0.1:2379',
                'param' => json_encode(['name' => $name]),
            ];
        }

        public function refreshCache($name, $data)
        {
            $this->cached[$name] = $data;
        }
    }
}

namespace teamones\process {
    class EtcdGoServer
    {
        public static $execCount = 0;
        public static $restartCount = 0;

        public static function exec()
        {
            self::$execCount++;
            return true;
        }

        public static function restart()
        {
            self::$restartCount++;
            return true;
        }
    }

    function config($name, $default = [])
    {
        return $GLOBALS['etcd_lifecycle_config'] ?? $default;
    }

    function error_log($message)
    {
        $GLOBALS['etcd_lifecycle_error_log'][] = $message;
        return true;
    }
}

namespace Workerman {
    class Timer
    {
        public static $nextId = 1;
        public static $timers = [];
        public static $failNextAdd = false;

        public static function add($interval, $callback, $args = [], $persistent = true)
        {
            if (self::$failNextAdd) {
                self::$failNextAdd = false;
                return false;
            }

            $id = self::$nextId++;
            self::$timers[$id] = [
                'interval' => $interval,
                'callback' => $callback,
                'args' => $args ?: [],
                'persistent' => $persistent,
            ];
            return $id;
        }

        public static function del($id)
        {
            unset(self::$timers[$id]);
            return true;
        }

        public static function fire($id)
        {
            if (!isset(self::$timers[$id])) {
                throw new \RuntimeException("timer {$id} not found");
            }
            $timer = self::$timers[$id];
            if (!$timer['persistent']) {
                unset(self::$timers[$id]);
            }
            call_user_func_array($timer['callback'], $timer['args']);
        }

        public static function countByInterval($interval, $persistent = null)
        {
            $count = 0;
            foreach (self::$timers as $timer) {
                if ($timer['interval'] == $interval
                    && ($persistent === null || $timer['persistent'] === $persistent)
                ) {
                    $count++;
                }
            }
            return $count;
        }
    }
}

namespace Workerman\Connection {
    class AsyncTcpConnection
    {
        public const STATUS_INITIAL = 0;
        public const STATUS_CONNECTING = 1;
        public const STATUS_ESTABLISHED = 2;
        public const STATUS_CLOSING = 4;
        public const STATUS_CLOSED = 8;

        public $onWebSocketConnect;
        public $onMessage;
        public $onError;
        public $onClose;
        public $sent = [];
        public $connectCount = 0;
        public $reconnectDelays = [];
        public $cancelReconnectCount = 0;
        public $sendResult = true;
        protected $status = self::STATUS_INITIAL;

        public function __construct($address)
        {
        }

        public function connect()
        {
            $this->connectCount++;
            $this->status = self::STATUS_CONNECTING;
        }

        public function handshake()
        {
            $this->status = self::STATUS_ESTABLISHED;
            call_user_func($this->onWebSocketConnect, $this);
        }

        public function receive($data)
        {
            call_user_func($this->onMessage, $this, $data);
        }

        public function send($message)
        {
            $this->sent[] = $message;
            return $this->sendResult;
        }

        public function close()
        {
            if ($this->status === self::STATUS_CLOSING || $this->status === self::STATUS_CLOSED) {
                return;
            }
            $this->status = self::STATUS_CLOSED;
            $onClose = $this->onClose;
            if ($onClose) {
                call_user_func($onClose, $this);
            }

            // Workerman destroys callbacks unless onClose changed the status
            // back to INITIAL by scheduling its native reconnect.
            if ($this->status === self::STATUS_CLOSED) {
                $this->onWebSocketConnect = null;
                $this->onMessage = null;
                $this->onError = null;
                $this->onClose = null;
            }
        }

        public function reconnect($delay = 0)
        {
            $this->status = self::STATUS_INITIAL;
            $this->reconnectDelays[] = $delay;
            if ($delay <= 0) {
                $this->connect();
            }
        }

        public function runNativeReconnect()
        {
            $this->connect();
        }

        public function cancelReconnect()
        {
            $this->cancelReconnectCount++;
        }

        public function getStatus()
        {
            return $this->status;
        }
    }
}

namespace {
    use teamones\process\Etcd;
    use teamones\process\EtcdGoServer;
    use Workerman\Timer;

    require __DIR__ . '/../src/process/Etcd.php';

    class TestEtcd extends Etcd
    {
        public $testNow = 1000.0;

        public function connection()
        {
            return $this->connection;
        }

        public function registerAckTimer()
        {
            return $this->registerAckTimerId;
        }

        public function heartbeatTimer()
        {
            return $this->heartbeatTimerId;
        }

        public function discoveryTimer()
        {
            return $this->discoveryTimerId;
        }

        public function reconnectTimer()
        {
            return $this->reconnectTimerId;
        }

        public function connectTimer()
        {
            return $this->connectTimerId;
        }

        public function registerRetryTimer()
        {
            return $this->registerRetryTimerId;
        }

        public function setLastServerMessageAt($value)
        {
            $this->lastServerMessageAt = $value;
        }

        public function lastServerMessageAt()
        {
            return $this->lastServerMessageAt;
        }

        protected function now()
        {
            return $this->testNow;
        }
    }

    function assertLifecycleSame($expected, $actual, $message)
    {
        if ($expected !== $actual) {
            throw new \RuntimeException(
                $message . '\nexpected=' . var_export($expected, true)
                . '\nactual=' . var_export($actual, true)
            );
        }
    }

    function lastSentMethod($connection)
    {
        $sent = $connection->sent;
        $message = json_decode(end($sent), true);
        return $message['method'] ?? '';
    }

    $GLOBALS['etcd_lifecycle_config'] = [
        'discovery' => [
            'etcd_host' => '127.0.0.1:2379',
            'server_uuid' => 'consumer-1',
            'server_name' => 'im',
            'server_port' => 8080,
            'discovery_name' => ['saas', 'log'],
        ],
    ];

    $etcd = new TestEtcd();
    $etcd->onWorkerStart();
    $connection = $etcd->connection();
    assertLifecycleSame(1, EtcdGoServer::$execCount, 'worker start must ensure the Go client is running');

    $connection->handshake();
    assertLifecycleSame('register', lastSentMethod($connection), 'websocket connect must register immediately');
    assertLifecycleSame(1, Timer::countByInterval(10, true), 'exactly one heartbeat timer is required');

    $connection->receive(json_encode([
        'method' => 'register',
        'code' => -1,
        'msg' => 'temporary registration failure',
    ]));
    assertLifecycleSame(1, Timer::countByInterval(1, false), 'first register retry must wait one second');
    Timer::fire($etcd->registerRetryTimer());
    assertLifecycleSame('register', lastSentMethod($connection), 'register retry must send register again');

    $connection->receive(json_encode([
        'method' => 'register',
        'code' => -1,
        'msg' => 'temporary registration failure',
    ]));
    assertLifecycleSame(1, Timer::countByInterval(2, false), 'second register retry must back off to two seconds');
    Timer::fire($etcd->registerRetryTimer());

    $connection->receive(json_encode(['method' => 'register', 'code' => 200, 'data' => []]));
    assertLifecycleSame(null, $etcd->registerAckTimer(), 'register success must clear the ACK timer');
    assertLifecycleSame(1, Timer::countByInterval(1, true), 'register success must create one discovery timer');

    $connection->receive(json_encode(['method' => 'register', 'code' => 200, 'data' => []]));
    assertLifecycleSame(1, Timer::countByInterval(1, true), 'duplicate register success must reuse discovery timer');

    $etcd->setLastServerMessageAt(900.0);
    \teamones\Log::$returnNull = true;
    $connection->receive('invalid frame');
    \teamones\Log::$returnNull = false;
    assertLifecycleSame(
        900.0,
        $etcd->lastServerMessageAt(),
        'invalid protocol data must not keep a broken connection alive'
    );
    assertLifecycleSame(
        1,
        count($GLOBALS['etcd_lifecycle_error_log']),
        'recovery logging must fall back safely when no logger is configured'
    );
    $connection->receive('PONG');
    assertLifecycleSame(1000.0, $etcd->lastServerMessageAt(), 'PONG must refresh server activity');

    $connection->close();
    assertLifecycleSame([1], $connection->reconnectDelays, 'onClose must schedule Workerman native reconnect');
    assertLifecycleSame(
        true,
        $connection->onMessage !== null && $connection->onClose !== null,
        'native reconnect must preserve Workerman callbacks'
    );
    assertLifecycleSame(null, $etcd->connectTimer(), 'connect timeout must not include reconnect delay');
    $firstReconnectTimer = $etcd->reconnectTimer();
    assertLifecycleSame(1, Timer::$timers[$firstReconnectTimer]['interval'], 'first reconnect delay must be one second');

    $etcd->testNow = 1005.0;
    Timer::fire($firstReconnectTimer);
    assertLifecycleSame([1, 0], $connection->reconnectDelays, 'watchdog must start reconnect if its timer wins');
    $delayedConnectTimer = $etcd->connectTimer();
    assertLifecycleSame(10, Timer::$timers[$delayedConnectTimer]['interval'], 'connect watchdog starts after reconnect');

    $etcd->testNow = 1020.0;
    Timer::fire($delayedConnectTimer);
    assertLifecycleSame(0, EtcdGoServer::$restartCount, 'a delayed event-loop tick must not kill healthy Go');
    assertLifecycleSame(
        10,
        Timer::$timers[$etcd->connectTimer()]['interval'],
        'a delayed connect watchdog must receive a fresh timeout window'
    );

    $connection->handshake();
    $connection->close();
    $secondReconnectTimer = $etcd->reconnectTimer();
    assertLifecycleSame(2, Timer::$timers[$secondReconnectTimer]['interval'], 'backoff must grow without register success');
    $connection->runNativeReconnect();
    Timer::fire($secondReconnectTimer);
    assertLifecycleSame(
        [1, 0, 2],
        $connection->reconnectDelays,
        'custom watchdog must not reconnect twice when Workerman native timer wins'
    );
    assertLifecycleSame(10, Timer::$timers[$etcd->connectTimer()]['interval'], 'native-first reconnect must arm watchdog');

    $connection->handshake();
    $lateAckTimer = $etcd->registerAckTimer();
    $etcd->testNow = 1045.0;
    Timer::fire($lateAckTimer);
    assertLifecycleSame(0, EtcdGoServer::$restartCount, 'late timer execution must grant buffered ACK a read turn');
    assertLifecycleSame(
        1,
        Timer::$timers[$etcd->registerAckTimer()]['interval'],
        'late ACK watchdog must use a short I/O grace period'
    );

    $connection->receive(json_encode(['method' => 'register', 'code' => 200, 'data' => []]));
    assertLifecycleSame(null, $etcd->registerAckTimer(), 'buffered register ACK must cancel grace timer');

    $etcd->setLastServerMessageAt(1000.0);
    Timer::fire($etcd->heartbeatTimer());
    assertLifecycleSame(0, EtcdGoServer::$restartCount, 'late heartbeat timer must not restart healthy Go');
    assertLifecycleSame(1045.0, $etcd->lastServerMessageAt(), 'late heartbeat must start a fresh response window');

    $etcd->testNow = 1055.0;
    $etcd->setLastServerMessageAt(1000.0);
    Timer::fire($etcd->heartbeatTimer());
    assertLifecycleSame(1, EtcdGoServer::$restartCount, 'responsive timer plus silent server must restart Go');

    $thirdReconnectTimer = $etcd->reconnectTimer();
    assertLifecycleSame(1, Timer::$timers[$thirdReconnectTimer]['interval'], 'register success must reset reconnect backoff');
    Timer::fire($thirdReconnectTimer);
    $connection->handshake();
    $ackTimer = $etcd->registerAckTimer();
    $etcd->testNow = 1065.0;
    Timer::fire($ackTimer);
    assertLifecycleSame(2, EtcdGoServer::$restartCount, 'on-time register ACK timeout must restart Go');

    $pendingReconnectTimer = $etcd->reconnectTimer();

    $reconnectCount = count($connection->reconnectDelays);
    $etcd->onWorkerStop();
    assertLifecycleSame($reconnectCount, count($connection->reconnectDelays), 'worker stop must not reconnect');
    assertLifecycleSame(1, $connection->cancelReconnectCount, 'worker stop must cancel Workerman reconnect');
    assertLifecycleSame(
        false,
        isset(Timer::$timers[$pendingReconnectTimer]),
        'worker stop must cancel the package reconnect timer'
    );

    Timer::$timers = [];
    Timer::$nextId = 1;
    $driftEtcd = new TestEtcd();
    $driftEtcd->testNow = 3000.0;
    $driftEtcd->onWorkerStart();
    $driftConnection = $driftEtcd->connection();
    $firstLateConnectTimer = $driftEtcd->connectTimer();
    $driftEtcd->testNow = 3020.0;
    Timer::fire($firstLateConnectTimer);
    $restartCountAfterFirstGrace = EtcdGoServer::$restartCount;
    $secondLateConnectTimer = $driftEtcd->connectTimer();
    $driftEtcd->testNow = 3040.0;
    Timer::fire($secondLateConnectTimer);
    assertLifecycleSame(
        $restartCountAfterFirstGrace + 1,
        EtcdGoServer::$restartCount,
        'timer drift grace must be granted at most once per connect attempt'
    );
    $driftEtcd->onWorkerStop();

    Timer::$timers = [];
    Timer::$nextId = 1;
    $fatalEtcd = new TestEtcd();
    $fatalEtcd->testNow = 2000.0;
    $fatalEtcd->onWorkerStart();
    $fatalConnection = $fatalEtcd->connection();
    Timer::$failNextAdd = true;
    try {
        $fatalConnection->handshake();
        throw new \RuntimeException('Expected critical timer failure was not thrown');
    } catch (\RuntimeException $e) {
        assertLifecycleSame(
            true,
            strpos($e->getMessage(), 'heartbeat timer') !== false,
            'critical timer failure must be explicit'
        );
    }
    assertLifecycleSame(
        \Workerman\Connection\AsyncTcpConnection::STATUS_CLOSED,
        $fatalConnection->getStatus(),
        'critical watchdog failure must close the unsafe connection'
    );
    assertLifecycleSame(null, $fatalEtcd->reconnectTimer(), 'critical timer failure must not recurse reconnect');
    $fatalEtcd->onWorkerStop();

    echo "EtcdLifecycleTest OK\n";
}
