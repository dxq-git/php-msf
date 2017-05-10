<?php
/**
 * Controller 控制器
 * 对象池模式，实例会被反复使用，成员变量缓存数据记得在销毁时清理
 *
 * @author camera360_server@camera360.com
 * @copyright Chengdu pinguo Technology Co.,Ltd.
 */

namespace PG\MSF\Controllers;

use PG\MSF\{
    Base\Core, Base\Input, Base\Output, Base\AOP, Base\AOPFactory, Base\Exception,
    Coroutine\GeneratorContext, DataBase\MysqlAsynPool, Marco, Server
};

class Controller extends Core
{
    /**
     * @var MysqlAsynPool
     */
    public $mysqlPool;
    /**
     * @var Input
     */
    public $input;
    /**
     * @var Output
     */
    public $output;
    /**
     * 是否来自http的请求不是就是来自tcp
     * @var string
     */
    public $requestType;
    /**
     * @var \PG\MSF\Client\Http\Client
     */
    public $client;
    /**
     * @var \PG\MSF\Client\Tcp\Client
     */
    public $tcpClient;
    /**
     * @var AOP|\PG\MSF\Memory\Pool
     */
    public $objectPool;
    /**
     * @var array
     */
    public $objectPoolBuckets = [];
    /**
     * fd
     * @var int
     */
    protected $fd;
    /**
     * uid
     * @var int
     */
    protected $uid;
    /**
     * 用户数据
     * @var
     */
    protected $clientData;
    /**
     * http response
     * @var \swoole_http_request
     */
    protected $request;
    /**
     * http response
     * @var \swoole_http_response
     */
    protected $response;
    /**
     * 用于单元测试模拟捕获服务器发出的消息
     * @var array
     */
    protected $testUnitSendStack = [];
    /**
     * 协程上正文对象
     * @var GeneratorContext
     */
    protected $generatorContext;

    /**
     * redis连接池
     * @var array
     */
    private $redisPools;
    /**
     * redis代理池
     * @var array
     */
    private $redisProxies;

    /**
     * Controller constructor.
     */
    final public function __construct()
    {
        parent::__construct();
        $this->input      = new Input();
        $this->output     = new Output($this);
        $this->objectPool = AOPFactory::getObjectPool(getInstance()->objectPool, $this);
        $this->mysqlPool  = getInstance()->mysqlPool;
        $this->client     = clone getInstance()->client;
        $this->tcpClient  = clone getInstance()->tcpClient;
    }

    /**
     * 设置客户端协议数据
     * @param $uid
     * @param $fd
     * @param $clientData
     * @param $controllerName
     * @param $methodName
     */
    public function setClientData($uid, $fd, $clientData, $controllerName, $methodName)
    {
        $this->uid = $uid;
        $this->fd = $fd;
        $this->clientData = $clientData;
        $this->input->set($clientData);
        $this->requestType = Marco::TCP_REQUEST;
        $this->initialization($controllerName, $methodName);
    }

    /**
     * 初始化每次执行方法之前都会执行initialization
     * @param string $controllerName 准备执行的controller名称
     * @param string $methodName 准备执行的method名称
     */
    public function initialization($controllerName, $methodName)
    {
    }

    /**
     * set http Request Response
     * @param $request
     * @param $response
     * @param $controllerName
     * @param $methodName
     */
    public function setRequestResponse($request, $response, $controllerName, $methodName)
    {
        $this->request = $request;
        $this->response = $response;
        $this->input->set($request);
        $this->output->set($request, $response);
        $this->requestType = Marco::HTTP_REQUEST;
        $this->initialization($controllerName, $methodName);
    }

    /**
     * 返回协程上下文对象
     *
     * @return GeneratorContext
     */
    public function getGeneratorContext()
    {
        return $this->generatorContext;
    }

    /**
     * 设置协程上下文对象
     *
     * @param GeneratorContext $generatorContext
     * @return $this
     */
    public function setGeneratorContext(GeneratorContext $generatorContext)
    {
        $this->generatorContext = $generatorContext;
        return $this;
    }

    /**
     * 异常的回调
     * @param \Throwable $e
     * @throws \Throwable
     */
    public function onExceptionHandle(\Throwable $e)
    {
        switch ($this->requestType) {
            case Marco::HTTP_REQUEST:
                $this->output->setStatusHeader(500);
                $this->output->end($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
                break;
            case Marco::TCP_REQUEST:
                $this->send($e->getMessage() . ' in ' . $e->getFile() . ' on line ' . $e->getLine());
                break;
        }
    }

    /**
     * 向当前客户端发送消息
     * @param $data
     * @param $destroy
     * @throws Exception
     */
    public function send($data, $destroy = true)
    {
        if ($this->isDestroy) {
            throw new Exception('controller is destroy can not send data');
        }
        $data = getInstance()->encode($this->pack->pack($data));
        if (Server::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'send', 'fd' => $this->fd, 'data' => $data];
        } else {
            getInstance()->send($this->fd, $data);
        }
        if ($destroy) {
            $this->destroy();
        }
    }

    /**
     * 销毁
     */
    public function destroy()
    {
        parent::destroy();
        unset($this->fd);
        unset($this->uid);
        unset($this->clientData);
        unset($this->request);
        unset($this->response);
        unset($this->generatorContext);
        unset($this->redisProxies);
        unset($this->redisPools);
        $this->input->reset();
        $this->output->reset();
        //销毁对象池
        foreach ($this->objectPoolBuckets as $k => $obj) {
            $this->objectPool->push($obj);
            unset($this->objectPoolBuckets[$k]);
        }

        ControllerFactory::getInstance()->revertController($this);
    }

    /**
     * 获取单元测试捕获的数据
     * @return array
     */
    public function getTestUnitResult()
    {
        $stack = $this->testUnitSendStack;
        $this->testUnitSendStack = [];
        return $stack;
    }

    /**
     * 当控制器方法不存在的时候的默认方法
     */
    public function defaultMethod()
    {
        if ($this->requestType == Marco::HTTP_REQUEST) {
            $this->output->setHeader('HTTP/1.1', '404 Not Found');
            $template = $this->loader->view('server::error_404');
            $this->output->end($template->render());
        } else {
            throw new Exception('method not exist');
        }
    }

    /**
     * 断开链接
     * @param $fd
     * @param bool $autoDestroy
     */
    protected function close($fd, $autoDestroy = true)
    {
        if (Server::$testUnity) {
            $this->testUnitSendStack[] = ['action' => 'close', 'fd' => $fd];
        } else {
            getInstance()->close($fd);
        }
        if ($autoDestroy) {
            $this->destroy();
        }
    }

    /**
     * 获取redis连接池
     * @param string $poolName
     * @return bool|AOP|\PG\MSF\DataBase\CoroutineRedisHelp
     */
    protected function getRedisPool(string $poolName)
    {
        if (isset($this->redisPools[$poolName])) {
            return $this->redisPools[$poolName];
        }
        $pool = getInstance()->getAsynPool($poolName);
        if (!$pool) {
            return false;
        }

        $this->redisPools[$poolName] = AOPFactory::getRedisPoolCoroutine($pool->getCoroutine(), $this);
        return $this->redisPools[$poolName];
    }

    /**
     * 获取redis代理
     * @param string $proxyName
     * @return bool|AOP
     */
    protected function getRedisProxy(string $proxyName)
    {
        if (isset($this->redisProxies[$proxyName])) {
            return $this->redisProxies[$proxyName];
        }
        $proxy = getInstance()->getRedisProxy($proxyName);
        if (!$proxy) {
            return false;
        }

        $this->redisProxies[$proxyName] = AOPFactory::getRedisProxy($proxy, $this);
        return $this->redisProxies[$proxyName];
    }
}