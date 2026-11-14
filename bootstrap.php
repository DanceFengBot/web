<?php

/**
 * 舞小枫 · 舞立方助手 —— 独立网页版启动文件
 *
 * 页面（index.php）和接口（api.php）都从这里进入：
 *   1. 定义独立版常量，加载公共库；
 *   2. 读取 config.php，设置时区 / 错误显示；
 *   3. 为当前浏览器生成 CSRF 令牌（FORMHASH）。
 *
 * @package dancefengbot
 */

if(defined('DFB_BOOTSTRAPPED')) {
	return;
}
define('DFB_BOOTSTRAPPED', true);
define('DFB_STANDALONE', true);

if(!defined('DFB_ROOT_DIR')) {
	define('DFB_ROOT_DIR', __DIR__.'/');
}
if(!defined('DFB_VERSION')) {
	define('DFB_VERSION', '2026.11.05');
}

/* 生产环境默认不回显错误：任何 Warning 混进 HTML / JSON 都会破坏页面 */
error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('html_errors', '0');
ini_set('log_errors', '1');

require_once DFB_ROOT_DIR.'include/dfb_core.php';
require_once DFB_ROOT_DIR.'include/dfb_announcement.php';
require_once DFB_ROOT_DIR.'include/dfb_site.php';
require_once DFB_ROOT_DIR.'include/dfb_messages.php';
require_once DFB_ROOT_DIR.'include/dfb_changelog.php';
require_once DFB_ROOT_DIR.'include/dfb_machine_reviews.php';
require_once DFB_ROOT_DIR.'include/dfb_machine_exclusions.php';

$dfb_boot_config = dfb_config();

/* 调试开关：config.php 里 'debug' => true 时才显示错误 */
if(!empty($dfb_boot_config['debug'])) {
	ini_set('display_errors', '1');
}

$dfb_boot_tz = isset($dfb_boot_config['timezone']) ? trim((string)$dfb_boot_config['timezone']) : '';
if($dfb_boot_tz !== '') {
	@date_default_timezone_set($dfb_boot_tz);
}

/* 当前浏览器身份的 CSRF 令牌；页面注入、接口校验都用它 */
if(!defined('FORMHASH')) {
	define('FORMHASH', dfb_formhash());
}
