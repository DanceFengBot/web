<?php

/**
 * 舞小枫 · 舞立方助手 —— 独立网页版配置
 *
 * 直接修改本文件即可，不需要后台。改完刷新页面生效；
 * 修改时区 / 扩展相关项后建议重启 PHP。
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

return array(

	/* ---------------------------------------------------------------- 站点 */
	/* 浏览器标题栏、页面标题使用的站点名 */
	'site_name'      => '舞小枫 · 舞立方助手',

	/* 时区，影响日志时间；一般不用改 */
	'timezone'       => 'Asia/Shanghai',

	/* 调试模式：true 时把 PHP 错误直接显示在页面上（仅本地调试用） */
	'debug'          => false,

	/* ------------------------------------------------------------ 舞立方接口 */
	/* 接口请求超时（秒）。战力分析会并行请求多条接口，出网慢可调到 40 */
	'dfb_timeout'    => '20',

	/* 战力分析数据缓存秒数；0 表示不缓存。B15/B30/AP30 共用同一份缓存 */
	'dfb_ratio_cache_ttl' => '45',

	/* 扫码登录客户端 ID（可选）。留空时读取 data/client_ids.json 或 data/client_ids.txt */
	'dfb_client_ids' => '',

	/* 高德地图 Web 服务 Key，只有「查找机台」需要 */
	'dfb_gaode_key'  => '',

	/*
	 * 跨域白名单（uni-app H5 / 独立前端需要）。
	 * 留空 = 不发送任何 CORS 头，网页版行为不变；
	 * 填 * = 允许任意来源（回显 Origin）；
	 * 或填逗号分隔的来源，例如：
	 *   'cors_origin' => 'https://app.example.com,http://localhost:8080',
	 * App（非 H5）不受 CORS 限制，不需要配置。
	 */
	'cors_origin'    => '',

	/* ---------------------------------------------------------------- 权限 */
	/*
	 * 后台管理（Token 管理 / 客户端 ID 管理）总开关。
	 *
	 * 只有「舞立方 ID」出现在 admin_user_ids 里的账号登录后，页面才会出现
	 * 「后台管理」入口；首次进入必须设置后台密码，之后必须输入密码。
	 * 密码只保存带随机盐的哈希（bcrypt / PBKDF2），不会保存明文。
	 * 设为 false 时，任何账号都看不到后台管理。
	 */
	'admin_enabled'  => true,

	/* 允许进入后台管理的舞立方用户 ID（逗号 / 空格分隔，也可以写数组） */
	'admin_user_ids' => '',

	/* 后台密码最小长度（字符数）。默认 8，最低按 6 生效 */
	'admin_password_min_length' => '8',

	/* 通过密码验证后，后台管理的保持时间（秒）。默认 1800 = 30 分钟 */
	'admin_unlock_ttl' => '1800',

	/* 连续输错多少次密码后锁定，以及锁定多少秒 */
	'admin_max_attempts' => '5',
	'admin_lock_seconds' => '600',

	/*
	 * 后台密码哈希文件。
	 *
	 * 留空时读取 data/admin.php。data/ 目录已禁止 Web 访问，文件首行还有
	 * <?php exit; ?> 守卫，直接访问只会得到空白；文件里只有 password_hash，
	 * 没有明文密码。
	 *
	 * 想更安全，可以把该文件放到站点根目录之外，再在这里填绝对路径：
	 *   'admin_secret_file' => '/var/www/private/dfb-admin.json',
	 *   'admin_secret_file' => 'D:/private/dfb-admin.json',
	 * 此时文件是纯 JSON，不需要 <?php exit; ?> 首行。
	 */
	'admin_secret_file' => '',

);
