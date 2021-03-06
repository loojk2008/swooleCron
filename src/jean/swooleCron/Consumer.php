<?php
/**
 * Created by PhpStorm.
 * User: weijian
 * Date: 2016/7/1
 * Time: 10:17
 */

namespace jean\swoolecron;

!defined('DS') and define('DS', DIRECTORY_SEPARATOR);
!defined('SRC') and define('SRC', dirname(dirname(__DIR__)) . DS);


use jean\lib\BaseObject;
use jean\lib\Environment;
use jean\lib\Exception;
use jean\lib\Receive;
use jean\lib\Task;
use jean\lib\TaskProcess;

class Consumer extends BaseObject
{
    static public $app = null;
    static $configFile = SRC . 'jean/config/jobConsumer.php';

    static function _init(array $config)
    {
        if (is_null(self::$app) || empty(self::$app)) {
            self::$app = new BaseObject($config);
            $arr = include SRC . 'jean/config/jobConsumer.php';
            BaseObject::__init(self::$app, $arr);
        }
        !empty($config) and BaseObject::__init(self::$app, $config);
    }

    static function run(array $config = [])
    {
        self::_init($config);
        self::checkEnvironment();
        self::start();
        return 1;
    }

    static private function start()
    {
        $_server = new \swoole_server(self::$app['server_ip'], self::$app['server_port'], SWOOLE_PROCESS, SWOOLE_SOCK_TCP);
        $_config = get_object_vars(self::$app);
        $_server->set($_config);
        $_server->on('start', function ($server) use ($_config) {

        });
        $_server->on('Receive', function ($server, $fd, $from_id, $data) {
            $receiveServer = new Receive($server);
            $rs = $receiveServer->run($data);
            unset($receiveServer);
            $server->send($fd, $rs);
            $server->close($fd);
        });
        $_server->on('WorkerStart', function ($server, $worker_id) use ($_config) {
            if (!$server->taskworker && $worker_id < $_config['worker_num']) {
                if ($worker_id == '0') {
                    $queue = new Queue($server->setting['queue']);
                    $server->tick(100, function ($id) use ($server, $queue) {
                        $job = $queue->pop();
                        $job and $rs = $server->task($job);
                        if (isset($rs) && $rs !== false) {
                            $queue->buried($job['id']);
                        }
                    });
                }
            }
        });
        $_server->on('task', function ($server, $task_id, $from_id, $job) {
            static $queue = null;
            is_null($queue) and $queue = new Queue($server->setting['queue']);
            if ($job) {
                $data = isset($job['body']) ? $job['body'] : '';
                $task = unserialize($data);
                if ($task && $task instanceof Task) {
                    try {
                        $taskProcess = new TaskProcess($server);
                        $res = $taskProcess->run($task);
                    } catch (\Exception $e) {
                        if (!$server->setting['daemonize']) {
                            echo $e->getMessage();
                        }
                    }
                    if (!$server->setting['daemonize']) {
                        echo "处理完任务:$data\r\n";
                    }
                    $queue->delete($job['id']);
                } else {
                    $queue->delete($job['id']);
                }
            }
        });
        $_server->on('finish', function ($server, $task_id, $from_id, $data) {
        });
        $_server->start();
    }

    static function stop(array $config = [])
    {
        self::_init($config);
        return (
        new Client(
            self::$app['server_ip'],
            self::$app['server_port'],
            self::class
        )
        )->stopServer(
                self::$app['server_ip'],
                self::$app['server_port']
            );
    }

    static function reload(array $config = [])
    {

        self::_init($config);
        (
        new Client(
            self::$app['server_ip'],
            self::$app['server_port'],
            self::class
        )
        )->reloadServer(
                self::$app['server_ip'],
                self::$app['server_port']
            );
    }

    static function serverStatus(array $config = [])
    {
        self::__init($config);
        return (
        new Client(
            self::$app['server_ip'],
            self::$app['server_port'],
            self::class
        )
        )->serverStatus(
                self::$app['server_ip'],
                self::$app['server_port']
            );
    }

    /**
     * cli环境检查
     * @return bool
     * @throws Exception
     */
    static function checkEnvironment()
    {
        $mod = Environment::getName();
        if ($mod == 'cli') {
            return true;
        }
        throw  new Exception("Server 只能运行在CLI环境下~!", '1');
    }
}