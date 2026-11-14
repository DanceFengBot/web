<?php

/**
 * 舞小枫 · 舞立方助手 —— 接口动作表
 *
 * 每个 act 声明：请求方法、所需权限、可接收的参数、处理函数。
 * 参数一律走白名单，未声明的参数会被丢弃；账号标识由服务端注入，
 * 前端传什么都不作数。
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

/**
 * 动作白名单。
 *
 * @return array
 */
function dfb_action_map() {
	return array(
		/* --- 基础 --- */
		'bootstrap' => array(
			'method'  => 'GET',
			'feature' => 'access',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_bootstrap',
		),
		'state' => array(
			'method'  => 'GET',
			'feature' => 'access',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_state',
		),
		'help' => array(
			'method'  => 'GET',
			'feature' => 'access',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_help',
		),

		/* --- 登录 / 退出 --- */
		'login.qrcode.start' => array(
			'method'  => 'POST',
			'feature' => 'login',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_login_qr_start',
		),
		'login.qrcode.poll' => array(
			'method'  => 'GET',
			'feature' => 'login',
			'query'   => array('ticket'),
			'body'    => array(),
			'handler' => 'dfb_act_login_qr_poll',
		),
		'login.phone.start' => array(
			'method'  => 'POST',
			'feature' => 'login',
			'query'   => array(),
			'body'    => array('phone'),
			'handler' => 'dfb_act_login_phone_start',
		),
		'login.phone.sms' => array(
			'method'  => 'POST',
			'feature' => 'login',
			'query'   => array(),
			'body'    => array('ticket', 'graphCode'),
			'handler' => 'dfb_act_login_phone_sms',
		),
		'login.phone.verify' => array(
			'method'  => 'POST',
			'feature' => 'login',
			'query'   => array(),
			'body'    => array('ticket', 'smsCode'),
			'handler' => 'dfb_act_login_phone_verify',
		),
		'logout' => array(
			'method'  => 'POST',
			'feature' => 'login',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_logout',
		),

		/* --- 个人数据 --- */
		'user.info' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array('target'),
			'body'    => array(),
			'handler' => 'dfb_act_user_info',
		),
		'user.image' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array('target'),
			'body'    => array(),
			'handler' => 'dfb_act_user_image',
		),
		'ratio.b15' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_ratio_b15',
		),
		'ratio.b30' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_ratio_b30',
		),
		'ratio.ap30' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_ratio_ap30',
		),
		'ratio.image' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array('mode'),
			'body'    => array(),
			'handler' => 'dfb_act_ratio_image',
		),
		'level.scores' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array('level', 'page'),
			'body'    => array(),
			'handler' => 'dfb_act_level_scores',
		),
		'level.image' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array('level', 'page'),
			'body'    => array(),
			'handler' => 'dfb_act_level_image',
		),
		'team.detail' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array('page', 'sort', 'order'),
			'body'    => array(),
			'handler' => 'dfb_act_team_detail',
		),
		'message.list' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_message_list',
		),
		'message.post' => array(
			'method'  => 'POST',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array('content'),
			'handler' => 'dfb_act_message_post',
		),
		'changelog.list' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_changelog_list',
		),
		'reply.item' => array(
			'method'  => 'GET',
			'feature' => 'data',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_reply_item',
		),

		/* --- 查询 / 兑换 --- */
		'machine.list' => array(
			'method'  => 'GET',
			'feature' => 'query',
			'query'   => array('region'),
			'body'    => array(),
			'handler' => 'dfb_act_machine_list',
		),
		'machine.review.list' => array(
			'method'  => 'GET',
			'feature' => 'query',
			'query'   => array('terminalId'),
			'body'    => array(),
			'handler' => 'dfb_act_machine_review_list',
		),
		'machine.review.post' => array(
			'method'  => 'POST',
			'feature' => 'query',
			'query'   => array(),
			'body'    => array('terminalId', 'rating', 'content', 'images'),
			'handler' => 'dfb_act_machine_review_post',
		),
		'machine.review.upload' => array(
			'method'  => 'POST',
			'feature' => 'query',
			'query'   => array(),
			'body'    => array('data'),
			'handler' => 'dfb_act_machine_review_upload',
		),
		'machine.exclude.request' => array(
			'method'  => 'POST',
			'feature' => 'query',
			'query'   => array(),
			'body'    => array('terminalId', 'placeName', 'address', 'reason'),
			'handler' => 'dfb_act_machine_exclude_request',
		),
		'gain.code' => array(
			'method'  => 'POST',
			'feature' => 'query',
			'query'   => array(),
			'body'    => array('codes'),
			'handler' => 'dfb_act_gain_code',
		),

		/* --- Token --- */
		'token.show' => array(
			'method'  => 'GET',
			'feature' => 'token',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_token_show',
		),
		'token.default.show' => array(
			'method'     => 'GET',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array(),
			'handler'    => 'dfb_act_token_default_show',
		),
		'token.refresh' => array(
			'method'  => 'POST',
			'feature' => 'token',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_token_refresh',
		),

		/* --- 管理 --- */
		'admin.unlock' => array(
			'method'  => 'POST',
			'feature' => 'admin',
			'query'   => array(),
			'body'    => array('password'),
			'handler' => 'dfb_act_admin_unlock',
		),
		'admin.password.setup' => array(
			'method'  => 'POST',
			'feature' => 'admin',
			'query'   => array(),
			'body'    => array('password', 'confirm'),
			'handler' => 'dfb_act_admin_password_setup',
		),
		'admin.password.change' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('currentPassword', 'newPassword', 'confirmPassword'),
			'handler'    => 'dfb_act_admin_password_change',
		),
		'admin.lock' => array(
			'method'  => 'POST',
			'feature' => 'admin',
			'query'   => array(),
			'body'    => array(),
			'handler' => 'dfb_act_admin_lock',
		),
		'admin.announcement.publish' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('content', 'closeable'),
			'handler'    => 'dfb_act_admin_announcement_publish',
		),
		'admin.announcement.clear' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array(),
			'handler'    => 'dfb_act_admin_announcement_clear',
		),
		'admin.site.set' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('closed'),
			'handler'    => 'dfb_act_admin_site_set',
		),
		'admin.users.list' => array(
			'method'     => 'GET',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array(),
			'handler'    => 'dfb_act_admin_users_list',
		),
		'admin.message.delete' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('id'),
			'handler'    => 'dfb_act_admin_message_delete',
		),
		'admin.machine.review.delete' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('id'),
			'handler'    => 'dfb_act_admin_machine_review_delete',
		),
		'admin.machine.exclusions' => array(
			'method'     => 'GET',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array(),
			'handler'    => 'dfb_act_admin_machine_exclusions',
		),
		'admin.machine.exclude.review' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('terminalId', 'action'),
			'handler'    => 'dfb_act_admin_machine_exclude_review',
		),
		'admin.machine.exclude.set' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('terminalId', 'excluded', 'reason', 'placeName', 'address'),
			'handler'    => 'dfb_act_admin_machine_exclude_set',
		),
		'admin.token.set' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('accessToken', 'refreshToken'),
			'handler'    => 'dfb_act_admin_token_set',
		),
		'admin.tokens.save' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array(),
			'handler'    => 'dfb_act_admin_tokens_save',
		),
		'admin.tokens.load' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array(),
			'handler'    => 'dfb_act_admin_tokens_load',
		),
		'admin.update.ids' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('param'),
			'handler'    => 'dfb_act_admin_update_ids',
		),
		'admin.clear.login' => array(
			'method'     => 'POST',
			'feature'    => 'admin',
			'admin_only' => true,
			'query'      => array(),
			'body'       => array('param'),
			'handler'    => 'dfb_act_admin_clear_login',
		),
	);
}

/* ======================================================================
 * 一、账号状态
 * ==================================================================== */

/**
 * 客户端启动配置（uni-app / 独立前端）。
 *
 * 这个接口不要求 formhash，用身份头 / Cookie 换回 formhash、权限、
 * 登录状态和后台状态；网页版仍由 index.php 注入同样的数据。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_bootstrap($input, $account) {
	return dfb_view_config_data();
}

/**
 * 当前账号登录状态。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_state($input, $account) {
	$token = dfb_token_get($account);
	$tokens = dfb_tokens_all();
	$userName = '';
	if($token) {
		$userName = isset($token['userName']) ? trim((string)$token['userName']) : '';
		/* 首次登录后补取一次舞立方昵称，并缓存进 token，避免每次刷新都请求上游 */
		if($userName === '' && !empty($token['userId'])) {
			$profile = dfb_fetch_self_profile($token);
			if(!empty($profile['userName'])) {
				$name = dfb_truncate(dfb_plain($profile['userName']), 40);
				if($name !== '') {
					$userName = $name;
					$token['userName'] = $userName;
					try {
						dfb_token_set($account, $token);
					} catch(Exception $e) {
						/* 缓存失败不影响本次返回，下次再取 */
					}
				}
			}
		}
	}
	return array(
		'ok'              => true,
		'account'         => $account,
		'loggedIn'        => $token ? true : false,
		'userId'          => $token ? intval($token['userId']) : null,
		'userName'        => $userName,
		'available'       => $token ? !empty($token['available']) : false,
		'hasDefaultToken' => isset($tokens['0']),
		'tokenCount'      => count($tokens),
		'siteClosed'      => dfb_site_is_closed(),
		'admin'           => dfb_admin_status(),
		'version'         => DFB_VERSION,
	);
}

/**
 * 功能说明。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_help($input, $account) {
	return dfb_data_help();
}

/* ======================================================================
 * 二、登录 / 退出
 * ==================================================================== */

/**
 * 获取登录二维码。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_login_qr_start($input, $account) {
	return dfb_login_qr_start($account);
}

/**
 * 轮询扫码结果。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_login_qr_poll($input, $account) {
	return dfb_login_qr_poll(isset($input['ticket']) ? $input['ticket'] : '', $account);
}

/**
 * 手机号登录：获取图形验证码。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_login_phone_start($input, $account) {
	return dfb_login_phone_start($account, isset($input['phone']) ? $input['phone'] : '');
}

/**
 * 手机号登录：发送短信。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_login_phone_sms($input, $account) {
	return dfb_login_phone_sms(
		isset($input['ticket']) ? $input['ticket'] : '',
		$account,
		isset($input['graphCode']) ? $input['graphCode'] : ''
	);
}

/**
 * 手机号登录：提交短信验证码。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_login_phone_verify($input, $account) {
	return dfb_login_phone_verify(
		isset($input['ticket']) ? $input['ticket'] : '',
		$account,
		isset($input['smsCode']) ? $input['smsCode'] : ''
	);
}

/**
 * 退出登录（删除本地凭证）。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_logout($input, $account) {
	/* 顺手清掉可能卡住的登录会话，避免「一直在登录中」把用户挡在门外 */
	dfb_session_clear_account($account);
	$removed = dfb_token_delete($account);
	if(!$removed) {
		throw new DfbError('舞小枫这没有过你的账号！', 404);
	}
	/* 退出登录后一并作废后台管理解锁状态 */
	try {
		dfb_admin_forget($account);
	} catch(Exception $e) {
		/* 清理失败不影响退出登录本身 */
	}
	return array(
		'ok'      => true,
		'userId'  => intval($removed['userId']),
		'message' => 'id:'.intval($removed['userId']).' 退出登录成功！',
	);
}

/* ======================================================================
 * 三、个人数据
 * ==================================================================== */

/**
 * 个人信息。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_user_info($input, $account) {
	$token = dfb_require_token($account);
	$target = isset($input['target']) ? trim((string)$input['target']) : '';
	$targetId = 0;
	if($target !== '') {
		$targetId = dfb_resolve_target($target);
	}
	$data = dfb_data_user_info($account, $token, $targetId);
	if($data['profile']['status'] === 'nonexistent') {
		throw new DfbError('这个账号不存在哦，检查一下舞立方 ID 吧。', 404);
	}
	return $data;
}

/**
 * 把用户输入解析成舞立方 ID。
 *
 * @param string $text
 * @return int
 * @throws DfbError
 */
function dfb_resolve_target($text) {
	if(!preg_match('/^\d+$/', $text)) {
		throw new DfbError('啊...这个数字是什么', 400);
	}
	$num = intval($text);
	if($num > 9999 && $num < 99999999) {
		return $num;
	}
	if($num > 999999) {
		$token = dfb_token_get((string)$num);
		if($token) {
			return intval($token['userId']);
		}
	}
	throw new DfbError('唔...小枫好像不认识他', 404);
}

/**
 * 战力分析 B15 / R15。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_ratio_b15($input, $account) {
	$token = dfb_require_token($account);
	return dfb_data_ratio($account, $token, 'b15');
}

/**
 * 战力分析 B30。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_ratio_b30($input, $account) {
	$token = dfb_require_token($account);
	return dfb_data_ratio($account, $token, 'b30');
}

/**
 * 战力分析 AP30。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_ratio_ap30($input, $account) {
	$token = dfb_require_token($account);
	return dfb_data_ratio($account, $token, 'ap30');
}

/**
 * 分数列表。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_level_scores($input, $account) {
	$token = dfb_require_token($account);
	$level = isset($input['level']) ? intval($input['level']) : 1;
	$page = isset($input['page']) ? intval($input['page']) : 1;
	return dfb_data_level_scores($account, $token, $level, $page);
}

/**
 * 当前账号的战队信息与成员列表。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_team_detail($input, $account) {
	$token = dfb_require_token($account);
	$page = isset($input['page']) ? intval($input['page']) : 1;
	$sort = isset($input['sort']) ? (string)$input['sort'] : 'default';
	$order = isset($input['order']) ? (string)$input['order'] : 'desc';
	return dfb_data_team($account, $token, $page, $sort, $order);
}

/**
 * 留言列表。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_message_list($input, $account) {
	dfb_require_token($account);
	$messages = dfb_messages_read();
	return array(
		'ok'       => true,
		'messages' => $messages,
		'total'    => count($messages),
	);
}

/**
 * 发表留言。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_message_post($input, $account) {
	$token = dfb_require_token($account);
	$content = isset($input['content']) ? (string)$input['content'] : '';
	$message = dfb_messages_add($account, $token, $content);
	return array(
		'ok'      => true,
		'message' => '留言已发布',
		'item'    => $message,
	);
}

/**
 * 更新日志。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_changelog_list($input, $account) {
	dfb_require_token($account);
	$entries = dfb_changelog_read();
	return array(
		'ok'      => true,
		'entries' => $entries,
		'total'   => count($entries),
	);
}

/**
 * 管理员删除留言。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_message_delete($input, $account) {
	$id = isset($input['id']) ? (string)$input['id'] : '';
	if($id === '') {
		throw new DfbError('缺少留言 ID。', 400);
	}
	if(!dfb_messages_delete($id)) {
		throw new DfbError('留言不存在或已删除。', 404);
	}
	return array('ok' => true, 'message' => '留言已删除');
}

/**
 * 管理员删除场地评价。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_machine_review_delete($input, $account) {
	$id = isset($input['id']) ? (string)$input['id'] : '';
	if($id === '') {
		throw new DfbError('缺少评价 ID。', 400);
	}
	if(!dfb_machine_reviews_delete($id)) {
		throw new DfbError('评价不存在或已删除。', 404);
	}
	return array('ok' => true, 'message' => '评价已删除');
}

/**
 * 数据统计（myri）。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_reply_item($input, $account) {
	$token = dfb_require_token($account);
	$item = dfb_fetch_reply_item($token);

	$fields = array(
		array('label' => '个人胜率', 'value' => number_format($item['victoryRates'], 2, '.', '').'%'),
		array('label' => '团队胜率', 'value' => number_format($item['teamVictoryRates'], 2, '.', '').'%'),
		array('label' => '游玩年龄', 'value' => $item['playedAge'] !== '' ? $item['playedAge'] : '无'),
		array('label' => '游戏段位', 'value' => dfb_ladder_grade_name($item['danLevel'])),
		array('label' => '游玩次数', 'value' => (string)$item['playedTimes']),
		array('label' => '通过歌曲', 'value' => (string)$item['passedSongs']),
		array('label' => '累计加币', 'value' => (string)$item['addedCoins']),
	);

	$lines = array();
	foreach($fields as $pair) {
		$lines[] = $pair['label'].'：'.$pair['value'];
	}

	return array(
		'ok'     => true,
		'fields' => $fields,
		'raw'    => $item,
		'text'   => implode("\n", $lines),
	);
}

/* ======================================================================
 * 四、查询 / 兑换
 * ==================================================================== */

/**
 * 查找机台。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_machine_list($input, $account) {
	return dfb_data_machine_list(isset($input['region']) ? $input['region'] : '', $account);
}

/**
 * 场地评价列表。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_machine_review_list($input, $account) {
	$terminalId = isset($input['terminalId']) ? trim((string)$input['terminalId']) : '';
	if($terminalId === '') {
		throw new DfbError('缺少场地 ID。', 400);
	}
	$reviews = dfb_machine_reviews_for($terminalId);
	$summary = dfb_machine_reviews_summary($terminalId);
	return array(
		'ok'      => true,
		'items'   => $reviews,
		'total'   => count($reviews),
		'avg'     => $summary['avg'],
	);
}

/**
 * 发布场地评价。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_machine_review_post($input, $account) {
	$token = dfb_require_token($account);
	$item = dfb_machine_reviews_add(
		$token,
		isset($input['terminalId']) ? (string)$input['terminalId'] : '',
		isset($input['rating']) ? intval($input['rating']) : 0,
		isset($input['content']) ? (string)$input['content'] : '',
		isset($input['images']) && is_array($input['images']) ? $input['images'] : array()
	);
	return array('ok' => true, 'message' => '评价已发布', 'item' => $item);
}

/**
 * 上传评价照片。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_machine_review_upload($input, $account) {
	dfb_require_token($account);
	$url = dfb_machine_review_save_image(isset($input['data']) ? (string)$input['data'] : '');
	return array('ok' => true, 'url' => $url);
}

/**
 * 普通用户提交场地排除申请。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_machine_exclude_request($input, $account) {
	$token = dfb_require_token($account);
	$item = dfb_machine_exclusion_request(
		$token,
		isset($input['terminalId']) ? (string)$input['terminalId'] : '',
		isset($input['placeName']) ? (string)$input['placeName'] : '',
		isset($input['address']) ? (string)$input['address'] : '',
		isset($input['reason']) ? (string)$input['reason'] : ''
	);
	return array('ok' => true, 'message' => '排除申请已提交，等待管理员审核。', 'item' => $item);
}

/**
 * 管理员：待审核排除申请。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_machine_exclusions($input, $account) {
	$pending = dfb_machine_exclusions_pending();
	$all = array_values(dfb_machine_exclusions_read());
	return array(
		'ok'      => true,
		'pending' => $pending,
		'all'     => $all,
		'total'   => count($pending),
	);
}

/**
 * 管理员审核排除申请。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_machine_exclude_review($input, $account) {
	$token = dfb_require_token($account);
	$item = dfb_machine_exclusion_review(
		isset($input['terminalId']) ? (string)$input['terminalId'] : '',
		isset($input['action']) ? (string)$input['action'] : '',
		isset($token['userName']) ? (string)$token['userName'] : '管理员'
	);
	return array('ok' => true, 'message' => '审核完成', 'item' => $item);
}

/**
 * 管理员直接设置 / 取消场地排除。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_machine_exclude_set($input, $account) {
	$token = dfb_require_token($account);
	$item = dfb_machine_exclusion_set(
		isset($input['terminalId']) ? (string)$input['terminalId'] : '',
		!empty($input['excluded']),
		isset($input['reason']) ? (string)$input['reason'] : '',
		isset($input['placeName']) ? (string)$input['placeName'] : '',
		isset($input['address']) ? (string)$input['address'] : '',
		isset($token['userName']) ? (string)$token['userName'] : '管理员'
	);
	return array('ok' => true, 'message' => '场地排除状态已更新', 'item' => $item);
}

/**
 * 兑换码。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_gain_code($input, $account) {
	$token = dfb_require_token($account);
	$codes = isset($input['codes']) ? $input['codes'] : array();
	return dfb_data_gain_codes($token, $codes);
}

/* ======================================================================
 * 五、Token 展示与刷新
 * ==================================================================== */

/**
 * 整理 Token 输出结构。
 *
 * @param array  $token
 * @param string $message
 * @return array
 */
function dfb_token_payload($token, $message = '') {
	$recTime = isset($token['recTime']) ? intval($token['recTime']) : 0;
	$days = $recTime > 0 ? (dfb_now_ms() - $recTime) / 86400000 : 0;
	$userId = isset($token['userId']) ? intval($token['userId']) : 0;
	$access = isset($token['accessToken']) ? (string)$token['accessToken'] : '';
	$refresh = isset($token['refreshToken']) ? (string)$token['refreshToken'] : '';
	$daysText = number_format($days, 3, '.', '');

	$lines = array();
	$lines[] = '{';
	$lines[] = '    "userId"="'.$userId.'",';
	$lines[] = '    "accessToken"="'.$access.'",';
	$lines[] = '    "refreshToken"="'.$refresh.'",';
	$lines[] = '    "recTime"='.$recTime.',';
	$lines[] = '    "desc"="当前token时长为'.$daysText.'天。Token拥有账号的所有控制权，'
		.'请务必保管好token以免泄露，你可以发送"退出登录"来删除当前会话token"';
	$lines[] = '}';
	$text = implode("\n", $lines);

	return array(
		'ok'           => true,
		'userId'       => $userId,
		'accessToken'  => $access,
		'refreshToken' => $refresh,
		'recTime'      => $recTime,
		'available'    => !empty($token['available']),
		'days'         => round($days, 3),
		'text'         => $text,
		'message'      => $message,
	);
}

/**
 * 查看当前账号 Token。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_token_show($input, $account) {
	$token = dfb_require_token($account);
	return dfb_token_payload($token);
}

/**
 * 查看默认 Token。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_token_default_show($input, $account) {
	$token = dfb_token_get('0');
	if(!$token) {
		throw new DfbError('还没有设置默认 Token 哦', 404);
	}
	return dfb_token_payload($token);
}

/**
 * 强制刷新当前账号 Token。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_token_refresh($input, $account) {
	$token = dfb_require_token($account);
	$refreshed = dfb_token_refresh($token);
	if(!$refreshed) {
		throw new DfbError('刷新失败，请重新登录！', 502);
	}
	/* 刷新只换 access/refresh token，缓存过的昵称继续保留 */
	if(!empty($token['userName'])) {
		$refreshed['userName'] = $token['userName'];
	}
	dfb_token_set($account, $refreshed);
	return dfb_token_payload($refreshed, '#Token已强制刷新#');
}

/* ======================================================================
 * 六、管理功能
 *
 * 这些动作分两层校验：
 *   1. feature = admin：当前登录的舞立方 ID 必须在 admin_user_ids 里；
 *   2. admin_only = true：还必须已经通过后台密码验证（未过期）。
 * 具体拦截在 api.php 里统一完成，处理函数不需要重复判断。
 * ==================================================================== */

/**
 * 输入后台密码，解锁后台管理。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_unlock($input, $account) {
	$password = isset($input['password']) ? (string)$input['password'] : '';
	return dfb_admin_unlock($password);
}

/**
 * 首次设置后台密码。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_password_setup($input, $account) {
	$password = isset($input['password']) ? (string)$input['password'] : '';
	$confirm = isset($input['confirm']) ? (string)$input['confirm'] : '';
	return dfb_admin_password_setup($password, $confirm);
}

/**
 * 修改后台密码（需已解锁）。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_password_change($input, $account) {
	$current = isset($input['currentPassword']) ? (string)$input['currentPassword'] : '';
	$new = isset($input['newPassword']) ? (string)$input['newPassword'] : '';
	$confirm = isset($input['confirmPassword']) ? (string)$input['confirmPassword'] : '';
	return dfb_admin_password_change($current, $new, $confirm);
}

/**
 * 立即锁定后台管理。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_lock($input, $account) {
	return dfb_admin_lock();
}

/**
 * 发布 / 更新站点公告。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_announcement_publish($input, $account) {
	$content = isset($input['content']) ? (string)$input['content'] : '';
	$raw = isset($input['closeable']) ? $input['closeable'] : false;
	$closeable = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true');
	$announcement = dfb_announcement_save($content, $closeable, $account);
	return array(
		'ok'           => true,
		'message'      => '公告已发布',
		'announcement' => $announcement,
	);
}

/**
 * 清除站点公告。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_announcement_clear($input, $account) {
	dfb_announcement_clear();
	return array(
		'ok'           => true,
		'message'      => '公告已清除',
		'announcement' => null,
	);
}

/**
 * 开启 / 关闭站点。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_site_set($input, $account) {
	$raw = isset($input['closed']) ? $input['closed'] : false;
	$closed = ($raw === true || $raw === 1 || $raw === '1' || $raw === 'true');
	if(!dfb_site_write($closed, $account)) {
		throw new DfbError('站点状态保存失败：data/ 目录不可写。', 500);
	}
	return array(
		'ok'         => true,
		'siteClosed' => $closed,
		'message'    => $closed ? '网站已关闭，仅管理员和登录/退出可用。' : '网站已重新开放。',
	);
}

/**
 * 已登录用户列表（不返回 Token）。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_users_list($input, $account) {
	$users = dfb_logged_in_users();
	return array(
		'ok'    => true,
		'users' => $users,
		'total' => count($users),
		'now'   => time(),
	);
}

/**
 * 设置默认 Token（账号标识 "0"）。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_token_set($input, $account) {
	$access = isset($input['accessToken']) ? trim((string)$input['accessToken']) : '';
	$refresh = isset($input['refreshToken']) ? trim((string)$input['refreshToken']) : '';
	if($access === '' || $refresh === '') {
		throw new DfbError('格式错误：需要同时提供 Access Token 与 Refresh Token', 400);
	}
	$token = array(
		'userId'       => 0,
		'accessToken'  => $access,
		'refreshToken' => $refresh,
		'recTime'      => dfb_now_ms(),
		'available'    => 1,
	);
	if(!dfb_token_check($token)) {
		throw new DfbError('默认 Token 设置失败：已无效', 400);
	}
	dfb_token_set('0', $token);
	return dfb_token_payload($token, '默认 Token 设置成功');
}

/**
 * 保存（重写）全部 Token 文件。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_tokens_save($input, $account) {
	$state = dfb_state_read();
	$ok = dfb_state_write($state);
	if(!$ok) {
		throw new DfbError('保存失败：data/ 目录不可写。', 500);
	}
	$count = count($state['tokens']);
	return array('ok' => true, 'count' => $count, 'message' => '保存成功！共'.$count.'条');
}

/**
 * 重新从磁盘载入 Token。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_tokens_load($input, $account) {
	$state = dfb_state_read();
	$count = count($state['tokens']);
	return array('ok' => true, 'count' => $count, 'message' => '重新载入成功！共'.$count.'条');
}

/**
 * 刷新客户端 ID 列表（重新读取配置 / 内置列表）。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_update_ids($input, $account) {
	$param = isset($input['param']) ? strtolower(trim((string)$input['param'])) : '';
	if($param !== 'all' && $param !== 'id') {
		return array('ok' => false, 'count' => 0, 'ids' => array(), 'message' => 'nope...');
	}
	$ids = dfb_client_ids();
	return array(
		'ok'      => true,
		'count'   => count($ids),
		'ids'     => $ids,
		'message' => '已成功刷新 TokenID（共 '.count($ids).' 条）',
	);
}

/**
 * 清理登录会话。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_admin_clear_login($input, $account) {
	$param = isset($input['param']) ? strtolower(trim((string)$input['param'])) : '';
	if($param === 'all') {
		dfb_state_update(function (&$state) {
			$state['sessions'] = array();
		});
		return array('ok' => true, 'message' => '已清空全部登录中状态');
	}
	if($param === 'me') {
		$removed = 0;
		dfb_state_update(function (&$state) use ($account, &$removed) {
			foreach($state['sessions'] as $key => $session) {
				if(isset($session['account']) && $session['account'] === $account) {
					unset($state['sessions'][$key]);
					$removed++;
				}
			}
		});
		return array('ok' => true, 'message' => $removed ? '已清空！' : '未找到登录！');
	}
	throw new DfbError('参数仅支持 all 或 me', 400);
}
