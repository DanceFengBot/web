<?php

/**
 * 舞小枫 · 舞立方助手 —— 独立网页版接口
 *
 * 访问方式：api.php?act=xxx（页面里的 window.DFB.apiUrl）
 *
 * 与插件版保持同一套接口契约：
 *   - 接口名、请求方法、参数名全部走白名单；
 *   - 校验 formhash（防 CSRF）；
 *   - 账号标识由服务端按浏览器 Cookie 生成，前端传什么都无效。
 *
 * 除 ``act=ratio.image`` / ``act=level.image`` / ``act=user.image`` 返回 PNG 二进制外，其余 action 都返回 JSON。
 *
 * @package dancefengbot
 */

require_once __DIR__.'/bootstrap.php';
require_once __DIR__.'/include/dfb_cube.php';
require_once __DIR__.'/include/dfb_cube_ext.php';
require_once __DIR__.'/include/dfb_ratio_image.php';
require_once __DIR__.'/include/dfb_level_image.php';
require_once __DIR__.'/include/dfb_user_image.php';
require_once __DIR__.'/include/dfb_actions.php';

/* 跨域预检 / CORS（config.php 的 cors_origin 留空时不生效） */
dfb_send_cors_headers();

/**
 * 把 PHP 的 warning / notice 写进错误日志，而不是回显到响应体。
 *
 * @param int    $errno
 * @param string $errstr
 * @param string $errfile
 * @param int    $errline
 * @return bool
 */
function dfb_api_error_handler($errno, $errstr, $errfile = '', $errline = 0) {
	if(!(error_reporting() & $errno)) {
		return true;
	}
	@error_log('[dancefengbot] '.$errstr.' @ '.basename($errfile).':'.$errline);
	return true;
}

/**
 * 致命错误兜底：任何情况下都让接口返回合法 JSON。
 */
function dfb_api_shutdown() {
	if(!empty($GLOBALS['dfb_json_done'])) {
		return;
	}
	$last = function_exists('error_get_last') ? error_get_last() : null;
	if(!is_array($last)) {
		return;
	}
	$fatal = array(E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR);
	if(!in_array($last['type'], $fatal, true)) {
		return;
	}
	while(ob_get_level() > 0) {
		@ob_end_clean();
	}
	if(!headers_sent()) {
		@header_remove('Content-Encoding');
		header('Content-Type: application/json; charset=utf-8');
		header('X-Content-Type-Options: nosniff');
	}
	@error_log('[dancefengbot] fatal: '.$last['message'].' @ '.$last['file'].':'.$last['line']);
	$detail = '服务器处理请求时出错（'.basename($last['file']).':'.$last['line'].'），请把这条信息发给站点管理员。';
	if(function_exists('dfb_json_encode')) {
		echo dfb_json_encode(array('detail' => $detail));
	} else {
		echo '{"detail":"Internal Server Error"}';
	}
}

/* ------------------------------------------------------------------
 * 保护输出：除战力模板图片外只返回 JSON。先关掉错误回显、清空可能存在的
 * 输出缓冲，再声明 Content-Type，保证响应体里没有 BOM / 空白 / 警告。
 * ------------------------------------------------------------------ */
@ini_set('display_errors', '0');
@ini_set('html_errors', '0');
@ini_set('zlib.output_compression', '0');
@ini_set('output_handler', '');
@ini_set('default_charset', 'UTF-8');
while(ob_get_level() > 0) {
	@ob_end_clean();
}
if(!headers_sent()) {
	@header_remove('Content-Encoding');
	@header_remove('Content-Type');
	@header_remove('WWW-Authenticate');
	header('Content-Type: application/json; charset=utf-8');
	header('X-Content-Type-Options: nosniff');
	header('X-Robots-Tag: noindex, nofollow');
}
ob_start();

set_error_handler('dfb_api_error_handler');
register_shutdown_function('dfb_api_shutdown');

/* ---------- 1. 取接口名 ---------- */
$act = '';
if(isset($_GET['act'])) {
	$act = dfb_stripslashes_deep($_GET['act']);
}
$act = is_string($act) ? trim($act) : '';

$actions = dfb_action_map();
if(!isset($actions[$act])) {
	dfb_json_output(array('detail' => '未知的接口请求：'.$act), 404);
}
$action = $actions[$act];

/* ---------- 2. CSRF 校验（bootstrap 只需要身份，不需要 formhash） ---------- */
if($act !== 'bootstrap') {
	dfb_check_formhash();
}

/* ---------- 3. 请求方法校验 ---------- */
$method = isset($_SERVER['REQUEST_METHOD']) ? strtoupper($_SERVER['REQUEST_METHOD']) : 'GET';
if($method !== $action['method']) {
	dfb_json_output(array('detail' => '该接口只接受 '.$action['method'].' 请求。'), 405);
}

/* ---------- 3.1 站点关闭：非管理员只允许启动/状态/登录/退出 ---------- */
if(dfb_site_is_closed()) {
	$siteAllowed = in_array($act, array(
		'bootstrap',
		'state',
		'login.qrcode.start',
		'login.qrcode.poll',
		'login.phone.start',
		'login.phone.sms',
		'login.phone.verify',
		'logout',
	), true);
	if(!$siteAllowed && dfb_admin_eligible()) {
		$siteAllowed = true;
	}
	if(!$siteAllowed) {
		dfb_json_output(array(
			'detail'     => '网站维护中，仅开放登录与退出，请稍后再试。',
			'siteClosed' => true,
		), 503);
	}
}

/* ---------- 4. 功能开关 ---------- */
if(!dfb_feature_allowed($action['feature'])) {
	$deny = array('detail' => dfb_deny_message($action['feature']));
	if($action['feature'] === 'admin') {
		$deny['admin'] = dfb_admin_status();
	}
	dfb_json_output($deny, 403);
}

/* ---------- 4.1 后台管理二次校验（必须先通过后台密码） ---------- */
if(!empty($action['admin_only'])) {
	try {
		dfb_admin_require_unlocked();
	} catch (DfbError $e) {
		dfb_json_output(array(
			'detail' => $e->getMessage(),
			'admin'  => dfb_admin_status(),
		), $e->status);
	}
}

/* ---------- 5. 组装白名单参数 ---------- */
$input = array();
foreach($action['query'] as $name) {
	if(isset($_GET[$name]) && $_GET[$name] !== '') {
		$value = dfb_stripslashes_deep($_GET[$name]);
		if(is_scalar($value)) {
			$input[$name] = (string)$value;
		}
	}
}
if(!empty($action['body'])) {
	$payload = dfb_read_payload();
	foreach($action['body'] as $name) {
		if(isset($payload[$name])) {
			$input[$name] = $payload[$name];
		}
	}
}

/* ---------- 5.1 模板图片（二进制 PNG）：战力 / 分数列表 / 个人信息 ---------- */
if($act === 'ratio.image' || $act === 'level.image' || $act === 'user.image') {
	$account = dfb_account();
	$png = null;
	$cacheKey = '';
	$cacheHit = false;
	try {
		if($act === 'ratio.image') {
			$mode = isset($input['mode']) ? $input['mode'] : 'b15';
			$cacheKey = 'ratio|'.DFB_VERSION.'|'.$account.'|'.$mode;
			$png = dfb_image_final_cache_get($cacheKey);
			$cacheHit = is_string($png);
			if(!$cacheHit) {
				$png = dfb_render_ratio_image($account, $mode);
				dfb_image_final_cache_set($cacheKey, $png);
			}
		} elseif($act === 'level.image') {
			$level = isset($input['level']) ? intval($input['level']) : 1;
			$page = isset($input['page']) ? intval($input['page']) : 1;
			$cacheKey = 'level|'.DFB_VERSION.'|'.$account.'|'.$level.'|'.$page;
			$png = dfb_image_final_cache_get($cacheKey);
			$cacheHit = is_string($png);
			if(!$cacheHit) {
				$png = dfb_render_level_image($account, $level, $page);
				dfb_image_final_cache_set($cacheKey, $png);
			}
		} else {
			$target = isset($input['target']) ? trim((string)$input['target']) : '';
			$targetId = $target === '' ? 0 : dfb_resolve_target($target);
			$cacheKey = 'user|'.DFB_VERSION.'|'.$account.'|'.$targetId;
			$png = dfb_image_final_cache_get($cacheKey);
			$cacheHit = is_string($png);
			if(!$cacheHit) {
				$png = dfb_render_user_image($account, $targetId);
				dfb_image_final_cache_set($cacheKey, $png);
			}
		}
	} catch (DfbError $e) {
		dfb_json_output(array(
			'detail' => $e->getMessage(),
			'loginRequired' => !empty($e->data['loginRequired']),
		), $e->status);
	} catch (Exception $e) {
		dfb_json_output(array('detail' => '图片生成失败：'.dfb_truncate($e->getMessage(), 160)), 500);
	}
	if(!is_string($png) || $png === '') {
		dfb_json_output(array('detail' => '图片生成失败，请稍后重试'), 500);
	}
	while(ob_get_level() > 0) {
		@ob_end_clean();
	}
	if(!headers_sent()) {
		$imageTtl = function_exists('dfb_image_final_cache_ttl') ? dfb_image_final_cache_ttl() : 180;
		$etag = '"'.sha1($png).'"';
		@header_remove('Content-Type');
		@header_remove('Content-Encoding');
		header('Content-Type: image/png');
		header('Cache-Control: private, max-age='.$imageTtl.', stale-while-revalidate=60');
		header('ETag: '.$etag);
		header('X-Image-Cache: '.($cacheHit ? 'HIT' : 'MISS'));
		header('X-Content-Type-Options: nosniff');
		$ifNoneMatch = isset($_SERVER['HTTP_IF_NONE_MATCH']) ? trim((string)$_SERVER['HTTP_IF_NONE_MATCH']) : '';
		if($ifNoneMatch !== '' && $ifNoneMatch === $etag) {
			http_response_code(304);
			exit;
		}
		header('Content-Length: '.strlen($png));
	}
	$GLOBALS['dfb_json_done'] = true;
	echo $png;
	exit;
}

/* ---------- 6. 执行 ---------- */
$account = dfb_account();

try {
	$handler = $action['handler'];
	if(!is_callable($handler)) {
		throw new Exception('接口处理函数缺失：'.$handler);
	}
	$result = call_user_func($handler, $input, $account);
	dfb_json_output($result, 200);
} catch (DfbError $e) {
	$error = array('detail' => $e->getMessage());
	if(!empty($e->data)) {
		$error = array_merge($error, $e->data);
	}
	/* 后台相关失败一并回传最新状态，方便前端刷新剩余次数 / 锁定时间 */
	if($action['feature'] === 'admin') {
		$error['admin'] = dfb_admin_status();
	}
	dfb_json_output($error, $e->status);
} catch (Exception $e) {
	dfb_json_output(array('detail' => '服务器处理失败：'.dfb_truncate($e->getMessage(), 200)), 500);
}
