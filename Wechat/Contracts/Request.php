<?php

// +----------------------------------------------------------------------
// | WeChatDeveloper
// +----------------------------------------------------------------------
// | 版权所有 2014~2018 广州楚才信息科技有限公司 [ http://www.cuci.cc ]
// +----------------------------------------------------------------------
// | 官方网站: http://think.ctolog.com
// +----------------------------------------------------------------------
// | 开源协议 ( https://mit-license.org )
// +----------------------------------------------------------------------
// | github开源项目：https://github.com/zoujingli/WeChatDeveloper
// +----------------------------------------------------------------------

namespace Wechat\Contracts;

use Wechat\Exceptions\InvalidArgumentException;
use Wechat\Exceptions\InvalidResponseException;
use Wechat\Exceptions\LocalCacheException;

/**
 * 网络请求支持
 * Class Request
 * @package Wechat
 */
class Request
{
    /**
     * 缓存路径
     * @var null
     */
    public static $cache_path = null;

    /**
     * 根据文件后缀获取文件MINE
     * @param array $ext 文件后缀
     * @param array $mine 文件后缀MINE信息
     * @return string
     * @throws LocalCacheException
     */
    public static function getExtMine($ext, $mine = [])
    {
        $mines = self::getMines();
        foreach (is_string($ext) ? explode(',', $ext) : $ext as $e) {
            $mine[] = isset($mines[strtolower($e)]) ? $mines[strtolower($e)] : 'application/octet-stream';
        }
        return join(',', array_unique($mine));
    }

    /**
     * 获取所有文件扩展的mine
     * @return array
     * @throws LocalCacheException
     */
    private static function getMines()
    {
        $mines = self::getCache('all_ext_mine');
        if (empty($mines)) {
            $content = file_get_contents('http://svn.apache.org/repos/asf/httpd/httpd/trunk/docs/conf/mime.types');
            preg_match_all('#^([^\s]{2,}?)\s+(.+?)$#ism', $content, $matches, PREG_SET_ORDER);
            foreach ($matches as $match) {
                foreach (explode(" ", $match[2]) as $ext) {
                    $mines[$ext] = $match[1];
                }
            }
            self::setCache('all_ext_mine', $mines);
        }
        return $mines;
    }

    /**
     * 创建CURL文件对象
     * @param $filename
     * @param string $mimetype
     * @param string $postname
     * @return \CURLFile|string
     * @throws LocalCacheException
     */
    public static function createCurlFile($filename, $mimetype = '', $postname = '')
    {
        $basename = $postname ?: basename($filename);
        $basemine = $mimetype ?: self::getExtMine(pathinfo($filename, 4));
        if (function_exists('curl_file_create')) {
            return curl_file_create($filename, $basemine, $basename);
        }
        return "@{$filename};filename={$basename};type={$basemine}";
    }

    /**
     * 以get访问模拟访问
     * @param string $url 访问URL
     * @param array $query GET数
     * @param array $options
     * @return bool|string
     */
    public static function get($url, $query = [], $options = [])
    {
        $options['query'] = $query;
        return self::doRequest('get', $url, $options);
    }

    /**
     * 以post访问模拟访问
     * @param string $url 访问URL
     * @param array $data POST数据
     * @param array $options
     * @return bool|string
     */
    public static function post($url, $data = [], $options = [])
    {
        $options['data'] = $data;
        return self::doRequest('post', $url, $options);
    }

    /**
     * 数组转XML内容
     * @param array $data
     * @return string
     */
    public static function toXml($data)
    {
        return "<xml>" . self::_data_to_xml($data) . "</xml>";
    }

    /**
     * XML内容生成
     * @param array $data 数据
     * @param string $content
     * @return string
     */
    private static function _data_to_xml($data, $content = '')
    {
        foreach ($data as $key => $val) {
            $content .= "<{$key}>";
            if (is_array($val) || is_object($val)) {
                $content .= self::_data_to_xml($val);
            } elseif (is_string($val)) {
                $content .= '<![CDATA[' . preg_replace("/[\\x00-\\x08\\x0b-\\x0c\\x0e-\\x1f]/", '', $val) . ']]>';
            } else {
                $content .= $val;
            }
            $content .= "</{$key}>";
        }
        return $content;
    }

    /**
     * 解析XML内容到数组
     * @param string $xml
     * @return array
     */
    public static function fromXml($xml)
    {
        return json_decode(self::toJson(simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA)), true);
    }

    /**
     * 数组转xml内容
     * @param array $data
     * @return null|string|string
     */
    public static function toJson($data)
    {
        return preg_replace_callback('/\\\\u([0-9a-f]{4})/i', function ($matches) {
            return mb_convert_encoding(pack("H*", $matches[1]), "UTF-8", "UCS-2BE");
        }, json_encode($data));
    }

    /**
     * 解析JSON内容到数组
     * @param string $json
     * @return array
     * @throws InvalidResponseException
     */
    public static function fromJson($json)
    {
        $result = json_decode($json, true);
        if (empty($result)) {
            throw new InvalidResponseException('invalid response.', '0');
        }
        if (!empty($result['errcode'])) {
            throw new InvalidResponseException($result['errmsg'], $result['errcode'], $result);
        }
        return $result;
    }

    /**
     * CURL模拟网络请求
     * @param string $method 请求方法
     * @param string $url 请求方法
     * @param array $options 请求参数[headers,data,ssl_cer,ssl_key]
     * @return bool|string
     */
    protected static function doRequest($method, $url, $options = [])
    {
        $curl = curl_init();
        // GET参数设置
        if (!empty($options['query'])) {
            $url .= (stripos($url, '?') !== false ? '&' : '?') . http_build_query($options['query']);
        }
        // POST数据设置
        if (strtolower($method) === 'post') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, self::build($options['data']));
        }
        // CURL头信息设置
        if (!empty($options['headers'])) {
            curl_setopt($curl, CURLOPT_HTTPHEADER, $options['headers']);
        }
        // 证书文件设置
        if (!empty($options['ssl_cer'])) {
            if (file_exists($options['ssl_cer'])) {
                curl_setopt($curl, CURLOPT_SSLCERTTYPE, 'PEM');
                curl_setopt($curl, CURLOPT_SSLCERT, $options['ssl_cer']);
            } else {
                throw new InvalidArgumentException("Certificate files that do not exist. --- [{$options['ssl_cer']}]");
            }
        }
        // 证书文件设置
        if (!empty($options['ssl_key'])) {
            if (file_exists($options['ssl_key'])) {
                curl_setopt($curl, CURLOPT_SSLKEYTYPE, 'PEM');
                curl_setopt($curl, CURLOPT_SSLKEY, $options['ssl_key']);
            } else {
                throw new InvalidArgumentException("Certificate files that do not exist. --- [{$options['ssl_key']}]");
            }
        }
        curl_setopt($curl, CURLOPT_URL, $url);
        curl_setopt($curl, CURLOPT_TIMEOUT, 60);
        curl_setopt($curl, CURLOPT_HEADER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYPEER, false);
        curl_setopt($curl, CURLOPT_SSL_VERIFYHOST, false);
        curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
        list($content, $status) = [curl_exec($curl), curl_getinfo($curl), curl_close($curl)];
        return (intval($status["http_code"]) === 200) ? $content : false;
    }

    /**
     * POST数据过滤处理
     * @param array $data
     * @return array
     */
    private static function build($data)
    {
        if (is_array($data)) {
            foreach ($data as $key => $value) {
                if (is_string($value) && class_exists('CURLFile', false) && stripos($value, '@') === 0) {
                    $filename = realpath(trim($value, '@'));
                    if ($filename && file_exists($filename)) {
                        $data[$key] = new \CURLFile($filename);
                    }
                }
            }
        }
        return $data;
    }

    /**
     * 缓存配置与存储
     * @param string $name 缓存名称
     * @param string $value 缓存内容
     * @param int $expired 缓存时间(0表示永久缓存)
     * @throws LocalCacheException
     */
    public static function setCache($name, $value = '', $expired = 3600)
    {
        $cache_file = self::getCacheName($name);
        $content = serialize(['name' => $name, 'value' => $value, 'expired' => time() + intval($expired)]);
        if (!file_put_contents($cache_file, $content)) {
            throw new LocalCacheException('local cache error.', '0');
        }
    }

    /**
     * 获取缓存内容
     * @param string $name 缓存名称
     * @return null|mixed
     */
    public static function getCache($name)
    {
        $cache_file = self::getCacheName($name);
        if (file_exists($cache_file) && ($content = file_get_contents($cache_file))) {
            $data = unserialize($content);
            if (isset($data['expired']) && (intval($data['expired']) === 0 || intval($data['expired']) >= time())) {
                return $data['value'];
            }
            self::delCache($name);
        }
        return null;
    }

    /**
     * 移除缓存文件
     * @param string $name 缓存名称
     * @return bool
     */
    public static function delCache($name)
    {
        $cache_file = self::getCacheName($name);
        return file_exists($cache_file) ? unlink($cache_file) : true;
    }

    /**
     * 应用缓存目录
     * @param string $name
     * @return string
     */
    private static function getCacheName($name)
    {
        if (empty(self::$cache_path)) {
            self::$cache_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . 'Cache' . DIRECTORY_SEPARATOR;
        }
        self::$cache_path = rtrim(self::$cache_path, '/\\') . DIRECTORY_SEPARATOR;
        file_exists(self::$cache_path) || mkdir(self::$cache_path, 0755, true);
        return self::$cache_path . md5($name);
    }
}