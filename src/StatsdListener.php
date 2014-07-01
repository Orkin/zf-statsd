<?php
namespace ZF\Statsd;

use Zend\EventManager\EventManagerInterface;
use Zend\EventManager\AbstractListenerAggregate;
use Zend\Http\Request as HttpRequest;
use Zend\Http\Response as HttpResponse;
use Zend\Mvc\MvcEvent;

class StatsdListener extends AbstractListenerAggregate
{
    /**
     * @var array
     */
    protected $config = array();

    /**
     * @var array
     */
    protected $eventConfig = array();

    /**
     * @var array
     */
    protected $events = array();

    /**
     * @var array
     */
    protected $metrics = array();

    /**
     * @param string $metricName
     * @param string $value
     * @return self
     */
    protected function addMemory($metricName, $value = null)
    {
        /*
         * Since the StatsD module event is called very late in the FINISH
         * event, this should really be the max RAM used for this call.
         *
         * We use a timer metric type since it can handle whatever number.
         */
        $value or $value = memory_get_peak_usage();

        $value *= 1000;

        $this->metrics[$metricName] = "$value|ms";

        return $this;
    }

    /**
     * @param string $metricName
     * @return self
     */
    protected function addTimer($metricName, $time)
    {
        $time *= 1000;

        $this->metrics[$metricName] = "$time|ms";

        return $this;
    }

    /**
     * @param EventManagerInterface $events
     * @param int                   $priority
     */
    public function attach(EventManagerInterface $events, $priority = 1)
    {
        $this->listeners[] = $events->attach('*', array($this, 'onEventStart'), 10000);
        $this->listeners[] = $events->attach('*', array($this, 'onEventEnd'), -10000);
        $this->listeners[] = $events->attach(MvcEvent::EVENT_FINISH, array($this, 'onFinish'), -11000);
    }

    /**
     * @throws \LogicException
     * @return integer
     */
    protected function getRequestTime()
    {
        if (version_compare(PHP_VERSION, '5.4.0') >= 0) {
            $start = $_SERVER["REQUEST_TIME_FLOAT"]; // As of PHP 5.4.0
        } else {
            if (! defined('REQUEST_TIME_FLOAT')) {
                throw new \LogicException("For a PHP version lower than 5.4.0 you MUST call define('REQUEST_TIME_FLOAT', microtime(true)) very early in your boostrap/index.php script in order to use a StatsD timer");
            }
            $start = REQUEST_TIME_FLOAT;
        }

        return $start;
    }

    /**
     * @param integer $end
     * @param integer $start
     * @return integer
     */
    protected function getTimeDiff($end, $start = null)
    {
        $start or $start = $this->getRequestTime();

        return (microtime(true) - $start);
    }

    /**
     * @param MvcEvent $e
     */
    public function onEventEnd(MvcEvent $e)
    {
        $start = $this->events[$e->getName()]['start'];
        unset($this->events[$e->getName()]['start']);

        $this->events[$e->getName()]['duration'] = (microtime(true) - $start);
        $this->events[$e->getName()]['memory']   = memory_get_peak_usage();
    }

    /**
     * @param MvcEvent $e
     */
    public function onEventStart(MvcEvent $e)
    {
        // First event just follows boostrap.
        if (empty($this->events['bootstrap'])) {
            $this->events['bootstrap']['duration'] = (microtime(true) - $this->getRequestTime());
            $this->events['bootstrap']['memory']   = memory_get_peak_usage();
        }

        $this->events[$e->getName()]['start'] = microtime(true);
    }

    /**
     * @param MvcEvent $e
     */
    public function onFinish(MvcEvent $e)
    {
        if (empty($this->config['enable'])) {
            return;
        }

        /* @var $request HttpRequest */
        $request = $e->getRequest();
        if (! $request instanceof HttpRequest) {
            return;
        }

        $response = $e->getResponse();
        if (! $response instanceof HttpResponse) {
            return;
        }

        list(
            $memoryMetric,
            $timerMetric
        ) = $this->prepareMetricNames($e);

        $this->resetMetrics();

        foreach ($this->events as $event) {
        }

        $this->resetEvents();

        $this->addMemory($memoryMetric)
            ->addTimer($timerMetric)
            ->send();
    }

    /**
     * @param MvcEvent $e
     */
    protected function prepareMetricNames(MvcEvent $e)
    {
        $request = $e->getRequest();
        $response = $e->getResponse();

        $memoryConfig = $this->config['memory_pattern'];
        $timerConfig  = $this->config['timer_pattern'];

        $tokens = array();

        if (
            strpos($memoryConfig, '%controller%')
            or strpos($timerConfig, '%controller%')
        ) {
            $tokens['controller'] = $e->getRouteMatch()
            ->getParam('controller');
        }

        if (
            strpos($memoryConfig, '%http-method%')
            or strpos($timerConfig, '%http-method%')
        ) {
            $tokens['http-method'] = $request->getMethod();
        }

        if (
            strpos($memoryConfig, '%http-code%')
            or strpos($timerConfig, '%http-code%')
        ) {
            $tokens['http-code'] = $response->getStatusCode();
        }

        if (
            strpos($memoryConfig, '%request-content-type%')
            or strpos($timerConfig, '%request-content-type%')
        ) {
            $tokens['request-content-type'] = $request->getHeaders()->get('request-content-type')->getFieldValue();
        }

        if (
            strpos($memoryConfig, '%response-content-type%')
            or strpos($timerConfig, '%response-content-type%')
        ) {
            $tokens['response-content-type'] = $response->getHeaders()->get('response-content-type')->getFieldValue();
        }

        $regex =  empty($this->config['replace_dots_in_tokens'])
        ? '/[^a-z0-9]+/ui'
            : '/[^a-z0-9.]+/ui';

        foreach ($tokens as &$v) {
            $v = preg_replace('/[^a-z0-9]+/ui', $this->config['replace_special_chars_with'], $v);
        }

        if (is_callable($this->config['metric_tokens_callback'])) {
            foreach ($tokens as &$v) {
                $v = call_user_func($this->config['metric_tokens_callback'], $v);
            }
        }

        foreach ($tokens as $k => $v) {
            $memoryConfig = str_replace("%$k%", $v, $memoryConfig);
            $timerConfig  = str_replace("%$k%", $v, $timerConfig);
        }

        return array($memoryConfig, $timerConfig);
    }

    /**
     * @return self
     */
    protected function resetEvents()
    {
        $this->events = array();

        return $this;
    }

    /**
     * @return self
     */
    protected function resetMetrics()
    {
        $this->metrics = array();

        return $this;
    }

    /**
     * Sets config.
     *
     * @param  array $config
     * @return self
     */
    public function setConfig(array $config)
    {
        $this->config = $config;

        return $this;
    }

    /**
     * Sends the metrics over UDP
     *
     * @return self
     */
    protected function send()
    {
        try {
            if (! empty($this->metrics)) {
                $fp = fsockopen("udp://{$this->config['statsd']['host']}", $this->config['statsd']['port']);

                if (! $fp) { return; }

                foreach ($this->metrics as $stat => $value) {
                    fwrite($fp, "$stat:$value");
                }

                fclose($fp);

                $this->resetMetrics();
            }
        } catch (\Exception $e) {
            // Ignores failures silently
        }

        return $this;
    }
}
