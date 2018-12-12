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

    protected $url = 'https://oapi.dingtalk.com/robot/send?access_token=';

    public function __construct($accessToken)
    {
        $this->accessToken = $accessToken;
    }

    /**
     * @return int|null
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function handle(){
        $exception = $this->getException();
        $inspector = $this->getInspector();

        $build = new MarkdownBuild($exception, $inspector);
        $markdown = $build->handle();
        $this->requestAsync($markdown);
        return Handler::DONE;
    }

    /**
     * @param $markdown
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function requestAsync($markdown){
        $client = new Client();

        $response = $client->request('post', $this->url . $this->accessToken, [
            'body' => \GuzzleHttp\json_encode([
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

        echo $response->getBody();
    }
}