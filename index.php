<?php

/**
 * 舞小枫 · 舞立方助手 —— 独立网页版入口
 *
 * 直接访问本文件即可：不需要 Discuz，也不需要数据库。
 * 页面所需的路由、接口地址、身份与 CSRF 令牌由 PHP 注入到 window.DFB。
 *
 * @package dancefengbot
 */

require_once __DIR__.'/bootstrap.php';

header('Content-Type: text/html; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: SAMEORIGIN');
header('Referrer-Policy: strict-origin-when-cross-origin');
header('X-Robots-Tag: noindex, nofollow');
header(
	"Content-Security-Policy: default-src 'self'; "
	."img-src 'self' data: https:; "
	."style-src 'self' 'unsafe-inline'; "
	."script-src 'self' 'unsafe-inline'; "
	."connect-src 'self'; "
	."font-src 'self' data:; "
	."base-uri 'self'; form-action 'self'"
);

$dfb_config = dfb_config();
$dfb_title = isset($dfb_config['site_name']) ? trim((string)$dfb_config['site_name']) : '';
if($dfb_title === '') {
	$dfb_title = '舞小枫 · 舞立方助手';
}

$vars = array(
	'dfb_title'       => htmlspecialchars($dfb_title, ENT_QUOTES, 'UTF-8'),
	'dfb_site_name'   => htmlspecialchars($dfb_title, ENT_QUOTES, 'UTF-8'),
	'dfb_json'        => dfb_view_config(),
	'dfb_static'      => dfb_static_base(),
	'dfb_plugin_url'  => dfb_plugin_url('index'),
	'dfb_manual'      => 'https://www.kdocs.cn/l/ccLRaUCuNMX0',
	'dfb_version'     => DFB_VERSION,
);

$template = @file_get_contents(__DIR__.'/template/index.htm');
if($template === false) {
	http_response_code(500);
	header('Content-Type: text/plain; charset=utf-8');
	echo '模板文件缺失：template/index.htm';
	exit;
}

$replace = array();
foreach($vars as $key => $value) {
	$replace['{$'.$key.'}'] = (string)$value;
}

echo strtr($template, $replace);
