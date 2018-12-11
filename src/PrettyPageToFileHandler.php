<?php
/**
 * Created by PhpStorm.
 * User: Luffy Zhao
 * Date: 2018/12/11 16:31
 */

namespace ErrorHandler;


use InvalidArgumentException;
use Ramsey\Uuid\Uuid;
use RuntimeException;
use UnexpectedValueException;
use Whoops\Exception\Formatter;
use Whoops\Handler\Handler;
use Whoops\Util\Misc;
use ErrorHandler\Util\TemplateHelper;


class PrettyPageToFileHandler extends Handler
{
    /**
     * Search paths to be scanned for resources, in the reverse
     * order they're declared.
     *
     * @var array
     */
    private $searchPaths = array();

    /**
     * Fast lookup cache for known resource locations.
     *
     * @var array
     */
    private $resourceCache = array();

    /**
     * The name of the custom css file.
     *
     * @var string
     */
    private $customCss = null;

    /**
     * @var array[]
     */
    private $extraTables = array();

    /**
     * @var bool
     */
    private $handleUnconditionally = false;

    /**
     * @var string
     */
    private $pageTitle = "项目错误日志";

    /**
     * 钉钉Api调用地址
     * @var string
     */
    private $dingDingApiUrl = 'https://oapi.dingtalk.com/robot/send?access_token=5a6b429d26612cfa397c628f9f3ce877776b2a59df82aee515623cf609ba2eef';

    /**
     * A string identifier for a known IDE/text editor, or a closure
     * that resolves a string that can be used to open a given file
     * in an editor. If the string contains the special substrings
     * %file or %line, they will be replaced with the correct data.
     *
     * @example
     *  "txmt://open?url=%file&line=%line"
     * @var mixed $editor
     */
    protected $editor;

    /**
     * A list of known editor strings
     * @var array
     */
    protected $editors = array(
        "sublime" => "subl://open?url=file://%file&line=%line",
        "textmate" => "txmt://open?url=file://%file&line=%line",
        "emacs" => "emacs://open?url=file://%file&line=%line",
        "macvim" => "mvim://open/?url=file://%file&line=%line",
        "phpstorm" => "phpstorm://open?file=%file&line=%line",
    );

    /**
     * Constructor.
     */
    public function __construct()
    {
        if (ini_get('xdebug.file_link_format') || extension_loaded('xdebug')) {
            // Register editor using xdebug's file_link_format option.
            $this->editors['xdebug'] = function ($file, $line) {
                return str_replace(array('%f', '%l'), array($file, $line), ini_get('xdebug.file_link_format'));
            };
        }

        // Add the default, local resource search path:
        $this->searchPaths[] = __DIR__ . "/../vendor/filp/whoops/src/Whoops/Resources";
    }

    /**
     * @return int|null
     * @throws \Exception
     */
    public function handle()
    {
        if (!$this->handleUnconditionally()) {
            // Check conditions for outputting HTML:
            // @todo: Make this more robust
            if (php_sapi_name() === 'cli') {
                // Help users who have been relying on an internal test value
                // fix their code to the proper method
                if (isset($_ENV['whoops-test'])) {
                    throw new \Exception(
                        'Use handleUnconditionally instead of whoops-test'
                        . ' environment variable'
                    );
                }

                return Handler::DONE;
            }
        }

        // @todo: Make this more dynamic
        $helper = new TemplateHelper();

        $templateFile = $this->getResource("views/layout.html.php");
        $cssFile = $this->getResource("css/whoops.base.css");
        $zeptoFile = $this->getResource("js/zepto.min.js");
        $jsFile = $this->getResource("js/whoops.base.js");

        if ($this->customCss) {
            $customCssFile = $this->getResource($this->customCss);
        }

        $inspector = $this->getInspector();
        $frames = $inspector->getFrames();

        $code = $inspector->getException()->getCode();

        if ($inspector->getException() instanceof \ErrorException) {
            // ErrorExceptions wrap the php-error types within the "severity" property
            $code = Misc::translateErrorCode($inspector->getException()->getSeverity());
        }

        // List of variables that will be passed to the layout template.
        $vars = array(
            "page_title" => $this->getPageTitle(),

            // @todo: Asset compiler
            "stylesheet" => file_get_contents($cssFile),
            "zepto" => file_get_contents($zeptoFile),
            "javascript" => file_get_contents($jsFile),

            // Template paths:
            "header" => $this->getResource("views/header.html.php"),
            "frame_list" => $this->getResource("views/frame_list.html.php"),
            "frame_code" => $this->getResource("views/frame_code.html.php"),
            "env_details" => $this->getResource("views/env_details.html.php"),

            "title" => $this->getPageTitle(),
            "name" => explode("\\", $inspector->getExceptionName()),
            "message" => $inspector->getException()->getMessage(),
            "code" => $code,
            "plain_exception" => Formatter::formatExceptionPlain($inspector),
            "frames" => $frames,
            "has_frames" => !!count($frames),
            "handler" => $this,
            "handlers" => $this->getRun()->getHandlers(),

            "tables" => array(
                "GET Data" => $_GET,
                "POST Data" => $_POST,
                "Files" => $_FILES,
                "Cookies" => $_COOKIE,
                "Session" => isset($_SESSION) ? $_SESSION : array(),
                "Server/Request Data" => $_SERVER,
                "Environment Variables" => $_ENV,
            ),
        );

        if (isset($customCssFile)) {
            $vars["stylesheet"] .= file_get_contents($customCssFile);
        }

        // Add extra entries list of data tables:
        // @todo: Consolidate addDataTable and addDataTableCallback
        $extraTables = array_map(function ($table) {
            return $table instanceof \Closure ? $table() : $table;
        }, $this->getDataTables());
        $vars["tables"] = array_merge($extraTables, $vars["tables"]);

        $helper->setVariables($vars);
        $helper->render($templateFile);

        $output = ob_get_contents();
        $this->afterHandle($output);

        return Handler::DONE;
    }

    /**
     * 操作完成后
     * @param $output
     * @throws \Exception
     */
    protected function afterHandle($output)
    {
        $logFile = $this->getErrorPath() . $this->getErrorName();
        $this->saveFile($logFile, $output);

        $dingdingTemp = file_get_contents(__DIR__ . '/Templates/dingding.md');

        $http = ((isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] == 'on') || (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && $_SERVER['HTTP_X_FORWARDED_PROTO'] == 'https')) ? 'https://' : "http://";

        $dingdingTemp = str_ireplace('{{ code }}', $this->getInspector()->getException()->getCode(), $dingdingTemp);
        $dingdingTemp = str_ireplace('{{ message }}', $this->getInspector()->getException()->getMessage(), $dingdingTemp);
        $dingdingTemp = str_ireplace('{{ url }}', $http . $_SERVER['HTTP_HOST'], $dingdingTemp);
        $dingdingTemp = str_ireplace('{{ time }}', date('Y-m-d H:i:s'), $dingdingTemp);
        if (defined('BasePath')) {
            $dingdingTemp = str_ireplace('{{ link }}', str_ireplace(BasePath, '', $http . $logFile), $dingdingTemp);
        } else {
            $dingdingTemp = str_ireplace('{{ link }}', $http . $_SERVER['HTTP_HOST'] . '/' . $logFile, $dingdingTemp);
        }

        $this->postToDingDing($dingdingTemp);

    }

    protected function postToDingDing($content)
    {
        $msg = json_encode(['msgtype' => 'markdown', 'markdown' => ['text' => $content, 'title' => '项目错误报告']]);
        return $this->curlPost($this->dingDingApiUrl, $msg, '', array('Content-Type: application/json; charset=utf-8'));
    }

    /**
     * 保存文件
     * @param $logFile
     * @param $output
     * @return bool|int
     */
    protected function saveFile($logFile, $output)
    {
        return file_put_contents($logFile, $output);
    }

    /**
     * 获取错误文件
     * @return string
     */
    protected function getErrorName()
    {
        try {
            $name = Uuid::uuid1()->toString() . '.html';
        } catch (\Exception $exception) {
            $name = time() . '.html';
        }
        return $name;
    }

    /**
     * 获取错误目录
     * @return string
     */
    protected function getErrorPath()
    {
        if (defined('BasePath')) {
            $path = BasePath . "error_log/";
        } else {
            $path = './error_log/';
        }

        $path .= date('Y-m/d/');

        if (!file_exists($path)) {
            mkdir($path, 0755, true);
        }

        return $path;
    }

    /**
     * @param $url
     * @param $curlPost
     * @param string $cookie_str
     * @param array $header_ary
     * @param int $conn_timeout
     * @param int $timeout
     * @return bool|string
     */
    function curlPost($url, $curlPost, $cookie_str = '', $header_ary = array(), $conn_timeout = 3, $timeout = 15)
    {
        $curl = curl_init();
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($curl, CURLOPT_CONNECTTIMEOUT, $conn_timeout);
        curl_setopt($curl, CURLOPT_TIMEOUT, $timeout);
        curl_setopt($curl, CURLOPT_POST, true);
        curl_setopt($curl, CURLOPT_POSTFIELDS, $curlPost);
        if (substr($url, 0, 5) == 'https') {
            curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        }
        if ($cookie_str) {
            curl_setopt($curl, CURLOPT_COOKIE, $cookie_str);
        }
        //header
        $flag_ua = 0;
        $flag_ref = 0;
        foreach ($header_ary as $header) {
            if (strpos(strtolower($header), 'user-agent') !== false) {
                $flag_ua = 1;
            }
            if (strpos(strtolower($header), 'referer') !== false) {
                $flag_ref = 1;
            }
        }
        if (!$flag_ua) {
            $header_ary[] = 'User-Agent: ' . (isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] . ' ' : '') . (defined('PHP_UA') ? PHP_UA : 'eowyn') . '/' . PHP_VERSION . ' (' . PHP_OS . ')';
        }
        if (!$flag_ref && isset($_SERVER["HTTP_REFERER"]) && $_SERVER["HTTP_REFERER"]) {
            $header_ary[] = $_SERVER["HTTP_REFERER"];
        }
        if ($header_ary) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $header_ary);
        }
        $return_str = curl_exec($curl);
        curl_close($curl);
        return $return_str;
    }

    /**
     * Adds an entry to the list of tables displayed in the template.
     * The expected data is a simple associative array. Any nested arrays
     * will be flattened with print_r
     * @param string $label
     * @param array $data
     */
    public function addDataTable($label, array $data)
    {
        $this->extraTables[$label] = $data;
    }

    /**
     * Lazily adds an entry to the list of tables displayed in the table.
     * The supplied callback argument will be called when the error is rendered,
     * it should produce a simple associative array. Any nested arrays will
     * be flattened with print_r.
     *
     * @throws InvalidArgumentException If $callback is not callable
     * @param  string $label
     * @param  callable $callback Callable returning an associative array
     */
    public function addDataTableCallback($label, /* callable */
                                         $callback)
    {
        if (!is_callable($callback)) {
            throw new InvalidArgumentException('Expecting callback argument to be callable');
        }

        $this->extraTables[$label] = function () use ($callback) {
            try {
                $result = call_user_func($callback);

                // Only return the result if it can be iterated over by foreach().
                return is_array($result) || $result instanceof \Traversable ? $result : array();
            } catch (\Exception $e) {
                // Don't allow failure to break the rendering of the original exception.
                return array();
            }
        };
    }

    /**
     * Returns all the extra data tables registered with this handler.
     * Optionally accepts a 'label' parameter, to only return the data
     * table under that label.
     * @param  string|null $label
     * @return array[]|callable
     */
    public function getDataTables($label = null)
    {
        if ($label !== null) {
            return isset($this->extraTables[$label]) ?
                $this->extraTables[$label] : array();
        }

        return $this->extraTables;
    }

    /**
     * Allows to disable all attempts to dynamically decide whether to
     * handle or return prematurely.
     * Set this to ensure that the handler will perform no matter what.
     * @param  bool|null $value
     * @return bool|null
     */
    public function handleUnconditionally($value = null)
    {
        if (func_num_args() == 0) {
            return $this->handleUnconditionally;
        }

        $this->handleUnconditionally = (bool)$value;
    }

    /**
     * Adds an editor resolver, identified by a string
     * name, and that may be a string path, or a callable
     * resolver. If the callable returns a string, it will
     * be set as the file reference's href attribute.
     *
     * @example
     *  $run->addEditor('macvim', "mvim://open?url=file://%file&line=%line")
     * @example
     *   $run->addEditor('remove-it', function($file, $line) {
     *       unlink($file);
     *       return "http://stackoverflow.com";
     *   });
     * @param string $identifier
     * @param string $resolver
     */
    public function addEditor($identifier, $resolver)
    {
        $this->editors[$identifier] = $resolver;
    }

    /**
     * Set the editor to use to open referenced files, by a string
     * identifier, or a callable that will be executed for every
     * file reference, with a $file and $line argument, and should
     * return a string.
     *
     * @example
     *   $run->setEditor(function($file, $line) { return "file:///{$file}"; });
     * @example
     *   $run->setEditor('sublime');
     *
     * @throws InvalidArgumentException If invalid argument identifier provided
     * @param  string|callable $editor
     */
    public function setEditor($editor)
    {
        if (!is_callable($editor) && !isset($this->editors[$editor])) {
            throw new InvalidArgumentException(
                "Unknown editor identifier: $editor. Known editors:" .
                implode(",", array_keys($this->editors))
            );
        }

        $this->editor = $editor;
    }

    /**
     * Given a string file path, and an integer file line,
     * executes the editor resolver and returns, if available,
     * a string that may be used as the href property for that
     * file reference.
     *
     * @throws InvalidArgumentException If editor resolver does not return a string
     * @param  string $filePath
     * @param  int $line
     * @return string|bool
     */
    public function getEditorHref($filePath, $line)
    {
        $editor = $this->getEditor($filePath, $line);

        if (!$editor) {
            return false;
        }

        // Check that the editor is a string, and replace the
        // %line and %file placeholders:
        if (!isset($editor['url']) || !is_string($editor['url'])) {
            throw new UnexpectedValueException(
                __METHOD__ . " should always resolve to a string or a valid editor array; got something else instead."
            );
        }

        $editor['url'] = str_replace("%line", rawurlencode($line), $editor['url']);
        $editor['url'] = str_replace("%file", rawurlencode($filePath), $editor['url']);

        return $editor['url'];
    }

    /**
     * Given a boolean if the editor link should
     * act as an Ajax request. The editor must be a
     * valid callable function/closure
     *
     * @throws UnexpectedValueException  If editor resolver does not return a boolean
     * @param  string $filePath
     * @param  int $line
     * @return bool
     */
    public function getEditorAjax($filePath, $line)
    {
        $editor = $this->getEditor($filePath, $line);

        // Check that the ajax is a bool
        if (!isset($editor['ajax']) || !is_bool($editor['ajax'])) {
            throw new UnexpectedValueException(
                __METHOD__ . " should always resolve to a bool; got something else instead."
            );
        }
        return $editor['ajax'];
    }

    /**
     * Given a boolean if the editor link should
     * act as an Ajax request. The editor must be a
     * valid callable function/closure
     *
     * @throws UnexpectedValueException  If editor resolver does not return a boolean
     * @param  string $filePath
     * @param  int $line
     * @return mixed
     */
    protected function getEditor($filePath, $line)
    {
        if ($this->editor === null && !is_string($this->editor) && !is_callable($this->editor)) {
            return false;
        } else if (is_string($this->editor) && isset($this->editors[$this->editor]) && !is_callable($this->editors[$this->editor])) {
            return array(
                'ajax' => false,
                'url' => $this->editors[$this->editor],
            );
        } else if (is_callable($this->editor) || (isset($this->editors[$this->editor]) && is_callable($this->editors[$this->editor]))) {
            if (is_callable($this->editor)) {
                $callback = call_user_func($this->editor, $filePath, $line);
            } else {
                $callback = call_user_func($this->editors[$this->editor], $filePath, $line);
            }

            return array(
                'ajax' => isset($callback['ajax']) ? $callback['ajax'] : false,
                'url' => (is_array($callback) ? $callback['url'] : $callback),
            );
        }

        return false;
    }

    /**
     * @param  string $title
     * @return void
     */
    public function setPageTitle($title)
    {
        $this->pageTitle = (string)$title;
    }

    /**
     * @return string
     */
    public function getPageTitle()
    {
        return $this->pageTitle;
    }

    /**
     * Adds a path to the list of paths to be searched for
     * resources.
     *
     * @throws InvalidArgumnetException If $path is not a valid directory
     *
     * @param  string $path
     * @return void
     */
    public function addResourcePath($path)
    {
        if (!is_dir($path)) {
            throw new InvalidArgumentException(
                "'$path' is not a valid directory"
            );
        }

        array_unshift($this->searchPaths, $path);
    }

    /**
     * Adds a custom css file to be loaded.
     *
     * @param  string $name
     * @return void
     */
    public function addCustomCss($name)
    {
        $this->customCss = $name;
    }

    /**
     * @return array
     */
    public function getResourcePaths()
    {
        return $this->searchPaths;
    }

    /**
     * Finds a resource, by its relative path, in all available search paths.
     * The search is performed starting at the last search path, and all the
     * way back to the first, enabling a cascading-type system of overrides
     * for all resources.
     *
     * @throws RuntimeException If resource cannot be found in any of the available paths
     *
     * @param  string $resource
     * @return string
     */
    protected function getResource($resource)
    {
        // If the resource was found before, we can speed things up
        // by caching its absolute, resolved path:
        if (isset($this->resourceCache[$resource])) {
            return $this->resourceCache[$resource];
        }

        // Search through available search paths, until we find the
        // resource we're after:
        foreach ($this->searchPaths as $path) {
            $fullPath = $path . "/$resource";

            if (is_file($fullPath)) {
                // Cache the result:
                $this->resourceCache[$resource] = $fullPath;
                return $fullPath;
            }
        }

        // If we got this far, nothing was found.
        throw new RuntimeException(
            "Could not find resource '$resource' in any resource paths."
            . "(searched: " . join(", ", $this->searchPaths) . ")"
        );
    }

    /**
     * @deprecated
     *
     * @return string
     */
    public function getResourcesPath()
    {
        $allPaths = $this->getResourcePaths();

        // Compat: return only the first path added
        return end($allPaths) ?: null;
    }

    /**
     * @deprecated
     *
     * @param  string $resourcesPath
     * @return void
     */
    public function setResourcesPath($resourcesPath)
    {
        $this->addResourcePath($resourcesPath);
    }
}