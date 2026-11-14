<?php

/**
 * 舞小枫 · 舞立方助手 —— 独立网页版公共库
 *
 * 由 Discuz 插件版移植而来，去掉论坛依赖后：
 *   1. 配置改为读取站点根目录的 config.php；
 *   2. 身份改为浏览器 Cookie 自动分配（无需注册 / 登录论坛）；
 *   3. 本地状态（登录凭证 / 登录会话）仍存放在 data/ 目录；
 *   4. 通用 HTTP 客户端（curl 优先，退回 stream）保持不变；
 *   5. 统一的 JSON 输出与错误类型保持不变。
 *
 * 兼容目标：PHP 5.6+（已在 PHP 8.3 实测）。
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

if(!defined('DFB_IDENTIFIER')) {
	define('DFB_IDENTIFIER', 'dancefengbot');
}
if(!defined('DFB_ROOT_DIR')) {
	define('DFB_ROOT_DIR', dirname(__DIR__).'/');
}
if(!defined('DFB_PLUGIN_DIR')) {
	define('DFB_PLUGIN_DIR', DFB_ROOT_DIR);
}
if(!defined('DFB_VERSION')) {
	define('DFB_VERSION', '2026.11.05');
}
/* 舞立方官方接口根地址 */
if(!defined('DFB_CUBE_BASE')) {
	define('DFB_CUBE_BASE', 'https://dancedemo.shenghuayule.com/Dance');
}

/**
 * 业务异常：携带 HTTP 状态码，由接口层转换成对应的 JSON 响应。
 */
class DfbError extends Exception {

	/** @var int */
	public $status;

	/** @var array 额外的结构化信息（例如 loginRequired） */
	public $data;

	/**
	 * @param string $message 提示文案（直接展示给用户）
	 * @param int    $status  HTTP 状态码
	 * @param array  $data    额外的结构化信息
	 */
	public function __construct($message, $status = 400, $data = array()) {
		parent::__construct($message);
		$this->status = intval($status);
		$this->data = is_array($data) ? $data : array();
	}
}

/* ======================================================================
 * 一、基础工具
 * ==================================================================== */

/**
 * 递归去除 Discuz 对 GPC 自动添加的反斜杠。
 *
 * @param mixed $value
 * @return mixed
 */
function dfb_stripslashes_deep($value) {
	if(is_array($value)) {
		foreach($value as $key => $item) {
			$value[$key] = dfb_stripslashes_deep($item);
		}
		return $value;
	}
	if(is_string($value)) {
		return stripslashes($value);
	}
	return $value;
}

/**
 * 把任意值转成用于展示的纯文本（去掉标签与换行）。
 *
 * @param mixed $text
 * @return string
 */
function dfb_plain($text) {
	if(!is_scalar($text)) {
		return '';
	}
	$text = strip_tags((string)$text);
	$text = str_replace(array("\r", "\n", "\t"), ' ', $text);
	return trim($text);
}

/**
 * 把任意字符串规整成合法的 UTF-8。
 *
 * 乱码的根源通常是「字节是 GBK、却被当成 UTF-8 解码」（或反过来）。
 * 这里统一做一次归一化：本来合法的直接放行，不合法的按 GBK / GB18030
 * 重解码，仍然不行就把非法字节丢掉——保证下游 json_encode 永远不会失败。
 *
 * @param string $text
 * @return string
 */
function dfb_utf8_clean($text) {
	if(!is_string($text) || $text === '') {
		return $text;
	}
	/* 已经是合法 UTF-8：直接返回（最常见，零开销） */
	if(preg_match('//u', $text)) {
		return $text;
	}
	if(function_exists('iconv')) {
		foreach(array('GB18030', 'GBK', 'BIG5') as $from) {
			$converted = @iconv($from, 'UTF-8//IGNORE', $text);
			if(is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
				return $converted;
			}
		}
		/* 兜底：丢掉非法字节序列 */
		$converted = @iconv('UTF-8', 'UTF-8//IGNORE', $text);
		if(is_string($converted) && preg_match('//u', $converted)) {
			return $converted;
		}
	}
	if(function_exists('mb_convert_encoding')) {
		$converted = @mb_convert_encoding($text, 'UTF-8', 'GB18030');
		if(is_string($converted) && $converted !== '' && preg_match('//u', $converted)) {
			return $converted;
		}
	}
	/* 最后兜底：只保留可打印 ASCII，避免把二进制塞给浏览器 */
	return (string)preg_replace('/[^\x09\x0A\x0D\x20-\x7E]/', '', $text);
}

/**
 * 递归规整数组 / 对象里的字符串。
 *
 * @param mixed $data
 * @return mixed
 */
function dfb_utf8_clean_deep($data) {
	if(is_string($data)) {
		return dfb_utf8_clean($data);
	}
	if(is_array($data)) {
		$result = array();
		foreach($data as $key => $value) {
			$result[is_string($key) ? dfb_utf8_clean($key) : $key] = dfb_utf8_clean_deep($value);
		}
		return $result;
	}
	return $data;
}

/**
 * 截断文本，用于错误提示里回显脏数据。
 *
 * @param string $text
 * @param int    $length
 * @return string
 */
function dfb_truncate($text, $length = 120) {
	$text = dfb_utf8_clean(dfb_plain($text));
	$text = trim((string)preg_replace('/\s+/u', ' ', $text));
	if(function_exists('mb_substr') && function_exists('mb_strlen')) {
		if(mb_strlen($text, 'UTF-8') > $length) {
			return mb_substr($text, 0, $length, 'UTF-8').'…';
		}
		return $text;
	}
	/* 没有 mbstring 时按 UTF-8 字符切，避免切在多字节中间产生非法字节 */
	$chars = preg_split('//u', $text, -1, PREG_SPLIT_NO_EMPTY);
	if(is_array($chars) && count($chars) > $length) {
		return implode('', array_slice($chars, 0, $length)).'…';
	}
	return $text;
}

/**
 * JSON 编码。
 *
 * **默认把所有非 ASCII 字符转成 \uXXXX**，也就是输出纯 ASCII 的 JSON。
 * 这样无论站点、浏览器、CDN 把 charset 当成 UTF-8 / GBK / Latin-1，
 * 客户端 JSON.parse 出来的中文都是对的——中文乱码问题从根上消失。
 * 代价只是报文变长一点（一个汉字 6 字节），对几百字节的提示语无影响。
 *
 * @param mixed $data
 * @param int   $extra 额外 flags
 * @return string
 */
function dfb_json_encode($data, $extra = 0) {
	$flags = intval($extra);
	/* 兜底：个别字段编码失败时输出 null，而不是整个报文变成 false */
	if(defined('JSON_PARTIAL_OUTPUT_ON_ERROR')) {
		$flags = $flags | JSON_PARTIAL_OUTPUT_ON_ERROR;
	}
	$data = dfb_utf8_clean_deep($data);
	$json = json_encode($data, $flags);
	if($json === false) {
		$json = '{"detail":"\\u6570\\u636e\\u7f16\\u7801\\u5931\\u8d25"}';
	}
	return $json;
}

/**
 * 写入 data/ 文件时使用的 pretty JSON（2 空格缩进）。
 *
 * API 响应仍使用 dfb_json_encode 的紧凑格式；只有落盘文件用这个。
 *
 * @param mixed $data
 * @return string
 */
function dfb_json_pretty($data) {
	$flags = 0;
	foreach(array('JSON_PRETTY_PRINT', 'JSON_UNESCAPED_UNICODE', 'JSON_UNESCAPED_SLASHES', 'JSON_PARTIAL_OUTPUT_ON_ERROR') as $name) {
		if(defined($name)) {
			$flags = $flags | constant($name);
		}
	}
	$data = dfb_utf8_clean_deep($data);
	$json = json_encode($data, $flags);
	if(!is_string($json) || $json === '') {
		return dfb_json_encode($data);
	}
	/* PHP JSON_PRETTY_PRINT 固定 4 空格，这里统一成 2 空格。 */
	return preg_replace_callback('/^( +)/m', function ($matches) {
		return str_repeat(' ', intval(strlen($matches[1]) / 2));
	}, $json);
}

/**
 * HTTP 状态码文案。
 *
 * @param int $status
 * @return string
 */
function dfb_status_text($status) {
	$map = array(
		200 => 'OK', 201 => 'Created', 204 => 'No Content',
		400 => 'Bad Request', 401 => 'Unauthorized', 403 => 'Forbidden',
		404 => 'Not Found', 405 => 'Method Not Allowed', 409 => 'Conflict',
		422 => 'Unprocessable Entity', 429 => 'Too Many Requests',
		500 => 'Internal Server Error', 502 => 'Bad Gateway',
		503 => 'Service Unavailable', 504 => 'Gateway Timeout',
	);
	return isset($map[$status]) ? $map[$status] : 'Status';
}

/**
 * 输出 JSON 并结束请求。
 *
 * 会清空 Discuz 可能开启的输出缓冲区（含 gzip），保证返回给浏览器的
 * 是干净的 JSON 而不是夹杂 HTML 的页面。
 *
 * @param mixed $data
 * @param int   $status
 */
function dfb_json_output($data, $status = 200) {
	while(ob_get_level() > 0) {
		@ob_end_clean();
	}
	$status = intval($status);
	if($status < 100 || $status > 599) {
		$status = 200;
	}
	if(!headers_sent()) {
		$protocol = isset($_SERVER['SERVER_PROTOCOL']) ? $_SERVER['SERVER_PROTOCOL'] : 'HTTP/1.1';
		header($protocol.' '.$status.' '.dfb_status_text($status));
		header('Content-Type: application/json; charset=utf-8');
		/* 不要主动设 Content-Encoding: identity。
		 * 原因：若服务器开启了 zlib.output_compression（PHP 内核层，
		 * ob_end_clean 清不掉），内核会压缩内容；而一旦脚本已设过
		 * Content-Encoding 头，内核就不再覆盖成 gzip，结果「头说不
		 * 压缩、内容却是 gzip 流」，前端拿到一堆乱码字节、JSON.parse
		 * 失败。改成 header_remove 让内核自己决定（压缩就发 gzip 头，
		 * 浏览器自动解压；不压缩就无此头），头与内容永远一致。 */
		@header_remove('Content-Encoding');
		/* 服务端 / 代理即使给 401 加了 HTTP Basic 挑战，也不要让浏览器弹框 */
		@header_remove('WWW-Authenticate');
		header('Cache-Control: no-store, no-cache, must-revalidate, post-check=0, pre-check=0');
		header('Pragma: no-cache');
		header('X-Content-Type-Options: nosniff');
	}
	$GLOBALS['dfb_json_done'] = 1;
	echo dfb_json_encode($data);
	exit;
}

/**
 * 读取 JSON 请求体（前端统一用 JSON 提交）。
 *
 * @return array
 */
function dfb_read_payload() {
	static $payload = null;
	if($payload !== null) {
		return $payload;
	}
	$payload = array();
	$raw = @file_get_contents('php://input');
	if(is_string($raw) && $raw !== '') {
		$decoded = json_decode($raw, true);
		if(is_array($decoded)) {
			$payload = dfb_stripslashes_deep($decoded);
		}
	}
	return $payload;
}

/**
 * 校验 formhash（防跨站请求伪造）。
 *
 * 取不到 FORMHASH 时不拦截，权限层仍然会兜底，避免把用户挡在门外。
 */
function dfb_check_formhash() {
	if(!defined('FORMHASH') || FORMHASH === '') {
		return;
	}
	$sent = '';
	if(isset($_GET['formhash'])) {
		$sent = $_GET['formhash'];
	} elseif(isset($_POST['formhash'])) {
		$sent = $_POST['formhash'];
	} else {
		$payload = dfb_read_payload();
		if(isset($payload['formhash'])) {
			$sent = $payload['formhash'];
		}
	}
	$sent = dfb_stripslashes_deep($sent);
	$sent = is_string($sent) ? trim($sent) : '';
	if($sent === '' || $sent !== FORMHASH) {
		dfb_json_output(array('detail' => '页面校验令牌已失效，请刷新页面后重试。'), 403);
	}
}

/* ======================================================================
 * 二、配置
 * ==================================================================== */

/**
 * 站点配置的默认值（对应根目录 config.php）。
 *
 * @return array
 */
function dfb_default_config() {
	return array(
		'site_name'      => '舞小枫 · 舞立方助手',
		'timezone'       => 'Asia/Shanghai',
		'debug'          => '0',
		'dfb_timeout'    => '20',
		'dfb_ratio_cache_ttl' => '45',
		'dfb_gaode_key'  => '',
		'dfb_client_ids' => '',
		'cors_origin'    => '',
		'admin_enabled'  => '1',
		'admin_user_ids' => '5559326,6794241',
		'admin_password_min_length' => '8',
		'admin_unlock_ttl' => '1800',
		'admin_max_attempts' => '5',
		'admin_lock_seconds' => '600',
		'admin_secret_file' => '',
	);
}

/**
 * 读取站点配置（config.php 未填写的项回落到默认值）。
 *
 * @return array
 */
function dfb_config() {
	static $config = null;
	if($config !== null) {
		return $config;
	}
	$saved = array();
	if(is_file(DFB_ROOT_DIR.'config.php')) {
		$loaded = include DFB_ROOT_DIR.'config.php';
		if(is_array($loaded)) {
			$saved = $loaded;
		}
	}
	$config = array();
	foreach(dfb_default_config() as $key => $default) {
		if(array_key_exists($key, $saved) && $saved[$key] !== null) {
			$config[$key] = is_array($saved[$key])
				? $saved[$key]
				: (is_scalar($saved[$key]) ? (string)$saved[$key] : $default);
		} else {
			$config[$key] = $default;
		}
	}
	return $config;
}

/**
 * 按 config.php 的 cors_origin 输出跨域头（uni-app H5 / 独立前端用）。
 *
 * - 留空：完全不发 CORS 头，网页版行为不变；
 * - 填 `*`：允许任意 Origin（会回显 Origin，配合 credentials）；
 * - 也可以填逗号分隔的白名单，例如 https://app.example.com,http://localhost:8080。
 *
 * 预检 OPTIONS 直接返回 204。
 *
 * @return void
 */
function dfb_send_cors_headers() {
	if(!isset($_SERVER['HTTP_ORIGIN'])) {
		return;
	}
	$origin = trim((string)$_SERVER['HTTP_ORIGIN']);
	if($origin === '') {
		return;
	}
	$config = dfb_config();
	$allowed = isset($config['cors_origin']) ? trim((string)$config['cors_origin']) : '';
	if($allowed === '') {
		return;
	}
	$ok = ($allowed === '*');
	if(!$ok) {
		foreach(explode(',', $allowed) as $item) {
			if(trim($item) === $origin) {
				$ok = true;
				break;
			}
		}
	}
	if(!$ok) {
		return;
	}
	header('Access-Control-Allow-Origin: '.$origin);
	header('Vary: Origin');
	header('Access-Control-Allow-Credentials: true');
	header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
	header('Access-Control-Allow-Headers: Content-Type, X-Requested-With, X-Dfb-Web-Id');
	header('Access-Control-Max-Age: 86400');
	if(isset($_SERVER['REQUEST_METHOD']) && strtoupper((string)$_SERVER['REQUEST_METHOD']) === 'OPTIONS') {
		http_response_code(204);
		exit;
	}
}

/**
 * 站点根目录的「根相对路径」，例如 ``/`` 或 ``/dance/``。
 *
 * 通过当前脚本地址反推，部署在子目录时也不需要改配置。
 *
 * @return string
 */
function dfb_root_relative_path() {
	static $base = null;
	if($base !== null) {
		return $base;
	}
	$script = isset($_SERVER['SCRIPT_NAME']) ? (string)$_SERVER['SCRIPT_NAME'] : '/index.php';
	$script = str_replace('\\', '/', $script);
	$dir = str_replace('\\', '/', dirname($script));
	$dir = preg_replace('#/+#', '/', $dir);
	if($dir === '.' || $dir === '') {
		$dir = '/';
	}
	if(substr($dir, 0, 1) !== '/') {
		$dir = '/'.$dir;
	}
	if(substr($dir, -1) !== '/') {
		$dir .= '/';
	}
	$base = $dir;
	return $base;
}

/**
 * 静态资源目录的地址（根相对路径）。
 *
 * @return string
 */
function dfb_static_base() {
	return dfb_root_relative_path().'static/';
}

/**
 * 站点页面 / 接口地址，例如 ``/index.php``、``/api.php``。
 *
 * @param string $module index | api
 * @return string
 */
function dfb_plugin_url($module = 'index') {
	return dfb_root_relative_path().($module === 'api' ? 'api.php' : 'index.php');
}

/* ======================================================================
 * 三、身份（浏览器 Cookie 自动分配）
 *
 * 独立版没有论坛帐号，用一个随机 32 位十六进制 ID 作为身份：
 *   - 第一次访问时生成，写进 dfb_web_id Cookie（HttpOnly + SameSite=Lax）；
 *   - 账号标识为 web_<id>，只用于本站在 data/state.php 里区分凭证；
 *   - 换浏览器 / 清除 Cookie 会得到新身份，需要重新登录舞立方。
 * ==================================================================== */

/**
 * 生成指定字节数的随机十六进制串。
 *
 * @param int $bytes
 * @return string
 */
function dfb_random_hex($bytes = 16) {
	$bytes = max(8, intval($bytes));
	if(function_exists('random_bytes')) {
		try {
			return bin2hex(random_bytes($bytes));
		} catch(Exception $e) {
			/* 继续走下面的兜底 */
		}
	}
	if(function_exists('openssl_random_pseudo_bytes')) {
		$raw = openssl_random_pseudo_bytes($bytes);
		if(is_string($raw) && $raw !== '') {
			return bin2hex($raw);
		}
	}
	$raw = '';
	while(strlen($raw) < $bytes) {
		$raw .= pack('N', mt_rand(0, 0xFFFFFFFF));
	}
	return bin2hex(substr($raw, 0, $bytes));
}

/**
 * 当前请求是否走 HTTPS。
 *
 * @return bool
 */
function dfb_is_https() {
	if(!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') {
		return true;
	}
	if(isset($_SERVER['HTTP_X_FORWARDED_PROTO'])) {
		$proto = strtolower(trim((string)$_SERVER['HTTP_X_FORWARDED_PROTO']));
		if($proto === 'https') {
			return true;
		}
	}
	$port = isset($_SERVER['SERVER_PORT']) ? intval($_SERVER['SERVER_PORT']) : 0;
	return $port === 443;
}

/**
 * 写一个 Cookie（不依赖 PHP 7.3 的数组签名，兼容更老的 PHP）。
 *
 * @param string $name
 * @param string $value
 * @param int    $expires
 * @param bool   $httpOnly
 * @return void
 */
function dfb_set_cookie($name, $value, $expires, $httpOnly = true) {
	static $sent = array();
	if(isset($sent[$name])) {
		return;
	}
	$sent[$name] = true;
	$cookie = $name.'='.rawurlencode((string)$value)
		.'; Path='.dfb_root_relative_path()
		.'; Max-Age='.max(0, intval($expires) - time())
		.'; SameSite=Lax';
	if($httpOnly) {
		$cookie .= '; HttpOnly';
	}
	if(dfb_is_https()) {
		$cookie .= '; Secure';
	}
	if(!headers_sent()) {
		header('Set-Cookie: '.$cookie, false);
	}
}

/**
 * 当前浏览器 / 客户端身份（session id / 账号标识 / 展示名）。
 *
 * 身份来源优先级：
 *   1. 请求头 `X-Dfb-Web-Id`（uni-app / 独立客户端）；
 *   2. query / POST 参数 `dfb_web_id`（防止代理 / 网关吞掉自定义请求头）；
 *   3. Cookie `dfb_web_id`（网页版）；
 *   4. 都没有时随机生成一个并写 Cookie。
 *
 * @return array
 */
function dfb_web_identity() {
	static $identity = null;
	if($identity !== null) {
		return $identity;
	}
	$sid = '';
	if(isset($_SERVER['HTTP_X_DFB_WEB_ID'])) {
		$sid = strtolower(trim((string)$_SERVER['HTTP_X_DFB_WEB_ID']));
	}
	if(!preg_match('/^[a-f0-9]{32}$/', $sid) && isset($_GET['dfb_web_id'])) {
		$sid = strtolower(trim((string)$_GET['dfb_web_id']));
	}
	if(!preg_match('/^[a-f0-9]{32}$/', $sid) && isset($_POST['dfb_web_id'])) {
		$sid = strtolower(trim((string)$_POST['dfb_web_id']));
	}
	if(!preg_match('/^[a-f0-9]{32}$/', $sid) && isset($_COOKIE['dfb_web_id'])) {
		$sid = strtolower(trim((string)$_COOKIE['dfb_web_id']));
	}
	if(!preg_match('/^[a-f0-9]{32}$/', $sid)) {
		$sid = dfb_random_hex(16);
	}
	/* 把最终身份写回 Cookie；客户端下次不带 X-Dfb-Web-Id 时也能延续 */
	if(!isset($_COOKIE['dfb_web_id']) || strtolower(trim((string)$_COOKIE['dfb_web_id'])) !== $sid) {
		dfb_set_cookie('dfb_web_id', $sid, time() + 31536000, true);
	}
	$_COOKIE['dfb_web_id'] = $sid;
	$identity = array(
		'sid'      => $sid,
		'account'  => 'web_'.$sid,
		'username' => '舞者 '.strtoupper(substr($sid, 0, 4)),
	);
	return $identity;
}

/**
 * 当前账号在本站内的标识。
 *
 * @return string
 */
function dfb_account() {
	$identity = dfb_web_identity();
	return $identity['account'];
}

/**
 * 独立版没有封禁概念。
 *
 * @return bool
 */
function dfb_user_banned() {
	return false;
}

/**
 * 功能名称（用于提示文案与前端展示）。
 *
 * @return array
 */
function dfb_feature_labels() {
	return array(
		'access' => '基础访问',
		'login'  => '账号登录',
		'data'   => '个人数据',
		'query'  => '查询功能',
		'token'  => 'Token 查看',
		'admin'  => '管理功能',
	);
}

/**
 * 功能 => 配置变量名。
 *
 * @return array
 */
function dfb_feature_config_keys() {
	return array(
		'access' => 'dfb_groups',
		'login'  => 'dfb_groups_login',
		'data'   => 'dfb_groups_data',
		'query'  => 'dfb_groups_query',
		'token'  => 'dfb_groups_token',
		'admin'  => 'dfb_groups_admin',
	);
}

/**
 * 把配置里的用户组字符串拆成数组。
 *
 * @param string $raw
 * @return array
 */
function dfb_group_list($raw) {
	$raw = trim((string)$raw);
	if($raw === '') {
		return array();
	}
	$parts = preg_split('/[\s,，、;；|\/]+/u', $raw);
	$list = array();
	if(is_array($parts)) {
		foreach($parts as $part) {
			$part = trim($part);
			if($part !== '') {
				$list[] = $part;
			}
		}
	}
	return array_values(array_unique($list));
}

/**
 * 取得某个功能的用户组限制原始值（空表示未单独配置）。
 *
 * @param string $feature
 * @return string
 */
function dfb_feature_rule($feature) {
	$config = dfb_config();
	$keys = dfb_feature_config_keys();
	if(!isset($keys[$feature])) {
		return '';
	}
	$raw = isset($config[$keys[$feature]]) ? trim((string)$config[$keys[$feature]]) : '';
	if($raw === '' && $feature !== 'access') {
		// 未单独配置的功能沿用「基础访问」的用户组设置
		$raw = isset($config['dfb_groups']) ? trim((string)$config['dfb_groups']) : '';
	}
	return $raw;
}

/**
 * 判断当前访问者是否有权使用某个功能。
 *
 * 独立版默认开放全部功能；「后台管理」单独处理：
 *   - admin_enabled 必须是开启状态；
 *   - 当前登录的舞立方 ID 必须在 admin_user_ids 里；
 *   - 通过密码验证后，具体 admin.* 动作还会再做一次解锁校验。
 *
 * 注意：这里返回 true 只代表「有资格看到后台管理入口」，
 * 并不代表已经解锁。
 *
 * @param string $feature
 * @return bool
 */
function dfb_feature_allowed($feature) {
	/* 关闭站点后，非管理员只保留登录能力；接口层还会做一次硬拦截 */
	if(function_exists('dfb_site_is_closed') && dfb_site_is_closed()
		&& $feature !== 'login' && $feature !== 'access' && !dfb_admin_eligible()) {
		return false;
	}
	if($feature === 'admin') {
		return dfb_admin_eligible();
	}
	return true;
}

/**
 * 汇总当前用户对全部功能的权限，交给前端做界面裁剪。
 *
 * @return array
 */
function dfb_permissions() {
	$result = array();
	foreach(array_keys(dfb_feature_labels()) as $feature) {
		$result[$feature] = dfb_feature_allowed($feature) ? 1 : 0;
	}
	return $result;
}

/**
 * 无权限时的提示文案。
 *
 * @param string $feature
 * @return string
 */
function dfb_deny_message($feature) {
	if($feature === 'admin') {
		return dfb_admin_deny_reason();
	}
	$labels = dfb_feature_labels();
	$name = isset($labels[$feature]) ? $labels[$feature] : '该功能';
	return '当前站点已关闭「'.$name.'」。如需开启，请修改 config.php 中的相关开关。';
}

/**
 * 页面 / 客户端启动时需要的运行时配置（数组形式）。
 *
 * 网页版会 JSON 编码后注入 window.DFB；uni-app 通过
 * ``api.php?act=bootstrap`` 直接取这份数据。
 *
 * @return array
 */
function dfb_view_config_data() {
	$identity = dfb_web_identity();
	$config = dfb_config();
	$token = dfb_token_get($identity['account']);
	$userName = '';
	if($token && isset($token['userName'])) {
		$userName = trim((string)$token['userName']);
	}
	return array(
		'uid'       => 0,
		'username'  => $identity['username'],
		'groupid'   => '0',
		'groupname' => '独立网页版',
		'isAdmin'   => 0,
		'banned'    => 0,
		'account'   => $identity['account'],
		'webId'     => $identity['sid'],
		'loggedIn'  => $token ? true : false,
		'userId'    => $token ? intval($token['userId']) : 0,
		'userName'  => $userName,
		'formhash'  => defined('FORMHASH') ? FORMHASH : '',
		'apiUrl'    => dfb_plugin_url('api'),
		'pluginUrl' => dfb_plugin_url('index'),
		'staticUrl' => dfb_static_base(),
		'manualUrl' => 'https://www.kdocs.cn/l/ccLRaUCuNMX0',
		'siteName'  => isset($config['site_name']) ? $config['site_name'] : '舞小枫 · 舞立方助手',
		'version'   => DFB_VERSION,
		'perms'     => dfb_permissions(),
		'permNames' => dfb_feature_labels(),
		/* 站点公告：所有人（含游客）都能看到 */
		'announcement' => function_exists('dfb_announcement_public') ? dfb_announcement_public() : null,
		/* 全站关闭状态：Web/App 只展示公告 + 登录 */
		'siteClosed' => function_exists('dfb_site_is_closed') ? dfb_site_is_closed() : false,
		/* 后台管理状态：是否具备资格、是否已通过密码、密码是否已设置等 */
		'admin'     => dfb_admin_status(),
	);
}

/**
 * 注入到页面里的运行时配置（会自动 JSON 编码）。
 *
 * 注意：这段 JSON 是**直接内联进 <script> 标签**的，所以必须用
 * JSON_HEX_TAG / JSON_HEX_AMP / JSON_HEX_APOS / JSON_HEX_QUOT
 * 把 < > & ' " 全部转成 \uXXXX。
 * 否则用户名里只要出现 `</script>`，就能提前闭合脚本标签并注入任意脚本。
 *
 * @return string JSON
 */
function dfb_view_config() {
	$data = dfb_view_config_data();
	$data['i18n'] = dfb_web_language_packs();
	$flags = 0;
	foreach(array('JSON_HEX_TAG', 'JSON_HEX_AMP', 'JSON_HEX_APOS', 'JSON_HEX_QUOT') as $name) {
		if(defined($name)) {
			$flags = $flags | constant($name);
		}
	}
	return dfb_json_encode($data, $flags);
}

/**
 * Web 语言包：template/lang/zh_cn.php、en_us.php。
 *
 * 只在页面入口注入；api.php 的 bootstrap 不携带完整语言包。
 *
 * @return array
 */
function dfb_web_language_packs() {
	static $packs = null;
	if($packs !== null) {
		return $packs;
	}
	$packs = array();
	$files = array(
		'zh-CN' => 'zh_cn.php',
		'en-US' => 'en_us.php',
	);
	foreach($files as $lang => $name) {
		$path = DFB_ROOT_DIR.'template/lang/'.$name;
		if(!is_file($path)) {
			continue;
		}
		$data = @include $path;
		if(is_array($data)) {
			$packs[$lang] = $data;
		}
	}
	return $packs;
}

/* ======================================================================
 * 四、本地存储（登录凭证 / 登录会话）
 *
 * 落在插件目录 data/ 下的 state.php：
 *   <?php exit; ?>
 *   { ...JSON... }
 *
 * 这样做的好处：
 *   - 文件被直接访问时只输出空白（`exit` 之前没有任何输出），凭证不会泄露；
 *   - 不受 opcache 影响（不 include，而是按文本读取）；
 *   - 不建表、不改 Discuz 核心文件。
 * ==================================================================== */

/**
 * 插件数据目录（不存在时尝试创建）。
 *
 * @return string
 */
function dfb_data_dir() {
	$dir = DFB_PLUGIN_DIR.'data/';
	if(!is_dir($dir)) {
		@mkdir($dir, 0777, true);
	}
	/* 额外保险：Apache 环境下直接拒绝外部访问该目录（文件本身也带 exit 守卫） */
	if(!is_file($dir.'.htaccess')) {
		@file_put_contents($dir.'.htaccess',
			"<IfModule mod_authz_core.c>\n\tRequire all denied\n</IfModule>\n"
			."<IfModule !mod_authz_core.c>\n\tOrder allow,deny\n\tDeny from all\n</IfModule>\n");
	}
	if(!is_file($dir.'index.html')) {
		@file_put_contents($dir.'index.html', '');
	}
	return $dir;
}

/**
 * 目录是否真的可写。
 *
 * Windows 上 PHP 的 ``is_writable()`` 对目录经常返回 false（即使实际能写），
 * 所以这里用「创建再删除一个探测文件」的方式判断。
 *
 * @param string $dir
 * @return bool
 */
function dfb_dir_writable($dir) {
	static $cache = array();
	$dir = rtrim((string)$dir, '/\\').'/';
	if(isset($cache[$dir])) {
		return $cache[$dir];
	}
	if(!is_dir($dir)) {
		$cache[$dir] = false;
		return false;
	}
	$probe = $dir.'.write-'.dfb_random_hex(4).'.tmp';
	$fp = @fopen($probe, 'x');
	if(!$fp) {
		$cache[$dir] = false;
		return false;
	}
	@fclose($fp);
	@unlink($probe);
	$cache[$dir] = true;
	return true;
}

/**
 * 站点级密钥（用于派生 formhash），首次访问时自动生成。
 *
 * 文件内容形如 ``<?php exit; ?>\n<64 位十六进制>``，直接访问只输出空白。
 * 数据目录不可写时退化为固定哈希，保证 formhash 不会每次请求都变。
 *
 * @return string
 */
function dfb_secret() {
	static $secret = null;
	if($secret !== null) {
		return $secret;
	}
	$dir = dfb_data_dir();
	$path = $dir.'secret.php';
	$fallback = hash('sha256', 'dfb-secret|'.DFB_ROOT_DIR);
	$raw = @file_get_contents($path);
	if(is_string($raw) && preg_match('/([a-f0-9]{64})/', $raw, $matched)) {
		$secret = $matched[1];
		return $secret;
	}
	if(!dfb_dir_writable($dir)) {
		$secret = $fallback;
		return $secret;
	}
	$candidate = dfb_random_hex(32);
	$fp = @fopen($path, 'x');
	if($fp) {
		@fwrite($fp, "<?php exit; ?>\n".$candidate);
		@fclose($fp);
		$secret = $candidate;
		return $secret;
	}
	/* 并发下别的请求已经创建成功：以磁盘上的为准 */
	$raw = @file_get_contents($path);
	if(is_string($raw) && preg_match('/([a-f0-9]{64})/', $raw, $matched)) {
		$secret = $matched[1];
	} else {
		$secret = $fallback;
	}
	return $secret;
}

/**
 * 当前浏览器身份的 CSRF 令牌（写进页面，接口校验）。
 *
 * @return string
 */
function dfb_formhash() {
	static $hash = null;
	if($hash !== null) {
		return $hash;
	}
	$identity = dfb_web_identity();
	$hash = substr(hash_hmac('sha256', 'dfb-form|'.$identity['sid'], dfb_secret()), 0, 8);
	return $hash;
}

/**
 * 状态文件路径。
 *
 * @return string
 */
function dfb_state_path() {
	return dfb_data_dir().'state.php';
}

/**
 * 空状态。
 *
 * @return array
 */
function dfb_state_default() {
	return array(
		'tokens'         => array(),
		'sessions'       => array(),
		'admin_unlocks'  => array(),
		'admin_attempts' => array(),
	);
}

/**
 * 读取本地状态。
 *
 * @return array
 */
function dfb_state_read() {
	$raw = @file_get_contents(dfb_state_path());
	if(!is_string($raw) || $raw === '') {
		return dfb_state_default();
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return dfb_state_default();
	}
	$data = json_decode(substr($raw, $pos), true);
	if(!is_array($data)) {
		return dfb_state_default();
	}
	if(!isset($data['tokens']) || !is_array($data['tokens'])) {
		$data['tokens'] = array();
	}
	if(!isset($data['sessions']) || !is_array($data['sessions'])) {
		$data['sessions'] = array();
	}
	if(!isset($data['admin_unlocks']) || !is_array($data['admin_unlocks'])) {
		$data['admin_unlocks'] = array();
	}
	if(!isset($data['admin_attempts']) || !is_array($data['admin_attempts'])) {
		$data['admin_attempts'] = array();
	}
	return $data;
}

/**
 * 写入本地状态（临时文件 + rename，避免写到一半被读到）。
 *
 * @param array $state
 * @return bool
 */
function dfb_state_write($state) {
	$path = dfb_state_path();
	$dir = dirname($path);
	if(!is_dir($dir)) {
		@mkdir($dir, 0777, true);
	}
	$content = "<?php exit; ?>\n".dfb_json_pretty($state);
	$tmp = $path.'.'.substr(md5(uniqid('', true)), 0, 8).'.tmp';
	if(@file_put_contents($tmp, $content, LOCK_EX) === false) {
		return false;
	}
	if(!@rename($tmp, $path)) {
		@unlink($path);
		if(!@rename($tmp, $path)) {
			@unlink($tmp);
			return false;
		}
	}
	return true;
}

/**
 * 读-改-写状态（带文件锁，避免并发覆盖）。
 *
 * 回调签名为 ``function (&$state) { ... }``。
 *
 * @param callable $callback
 * @return mixed 回调的返回值
 * @throws DfbError 状态文件不可写
 */
function dfb_state_update($callback) {
	$dir = dfb_data_dir();
	if(!dfb_dir_writable($dir)) {
		throw new DfbError('数据目录不可写：data/，请检查目录权限。', 500);
	}
	$lockPath = $dir.'state.lock';
	$lock = @fopen($lockPath, 'c');
	if($lock) {
		@flock($lock, LOCK_EX);
	}
	$state = dfb_state_read();
	$result = $callback($state);
	if(dfb_state_write($state) === false) {
		if($lock) {
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
		throw new DfbError('登录凭证写入失败：请检查 data/ 目录权限。', 500);
	}
	if($lock) {
		@flock($lock, LOCK_UN);
		@fclose($lock);
	}
	return $result;
}

/**
 * 取某个账号的 Token。
 *
 * @param string $account
 * @return array|null
 */
function dfb_token_get($account) {
	$state = dfb_state_read();
	$key = (string)$account;
	return isset($state['tokens'][$key]) && is_array($state['tokens'][$key]) ? $state['tokens'][$key] : null;
}

/**
 * 保存某个账号的 Token。
 *
 * @param string $account
 * @param array  $token
 * @return void
 */
function dfb_token_set($account, $token) {
	dfb_state_update(function (&$state) use ($account, $token) {
		$state['tokens'][(string)$account] = $token;
	});
}

/**
 * 删除某个账号的 Token，返回被删除的 Token（不存在时返回 null）。
 *
 * @param string $account
 * @return array|null
 */
function dfb_token_delete($account) {
	$removed = null;
	dfb_state_update(function (&$state) use ($account, &$removed) {
		$key = (string)$account;
		if(isset($state['tokens'][$key])) {
			$removed = $state['tokens'][$key];
			unset($state['tokens'][$key]);
		}
	});
	return $removed;
}

/**
 * 全部 Token（供管理功能统计条数）。
 *
 * @return array
 */
function dfb_tokens_all() {
	$state = dfb_state_read();
	return $state['tokens'];
}

/**
 * 取登录会话（二维码 / 手机号登录过程中的临时状态）。
 *
 * @param string $ticket
 * @return array|null
 */
function dfb_session_get($ticket) {
	$state = dfb_state_read();
	$key = (string)$ticket;
	if(!isset($state['sessions'][$key]) || !is_array($state['sessions'][$key])) {
		return null;
	}
	return $state['sessions'][$key];
}

/**
 * 写入登录会话，同时清理过期会话。
 *
 * @param string $ticket
 * @param array  $session
 * @return void
 */
function dfb_session_set($ticket, $session) {
	dfb_state_update(function (&$state) use ($ticket, $session) {
		$now = time();
		foreach($state['sessions'] as $key => $item) {
			$created = isset($item['created']) ? intval($item['created']) : 0;
			if($created + 900 < $now) {
				unset($state['sessions'][$key]);
			}
		}
		$state['sessions'][(string)$ticket] = $session;
	});
}

/**
 * 清理某个账号的全部登录会话（重新登录 / 退出登录时使用）。
 *
 * @param string $account
 * @return int 清理掉的会话数量
 */
function dfb_session_clear_account($account) {
	$removed = 0;
	dfb_state_update(function (&$state) use ($account, &$removed) {
		foreach($state['sessions'] as $key => $session) {
			if(isset($session['account']) && $session['account'] === $account) {
				unset($state['sessions'][$key]);
				$removed++;
			}
		}
	});
	return $removed;
}

/**
 * 删除登录会话。
 *
 * @param string $ticket
 * @return void
 */
function dfb_session_delete($ticket) {
	dfb_state_update(function (&$state) use ($ticket) {
		unset($state['sessions'][(string)$ticket]);
	});
}

/* ======================================================================
 * 四·五、后台管理（管理员账号 + 密码）
 *
 * 设计目标：
 *   1. 只有登录的舞立方 ID 在 admin_user_ids 里，后台管理入口才出现；
 *   2. 入口出现后仍是锁定状态，首次必须设置后台密码，之后必须输入密码；
 *   3. 密码哈希放在 data/admin.php（data/ 禁止 Web 访问），也可以
 *      通过 config.php 的 admin_secret_file 指到站点根目录之外；
 *   4. 密码使用 bcrypt（随机盐）或 PBKDF2-HMAC-SHA256（随机盐）存储，
 *      绝不保存明文；
 *   5. 解锁记录绑定「浏览器身份 + 舞立方 ID + 密码哈希」，有有效期，
 *      改密后旧会话立即失效；
 *   6. 连续答错达到上限后按账号锁定一段时间，避免在线爆破。
 * ==================================================================== */

/**
 * 允许进入后台管理的舞立方用户 ID 列表。
 *
 * @return array
 */
function dfb_admin_user_ids() {
	$config = dfb_config();
	$raw = isset($config['admin_user_ids']) ? $config['admin_user_ids'] : '';
	$items = is_array($raw) ? $raw : dfb_group_list($raw);
	$ids = array();
	foreach($items as $item) {
		if(!is_scalar($item)) {
			continue;
		}
		$id = intval(trim((string)$item));
		if($id > 0) {
			$ids[] = $id;
		}
	}
	return array_values(array_unique($ids));
}

/**
 * 后台管理总开关是否开启。
 *
 * @return bool
 */
function dfb_admin_master_enabled() {
	$config = dfb_config();
	return intval($config['admin_enabled']) === 1;
}

/**
 * 当前浏览器身份已登录的舞立方用户 ID（未登录返回 0）。
 *
 * 只读本地 state，不请求上游，因此可以在页面渲染时安全调用。
 *
 * @return int
 */
function dfb_admin_token_user_id() {
	$token = dfb_token_get(dfb_account());
	if(!$token || !isset($token['userId'])) {
		return 0;
	}
	return intval($token['userId']);
}

/**
 * 当前账号是否有「后台管理」资格（已登录且 ID 在白名单内）。
 *
 * @return bool
 */
function dfb_admin_eligible() {
	if(!dfb_admin_master_enabled()) {
		return false;
	}
	$ids = dfb_admin_user_ids();
	if(!$ids) {
		return false;
	}
	$uid = dfb_admin_token_user_id();
	return $uid > 0 && in_array($uid, $ids, true);
}

/**
 * 没有后台管理资格时的提示文案。
 *
 * @return string
 */
function dfb_admin_deny_reason() {
	if(!dfb_admin_master_enabled()) {
		return '当前站点已关闭「后台管理」。如需开启，请把 config.php 中的 admin_enabled 设为 true。';
	}
	$ids = dfb_admin_user_ids();
	if(!$ids) {
		return '后台管理尚未配置可访问的舞立方账号：请检查 config.php 中的 admin_user_ids。';
	}
	$token = dfb_token_get(dfb_account());
	if(!$token) {
		return '「后台管理」仅对指定舞立方账号开放，请先登录。';
	}
	$uid = isset($token['userId']) ? intval($token['userId']) : 0;
	if($uid <= 0 || !in_array($uid, $ids, true)) {
		return '当前登录的舞立方账号（ID '.$uid.'）没有后台管理权限。';
	}
	return '后台管理已锁定，请先输入后台密码。';
}

/**
 * 是否使用了自定义密码文件路径。
 *
 * @return bool
 */
function dfb_admin_secret_is_custom() {
	$config = dfb_config();
	return isset($config['admin_secret_file']) && trim((string)$config['admin_secret_file']) !== '';
}

/**
 * 密码文件路径。
 *
 * 默认 ``data/admin.php``；config.php 里设置 admin_secret_file 后优先使用
 * 该路径（相对路径按站点根目录解析）。推荐填站点根目录之外的绝对路径。
 *
 * @return string
 */
function dfb_admin_secret_path() {
	$config = dfb_config();
	$custom = isset($config['admin_secret_file']) ? trim((string)$config['admin_secret_file']) : '';
	if($custom === '') {
		return dfb_data_dir().'admin.php';
	}
	if(!preg_match('#^(?:[A-Za-z]:[\\\\/]|[\\\\/])#', $custom)) {
		$custom = DFB_ROOT_DIR.$custom;
	}
	return $custom;
}

/**
 * 后台密码配置的默认值（视为未设置密码）。
 *
 * @return array
 */
function dfb_admin_secret_default() {
	return array(
		'password_hash' => '',
		'hint'          => '',
		'updated'       => 0,
		'configured'    => false,
	);
}

/**
 * 判断密码哈希是否是我们支持的形式（bcrypt / PBKDF2）。
 *
 * @param string $hash
 * @return bool
 */
function dfb_admin_hash_looks_valid($hash) {
	$hash = (string)$hash;
	if($hash === '') {
		return false;
	}
	/* password_hash(PASSWORD_BCRYPT)：$2y$12$ + 22 位盐 + 31 位摘要 */
	if(preg_match('/^\$2[aby]\$\d{2}\$[.\/A-Za-z0-9]{53}$/', $hash)) {
		return true;
	}
	/* 如果未来 PASSWORD_DEFAULT 换成 Argon2，也兼容 password_verify */
	if(preg_match('/^\$argon2(?:id|i)\$v=\d+\$m=\d+,t=\d+,p=\d+\$[A-Za-z0-9+\/=]+\$[A-Za-z0-9+\/=]+$/', $hash)) {
		return true;
	}
	/* 自定义 PBKDF2 回退格式：pbkdf2_sha256$迭代次数$盐$摘要 */
	if(preg_match('/^pbkdf2_sha256\$\d{3,9}\$[a-f0-9]{16,64}\$[A-Za-z0-9+\/=]+$/', $hash)) {
		return true;
	}
	return false;
}

/**
 * 把后台密码配置写回文件。
 *
 * - 默认 data/admin.php：保留首行 <?php exit; ?>，直接访问只输出空白；
 * - admin_secret_file 指向站点根目录外：写纯 JSON 即可。
 * - 只写 password_hash，绝不会有明文密码。
 *
 * @param array $secret
 * @return bool
 */
function dfb_admin_secret_write($secret) {
	$path = dfb_admin_secret_path();
	$custom = dfb_admin_secret_is_custom();
	$dir = dirname($path);
	if(!is_dir($dir)) {
		if($custom) {
			return false;
		}
		@mkdir($dir, 0700, true);
	}
	if(!is_dir($dir)) {
		return false;
	}
	$data = array(
		'password_hash' => isset($secret['password_hash']) ? (string)$secret['password_hash'] : '',
		'hint'          => isset($secret['hint']) ? (string)$secret['hint'] : '',
		'updated'       => isset($secret['updated']) ? intval($secret['updated']) : time(),
	);
	$json = dfb_json_pretty($data);
	if(!is_string($json) || $json === '') {
		return false;
	}
	$existing = @file_get_contents($path);
	/* 自定义路径如果在站点根目录内，也强制保留首行守卫，避免放到 Web 可访问处时泄露哈希 */
	$root = str_replace('\\', '/', DFB_ROOT_DIR);
	$normalizedPath = str_replace('\\', '/', $path);
	$insideRoot = strpos($normalizedPath, $root) === 0;
	$guard = !$custom || $insideRoot || (is_string($existing) && strpos($existing, '<?php') === 0);
	$content = ($guard ? "<?php exit; ?>\n" : '').$json."\n";

	$tmp = $dir.'/.'.basename($path).'.'.substr(md5(uniqid('', true)), 0, 8).'.tmp';
	if(@file_put_contents($tmp, $content, LOCK_EX) === false) {
		return false;
	}
	@chmod($tmp, 0600);
	if(!@rename($tmp, $path)) {
		/* Windows 下 rename 覆盖现有文件偶尔失败，退化为直接写 */
		if(@file_put_contents($path, $content, LOCK_EX) === false) {
			@unlink($tmp);
			return false;
		}
		@unlink($tmp);
	}
	@chmod($path, 0600);
	return true;
}

/**
 * 生成默认密码文件（哈希为空，等待管理员首次设置）。
 *
 * @param string $path 兼容旧调用；实际路径由 dfb_admin_secret_path() 决定
 * @return bool
 */
function dfb_admin_secret_create($path = '') {
	$path = dfb_admin_secret_path();
	$dir = dirname($path);
	if(!is_dir($dir)) {
		if(dfb_admin_secret_is_custom()) {
			return false;
		}
		@mkdir($dir, 0700, true);
	}
	if(!is_dir($dir)) {
		return false;
	}
	$flags = 0;
	foreach(array('JSON_PRETTY_PRINT', 'JSON_UNESCAPED_UNICODE', 'JSON_UNESCAPED_SLASHES') as $name) {
		if(defined($name)) {
			$flags = $flags | constant($name);
		}
	}
	$json = json_encode(array('password_hash' => '', 'hint' => '', 'updated' => 0), $flags);
	if(!is_string($json) || $json === '') {
		return false;
	}
	$content = "<?php exit; ?>\n".$json."\n";
	/* 用 'x' 打开：并发下如果别的请求已经创建成功，绝不覆盖 */
	$fp = @fopen($path, 'x');
	if(!$fp) {
		return false;
	}
	@fwrite($fp, $content);
	@fclose($fp);
	@chmod($path, 0600);
	return true;
}

/**
 * 读取后台密码配置。
 *
 * 支持两种文件：
 *   1. 首行 ``<?php exit; ?>`` + JSON（默认 data/admin.php）；
 *   2. 纯 JSON（放在站点根目录之外时，通常不需要首行守卫）。
 *
 * 只读取 password_hash，文件里不会出现明文密码。
 *
 * @param bool $reload 是否强制重新读盘
 * @return array
 */
function dfb_admin_secret($reload = false) {
	static $secret = null;
	if($secret !== null && !$reload) {
		return $secret;
	}
	$secret = dfb_admin_secret_default();
	$path = dfb_admin_secret_path();
	$custom = dfb_admin_secret_is_custom();
	if(!is_file($path) && !$custom) {
		/* 首次使用时生成模板；哈希为空，后台保持未设置状态 */
		dfb_admin_secret_create($path);
	}
	if(!is_file($path)) {
		return $secret;
	}
	$raw = @file_get_contents($path);
	if(!is_string($raw) || $raw === '') {
		return $secret;
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return $secret;
	}
	$data = json_decode(substr($raw, $pos), true);
	if(!is_array($data)) {
		return $secret;
	}
	$hash = isset($data['password_hash']) && is_scalar($data['password_hash']) ? trim((string)$data['password_hash']) : '';
	$hint = isset($data['hint']) && is_scalar($data['hint']) ? trim((string)$data['hint']) : '';
	$updated = isset($data['updated']) ? intval($data['updated']) : 0;
	$configured = dfb_admin_hash_looks_valid($hash);
	$secret = array(
		'password_hash' => $configured ? $hash : '',
		'hint'          => $hint,
		'updated'       => $updated,
		'configured'    => $configured,
	);
	return $secret;
}

/**
 * PBKDF2-HMAC-SHA256（没有 hash_pbkdf2 时的回退实现）。
 *
 * @param string $password
 * @param string $salt
 * @param int    $iterations
 * @param int    $length
 * @return string 二进制摘要
 */
function dfb_admin_pbkdf2($password, $salt, $iterations, $length) {
	$iterations = max(1000, intval($iterations));
	$length = max(16, intval($length));
	if(function_exists('hash_pbkdf2')) {
		$raw = @hash_pbkdf2('sha256', (string)$password, (string)$salt, $iterations, $length, true);
		if(is_string($raw) && strlen($raw) === $length) {
			return $raw;
		}
	}
	/* 极老环境没有 hash_pbkdf2：用迭代 SHA-256 兜底，仍然是“盐 + 多轮” */
	$raw = hash('sha256', (string)$salt.'|'.(string)$password, true);
	for($i = 0; $i < $iterations; $i++) {
		$raw = hash('sha256', $raw.(string)$salt, true);
	}
	$out = '';
	while(strlen($out) < $length) {
		$out .= $raw;
		$raw = hash('sha256', $raw.(string)$salt, true);
	}
	return substr($out, 0, $length);
}

/**
 * 生成密码哈希。
 *
 * 优先 password_hash + PASSWORD_BCRYPT（bcrypt 自带随机盐，cost=12）；
 * 环境不支持时退回 PBKDF2-HMAC-SHA256 + 16 字节随机盐 + 高迭代。
 * 无论哪种方式，都只保存哈希，不保存明文。
 *
 * @param string $password
 * @return string
 */
function dfb_admin_hash_password($password) {
	$password = (string)$password;
	if(function_exists('password_hash')) {
		$algo = defined('PASSWORD_BCRYPT') ? PASSWORD_BCRYPT : PASSWORD_DEFAULT;
		$hash = @password_hash($password, $algo, array('cost' => 12));
		if(is_string($hash) && $hash !== '') {
			return $hash;
		}
	}
	$iterations = 120000;
	$salt = dfb_random_hex(16);
	$raw = dfb_admin_pbkdf2($password, $salt, $iterations, 32);
	return 'pbkdf2_sha256$'.$iterations.'$'.$salt.'$'.base64_encode($raw);
}

/**
 * 校验密码哈希。
 *
 * bcrypt 用 password_verify（内部按哈希里的随机盐计算，恒定时间比较）；
 * 自定义 PBKDF2 用 hash_equals 比较。
 *
 * @param string $password
 * @param string $hash
 * @return bool
 */
function dfb_admin_verify_password($password, $hash) {
	$password = (string)$password;
	$hash = (string)$hash;
	if($hash === '' || $password === '') {
		return false;
	}
	if(strpos($hash, 'pbkdf2_sha256$') === 0) {
		$parts = explode('$', $hash);
		if(count($parts) !== 4) {
			return false;
		}
		$iterations = intval($parts[1]);
		$salt = $parts[2];
		$expected = base64_decode($parts[3], true);
		if($iterations < 1000 || $salt === '' || !is_string($expected) || $expected === '') {
			return false;
		}
		$raw = dfb_admin_pbkdf2($password, $salt, $iterations, strlen($expected));
		return dfb_admin_secure_equals($expected, $raw);
	}
	if(function_exists('password_verify')) {
		return @password_verify($password, $hash);
	}
	return false;
}

/**
 * 密码配置的指纹：密码哈希变化后，旧的解锁记录立即失效。
 *
 * @param array $secret
 * @return string
 */
function dfb_admin_secret_fingerprint($secret) {
	$hash = isset($secret['password_hash']) ? (string)$secret['password_hash'] : '';
	return substr(hash('sha256', 'dfb-admin|'.$hash), 0, 32);
}

/**
 * 当前账号的解锁记录（不存在 / 结构异常时返回 null）。
 *
 * @return array|null
 */
function dfb_admin_unlock_record() {
	$state = dfb_state_read();
	$account = dfb_account();
	if(!isset($state['admin_unlocks'][$account]) || !is_array($state['admin_unlocks'][$account])) {
		return null;
	}
	return $state['admin_unlocks'][$account];
}

/**
 * 当前账号是否已通过密码验证且仍在有效期内。
 *
 * 会同时校验：资格、解锁时的舞立方 ID 与当前登录 ID 是否一致、是否过期。
 *
 * @return bool
 */
function dfb_admin_unlocked() {
	if(!dfb_admin_eligible()) {
		return false;
	}
	/* 密码文件被删除 / 哈希被清空时，已有的解锁记录立即失效 */
	$secret = dfb_admin_secret();
	if(!$secret['configured']) {
		return false;
	}
	$record = dfb_admin_unlock_record();
	if(!$record) {
		return false;
	}
	if(!isset($record['secret']) || !dfb_admin_secure_equals((string)$record['secret'], dfb_admin_secret_fingerprint($secret))) {
		return false;
	}
	$uid = dfb_admin_token_user_id();
	if($uid <= 0 || intval($record['userId']) !== $uid) {
		return false;
	}
	return intval($record['expires']) > time();
}

/**
 * 当前账号的密码失败次数 / 锁定状态。
 *
 * @return array
 */
function dfb_admin_attempt_state() {
	$state = dfb_state_read();
	$account = dfb_account();
	$record = isset($state['admin_attempts'][$account]) && is_array($state['admin_attempts'][$account])
		? $state['admin_attempts'][$account]
		: array();
	$fail = isset($record['fail']) ? intval($record['fail']) : 0;
	$first = isset($record['first']) ? intval($record['first']) : 0;
	$lockedUntil = isset($record['lockedUntil']) ? intval($record['lockedUntil']) : 0;
	$now = time();
	if($lockedUntil > 0 && $lockedUntil <= $now) {
		$lockedUntil = 0;
		$fail = 0;
		$first = 0;
	}
	/* 超过 15 分钟没有继续答错，就重新计数 */
	if($first > 0 && $now - $first > 900) {
		$fail = 0;
		$first = 0;
	}
	return array('fail' => $fail, 'first' => $first, 'lockedUntil' => $lockedUntil);
}

/**
 * 后台管理状态（注入页面 / 返回接口）。
 *
 * 未登录或 ID 不在白名单时不会读取密码文件，避免把状态泄露给普通访客。
 *
 * @return array
 */
function dfb_admin_status() {
	$config = dfb_config();
	$ttl = intval($config['admin_unlock_ttl']);
	if($ttl <= 0) {
		$ttl = 1800;
	}
	$maxAttempts = intval($config['admin_max_attempts']);
	if($maxAttempts <= 0) {
		$maxAttempts = 5;
	}
	$minLength = dfb_admin_password_min_length();
	$status = array(
		'eligible'     => false,
		'unlocked'     => false,
		'userId'       => 0,
		'passwordSet'  => false,
		'minLength'    => $minLength,
		'ttl'          => $ttl,
		'expiresIn'    => 0,
		'lockedFor'    => 0,
		'attemptsLeft' => 0,
		'updated'      => 0,
		'reason'       => '',
	);
	if(!dfb_admin_eligible()) {
		$status['reason'] = dfb_admin_deny_reason();
		return $status;
	}
	$status['eligible'] = true;
	$status['userId'] = dfb_admin_token_user_id();

	$secret = dfb_admin_secret();
	$status['passwordSet'] = $secret['configured'];
	$status['updated'] = $secret['updated'];

	$record = dfb_admin_unlock_record();
	if(dfb_admin_unlocked() && $record) {
		$status['unlocked'] = true;
		$status['expiresIn'] = max(0, intval($record['expires']) - time());
		$status['attemptsLeft'] = $maxAttempts;
	} else {
		$attempt = dfb_admin_attempt_state();
		$lockedFor = $attempt['lockedUntil'] > time() ? ($attempt['lockedUntil'] - time()) : 0;
		$status['lockedFor'] = $lockedFor;
		$status['attemptsLeft'] = max(0, $maxAttempts - intval($attempt['fail']));
		$status['reason'] = $secret['configured']
			? '后台管理已锁定，请输入后台密码。'
			: '尚未设置后台密码，请先设置。';
	}
	return $status;
}

/**
 * 恒定时间字符串比较（兼容没有 hash_equals 的 PHP 5.6）。
 *
 * @param string $known
 * @param string $given
 * @return bool
 */
function dfb_admin_secure_equals($known, $given) {
	if(function_exists('hash_equals')) {
		return hash_equals((string)$known, (string)$given);
	}
	$known = (string)$known;
	$given = (string)$given;
	if(strlen($known) !== strlen($given)) {
		return false;
	}
	$diff = 0;
	$length = strlen($known);
	for($i = 0; $i < $length; $i++) {
		$diff = $diff | (ord($known[$i]) ^ ord($given[$i]));
	}
	return $diff === 0;
}

/**
 * 后台密码最小长度（config.php 的 admin_password_min_length）。
 *
 * @return int
 */
function dfb_admin_password_min_length() {
	$config = dfb_config();
	$min = intval($config['admin_password_min_length']);
	if($min < 6) {
		$min = 6;
	}
	if($min > 64) {
		$min = 64;
	}
	return $min;
}

/**
 * 校验新密码（只用于设置 / 修改，不用于登录校验）。
 *
 * @param string $password
 * @param string $confirm
 * @return string 错误文案；空字符串表示通过
 */
function dfb_admin_validate_password($password, $confirm) {
	$password = (string)$password;
	$confirm = (string)$confirm;
	$min = dfb_admin_password_min_length();
	if($password === '') {
		return '请输入后台密码。';
	}
	if(strpos($password, "\0") !== false) {
		return '密码不能包含空字节。';
	}
	if(trim($password) === '') {
		return '密码不能全是空白。';
	}
	$length = function_exists('mb_strlen') ? mb_strlen($password, 'UTF-8') : strlen($password);
	if($length < $min) {
		return '密码至少需要 '.$min.' 个字符。';
	}
	if(strlen($password) > 72) {
		return '密码最多 72 字节（约 24 个汉字），请换短一点的。';
	}
	if($password !== $confirm) {
		return '两次输入的密码不一致。';
	}
	return '';
}

/**
 * 记录一次密码错误并抛出异常（登录 / 修改密码共用）。
 *
 * @param array $attempt
 * @param int   $maxAttempts
 * @param int   $lockSeconds
 * @return void
 * @throws DfbError
 */
function dfb_admin_register_failure($attempt, $maxAttempts, $lockSeconds) {
	$now = time();
	$fail = intval($attempt['fail']) + 1;
	$first = intval($attempt['first']) > 0 ? intval($attempt['first']) : $now;
	$lockedUntil = 0;
	if($fail >= $maxAttempts) {
		$lockedUntil = $now + $lockSeconds;
	}
	$account = dfb_account();
	dfb_state_update(function (&$state) use ($account, $fail, $first, $lockedUntil, $now) {
		$state['admin_attempts'][$account] = array(
			'fail'        => $fail,
			'first'       => $first,
			'lockedUntil' => $lockedUntil,
			'updated'     => $now,
		);
	});
	if($lockedUntil > 0) {
		$minutes = (int)ceil($lockSeconds / 60);
		throw new DfbError('密码不正确。已连续答错 '.$fail.' 次，请 '.$minutes.' 分钟后再试。', 429);
	}
	throw new DfbError('密码不正确，还可以尝试 '.max(0, $maxAttempts - $fail).' 次。', 403);
}

/**
 * 为当前账号写入解锁记录（首次设置 / 登录 / 修改密码成功后调用）。
 *
 * @return void
 */
function dfb_admin_issue_unlock() {
	$config = dfb_config();
	$ttl = intval($config['admin_unlock_ttl']);
	if($ttl <= 0) {
		$ttl = 1800;
	}
	$secret = dfb_admin_secret();
	$account = dfb_account();
	$uid = dfb_admin_token_user_id();
	$now = time();
	$expires = $now + $ttl;
	$fingerprint = dfb_admin_secret_fingerprint($secret);
	dfb_state_update(function (&$state) use ($account, $uid, $now, $expires, $fingerprint) {
		/* 顺手清理过期解锁记录，避免 state 越积越大 */
		foreach($state['admin_unlocks'] as $key => $item) {
			if(!is_array($item) || intval($item['expires']) <= $now) {
				unset($state['admin_unlocks'][$key]);
			}
		}
		$state['admin_unlocks'][$account] = array(
			'userId'  => $uid,
			'secret'  => $fingerprint,
			'created' => $now,
			'expires' => $expires,
		);
		unset($state['admin_attempts'][$account]);
	});
}

/**
 * 在 data/admin.lock 上加锁执行回调，避免并发设置 / 修改密码。
 *
 * @param callable $callback
 * @return mixed
 * @throws Exception
 */
function dfb_admin_with_lock($callback) {
	$lock = @fopen(dfb_data_dir().'admin.lock', 'c');
	if($lock) {
		@flock($lock, LOCK_EX);
	}
	try {
		$result = call_user_func($callback);
	} catch (Exception $e) {
		if($lock) {
			@flock($lock, LOCK_UN);
			@fclose($lock);
		}
		throw $e;
	}
	if($lock) {
		@flock($lock, LOCK_UN);
		@fclose($lock);
	}
	return $result;
}

/**
 * 首次设置后台密码。
 *
 * 只有「尚未设置密码」时可用；设置成功后自动解锁当前会话。
 *
 * @param string $password
 * @param string $confirm
 * @return array
 * @throws DfbError
 */
function dfb_admin_password_setup($password, $confirm) {
	if(!dfb_admin_eligible()) {
		throw new DfbError(dfb_admin_deny_reason(), 403);
	}
	return dfb_admin_with_lock(function () use ($password, $confirm) {
		$secret = dfb_admin_secret(true);
		if($secret['configured']) {
			throw new DfbError('后台密码已经设置过了，请使用「修改密码」。', 409);
		}
		$error = dfb_admin_validate_password($password, $confirm);
		if($error !== '') {
			throw new DfbError($error, 400);
		}
		$hash = dfb_admin_hash_password($password);
		if($hash === '') {
			throw new DfbError('密码加密失败：请检查 PHP 的 password_hash / hash 扩展。', 500);
		}
		$data = array(
			'password_hash' => $hash,
			'hint'          => $secret['hint'],
			'updated'       => time(),
		);
		if(!dfb_admin_secret_write($data)) {
			throw new DfbError('密码保存失败：请检查 data/ 目录（或 admin_secret_file 所在目录）是否可写，以及文件权限。', 500);
		}
		$check = dfb_admin_secret(true);
		if(!$check['configured'] || !dfb_admin_verify_password($password, $check['password_hash'])) {
			throw new DfbError('密码写入后校验失败：请检查文件权限与 PHP 的密码扩展。', 500);
		}
		dfb_admin_issue_unlock();
		return array(
			'ok'      => true,
			'message' => '后台密码设置成功，已进入后台管理。',
			'admin'   => dfb_admin_status(),
		);
	});
}

/**
 * 修改后台密码（必须已通过密码解锁）。
 *
 * 修改成功后，密码哈希变化会使其它会话的旧解锁记录立即失效；
 * 当前会话会重新写入一条新的解锁记录。
 *
 * @param string $current
 * @param string $new
 * @param string $confirm
 * @return array
 * @throws DfbError
 */
function dfb_admin_password_change($current, $new, $confirm) {
	if(!dfb_admin_eligible()) {
		throw new DfbError(dfb_admin_deny_reason(), 403);
	}
	return dfb_admin_with_lock(function () use ($current, $new, $confirm) {
		$secret = dfb_admin_secret(true);
		if(!$secret['configured']) {
			throw new DfbError('尚未设置后台密码，请先设置。', 409);
		}
		$config = dfb_config();
		$maxAttempts = intval($config['admin_max_attempts']);
		if($maxAttempts <= 0) {
			$maxAttempts = 5;
		}
		$lockSeconds = intval($config['admin_lock_seconds']);
		if($lockSeconds < 60) {
			$lockSeconds = 600;
		}
		$attempt = dfb_admin_attempt_state();
		$now = time();
		if($attempt['lockedUntil'] > $now) {
			$minutes = (int)ceil(($attempt['lockedUntil'] - $now) / 60);
			throw new DfbError('密码尝试次数过多，请 '.$minutes.' 分钟后再试。', 429);
		}
		if(strpos((string)$current, "\0") !== false) {
			throw new DfbError('当前密码格式不正确。', 400);
		}
		if(!dfb_admin_verify_password((string)$current, $secret['password_hash'])) {
			dfb_admin_register_failure($attempt, $maxAttempts, $lockSeconds);
		}
		$error = dfb_admin_validate_password($new, $confirm);
		if($error !== '') {
			throw new DfbError($error, 400);
		}
		if(dfb_admin_verify_password((string)$new, $secret['password_hash'])) {
			throw new DfbError('新密码不能与当前密码相同。', 400);
		}
		$hash = dfb_admin_hash_password($new);
		if($hash === '') {
			throw new DfbError('密码加密失败：请检查 PHP 的 password_hash / hash 扩展。', 500);
		}
		$data = array(
			'password_hash' => $hash,
			'hint'          => $secret['hint'],
			'updated'       => time(),
		);
		if(!dfb_admin_secret_write($data)) {
			throw new DfbError('密码保存失败：请检查 data/ 目录（或 admin_secret_file 所在目录）是否可写，以及文件权限。', 500);
		}
		$check = dfb_admin_secret(true);
		if(!$check['configured'] || !dfb_admin_verify_password($new, $check['password_hash'])) {
			throw new DfbError('密码写入后校验失败：请检查文件权限与 PHP 的密码扩展。', 500);
		}
		$account = dfb_account();
		dfb_state_update(function (&$state) use ($account) {
			/* 密码已变化：清掉所有旧解锁与失败记录，其它会话立即失效 */
			$state['admin_unlocks'] = array();
			$state['admin_attempts'] = array();
		});
		dfb_admin_issue_unlock();
		return array(
			'ok'      => true,
			'message' => '后台密码已修改，其它会话的解锁状态已失效。',
			'admin'   => dfb_admin_status(),
		);
	});
}

/**
 * 输入后台密码并解锁后台管理。
 *
 * @param string $password
 * @return array
 * @throws DfbError
 */
function dfb_admin_unlock($password) {
	if(!dfb_admin_eligible()) {
		throw new DfbError(dfb_admin_deny_reason(), 403);
	}
	$secret = dfb_admin_secret();
	if(!$secret['configured']) {
		throw new DfbError('后台密码尚未设置，请先设置密码。', 409);
	}
	/* 已经解锁：直接返回当前状态，不重复校验 / 计数 */
	if(dfb_admin_unlocked()) {
		return array(
			'ok'      => true,
			'message' => '后台管理已经是解锁状态。',
			'admin'   => dfb_admin_status(),
		);
	}
	$password = (string)$password;
	if($password === '') {
		throw new DfbError('请输入后台密码。', 400);
	}
	if(strpos($password, "\0") !== false) {
		throw new DfbError('密码格式不正确。', 400);
	}

	$config = dfb_config();
	$maxAttempts = intval($config['admin_max_attempts']);
	if($maxAttempts <= 0) {
		$maxAttempts = 5;
	}
	$lockSeconds = intval($config['admin_lock_seconds']);
	if($lockSeconds < 60) {
		$lockSeconds = 600;
	}

	$attempt = dfb_admin_attempt_state();
	$now = time();
	if($attempt['lockedUntil'] > $now) {
		$minutes = (int)ceil(($attempt['lockedUntil'] - $now) / 60);
		throw new DfbError('密码尝试次数过多，请 '.$minutes.' 分钟后再试。', 429);
	}

	if(!dfb_admin_verify_password($password, $secret['password_hash'])) {
		dfb_admin_register_failure($attempt, $maxAttempts, $lockSeconds);
	}

	dfb_admin_issue_unlock();

	return array(
		'ok'      => true,
		'message' => '密码验证通过，后台管理已解锁。',
		'admin'   => dfb_admin_status(),
	);
}

/**
 * 立即锁定后台管理（清掉解锁记录与失败计数）。
 *
 * @return array
 */
function dfb_admin_lock() {
	$account = dfb_account();
	dfb_admin_forget($account);
	return array(
		'ok'      => true,
		'message' => '后台管理已锁定。',
		'admin'   => dfb_admin_status(),
	);
}

/**
 * 清掉某个账号的后台解锁 / 失败记录（退出登录时调用）。
 *
 * @param string $account
 * @return void
 */
function dfb_admin_forget($account) {
	dfb_state_update(function (&$state) use ($account) {
		unset($state['admin_unlocks'][(string)$account]);
		unset($state['admin_attempts'][(string)$account]);
	});
}

/**
 * 具体 admin.* 动作的二次校验：必须有资格且已通过密码验证。
 *
 * @return void
 * @throws DfbError
 */
function dfb_admin_require_unlocked() {
	if(!dfb_admin_eligible()) {
		throw new DfbError(dfb_admin_deny_reason(), 403);
	}
	if(!dfb_admin_unlocked()) {
		throw new DfbError('后台管理已锁定，请先输入后台密码。', 403);
	}
}

/* ======================================================================
 * 五、HTTP 客户端
 * ==================================================================== */

/**
 * 服务器是否具备访问 HTTPS 的能力（curl 扩展或 openssl 流封装）。
 *
 * 便携版 / 精简版 PHP 常常两个都没有，表现就是「所有接口都失败」。
 * 提前判断并给出明确提示，比抛一个「无效的手机号」有用得多。
 *
 * @return bool
 */
function dfb_https_supported() {
	if(function_exists('curl_init')) {
		return true;
	}
	if(function_exists('stream_get_wrappers')) {
		$wrappers = stream_get_wrappers();
		if(is_array($wrappers) && in_array('https', $wrappers, true)) {
			return true;
		}
	}
	return false;
}

/**
 * 把底层传输错误翻译成给用户看的文案。
 *
 * @param array $resp dfb_http() 的返回值
 * @return string
 */
function dfb_http_fail_message($resp) {
	$error = isset($resp['error']) ? (string)$resp['error'] : '';
	if($error === 'unsupported_https') {
		return '服务器 PHP 未启用 curl 或 openssl 扩展，无法访问 HTTPS 接口，请在 php.ini 中开启后重启 PHP。';
	}
	if($error === '') {
		return '无法连接舞立方服务器，请稍后再试。';
	}
	return '无法连接舞立方服务器（'.dfb_truncate($error, 120).'），请稍后再试。';
}

/**
 * 从上游响应体里取可读的错误文案。
 *
 * 舞立方的错误响应形如 ``{"Message":"验证码错误"}``；拿不到就回落默认文案。
 * 取出来的文案会做 UTF-8 归一化，避免把 GBK 字节直接吐给前端。
 *
 * @param array  $resp
 * @param string $fallback
 * @return string
 */
function dfb_upstream_message($resp, $fallback) {
	$body = isset($resp['body']) ? trim((string)$resp['body']) : '';
	if($body !== '') {
		$data = json_decode($body, true);
		if(is_array($data)) {
			foreach(array('Message', 'message', 'detail', 'msg', 'error') as $key) {
				if(isset($data[$key]) && is_scalar($data[$key]) && trim((string)$data[$key]) !== '') {
					return dfb_truncate($data[$key], 80);
				}
			}
		}
		if(!preg_match('/^\s*[<{\[]/', $body)) {
			/* 纯文本错误（不是 JSON、不是 HTML） */
			return dfb_truncate($body, 80);
		}
	}
	return $fallback;
}

/**
 * 根据文件头判断图片真实类型。
 *
 * 舞立方的图形验证码实际是 **GIF**，如果按 image/png 声明，
 * 部分浏览器 / WebView 会直接拒绝渲染（表现为「验证码不显示」）。
 *
 * @param string $bytes
 * @return string 形如 image/gif
 */
function dfb_image_mime($bytes) {
	if(!is_string($bytes) || $bytes === '') {
		return 'image/png';
	}
	$head = substr($bytes, 0, 12);
	if(substr($head, 0, 6) === 'GIF87a' || substr($head, 0, 6) === 'GIF89a') {
		return 'image/gif';
	}
	if(substr($head, 0, 8) === "\x89PNG\r\n\x1a\n") {
		return 'image/png';
	}
	if(substr($head, 0, 3) === "\xFF\xD8\xFF") {
		return 'image/jpeg';
	}
	if(substr($head, 0, 2) === 'BM') {
		return 'image/bmp';
	}
	if(substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
		return 'image/webp';
	}
	return 'image/png';
}

/**
 * 把图片字节包成 data URL（自动带上正确的 MIME）。
 *
 * @param string $bytes
 * @return string
 */
function dfb_image_data_url($bytes) {
	return 'data:'.dfb_image_mime($bytes).';base64,'.base64_encode($bytes);
}

/**
 * 请求外部接口。
 *
 * @param string       $method  GET / POST
 * @param string       $url     完整地址
 * @param array        $headers 形如 ``array('Authorization: bearer xxx')``
 * @param array|string $post    数组 -> 表单编码；字符串 -> 原样请求体；null -> 无请求体
 * @param int          $timeout 秒，0 表示使用插件配置
 * @return array array('status' => int, 'body' => string, 'error' => string)
 */
function dfb_http($method, $url, $headers = array(), $post = null, $timeout = 0) {
	/* 测试桩：单元测试注入固定响应，避免依赖外网 */
	if(isset($GLOBALS['dfb_http_stub']) && is_callable($GLOBALS['dfb_http_stub'])) {
		$stub = call_user_func($GLOBALS['dfb_http_stub'], $method, $url, $headers, $post);
		if(is_array($stub)) {
			return array(
				'status' => isset($stub['status']) ? intval($stub['status']) : 0,
				'body'   => isset($stub['body']) ? (string)$stub['body'] : '',
				'error'  => isset($stub['error']) ? (string)$stub['error'] : '',
			);
		}
	}

	/* HTTPS 但服务器两个传输手段都没有：直接给出可诊断的错误 */
	if(stripos($url, 'https://') === 0 && !dfb_https_supported()) {
		return array('status' => 0, 'body' => '', 'error' => 'unsupported_https');
	}

	$config = dfb_config();
	if(!$timeout) {
		$timeout = intval($config['dfb_timeout']);
	}
	if($timeout < 5) {
		$timeout = 5;
	}
	if($timeout > 120) {
		$timeout = 120;
	}
	$method = strtoupper($method);

	/* 可选代理：服务器只能通过代理出网时，设置环境变量 DFB_HTTP_PROXY=http://host:port */
	$proxy = trim((string)getenv('DFB_HTTP_PROXY'));

	$body = null;
	if(is_array($post)) {
		$body = http_build_query($post);
		$hasType = false;
		foreach($headers as $header) {
			if(stripos($header, 'content-type:') === 0) {
				$hasType = true;
				break;
			}
		}
		if(!$hasType) {
			$headers[] = 'Content-Type: application/x-www-form-urlencoded';
		}
	} elseif(is_string($post)) {
		$body = $post;
	}

	if(function_exists('curl_init')) {
		$ch = curl_init();
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_USERAGENT, 'DanceFengBot-Web/'.DFB_VERSION);
		if($proxy !== '') {
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
		}
		if($headers) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		if($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body);
		} elseif($method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			if($body !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}
		}
		$raw = curl_exec($ch);
		$status = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$error = $raw === false ? curl_error($ch) : '';
		curl_close($ch);
		if($raw === false) {
			return array('status' => 0, 'body' => '', 'error' => $error ? $error : '网络请求失败');
		}
		return array('status' => $status, 'body' => (string)$raw, 'error' => '');
	}

	/* 没有 curl 时退回 stream_context */
	$options = array(
		'http' => array(
			'method'        => $method,
			'timeout'       => $timeout,
			'header'        => implode("\r\n", $headers),
			'ignore_errors' => true,
			'user_agent'    => 'DanceFengBot-Web/'.DFB_VERSION,
		),
	);
	if($method === 'POST') {
		$options['http']['content'] = $body === null ? '' : $body;
	}
	if($proxy !== '') {
		$options['http']['proxy'] = 'tcp://'.preg_replace('#^https?://#i', '', $proxy);
		$options['http']['request_fulluri'] = true;
	}
	$context = @stream_context_create($options);
	$error = '';
	$raw = @file_get_contents($url, false, $context);
	$status = 0;
	if(isset($http_response_header) && is_array($http_response_header)) {
		/* 走代理时第一行是「200 Connection Established」，真正的状态码在后面，
		   所以从后往前找第一条真正的 HTTP 状态行 */
		foreach(array_reverse($http_response_header) as $headerLine) {
			if(preg_match('#^HTTP/\d(?:\.\d)?\s+(\d{3})#i', $headerLine, $matched)) {
				$status = intval($matched[1]);
				break;
			}
		}
	}
	if($raw === false) {
		$last = function_exists('error_get_last') ? error_get_last() : null;
		if(is_array($last) && isset($last['message']) && $last['message'] !== '') {
			$error = dfb_truncate($last['message'], 160);
		}
		if($error === '') {
			$error = '无法建立连接';
		}
		return array('status' => 0, 'body' => '', 'error' => $error);
	}
	return array('status' => $status, 'body' => (string)$raw, 'error' => '');
}

/**
 * 请求并解析 JSON。
 *
 * @param string       $method
 * @param string       $url
 * @param array        $headers
 * @param array|string $post
 * @param int          $timeout
 * @return array array('status' => int, 'data' => mixed, 'body' => string, 'error' => string)
 */
function dfb_http_json($method, $url, $headers = array(), $post = null, $timeout = 0) {
	$resp = dfb_http($method, $url, $headers, $post, $timeout);
	$data = null;
	if($resp['body'] !== '') {
		$decoded = json_decode($resp['body'], true);
		if($decoded !== null || strtolower(trim($resp['body'])) === 'null') {
			$data = $decoded;
		}
	}
	return array(
		'status' => $resp['status'],
		'data'   => $data,
		'body'   => $resp['body'],
		'error'  => $resp['error'],
	);
}

/**
 * 并发执行多条 HTTP 请求（curl_multi）。
 *
 * $requests 形如：
 *   array(
 *     'profile' => array('method' => 'GET', 'url' => '...', 'headers' => array(), 'post' => null),
 *     ...
 *   )
 * 返回同样键名的数组，每项与 dfb_http() 的返回值一致。
 *
 * 没有 curl / curl_multi 或测试桩存在时，自动退回串行 dfb_http()，
 * 保证语义一致。
 *
 * @param array $requests
 * @param int   $timeout
 * @return array
 */
function dfb_http_multi($requests, $timeout = 0) {
	$result = array();
	if(!is_array($requests) || !$requests) {
		return $result;
	}
	if(isset($GLOBALS['dfb_http_stub']) && is_callable($GLOBALS['dfb_http_stub'])) {
		foreach($requests as $key => $req) {
			$result[$key] = dfb_http(
				isset($req['method']) ? $req['method'] : 'GET',
				isset($req['url']) ? $req['url'] : '',
				isset($req['headers']) ? $req['headers'] : array(),
				isset($req['post']) ? $req['post'] : null,
				isset($req['timeout']) ? $req['timeout'] : $timeout
			);
		}
		return $result;
	}
	if(!function_exists('curl_init') || !function_exists('curl_multi_init')) {
		foreach($requests as $key => $req) {
			$result[$key] = dfb_http(
				isset($req['method']) ? $req['method'] : 'GET',
				isset($req['url']) ? $req['url'] : '',
				isset($req['headers']) ? $req['headers'] : array(),
				isset($req['post']) ? $req['post'] : null,
				isset($req['timeout']) ? $req['timeout'] : $timeout
			);
		}
		return $result;
	}

	$config = dfb_config();
	if(!$timeout) {
		$timeout = intval($config['dfb_timeout']);
	}
	if($timeout < 5) {
		$timeout = 5;
	}
	if($timeout > 120) {
		$timeout = 120;
	}
	$proxy = trim((string)getenv('DFB_HTTP_PROXY'));
	$mh = curl_multi_init();
	if(!$mh) {
		foreach($requests as $key => $req) {
			$result[$key] = dfb_http(
				isset($req['method']) ? $req['method'] : 'GET',
				isset($req['url']) ? $req['url'] : '',
				isset($req['headers']) ? $req['headers'] : array(),
				isset($req['post']) ? $req['post'] : null,
				isset($req['timeout']) ? $req['timeout'] : $timeout
			);
		}
		return $result;
	}

	$handles = array();
	foreach($requests as $key => $req) {
		$method = strtoupper(isset($req['method']) ? $req['method'] : 'GET');
		$url = isset($req['url']) ? (string)$req['url'] : '';
		$headers = isset($req['headers']) && is_array($req['headers']) ? $req['headers'] : array();
		$post = isset($req['post']) ? $req['post'] : null;
		$reqTimeout = isset($req['timeout']) && intval($req['timeout']) > 0 ? intval($req['timeout']) : $timeout;
		if($reqTimeout < 5) {
			$reqTimeout = 5;
		}
		if($reqTimeout > 120) {
			$reqTimeout = 120;
		}

		$body = null;
		if(is_array($post)) {
			$body = http_build_query($post);
			$hasType = false;
			foreach($headers as $header) {
				if(stripos($header, 'content-type:') === 0) {
					$hasType = true;
					break;
				}
			}
			if(!$hasType) {
				$headers[] = 'Content-Type: application/x-www-form-urlencoded';
			}
		} elseif(is_string($post)) {
			$body = $post;
		}

		$ch = curl_init();
		if(!$ch) {
			continue;
		}
		curl_setopt($ch, CURLOPT_URL, $url);
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_HEADER, false);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($ch, CURLOPT_MAXREDIRS, 3);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
		curl_setopt($ch, CURLOPT_TIMEOUT, $reqTimeout);
		curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
		curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
		curl_setopt($ch, CURLOPT_USERAGENT, 'DanceFengBot-Web/'.DFB_VERSION);
		if($proxy !== '') {
			curl_setopt($ch, CURLOPT_PROXY, $proxy);
		}
		if($headers) {
			curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
		}
		if($method === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $body === null ? '' : $body);
		} elseif($method !== 'GET') {
			curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);
			if($body !== null) {
				curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
			}
		}
		curl_multi_add_handle($mh, $ch);
		$handles[$key] = $ch;
	}

	$running = 0;
	do {
		do {
			$status = curl_multi_exec($mh, $running);
		} while(defined('CURLM_CALL_MULTI_PERFORM') && $status === CURLM_CALL_MULTI_PERFORM);
		if($running) {
			if(curl_multi_select($mh, 1.0) === -1) {
				usleep(100000);
			}
		}
	} while($running && $status === CURLM_OK);

	foreach($handles as $key => $ch) {
		$raw = curl_multi_getcontent($ch);
		$httpStatus = intval(curl_getinfo($ch, CURLINFO_HTTP_CODE));
		$errno = curl_errno($ch);
		$error = $errno ? curl_error($ch) : '';
		curl_multi_remove_handle($mh, $ch);
		curl_close($ch);
		if($errno || $raw === false) {
			$result[$key] = array('status' => 0, 'body' => '', 'error' => $error ? $error : '网络请求失败');
		} else {
			$result[$key] = array('status' => $httpStatus, 'body' => (string)$raw, 'error' => '');
		}
	}
	curl_multi_close($mh);
	return $result;
}

/**
 * 并发请求并解析 JSON。
 *
 * @param array $requests
 * @param int   $timeout
 * @return array
 */
function dfb_http_json_multi($requests, $timeout = 0) {
	$raw = dfb_http_multi($requests, $timeout);
	$result = array();
	foreach($raw as $key => $resp) {
		$data = null;
		if(isset($resp['body']) && $resp['body'] !== '') {
			$decoded = json_decode($resp['body'], true);
			if($decoded !== null || strtolower(trim($resp['body'])) === 'null') {
				$data = $decoded;
			}
		}
		$result[$key] = array(
			'status' => isset($resp['status']) ? intval($resp['status']) : 0,
			'data'   => $data,
			'body'   => isset($resp['body']) ? (string)$resp['body'] : '',
			'error'  => isset($resp['error']) ? (string)$resp['error'] : '',
		);
	}
	return $result;
}

/**
 * 二维码登录可用的 client_id 列表。
 *
 * 读取顺序：
 *   1. config.php 里的 ``dfb_client_ids``（字符串或数组）；
 *   2. ``data/client_ids.json``（可直接把 Python 端的 TokenIds.json 拷过来）；
 *   3. ``data/client_ids.txt``（一行一个）。
 *
 * 都为空时返回空数组，前端扫码登录会提示去补配置。
 *
 * @return array
 */
function dfb_client_ids() {
	$config = dfb_config();
	$list = dfb_parse_client_ids(isset($config['dfb_client_ids']) ? $config['dfb_client_ids'] : '');
	if($list) {
		return $list;
	}
	foreach(array('client_ids.json', 'client_ids.txt') as $name) {
		$path = dfb_data_dir().$name;
		if(!is_file($path)) {
			continue;
		}
		$raw = @file_get_contents($path);
		if(!is_string($raw) || $raw === '') {
			continue;
		}
		$list = dfb_parse_client_ids($raw);
		if($list) {
			return $list;
		}
	}
	return array();
}

/**
 * 把配置 / JSON / 纯文本里的 client_id 规整成数组。
 *
 * @param mixed $raw
 * @return array
 */
function dfb_parse_client_ids($raw) {
	if(is_array($raw)) {
		$list = array();
		foreach($raw as $item) {
			if(is_scalar($item) && trim((string)$item) !== '') {
				$list[] = trim((string)$item);
			}
		}
		return array_values(array_unique($list));
	}
	$raw = trim((string)$raw);
	if($raw === '') {
		return array();
	}
	if(substr($raw, 0, 1) === '[') {
		$decoded = json_decode($raw, true);
		if(is_array($decoded)) {
			return dfb_parse_client_ids($decoded);
		}
	}
	$parts = preg_split('/[\s,，;；]+/u', $raw);
	$list = array();
	if(is_array($parts)) {
		foreach($parts as $part) {
			$part = trim($part);
			if($part !== '') {
				$list[] = $part;
			}
		}
	}
	return array_values(array_unique($list));
}
