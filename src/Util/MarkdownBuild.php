<?php
/**
 * Created by PhpStorm.
 * User: Luffy Zhao
 * DateTime: 2018/12/12 11:07
 * Email: luffyzhao@vip.126.com
 */

namespace ErrorHandler\Util;


class MarkdownBuild
{
    /**
     * @var \Exception
     */
    protected $exception = null;
    /**
     * @var \Whoops\Exception\Inspector
     */
    protected $inspector = null;


    public function __construct($exception, $inspector)
    {
        $this->exception = $exception;
        $this->inspector = $inspector;
    }

    /**
     * @return mixed
     */
    public function handle()
    {
        $mdString = file_get_contents(__DIR__ . '/../Resources/Markdown/SendDingDing.md');
        $info = $this->getDataInfo() + $this->getErrorFileInfo() + $this->getExceptionInfo();

        return str_ireplace(array_keys($info), array_values($info), $mdString);
    }

    /**
     * @return array
     */
    protected function getDataInfo()
    {
        return [
            '{{ http_url }}' => $this->isHttps() ? 'https' : 'http' . '://'.$_SERVER['SERVER_NAME'].':'.$_SERVER["SERVER_PORT"].$_SERVER["REQUEST_URI"],
            '{{ get }}' => $this->arrayToString($_GET),
            '{{ post }}' => $this->arrayToString($_POST),
        ];
    }

    /**
     * 获取文件信息
     * @return array
     */
    protected function getErrorFileInfo()
    {
        $frame = $this->inspector->getFrames()->offsetGet(0);
        $line = $frame->getLine();
        return [
            '{{ file }}' => $frame->getFile(),
            '{{ line }}' => $line,
            '{{ fileCode }}' => implode("\n" ,$frame->getFileLines($line - 8, $line + 10)),
            '{{ args }}' => $this->arrayToString($frame->getArgs()),
        ];
    }

    /**
     * @return array
     */
    protected function getExceptionInfo(){
        return [
            '{{ messages }}' => $this->exception->getMessage(),
            '{{ code }}' => $this->exception->getCode()
        ];
    }

    /**
     * @param $array
     * @return string
     */
    protected function arrayToString($array){
        $string = "\n";
        foreach ($array as $key=>$value){
            $string .=  '> **'.$key .'**'.' : `'.\GuzzleHttp\json_encode($value).'` ' . "\n";
        }
        return $string . "\n";
    }

    /**
     * @return bool
     */
    function isHttps() {
        if ( !empty($_SERVER['HTTPS']) && strtolower($_SERVER['HTTPS']) !== 'off') {
            return true;
        } elseif ( isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] === 'https' ) {
            return true;
        } elseif ( !empty($_SERVER['HTTP_FRONT_END_HTTPS']) && strtolower($_SERVER['HTTP_FRONT_END_HTTPS']) !== 'off') {
            return true;
        }
        return false;
    }
}