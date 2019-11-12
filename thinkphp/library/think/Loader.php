<?php
// +----------------------------------------------------------------------
// | ThinkPHP [ WE CAN DO IT JUST THINK ]
// +----------------------------------------------------------------------
// | Copyright (c) 2006~2018 http://thinkphp.cn All rights reserved.
// +----------------------------------------------------------------------
// | Licensed ( http://www.apache.org/licenses/LICENSE-2.0 )
// +----------------------------------------------------------------------
// | Author: liu21st <liu21st@gmail.com>
// +----------------------------------------------------------------------

namespace think;

use think\exception\ClassNotFoundException;

class Loader
{
    /**
     * 类名映射信息
     * @var array
     */
    protected static $classMap = [];

    /**
     * 类库别名
     * @var array
     */
    protected static $classAlias = [];

    /**
     * PSR-4
     * @var array
     */
    private static $prefixLengthsPsr4 = [];
    private static $prefixDirsPsr4    = [];
    private static $fallbackDirsPsr4  = [];

    /**
     * PSR-0
     * @var array
     */
    private static $prefixesPsr0     = [];
    private static $fallbackDirsPsr0 = [];

    /**
     * 需要加载的文件
     * @var array
     */
    private static $files = [];

    /**
     * Composer安装路径
     * @var string
     */
    private static $composerPath;

    // 获取应用根目录
    public static function getRootPath()
    {
        //PHP_SAPI  php判断解析php服务是由那种服务器软件，是采用那种协议  （PHP运行环境检测）
        if ('cli' == PHP_SAPI) {
            //realpath 返回文件绝对路径   如果PHP_SAPI为命令行
            //$_SERVER['argv'][0] cli模式（命令行）下，第一个参数$_SERVER['argv'][0]是脚本名，其余的是传递给脚本的参数
            // PHP会在脚本运行时根据参数创建两个特殊的变量，$argc是一个整数，表示参数个数，
            //$argv是一个数组变量，包含每个参数的值，它的第一个元素一直是PHP脚本的名字，
            //你传递给脚本的第三个参数即 $_SERVER['argv'] [2]
            $scriptName = realpath($_SERVER['argv'][0]);
//            echo $scriptName.'-----';die;
        } else {
//            $_SERVER['SCRIPT_FILENAME'] 显示的是正在执行的文件，好比引入a，在$_SERVER['SCRIPT_FILENAME']输出的不是a，是根文件，与__FILE__不同，__FILE__会输出a
            $scriptName = $_SERVER['SCRIPT_FILENAME'];
//            echo $scriptName.'++++';die;
        }
        //dirname输出上级目录 realpath 返回绝对路径
        $path = realpath(dirname($scriptName));
        //应该是判断是不是根目录
        if (!is_file($path . DIRECTORY_SEPARATOR . 'think')) {
            //如果不是根目录  获取上级目录
            $path = dirname($path);
        }
        //返回 项目根目录的绝对路径，不带文件名
        return $path . DIRECTORY_SEPARATOR;
    }

    // 注册自动加载机制
    public static function register($autoload = '')
    {
        //方法中执行了内置函数apl_autoloader_register(),此函数的第一个参数接收一个匿名函数，或者回调方法，作用是每当php
        //调用了不存在的类时就会执行此函数当中的回调函数，
        //且携带一个参数，值是引入的未存在的带命名空间的类名（如果有类名空间），如在base.php20行注册异常机制，那么这是携带的参数值是：think\Error.
        // 注册系统自动加载
        spl_autoload_register($autoload ?: 'think\\Loader::autoload', true, true);

        ////返回 项目根目录的绝对路径，不带文件名
        $rootPath = self::getRootPath();
        //composer的目录
        self::$composerPath = $rootPath . 'vendor' . DIRECTORY_SEPARATOR . 'composer' . DIRECTORY_SEPARATOR;

        // Composer自动加载支持
        if (is_dir(self::$composerPath)) {
            if (is_file(self::$composerPath . 'autoload_static.php')) {
                require self::$composerPath . 'autoload_static.php';

                $declaredClass = get_declared_classes();
                $composerClass = array_pop($declaredClass);

                foreach (['prefixLengthsPsr4', 'prefixDirsPsr4', 'fallbackDirsPsr4', 'prefixesPsr0', 'fallbackDirsPsr0', 'classMap', 'files'] as $attr) {
                    if (property_exists($composerClass, $attr)) {
                        self::${$attr} = $composerClass::${$attr};
                    }
                }
            } else {
                self::registerComposerLoader(self::$composerPath);
            }
        }

        // 注册命名空间定义
        self::addNamespace([
            //__DIR__  当前执行文件的目录(理解为使用这个函数的文件的真实路径)
            'think'  => __DIR__,
            'traits' => dirname(__DIR__) . DIRECTORY_SEPARATOR . 'traits',
        ]);

        // 加载类库映射文件
        if (is_file($rootPath . 'runtime' . DIRECTORY_SEPARATOR . 'classmap.php')) {
            self::addClassMap(__include_file($rootPath . 'runtime' . DIRECTORY_SEPARATOR . 'classmap.php'));
        }

        // 自动加载extend目录
        self::addAutoLoadDir($rootPath . 'extend');
    }

    // 自动加载
    public static function autoload($class)
    {
        if (isset(self::$classAlias[$class])) {
            return class_alias(self::$classAlias[$class], $class);
        }

        if ($file = self::findFile($class)) {

            // Win环境严格区分大小写
            if (strpos(PHP_OS, 'WIN') !== false && pathinfo($file, PATHINFO_FILENAME) != pathinfo(realpath($file), PATHINFO_FILENAME)) {
                return false;
            }

            __include_file($file);
            return true;
        }
    }

    /**
     * 查找文件
     * @access private
     * @param  string $class
     * @return string|false
     */
    private static function findFile($class)
    {
        if (!empty(self::$classMap[$class])) {
            // 类库映射
            return self::$classMap[$class];
        }

        // 查找 PSR-4
        $logicalPathPsr4 = strtr($class, '\\', DIRECTORY_SEPARATOR) . '.php';

        $first = $class[0];
        if (isset(self::$prefixLengthsPsr4[$first])) {
            foreach (self::$prefixLengthsPsr4[$first] as $prefix => $length) {
                if (0 === strpos($class, $prefix)) {
                    foreach (self::$prefixDirsPsr4[$prefix] as $dir) {
                        if (is_file($file = $dir . DIRECTORY_SEPARATOR . substr($logicalPathPsr4, $length))) {
                            return $file;
                        }
                    }
                }
            }
        }

        // 查找 PSR-4 fallback dirs
        foreach (self::$fallbackDirsPsr4 as $dir) {
            if (is_file($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr4)) {
                return $file;
            }
        }

        // 查找 PSR-0
        if (false !== $pos = strrpos($class, '\\')) {
            // namespaced class name
            $logicalPathPsr0 = substr($logicalPathPsr4, 0, $pos + 1)
            . strtr(substr($logicalPathPsr4, $pos + 1), '_', DIRECTORY_SEPARATOR);
        } else {
            // PEAR-like class name
            $logicalPathPsr0 = strtr($class, '_', DIRECTORY_SEPARATOR) . '.php';
        }

        if (isset(self::$prefixesPsr0[$first])) {
            foreach (self::$prefixesPsr0[$first] as $prefix => $dirs) {
                if (0 === strpos($class, $prefix)) {
                    foreach ($dirs as $dir) {
                        if (is_file($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr0)) {
                            return $file;
                        }
                    }
                }
            }
        }

        // 查找 PSR-0 fallback dirs
        foreach (self::$fallbackDirsPsr0 as $dir) {
            if (is_file($file = $dir . DIRECTORY_SEPARATOR . $logicalPathPsr0)) {
                return $file;
            }
        }

        return self::$classMap[$class] = false;
    }

    // 注册classmap
    public static function addClassMap($class, $map = '')
    {
        if (is_array($class)) {
            self::$classMap = array_merge(self::$classMap, $class);
        } else {
            self::$classMap[$class] = $map;
        }
    }

    // 注册命名空间
    public static function addNamespace($namespace, $path = '')
    {
        if (is_array($namespace)) {
            //如果是数组的话， （$prefix是前缀，$paths是路径）
            foreach ($namespace as $prefix => $paths) {
                //rtrim从右面去除字符，默认是空格啥的，如果加参数就是从右面去除一个规定的字符
                self::addPsr4($prefix . '\\', rtrim($paths, DIRECTORY_SEPARATOR), true);
            }
        } else {
            self::addPsr4($namespace . '\\', rtrim($path, DIRECTORY_SEPARATOR), true);
        }
    }

    // 添加Ps0空间
    private static function addPsr0($prefix, $paths, $prepend = false)
    {
        if (!$prefix) {
            if ($prepend) {
                self::$fallbackDirsPsr0 = array_merge(
                    (array) $paths,
                    self::$fallbackDirsPsr0
                );
            } else {
                self::$fallbackDirsPsr0 = array_merge(
                    self::$fallbackDirsPsr0,
                    (array) $paths
                );
            }

            return;
        }

        $first = $prefix[0];
        if (!isset(self::$prefixesPsr0[$first][$prefix])) {
            self::$prefixesPsr0[$first][$prefix] = (array) $paths;

            return;
        }

        if ($prepend) {
            self::$prefixesPsr0[$first][$prefix] = array_merge(
                (array) $paths,
                self::$prefixesPsr0[$first][$prefix]
            );
        } else {
            self::$prefixesPsr0[$first][$prefix] = array_merge(
                self::$prefixesPsr0[$first][$prefix],
                (array) $paths
            );
        }
    }

    // 添加Psr4空间
    private static function addPsr4($prefix, $paths, $prepend = false)
    {
        // $prepend 是否优先考虑
        //$paths是路径去掉最后一个系统分隔符
        if (!$prefix) {
            //如果没有参数的时候
            // Register directories for the root namespace.
            //如果优先考虑就把新的路径加到这个数组的钱前面
            if ($prepend) {
                self::$fallbackDirsPsr4 = array_merge(
                    //强制把路径转换为数组然后把路径加到$fallbackDirsPsr4这个数组里
                    (array) $paths,
                    self::$fallbackDirsPsr4
                );
            } else {
                self::$fallbackDirsPsr4 = array_merge(
                    self::$fallbackDirsPsr4,
                    (array) $paths
                );
            }
        } elseif (!isset(self::$prefixDirsPsr4[$prefix])) {
            //如果$prefixDirsPsr4这个数组里不存在这个前缀
            // Register directories for a new namespace.
            $length = strlen($prefix);
            if ('\\' !== $prefix[$length - 1]) {
                throw new \InvalidArgumentException("A non-empty PSR-4 prefix must end with a namespace separator.");
            }

            self::$prefixLengthsPsr4[$prefix[0]][$prefix] = $length;
            self::$prefixDirsPsr4[$prefix]                = (array) $paths;
        } elseif ($prepend) {
            // Prepend directories for an already registered namespace.
            self::$prefixDirsPsr4[$prefix] = array_merge(
                (array) $paths,
                self::$prefixDirsPsr4[$prefix]
            );
        } else {
            // Append directories for an already registered namespace.
            self::$prefixDirsPsr4[$prefix] = array_merge(
                self::$prefixDirsPsr4[$prefix],
                (array) $paths
            );
        }
    }

    // 注册自动加载类库目录
    public static function addAutoLoadDir($path)
    {
        self::$fallbackDirsPsr4[] = $path;
    }

    // 注册类别名
    public static function addClassAlias($alias, $class = null)
    {
        if (is_array($alias)) {
            self::$classAlias = array_merge(self::$classAlias, $alias);
        } else {
            self::$classAlias[$alias] = $class;
        }
    }

    // 注册composer自动加载
    public static function registerComposerLoader($composerPath)
    {
        if (is_file($composerPath . 'autoload_namespaces.php')) {
            $map = require $composerPath . 'autoload_namespaces.php';
            foreach ($map as $namespace => $path) {
                self::addPsr0($namespace, $path);
            }
        }

        if (is_file($composerPath . 'autoload_psr4.php')) {
            $map = require $composerPath . 'autoload_psr4.php';
            foreach ($map as $namespace => $path) {
                self::addPsr4($namespace, $path);
            }
        }

        if (is_file($composerPath . 'autoload_classmap.php')) {
            $classMap = require $composerPath . 'autoload_classmap.php';
            if ($classMap) {
                self::addClassMap($classMap);
            }
        }

        if (is_file($composerPath . 'autoload_files.php')) {
            self::$files = require $composerPath . 'autoload_files.php';
        }
    }

    // 加载composer autofile文件
    public static function loadComposerAutoloadFiles()
    {
        foreach (self::$files as $fileIdentifier => $file) {
            if (empty($GLOBALS['__composer_autoload_files'][$fileIdentifier])) {
                __require_file($file);

                $GLOBALS['__composer_autoload_files'][$fileIdentifier] = true;
            }
        }
    }

    /**
     * 字符串命名风格转换
     * type 0 将Java风格转换为C的风格 1 将C风格转换为Java的风格
     * @access public
     * @param  string  $name 字符串
     * @param  integer $type 转换类型
     * @param  bool    $ucfirst 首字母是否大写（驼峰规则）
     * @return string
     */
    public static function parseName($name, $type = 0, $ucfirst = true)
    {
        if ($type) {
            $name = preg_replace_callback('/_([a-zA-Z])/', function ($match) {
                return strtoupper($match[1]);
            }, $name);
            return $ucfirst ? ucfirst($name) : lcfirst($name);
        }

        return strtolower(trim(preg_replace("/[A-Z]/", "_\\0", $name), "_"));
    }

    /**
     * 创建工厂对象实例
     * @access public
     * @param  string $name         工厂类名
     * @param  string $namespace    默认命名空间
     * @return mixed
     */
    public static function factory($name, $namespace = '', ...$args)
    {
        $class = false !== strpos($name, '\\') ? $name : $namespace . ucwords($name);

        if (class_exists($class)) {
            return Container::getInstance()->invokeClass($class, $args);
        } else {
            throw new ClassNotFoundException('class not exists:' . $class, $class);
        }
    }
}

/**
 * 作用范围隔离
 *
 * @param $file
 * @return mixed
 */
function __include_file($file)
{
    return include $file;
}

function __require_file($file)
{
    return require $file;
}
