<?php
/**
 * Created by PhpStorm.
 * User: Luffy Zhao
 * DateTime: 2018/12/12 10:21
 * Email: luffyzhao@vip.126.com
 */

namespace ErrorHandler;

use Whoops\Run;

class Error
{
    protected static $registerExists = false;

    /**
     * @param $handler
     * @return bool
     */
    public static function register($handler){
        if(self::$registerExists === true){
            return false;
        }
        $register = new Run();
        $register->pushHandler($handler);
        $register->register();
    }
}