<?php
/**
 * Created by PhpStorm.
 * User: Luffy Zhao
 * DateTime: 2018/12/12 11:07
 * Email: luffyzhao@vip.126.com
 */

namespace ErrorHandler\Util;


use Whoops\Exception\Frame;

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
            '{{ get }}' => $_SERVER["REQUEST_URI"],
            '{{ post }}' => $this->arrayToString($_POST),
        ];
    }

    /**
     * 获取文件信息
     * @return array
     */
    protected function getErrorFileInfo()
    {
        $frames = $this->inspector->getFrames();
        return [
            '{{ files }}' => $this->getFiles($frames),
        ];
    }

    /**
     * @param $frames
     * @return string
     */
    protected function getFiles($frames){
        $string = "\n";
        foreach ($frames as $key=>$frame){
            $string .= $this->getFrameInfo($frame);

        }
        return $string;
    }

    /**
     * @param Frame $frame
     * @return string
     */
    protected function getFrameInfo(Frame $frame){
        $string = ">> 文件: {$frame->getFile(true)} : {$frame->getLine()} \n\n";
        if(($class = $frame->getClass()) !== null){
            $string .= ">>> {$class}::";
        }
        if(($function = $frame->getFunction()) !== null){
            $string .= $class === null ? ">>> {$function}" : "{$function}";
            if($args = $frame->getArgs()){
                $string .= '(' . implode(',', $args) . ')';
            }
        }
        return $string . "\n\n";
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
        $string = http_build_query($array);
        if(mb_strlen($string) > 2000){
            $string = substr($string, 0, 2000) . '...';
        }
        return "\n" . http_build_query($array) . "\n\n";
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