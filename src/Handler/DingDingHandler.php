<?php
/**
 * Created by PhpStorm.
 * User: Luffy Zhao
 * DateTime: 2018/12/12 11:28
 * Email: luffyzhao@vip.126.com
 */

namespace ErrorHandler\Handler;


use ErrorHandler\Util\MarkdownBuild;
use GuzzleHttp\Client;
use Whoops\Handler\Handler;

class DingDingHandler extends Handler
{
    protected $accessToken;

    protected $debug;

    protected $ignore = [];

    protected $url = 'https://oapi.dingtalk.com/robot/send?access_token=';

    public function __construct($accessToken = '', $debug = false)
    {
        $this->accessToken = $accessToken;
        $this->debug = $debug;
    }

    /**
     * @return int|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle()
    {
        $exception = $this->getException();
        $inspector = $this->getInspector();

        $build = new MarkdownBuild($exception, $inspector);
        $markdown = $build->handle();

        if (!$this->isIgnore() && $this->accessToken) {
            $response = $this->requestAsync($markdown);
            if ($response->getStatusCode() === "200") {
                echo "<!-- 推送钉钉成功 -->";
            } else {
                echo "<!-- ";
                echo $response->getStatusCode();
                echo $response->getBody();
                echo " -->";
            }
        }

        echo $this->getView(json_encode($markdown));

        return Handler::DONE;
    }

    /**
     * 通过code判断
     */
    protected function isIgnore()
    {
        $exception = $this->getException();
        $code = $exception->getCode();

        foreach ($this->ignore as $key => $value) {
            switch ($key) {
                case 'code':
                    if (in_array($code, $value)) {
                        return true;
                    }
                    break;
                case 'exception':
                    foreach ($value as $item) {
                        if ($item instanceof $exception) {
                            return true;
                        }
                    }
                    break;
            }
        }
        return false;
    }

    /**
     * @param $markdown
     * @return false|mixed|string
     */
    protected function getView($markdown)
    {
        if ($this->debug) {
            $view = file_get_contents(__DIR__ . '/../Resources/Html/Markdown.html');
            $view = str_ireplace('{{-- markdown --}}', $markdown, $view);
        } else {
            $view = file_get_contents(__DIR__ . '/../Resources/Html/Production.html');
        }
        return $view;
    }

    /**
     * @param $markdown
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function requestAsync($markdown)
    {
        $client = new Client();

        $response = $client->post($this->url . $this->accessToken, [
            'body' => json_encode([
                'msgtype' => 'markdown',
                'markdown' => [
                    'title' => 'php代码错误信息',
                    'text' => $markdown,
                ],
            ]),
            'headers' => [
                'Content-Type' => 'application/json'
            ],
            'verify' => false
        ]);

        return $response;
    }

    /**
     * @param mixed $ignore
     */
    public function setIgnore($key, $ignore)
    {
        $this->ignore[$key][] = $ignore;
    }

    /**
     * @param mixed $accessToken
     * @return DingDingHandler
     */
    public function setAccessToken($accessToken)
    {
        $this->accessToken = $accessToken;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getAccessToken()
    {
        return $this->accessToken;
    }

    /**
     * @param bool $debug
     * @return DingDingHandler
     */
    public function setDebug($debug)
    {
        $this->debug = $debug;
        return $this;
    }

    /**
     * @return bool
     */
    public function isDebug()
    {
        return $this->debug;
    }
}