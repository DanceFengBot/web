<?php

/**
 * 舞小枫 · 舞立方助手 —— 扩展数据接口
 *
 * 包含三部分：
 *   1. 登录：微信扫码（获取二维码 + 轮询）与手机号（图形码 + 短信码）；
 *   2. 查询：按地区查找机台（高德地理编码 + 舞立方机台接口）；
 *   3. 兑换：批量兑换自制谱兑换码；
 *   4. 帮助：功能说明（纯本地静态数据，不再依赖后端）。
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

/* ======================================================================
 * 一、微信扫码登录
 * ==================================================================== */

/**
 * 生成登录二维码。
 *
 * @param string $account 当前账号标识（服务端注入）
 * @return array
 * @throws DfbError
 */
function dfb_login_qr_start($account) {
	$ids = dfb_client_ids();
	if(!$ids) {
		throw new DfbError('还没有配置可用的客户端 ID：请把 client_ids.json 放到 data/ 目录，或在 config.php 里填写 dfb_client_ids。', 500);
	}
	$clientId = $ids[mt_rand(0, count($ids) - 1)];

	$resp = dfb_http_json('GET', DFB_CUBE_BASE.'/api/Common/GetQrCode?id='.rawurlencode($clientId), array(), null, 20);
	if($resp['status'] === 0) {
		throw new DfbError(dfb_http_fail_message($resp), 502);
	}
	if($resp['status'] !== 200) {
		throw new DfbError('二维码获取失败（HTTP '.intval($resp['status']).'），请稍后再试', 502);
	}
	$url = '';
	if(is_array($resp['data']) && isset($resp['data']['QrcodeUrl'])) {
		$url = trim((string)$resp['data']['QrcodeUrl']);
	}
	if($url === '') {
		throw new DfbError('客户端 ID 响应解析失败，可能已失效，请更新 data/client_ids.json。', 502);
	}

	$image = dfb_http('GET', $url, array(), null, 20);
	if($image['status'] === 0) {
		throw new DfbError(dfb_http_fail_message($image), 502);
	}
	if($image['status'] !== 200 || $image['body'] === '') {
		throw new DfbError('二维码图片下载失败，请稍后再试', 502);
	}

	/* 同一账号重新获取二维码时，先清掉上一次未完成的会话，避免旧票据长期占位 */
	dfb_session_clear_account($account);

	$ticket = dfb_new_ticket();
	dfb_session_set($ticket, array(
		'type'     => 'qrcode',
		'clientId' => $clientId,
		'account'  => $account,
		'created'  => time(),
	));

	return array(
		'ok'     => true,
		'ticket' => $ticket,
		'qr'     => dfb_image_data_url($image['body']),
		'tip'    => '🤗 快用微信扫码，在五分钟内登录上吧~',
	);
}

/**
 * 轮询扫码结果（每次请求只查一次，不阻塞）。
 *
 * @param string $ticket
 * @param string $account
 * @return array
 * @throws DfbError
 */
function dfb_login_qr_poll($ticket, $account) {
	$session = dfb_session_get($ticket);
	if(!$session || !isset($session['type']) || $session['type'] !== 'qrcode') {
		throw new DfbError('登录票据已失效，请重新获取二维码。', 404);
	}
	if(!isset($session['account']) || $session['account'] !== $account) {
		throw new DfbError('登录票据与当前账号不匹配，请重新获取二维码。', 403);
	}
	if(time() - intval($session['created']) > 300) {
		dfb_session_delete($ticket);
		return array('ok' => false, 'status' => 'timeout', 'message' => '超时啦~ 请重试一下吧！');
	}

	$resp = dfb_http_json(
		'POST',
		DFB_CUBE_BASE.'/token',
		array('Content-Type: application/x-www-form-urlencoded'),
		array(
			'client_type' => 'qrcode',
			'grant_type'  => 'client_credentials',
			'client_id'   => $session['clientId'],
		),
		15
	);
	if($resp['status'] !== 200 || !is_array($resp['data']) || empty($resp['data']['access_token'])) {
		return array('ok' => true, 'status' => 'waiting', 'message' => '等待扫码中…');
	}

	$token = array(
		'userId'       => isset($resp['data']['userId']) ? intval($resp['data']['userId']) : 0,
		'accessToken'  => (string)$resp['data']['access_token'],
		'refreshToken' => isset($resp['data']['refresh_token']) ? (string)$resp['data']['refresh_token'] : '',
		'recTime'      => dfb_now_ms(),
		'available'    => 1,
	);
	dfb_token_set($account, $token);
	dfb_session_delete($ticket);

	return array(
		'ok'      => true,
		'status'  => 'success',
		'userId'  => $token['userId'],
		'message' => '登录成功啦~(●\'◡\'●) 你的ID是：'.$token['userId'],
	);
}

/* ======================================================================
 * 二、手机号登录
 * ==================================================================== */

/**
 * 校验手机号登录会话。
 *
 * @param string $ticket
 * @param string $account
 * @return array
 * @throws DfbError
 */
function dfb_phone_session($ticket, $account) {
	$session = dfb_session_get($ticket);
	if(!$session || !isset($session['type']) || $session['type'] !== 'phone') {
		throw new DfbError('登录票据已失效，请重新获取图形验证码。', 404);
	}
	if(!isset($session['account']) || $session['account'] !== $account) {
		throw new DfbError('登录票据与当前账号不匹配，请重新获取图形验证码。', 403);
	}
	if(time() - intval($session['created']) > 900) {
		dfb_session_delete($ticket);
		throw new DfbError('登录票据已超时，请重新获取图形验证码。', 400);
	}
	return $session;
}

/**
 * 第一步：获取图形验证码。
 *
 * @param string $account
 * @param string $phone
 * @return array
 * @throws DfbError
 */
function dfb_login_phone_start($account, $phone) {
	$phone = trim((string)$phone);
	if($phone === '') {
		throw new DfbError('请填写手机号', 400);
	}
	if(!preg_match('/^1\d{6,14}$/', $phone)) {
		throw new DfbError('手机号格式看起来不太对，请检查后重试。', 400);
	}

	$resp = dfb_http('GET', DFB_CUBE_BASE.'/api/Common/GetGraphCode?phone='.rawurlencode($phone), array(), null, 20);
	if($resp['status'] === 0) {
		throw new DfbError(dfb_http_fail_message($resp), 502);
	}

	$body = trim((string)$resp['body']);
	if($resp['status'] !== 200) {
		/* 上游错误形如 {"Message":"不合法的手机号"}，优先回显它自己的说法 */
		throw new DfbError(
			dfb_upstream_message($resp, '图形验证码获取失败（HTTP '.intval($resp['status']).'），请稍后再试。'),
			400
		);
	}

	/* 上游返回的是一段被双引号包住的 base64 文本；也兼容直接返回 data URL 的情况 */
	$raw = trim($body, " \t\n\r\0\x0B\"'");
	if(stripos($raw, 'data:') === 0) {
		$comma = strpos($raw, ',');
		$raw = $comma === false ? '' : substr($raw, $comma + 1);
	}
	$raw = preg_replace('/\s+/', '', $raw);
	if($raw === '' || !preg_match('#^[A-Za-z0-9+/=]+$#', $raw)) {
		throw new DfbError('图形验证码响应格式异常，请稍后再试。', 502);
	}
	$image = base64_decode($raw, true);
	if(!is_string($image) || strlen($image) < 32) {
		throw new DfbError('图形验证码解析失败，请稍后再试。', 502);
	}

	dfb_session_clear_account($account);

	$ticket = dfb_new_ticket();
	dfb_session_set($ticket, array(
		'type'    => 'phone',
		'phone'   => $phone,
		'account' => $account,
		'created' => time(),
	));

	return array(
		'ok'     => true,
		'ticket' => $ticket,
		'phone'  => $phone,
		/* 舞立方返回的实际是 GIF，这里按文件头自动判断，避免 MIME 写死成 png 后浏览器不渲染 */
		'graph'  => dfb_image_data_url($image),
		'mime'   => dfb_image_mime($image),
		'tip'    => '🐈 reCATcha 猫娘检测！请填写图片中的识别码',
	);
}

/**
 * 第二步：校验图形验证码并下发短信。
 *
 * @param string $ticket
 * @param string $account
 * @param string $graphCode
 * @return array
 * @throws DfbError
 */
function dfb_login_phone_sms($ticket, $account, $graphCode) {
	$graphCode = trim((string)$graphCode);
	if($graphCode === '') {
		throw new DfbError('请填写图形验证码', 400);
	}
	$session = dfb_phone_session($ticket, $account);

	$resp = dfb_http(
		'GET',
		DFB_CUBE_BASE.'/api/Common/GetSMSCode?phone='.rawurlencode($session['phone']).'&graphCode='.rawurlencode($graphCode),
		array(),
		null,
		20
	);
	if($resp['status'] === 0) {
		throw new DfbError(dfb_http_fail_message($resp), 502);
	}
	if($resp['status'] !== 200) {
		/* 优先回显上游自己的说法（形如 {"Message":"验证码错误"}） */
		throw new DfbError(dfb_upstream_message($resp, '图形验证码错误，请重新输入'), 400);
	}
	return array('ok' => true, 'message' => '很棒，你不是一只猫娘！验证码已发出，请及时查收', 'phone' => $session['phone']);
}

/**
 * 第三步：短信验证码换 Token。
 *
 * @param string $ticket
 * @param string $account
 * @param string $smsCode
 * @return array
 * @throws DfbError
 */
function dfb_login_phone_verify($ticket, $account, $smsCode) {
	$smsCode = trim((string)$smsCode);
	if($smsCode === '') {
		throw new DfbError('请填写短信验证码', 400);
	}
	$session = dfb_phone_session($ticket, $account);

	$resp = dfb_http_json(
		'POST',
		DFB_CUBE_BASE.'/token',
		array('Content-Type: application/x-www-form-urlencoded'),
		array(
			'client_type'   => 'phone',
			'grant_type'    => 'client_credentials',
			'client_id'     => $session['phone'],
			'client_secret' => $smsCode,
		),
		20
	);
	if($resp['status'] === 0) {
		throw new DfbError(dfb_http_fail_message($resp), 502);
	}
	if($resp['status'] !== 200 || !is_array($resp['data']) || empty($resp['data']['access_token'])) {
		throw new DfbError(dfb_upstream_message($resp, '验证码错误，请重新输入'), 400);
	}

	$token = array(
		'userId'       => isset($resp['data']['userId']) ? intval($resp['data']['userId']) : 0,
		'accessToken'  => (string)$resp['data']['access_token'],
		'refreshToken' => isset($resp['data']['refresh_token']) ? (string)$resp['data']['refresh_token'] : '',
		'recTime'      => dfb_now_ms(),
		'available'    => 1,
	);
	dfb_token_set($account, $token);
	dfb_session_delete($ticket);

	return array(
		'ok'      => true,
		'userId'  => $token['userId'],
		'message' => '登录成功啦~(●\'◡\'●) 你的ID是：'.$token['userId'],
	);
}

/* ======================================================================
 * 三、机台查询
 * ==================================================================== */

/**
 * 按地区查找舞立方机台。
 *
 * @param string $region
 * @return array
 * @throws DfbError
 */
function dfb_data_machine_list($region, $account = '') {
	$region = trim((string)$region);
	if($region === '') {
		throw new DfbError('格式：查找舞立方 (地区)', 400);
	}
	$len = function_exists('mb_strlen') ? mb_strlen($region, 'UTF-8') : strlen($region);
	if($len > 15) {
		throw new DfbError('地区名太长了（最多 15 个字）', 400);
	}

	$config = dfb_config();
	$key = trim((string)$config['dfb_gaode_key']);
	if($key === '' || $key === 'xxx') {
		throw new DfbError('还没有配置「高德地图 Key」，请在 config.php 里填写 dfb_gaode_key（只有「查找机台」需要）。', 500);
	}

	$geo = dfb_http_json(
		'GET',
		'https://restapi.amap.com/v3/geocode/geo?address='.rawurlencode($region).'&output=json&key='.rawurlencode($key),
		array(),
		null,
		20
	);
	if($geo['status'] !== 200 || !is_array($geo['data'])) {
		throw new DfbError('高德地图接口请求失败，请稍后再试。', 502);
	}
	if(isset($geo['data']['status']) && (string)$geo['data']['status'] === '0') {
		$info = isset($geo['data']['info']) ? dfb_truncate($geo['data']['info'], 60) : '未知错误';
		throw new DfbError('高德地图返回错误：'.$info.'（请检查 Key 是否有效、配额是否用尽）', 502);
	}
	$location = '';
	if(isset($geo['data']['geocodes'][0]['location'])) {
		$location = (string)$geo['data']['geocodes'][0]['location'];
	}
	if($location === '' || strpos($location, ',') === false) {
		throw new DfbError('在“'.$region.'”似乎没有找到舞立方欸...', 404);
	}
	$parts = explode(',', $location);
	$lng = trim($parts[0]);
	$lat = trim($parts[1]);

	$resp = dfb_http_json(
		'GET',
		DFB_CUBE_BASE.'/OAuth/GetMachineListByLocation?lng='.rawurlencode($lng).'&lat='.rawurlencode($lat),
		array(),
		null,
		25
	);
	if($resp['status'] !== 200 || !is_array($resp['data'])) {
		throw new DfbError('机台列表获取失败，请稍后再试。', 502);
	}

	$list = array();
	$excluded = array();
	$online = 0;
	$exclusions = function_exists('dfb_machine_exclusions_read') ? dfb_machine_exclusions_read() : array();
	$token = $account !== '' && function_exists('dfb_token_get') ? dfb_token_get($account) : null;
	$currentUserId = is_array($token) && isset($token['userId']) ? intval($token['userId']) : 0;
	foreach($resp['data'] as $item) {
		if(!is_array($item)) {
			continue;
		}
		$isOnline = !empty($item['Online']);
		if($isOnline) {
			$online++;
		}
		$terminalId = isset($item['MachineTerminalID']) ? trim((string)$item['MachineTerminalID']) : '';
		$images = array();
		foreach(array('Img1', 'Img2') as $imageKey) {
			$path = isset($item[$imageKey]) ? trim((string)$item[$imageKey]) : '';
			if($path === '') {
				continue;
			}
			if(stripos($path, 'http://') === 0 || stripos($path, 'https://') === 0) {
				$images[] = $path;
			} else {
				$images[] = rtrim(DFB_CUBE_BASE, '/').'/'.ltrim($path, '/');
			}
		}
		$summary = $terminalId !== '' ? dfb_machine_reviews_summary($terminalId) : array('count' => 0, 'avg' => 0);
		$record = array(
			'terminalId'   => $terminalId,
			'placeName'    => isset($item['PlaceName']) ? (string)$item['PlaceName'] : '',
			'address'      => isset($item['Address']) ? (string)$item['Address'] : '',
			'provinceCity' => isset($item['ProvinceAndCity']) ? (string)$item['ProvinceAndCity'] : '',
			'online'       => $isOnline,
			'show'         => isset($item['MachineType']) && intval($item['MachineType']) === 1,
			'verified'     => isset($item['VerifiedState']) ? intval($item['VerifiedState']) : 0,
			'latitude'     => isset($item['Latitude']) ? (float)$item['Latitude'] : 0,
			'longitude'    => isset($item['Longitude']) ? (float)$item['Longitude'] : 0,
			'images'       => $images,
			'reviewCount'  => intval($summary['count']),
			'reviewAvg'    => floatval($summary['avg']),
		);
		$exclusion = ($terminalId !== '' && isset($exclusions[$terminalId])) ? $exclusions[$terminalId] : null;
		if($exclusion && $exclusion['status'] === 'approved') {
			if($isOnline && $online > 0) {
				$online--;
			}
			$record['exclusionReason'] = $exclusion['reason'];
			$record['exclusionBy'] = $exclusion['userName'];
			$record['exclusionAt'] = $exclusion['createdAt'];
			$excluded[] = $record;
			continue;
		}
		$record['exclusionStatus'] = $exclusion ? $exclusion['status'] : '';
		$record['exclusionReason'] = $exclusion ? $exclusion['reason'] : '';
		$record['exclusionMine'] = $exclusion && intval($exclusion['userId']) === $currentUserId;
		$list[] = $record;
	}
	if(!$list && !$excluded) {
		throw new DfbError('在“'.$region.'”似乎没有找到舞立方欸...', 404);
	}

	return array(
		'ok'            => true,
		'region'        => $region,
		'total'         => count($list),
		'online'        => $online,
		'list'          => $list,
		'excluded'      => $excluded,
		'excludedTotal' => count($excluded),
	);
}

/* ======================================================================
 * 四、兑换码
 * ==================================================================== */

/**
 * 批量兑换自制谱兑换码（成功一个即停止，与 QQ 端行为一致）。
 *
 * @param array $token
 * @param array $codes
 * @return array
 * @throws DfbError
 */
function dfb_data_gain_codes($token, $codes) {
	$cleaned = array();
	if(is_array($codes)) {
		foreach($codes as $code) {
			$text = trim((string)$code);
			if($text !== '' && strlen($text) === 15 && preg_match('/^[A-Za-z0-9]{15}$/', $text)) {
				$cleaned[] = $text;
			}
		}
	}
	if(!$cleaned) {
		throw new DfbError('没有识别到有效的 15 位兑换码', 400);
	}
	$cleaned = array_slice($cleaned, 0, 24);

	$results = array();
	foreach($cleaned as $code) {
		$resp = dfb_http(
			'POST',
			DFB_CUBE_BASE.'/api/MusicData/GainMusicByCode?code='.rawurlencode($code),
			dfb_auth_headers($token),
			'',
			25
		);
		if($resp['status'] === 0) {
			$results[] = array('code' => $code, 'status' => '网络异常', 'ok' => false);
			break;
		}
		$ok = $resp['status'] === 200;
		$results[] = array(
			'code'   => $code,
			'status' => $ok ? '兑换成功' : '失败（'.intval($resp['status']).'）',
			'ok'     => $ok,
		);
		if($ok) {
			break;
		}
	}

	$success = false;
	foreach($results as $row) {
		if($row['ok']) {
			$success = true;
			break;
		}
	}
	return array(
		'ok'      => true,
		'success' => $success,
		'results' => $results,
		'message' => $success ? '兑换成功啦！快去背包找找吧' : '好像都失效了💦💦 换几个试试吧！',
	);
}

/* ======================================================================
 * 五、功能说明（本地静态数据）
 * ==================================================================== */

/**
 * 功能说明数据（对应原后端的 ``/api/help``）。
 *
 * @return array
 */
function dfb_data_help() {
	return array(
		'ok'        => true,
		'version'   => DFB_VERSION,
		'manualUrl' => 'https://www.kdocs.cn/l/ccLRaUCuNMX0',
		'note'      => '网页版由浏览器 Cookie 自动分配身份，所有数据由站点直接向舞立方接口拉取并在页面上渲染，不再生成图片。',
		'groups'    => array(
			array(
				'group' => '账号',
				'items' => array(
					array('cmd' => '扫码登录', 'desc' => '获取微信二维码，扫码后自动绑定（对应 QQ 端「登录」）'),
					array('cmd' => '手机号登录', 'desc' => '手机号 + 图形验证码 + 短信验证码（对应「手机号登录」）'),
					array('cmd' => '退出登录', 'desc' => '删除本账号保存的凭证（对应「退出登录 / #logout」）'),
				),
			),
			array(
				'group' => '我的数据',
				'items' => array(
					array('cmd' => '个人信息', 'desc' => '战力 / 积分 / 天梯段位 / 金币等（对应「个人信息」）'),
					array('cmd' => '查看他人', 'desc' => '填写他人舞立方 ID（对应「看看你的 <ID>」）'),
					array('cmd' => '战力分析 B15 / R15', 'desc' => '榜单前 15 + 最近游玩 15 首（对应「战力分析」）'),
					array('cmd' => '战力分析 B30', 'desc' => '榜单战力前 30 首（对应「战力分析b30」）'),
					array('cmd' => '战力分析 AP30', 'desc' => '满分成绩前 30 首（对应「战力分析ap30」）'),
					array('cmd' => '分数列表', 'desc' => '按等级列出成绩，每页 30 首（对应「分数列表 <等级>」）'),
					array('cmd' => '数据统计', 'desc' => '胜率 / 游玩次数 / 通过歌曲（对应「myri」）'),
				),
			),
			array(
				'group' => '查询与兑换',
				'items' => array(
					array('cmd' => '查找机台', 'desc' => '按地区查找附近机台（对应「查找舞立方 (地区)」）'),
					array('cmd' => '兑换码', 'desc' => '批量兑换 15 位自制谱兑换码'),
				),
			),
			array(
				'group' => 'Token',
				'items' => array(
					array('cmd' => '查看 Token', 'desc' => '查看当前账号的访问令牌（对应「#token」）'),
					array('cmd' => '强制刷新', 'desc' => '用 refresh_token 换新（对应「#refresh」）'),
					array('cmd' => '默认 Token', 'desc' => '查看 / 设置账号标识为 0 的默认凭证（对应「#token0 / #setToken0」）'),
					array('cmd' => '存储管理', 'desc' => '保存 / 重新载入本地凭证（对应「#save / #load」）'),
					array('cmd' => '清理登录状态', 'desc' => '清理卡住的登录会话（对应「#clearLogin」）'),
				),
			),
		),
	);
}
