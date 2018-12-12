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

    protected $url = 'https://oapi.dingtalk.com/robot/send?access_token=';

    public function __construct($accessToken, $debug = false)
    {
        $this->accessToken = $accessToken;
        $this->debug = $debug;
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

        $response = $this->requestAsync($markdown);

        echo $this->getView(json_encode($markdown));

        if($response->getStatusCode() === "200"){
            echo "<!-- 推送钉钉成功 -->";
        }else{
            echo "<!-- ";
            echo $response->getStatusCode();
            echo $response->getBody();
            echo " -->";
        }

        return Handler::DONE;
    }

    /**
     * @param $markdown
     * @return false|mixed|string
     */
    protected function getView($markdown){
        if($this->debug){
            $view = file_get_contents(__DIR__ . '/../Resources/Html/Markdown.html');
            $view = str_ireplace('{{-- markdown --}}', $markdown, $view);
        }else{
            $view = file_get_contents(__DIR__ . '/../Resources/Html/Production.html');
        }
        return $view;
    }
    /**
     * @param $markdown
     * @return bool
     * @throws \GuzzleHttp\Exception\GuzzleException
     */
    public function requestAsync($markdown){
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
}