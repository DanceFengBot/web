<?php

/**
 * 舞小枫 · 舞立方助手 —— 舞立方数据层
 *
 * 本文件把原 Python 后端的「取数 + 计算」逻辑用 PHP 实现，
 * 插件因此不再需要独立的 Python 服务：
 *
 *   - 登录（微信扫码 / 手机号 + 图形码 + 短信码）；
 *   - Token 校验与刷新；
 *   - 个人信息、账户金币、数据统计（myri）、天梯赛季；
 *   - 榜单成绩与最近游玩，B15 / R15 / B30 / AP30 战力计算；
 *   - 指定等级分数列表（分页）；
 *   - 机台查询（高德地理编码 + 舞立方机台接口）、兑换码。
 *
 * **所有函数只返回数据结构，不生成图片**：展示交给前端渲染。
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

/* ======================================================================
 * 一、Token 基础操作
 * ==================================================================== */

/**
 * 鉴权请求头。
 *
 * @param array $token
 * @return array
 */
function dfb_auth_headers($token) {
	return array('Authorization: bearer '.(isset($token['accessToken']) ? $token['accessToken'] : ''));
}

/**
 * 检测 Token 是否可用（请求「未读消息数」接口）。
 *
 * @param array $token
 * @return bool
 * @throws DfbError 网络层不可用时抛出，避免把「连不上」误报成「登录过期」
 */
function dfb_token_check($token) {
	$resp = dfb_http('GET', DFB_CUBE_BASE.'/api/Message/GetUnreadCount', dfb_auth_headers($token), null, 15);
	if($resp['status'] === 0) {
		throw new DfbError(dfb_http_fail_message($resp), 502);
	}
	return $resp['status'] === 200;
}

/**
 * 取当前账号可用的 Token，未登录 / 已失效时抛业务异常。
 *
 * @param string $account
 * @return array
 * @throws DfbError
 */
function dfb_require_token($account) {
	$token = dfb_token_get($account);
	if(!$token) {
		throw new DfbError(
			'好像还没有登录诶(´。＿。｀)'."\n".'请先完成登录再使用该功能',
			403,
			array('loginRequired' => true)
		);
	}
	if(!dfb_token_check($token)) {
		throw new DfbError(
			'小枫看到登录身份过期了💦'."\n".'请重新登录恢复吧💦',
			403,
			array('loginRequired' => true, 'tokenExpired' => true)
		);
	}
	return $token;
}

/**
 * 刷新 Token（refresh_token 换新）。
 *
 * @param array $token
 * @return array|false 成功返回更新后的 Token，失败返回 false
 */
function dfb_token_refresh($token) {
	$resp = dfb_http_json(
		'POST',
		DFB_CUBE_BASE.'/token',
		array('Content-Type: application/x-www-form-urlencoded'),
		array(
			'client_type'   => 'qrcode',
			'grant_type'    => 'refresh_token',
			'refresh_token' => isset($token['refreshToken']) ? $token['refreshToken'] : '',
		),
		20
	);
	if($resp['status'] !== 200 || !is_array($resp['data'])) {
		return false;
	}
	if(empty($resp['data']['access_token'])) {
		return false;
	}
	$token['accessToken'] = $resp['data']['access_token'];
	$token['refreshToken'] = isset($resp['data']['refresh_token']) ? $resp['data']['refresh_token'] : $token['refreshToken'];
	$token['recTime'] = dfb_now_ms();
	$token['available'] = 1;
	return $token;
}

/**
 * 当前毫秒时间戳。
 *
 * @return int
 */
function dfb_now_ms() {
	return intval(round(microtime(true) * 1000));
}

/**
 * 生成登录票据。
 *
 * @return string
 */
function dfb_new_ticket() {
	return md5(uniqid('', true).mt_rand(100000, 999999));
}

/* ======================================================================
 * 二、用户相关数据
 * ==================================================================== */

/**
 * 空用户信息（与 Python 端 ``UserInfo.get_null()`` 对应）。
 *
 * @param string $status
 * @return array
 */
function dfb_empty_profile($status = 'ok') {
	return array(
		'userId'         => 0,
		'userName'       => '',
		'teamId'         => 0,
		'teamName'       => '',
		'lvRatio'        => 0,
		'musicScore'     => 0,
		'comboPercent'   => 0,
		'rankNation'     => 0,
		'cityName'       => '',
		'sex'            => 0,
		'headimgUrl'     => '',
		'headimgBoxPath' => '',
		'titleUrl'       => '',
		'status'         => $status,
	);
}

/**
 * 解析个人信息接口返回的 JSON。
 *
 * @param string $body
 * @param string $status
 * @return array
 */
function dfb_parse_profile($body, $status = 'ok') {
	$data = json_decode($body, true);
	$profile = dfb_empty_profile($status);
	if(!is_array($data)) {
		return $profile;
	}
	$profile['userId'] = isset($data['UserID']) ? intval($data['UserID']) : 0;
	$profile['userName'] = isset($data['UserName']) ? (string)$data['UserName'] : '';
	$profile['teamId'] = isset($data['TeamID']) ? intval($data['TeamID']) : (isset($data['TeamId']) ? intval($data['TeamId']) : 0);
	$profile['teamName'] = isset($data['TeamName']) ? (string)$data['TeamName'] : '';
	$profile['lvRatio'] = isset($data['LvRatio']) ? intval($data['LvRatio']) : 0;
	$profile['musicScore'] = isset($data['MusicScore']) ? intval($data['MusicScore']) : 0;
	$profile['comboPercent'] = isset($data['ComboPercent']) ? round(floatval($data['ComboPercent']) / 100, 2) : 0;
	$profile['rankNation'] = isset($data['RankNation']) ? intval($data['RankNation']) : 0;
	$profile['cityName'] = isset($data['CityName']) ? (string)$data['CityName'] : '';
	$profile['sex'] = isset($data['Sex']) ? intval($data['Sex']) : 0;
	$profile['headimgUrl'] = isset($data['HeadimgURL']) ? (string)$data['HeadimgURL'] : '';
	$profile['headimgBoxPath'] = isset($data['HeadimgBoxPath']) ? (string)$data['HeadimgBoxPath'] : '';
	$profile['titleUrl'] = isset($data['TitleUrl']) ? (string)$data['TitleUrl'] : '';
	return $profile;
}

/**
 * 查询本人信息。
 *
 * @param array $token
 * @return array
 */
function dfb_fetch_self_profile($token) {
	$resp = dfb_http('GET', DFB_CUBE_BASE.'/api/User/GetInfo?userId='.intval($token['userId']), dfb_auth_headers($token));
	if($resp['status'] !== 200) {
		return dfb_empty_profile('nonexistent');
	}
	return dfb_parse_profile($resp['body']);
}

/**
 * 按舞立方 ID 查询他人信息（处理「账号不存在」「已设置保密」两种情况）。
 *
 * @param array $token
 * @param int   $userId
 * @return array
 */
function dfb_fetch_profile_by_id($token, $userId) {
	$resp = dfb_http('GET', DFB_CUBE_BASE.'/api/User/GetInfo?userId='.intval($userId), dfb_auth_headers($token));
	$body = $resp['body'];

	if(strpos($body, '账号不存在') !== false) {
		return dfb_empty_profile('nonexistent');
	}
	if(strpos($body, '已设置保密') !== false) {
		$profile = dfb_empty_profile('private');
		$profile['userId'] = intval($userId);
		$search = dfb_http_json(
			'GET',
			DFB_CUBE_BASE.'/api/Common/Search?keyword='.intval($userId).'&type=0&page=1&pagesize=1',
			dfb_auth_headers($token)
		);
		if($search['status'] === 200 && isset($search['data']['List'][0])) {
			$item = $search['data']['List'][0];
			$profile['userName'] = isset($item['Name']) ? (string)$item['Name'] : '';
			$profile['headimgUrl'] = isset($item['HeadimgURL']) ? (string)$item['HeadimgURL'] : '';
			$profile['lvRatio'] = isset($item['LvRatio']) ? intval($item['LvRatio']) : 0;
			$profile['cityName'] = isset($item['Region']) ? (string)$item['Region'] : '';
		}
		return $profile;
	}
	return dfb_parse_profile($body);
}

/**
 * 账户金币。
 *
 * @param array $token
 * @return int
 */
function dfb_fetch_gold($token) {
	$resp = dfb_http_json(
		'GET',
		DFB_CUBE_BASE.'/api/User/GetAccountInfo?userId='.intval($token['userId']),
		dfb_auth_headers($token)
	);
	if($resp['status'] !== 200 || !is_array($resp['data'])) {
		return 0;
	}
	$data = $resp['data'];
	if(isset($data['Gold'])) {
		return intval($data['Gold']);
	}
	return isset($data['gold']) ? intval($data['gold']) : 0;
}

/**
 * 数据统计（对应 QQ 端 ``myri``）。
 *
 * @param array $token
 * @return array
 */
function dfb_fetch_reply_item($token) {
	$result = array(
		'victoryRates'     => 0.0,
		'teamVictoryRates' => 0.0,
		'playedAge'        => '',
		'danLevel'         => 0,
		'playedTimes'      => 0,
		'passedSongs'      => 0,
		'addedCoins'       => 0,
	);
	$resp = dfb_http_json(
		'GET',
		DFB_CUBE_BASE.'/api/ReplyTextItem/GetAllList?machineId=0',
		dfb_auth_headers($token)
	);
	if($resp['status'] !== 200 || !is_array($resp['data'])) {
		return $result;
	}
	foreach($resp['data'] as $item) {
		if(!is_array($item) || !isset($item['ItemType'])) {
			continue;
		}
		$type = intval($item['ItemType']);
		$content = isset($item['Content']) ? trim((string)$item['Content']) : '';
		$none = (strpos($content, '无') !== false);
		switch($type) {
			case 13:
				$result['victoryRates'] = $none ? 0.0 : floatval(str_replace('%', '', $content));
				break;
			case 9:
				$result['teamVictoryRates'] = $none ? 0.0 : floatval(str_replace('%', '', $content));
				break;
			case 3:
				$result['playedAge'] = $content;
				break;
			case 7:
				$result['danLevel'] = $none ? 0 : intval($content);
				break;
			case 5:
				$result['playedTimes'] = intval($content);
				break;
			case 6:
				$result['passedSongs'] = intval($content);
				break;
			case 10:
				$result['addedCoins'] = intval($content);
				break;
		}
	}
	return $result;
}

/**
 * 当前天梯赛季（没有则返回 null）。
 *
 * @param array $token
 * @return array|null
 */
function dfb_fetch_ladder($token) {
	$resp = dfb_http_json('GET', DFB_CUBE_BASE.'/api/Match/GetQuanMinSeasons', dfb_auth_headers($token));
	return dfb_parse_ladder_response($resp);
}

/**
 * 天梯段位等级名称。
 *
 * ladderGrade 0~6 对应：未定级 / 一星 / 二星 / 三星 / 星钻 / 表神 / 里神。
 * danLevel（ReplyTextItem ItemType=7）使用同一套等级值。
 *
 * @param int $grade
 * @return string
 */
function dfb_ladder_grade_name($grade) {
	$names = array('未定级', '一星', '二星', '三星', '星钻', '表神', '里神');
	$g = intval($grade);
	if($g < 0 || $g >= count($names)) {
		return '未定级';
	}
	return $names[$g];
}

/**
 * 历史战力记录（按接口返回顺序）。
 *
 * @param array $token
 * @return array 形如 array(array('ratio' => int), ...)
 */
function dfb_fetch_ratio_history($token) {
	$resp = dfb_http_json(
		'GET',
		DFB_CUBE_BASE.'/api/User/GetLvRatioHistory?userId='.intval($token['userId']),
		dfb_auth_headers($token)
	);
	return dfb_parse_history_response($resp);
}

/* ======================================================================
 * 三、成绩与战力计算
 * ==================================================================== */

/**
 * 把一条榜单成绩整理成内部行结构。
 *
 * @param array $obj  外层（含 MusicID / Name / OwnerType）
 * @param array $item 内层（含各项成绩）
 * @param int   $index 原始顺序，用于稳定排序
 * @return array
 */
function dfb_rank_row($obj, $item, $index) {
	return array(
		'idx'        => $index,
		'id'         => isset($obj['MusicID']) ? intval($obj['MusicID']) : 0,
		'name'       => isset($obj['Name']) ? (string)$obj['Name'] : '',
		'difficulty' => isset($item['MusicLevOld']) ? intval($item['MusicLevOld']) : 0,
		'level'      => isset($item['MusicRank']) ? intval($item['MusicRank']) : 0,
		'levelType'  => isset($item['MusicLev']) ? intval($item['MusicLev']) : 0,
		'acc'        => isset($item['PlayerPercent']) ? round(floatval($item['PlayerPercent']) / 100, 4) : 0.0,
		'score'      => isset($item['PlayerScore']) ? intval($item['PlayerScore']) : 0,
		'combo'      => isset($item['ComboCount']) ? intval($item['ComboCount']) : 0,
		'miss'       => isset($item['PlayerMiss']) ? intval($item['PlayerMiss']) : 0,
		'ranking'    => isset($item['MusicRanking']) ? intval($item['MusicRanking']) : 0,
		'official'   => isset($obj['OwnerType']) ? intval($obj['OwnerType']) === 1 : false,
		'source'     => 'rank',
	);
}

/**
 * 拉取全部榜单成绩（musicIndex 2~6：国语 / 粤语 / 韩语 / 欧美 / 其它）。
 *
 * @param array $token
 * @return array
 */
function dfb_fetch_rank_rows($token) {
	$headers = dfb_auth_headers($token);
	$requests = array();
	for($i = 2; $i <= 6; $i++) {
		$requests['rank'.$i] = array(
			'method'  => 'GET',
			'url'     => DFB_CUBE_BASE.'/api/User/GetMyRankNew?musicIndex='.$i,
			'headers' => $headers,
		);
	}
	$responses = dfb_http_json_multi($requests);
	return dfb_parse_rank_rows_from_responses($responses);
}

/**
 * 拉取最近游玩记录。
 *
 * @param array $token
 * @return array
 */
function dfb_fetch_recent_rows($token) {
	$resp = dfb_http_json('GET', DFB_CUBE_BASE.'/api/User/GetLastPlay', dfb_auth_headers($token));
	return dfb_parse_recent_response($resp);
}

/**
 * 单曲战力：``准确率 × (等级 + 2)``。
 *
 * @param array $row
 * @return float
 */
function dfb_row_ratio($row) {
	return $row['acc'] * ($row['level'] + 2);
}

/**
 * 成绩是否计入战力（等级有效且为官方谱面）。
 *
 * @param array $row
 * @return bool
 */
function dfb_row_ratio_valid($row) {
	return $row['level'] >= 0 && !empty($row['official']);
}

/**
 * 准确率评级。
 *
 * @param float $acc 百分制准确率（PlayerPercent / 100，如 95.61）
 * @return string
 */
function dfb_grade_label($acc) {
	if($acc >= 100) {
		return 'AP';
	}
	if($acc >= 98) {
		return 'SSS';
	}
	if($acc >= 95) {
		return 'SS';
	}
	if($acc >= 90) {
		return 'S';
	}
	if($acc >= 80) {
		return 'A';
	}
	if($acc >= 70) {
		return 'B';
	}
	if($acc >= 60) {
		return 'C';
	}
	return 'D';
}

/**
 * 战力评语（与图片端文案一致）。
 *
 * @param int $ratio
 * @return string
 */
function dfb_ratio_comment($ratio) {
	if($ratio <= 1000) {
		return '你已初步了解这款游戏了，继续练习吧~';
	}
	if($ratio < 1300) {
		return '你已经适应10级的歌曲了，继续练习吧~';
	}
	if($ratio < 1500) {
		return '你正在对线14级的歌了，继续加油~';
	}
	if($ratio < 1800) {
		return '你即将迈入大佬的行列，加油加油！';
	}
	if($ratio < 1900) {
		return '恭喜突破1800守门员，正式成为大佬啦！';
	}
	if($ratio < 2000) {
		return '你即将成神，请继续和1819对线';
	}
	if($ratio < 2080) {
		return '你已步入神的行列，快快杀19吧~';
	}
	if($ratio <= 2100) {
		return '你已经成为外星人，正在薄纱一切歌曲';
	}
	return '';
}

/**
 * 按战力倒序排序（同分保持原始顺序）。
 *
 * @param array $rows
 * @return array
 */
function dfb_sort_by_ratio($rows) {
	usort($rows, 'dfb_cmp_ratio');
	return $rows;
}

/**
 * usort 比较器：战力倒序，同分按原始顺序。
 *
 * @param array $a
 * @param array $b
 * @return int
 */
function dfb_cmp_ratio($a, $b) {
	$ra = dfb_row_ratio($a);
	$rb = dfb_row_ratio($b);
	if($ra == $rb) {
		if($a['idx'] == $b['idx']) {
			return 0;
		}
		return $a['idx'] < $b['idx'] ? -1 : 1;
	}
	return $ra > $rb ? -1 : 1;
}

/**
 * usort 比较器：准确率倒序，同准确率按原始顺序。
 *
 * @param array $a
 * @param array $b
 * @return int
 */
function dfb_cmp_acc($a, $b) {
	if($a['acc'] == $b['acc']) {
		if($a['idx'] == $b['idx']) {
			return 0;
		}
		return $a['idx'] < $b['idx'] ? -1 : 1;
	}
	return $a['acc'] > $b['acc'] ? -1 : 1;
}

/**
 * 战力平均值。
 *
 * @param array $rows
 * @return float
 */
function dfb_ratio_average($rows) {
	if(!$rows) {
		return 0.0;
	}
	$sum = 0.0;
	foreach($rows as $row) {
		$sum += dfb_row_ratio($row);
	}
	return $sum / count($rows);
}

/**
 * 取「上次战力」：历史记录倒数第二条（只有一条时取唯一那条）。
 *
 * @param array $history
 * @param int   $fallback
 * @return int
 */
function dfb_last_ratio($history, $fallback) {
	if($history) {
		$count = count($history);
		$index = $count > 1 ? $count - 2 : $count - 1;
		return isset($history[$index]['ratio']) ? intval($history[$index]['ratio']) : $fallback;
	}
	return $fallback;
}

/**
 * 输出单曲结构（含封面地址、战力、评级）。
 *
 * @param array $row
 * @param int   $lvRatio 账号当前战力，用于计算差值
 * @return array
 */
function dfb_song_payload($row, $lvRatio) {
	$ratio = dfb_row_ratio($row);
	$ratioInt = intval(round($ratio));
	return array(
		'id'         => $row['id'],
		'name'       => $row['name'],
		'level'      => $row['level'],
		'difficulty' => $row['difficulty'],
		'acc'        => round($row['acc'], 2),
		'score'      => $row['score'],
		'combo'      => $row['combo'],
		'miss'       => $row['miss'],
		'ratio'      => round($ratio, 2),
		'ratioInt'   => $ratioInt,
		'diff'       => $ratioInt - intval($lvRatio),
		'grade'      => dfb_grade_label($row['acc']),
		'fullCombo'  => $row['miss'] === 0,
		'perfect'    => isset($row['perfect']) ? $row['perfect'] : null,
		'great'      => isset($row['great']) ? $row['great'] : null,
		'good'       => isset($row['good']) ? $row['good'] : null,
		'time'       => isset($row['time']) ? $row['time'] : '',
		'cover'      => dfb_cover_url($row['id']),
	);
}

/**
 * 批量输出单曲列表。
 *
 * @param array $rows
 * @param int   $lvRatio
 * @return array
 */
function dfb_songs_payload($rows, $lvRatio) {
	$songs = array();
	foreach($rows as $row) {
		$songs[] = dfb_song_payload($row, $lvRatio);
	}
	return $songs;
}

/* ======================================================================
 * 四、封面索引（缓存 7 天）
 * ==================================================================== */

/**
 * 下载官谱 / 非官谱封面索引。
 *
 * @return array|false
 */
function dfb_fetch_cover_index() {
	$sources = array(
		'official' => array(
			'url' => 'https://raw.githubusercontent.com/DanceFengBot/dfb-miscellaneous/main/DanceCubeCover/Official/CoverData.json',
			'key' => 'CoverURL',
		),
		'custom'   => array(
			'url' => 'https://raw.githubusercontent.com/DanceFengBot/dfb-miscellaneous/main/DanceCubeCover/Custom/CoverData.json',
			'key' => 'PicPath',
		),
	);
	$result = array('updated' => time(), 'official' => array(), 'custom' => array());
	$ok = 0;
	foreach($sources as $kind => $source) {
		$resp = dfb_http('GET', $source['url'], array(), null, 25);
		if($resp['status'] !== 200 || $resp['body'] === '') {
			continue;
		}
		$arr = json_decode($resp['body'], true);
		if(!is_array($arr)) {
			continue;
		}
		$ok++;
		foreach($arr as $item) {
			if(!is_array($item) || !isset($item['MusicID'])) {
				continue;
			}
			$urlKey = $source['key'];
			$url = isset($item[$urlKey]) ? trim((string)$item[$urlKey]) : '';
			if($url !== '') {
				$result[$kind][(string)intval($item['MusicID'])] = $url;
			}
		}
	}
	if(!$ok) {
		return false;
	}
	return $result;
}

/**
 * 封面索引（进程内缓存 + 本地文件缓存）。
 *
 * @return array
 */
function dfb_cover_index() {
	static $index = null;
	if($index !== null) {
		return $index;
	}
	$path = dfb_data_dir().'covers.php';
	$cached = null;
	$raw = @file_get_contents($path);
	if(is_string($raw) && $raw !== '') {
		$pos = strpos($raw, '{');
		if($pos !== false) {
			$decoded = json_decode(substr($raw, $pos), true);
			if(is_array($decoded)) {
				$cached = $decoded;
			}
		}
	}
	$fresh = $cached && isset($cached['updated']) && intval($cached['updated']) + 7 * 86400 > time();
	if(!$fresh) {
		$fetched = dfb_fetch_cover_index();
		if(is_array($fetched)) {
			@file_put_contents($path, "<?php exit; ?>\n".dfb_json_pretty($fetched), LOCK_EX);
			$cached = $fetched;
		}
	}
	if(!is_array($cached)) {
		$cached = array('official' => array(), 'custom' => array());
	}
	if(!isset($cached['official']) || !is_array($cached['official'])) {
		$cached['official'] = array();
	}
	if(!isset($cached['custom']) || !is_array($cached['custom'])) {
		$cached['custom'] = array();
	}
	$index = $cached;
	return $index;
}

/**
 * 歌曲封面地址（取不到时返回空串，前端显示占位图）。
 *
 * @param int $musicId
 * @return string
 */
function dfb_cover_url($musicId) {
	$index = dfb_cover_index();
	$key = (string)intval($musicId);
	if(isset($index['official'][$key])) {
		return $index['official'][$key];
	}
	if(isset($index['custom'][$key])) {
		return $index['custom'][$key];
	}
	return '';
}

/* ======================================================================
 * 五、组装：个人信息 / 战力分析 / 分数列表
 * ==================================================================== */

/**
 * 个人信息数据。
 *
 * @param string $account
 * @param array  $token
 * @param int    $targetId 0 表示本人
 * @return array
 */
function dfb_data_user_info($account, $token, $targetId = 0) {
	$selfId = intval($token['userId']);
	$targetId = intval($targetId);
	$isSelf = ($targetId === 0 || $targetId === $selfId);

	$profile = $isSelf ? dfb_fetch_self_profile($token) : dfb_fetch_profile_by_id($token, $targetId);
	if($isSelf) {
		$profile['userId'] = $profile['userId'] ? $profile['userId'] : $selfId;
	}

	$extra = null;
	if($isSelf && $profile['status'] === 'ok') {
		$ladder = dfb_fetch_ladder($token);
		$reply = dfb_fetch_reply_item($token);
		$extra = array(
			'gold'        => dfb_fetch_gold($token),
			'playedTimes' => $reply['playedTimes'],
			'ladderScore' => $ladder ? $ladder['levelPoint'] : null,
			'ladderGrade' => $ladder ? $ladder['levelGrade'] : null,
			'ladderName'  => $ladder ? $ladder['matchName'] : '',
		);
	}

	return array(
		'ok'      => true,
		'status'  => $profile['status'],
		'isSelf'  => $isSelf,
		'profile' => $profile,
		'extra'   => $extra,
	);
}

/* ======================================================================
 * 战力分析：并发取数 + 45 秒短缓存
 * ==================================================================== */

/**
 * 战力缓存文件路径。
 *
 * @return string
 */
function dfb_ratio_cache_path() {
	return dfb_data_dir().'ratio_cache.php';
}

/**
 * 读取战力缓存文件（静态缓存，单次请求只读一次）。
 *
 * @param bool $reload 写入缓存后强制重新读取
 * @return array
 */
function dfb_ratio_cache_read($reload = false) {
	static $cache = null;
	if(!$reload && $cache !== null) {
		return $cache;
	}
	$cache = array('entries' => array());
	$path = dfb_ratio_cache_path();
	if(!is_file($path)) {
		return $cache;
	}
	$raw = @file_get_contents($path);
	if(!is_string($raw) || $raw === '') {
		return $cache;
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return $cache;
	}
	$data = json_decode(substr($raw, $pos), true);
	if(!is_array($data) || !isset($data['entries']) || !is_array($data['entries'])) {
		return $cache;
	}
	$cache = array('entries' => $data['entries']);
	return $cache;
}

/**
 * 取缓存。
 *
 * @param string $key
 * @return array|null
 */
function dfb_ratio_cache_get($key) {
	$config = dfb_config();
	if(intval($config['dfb_ratio_cache_ttl']) <= 0) {
		return null;
	}
	$cache = dfb_ratio_cache_read();
	if(!isset($cache['entries'][$key]) || !is_array($cache['entries'][$key])) {
		return null;
	}
	$entry = $cache['entries'][$key];
	if(!isset($entry['expire']) || intval($entry['expire']) <= time()) {
		return null;
	}
	return isset($entry['data']) && is_array($entry['data']) ? $entry['data'] : null;
}

/**
 * 写缓存；ttl <= 0 时完全不缓存。
 *
 * @param string $key
 * @param array  $data
 * @return void
 */
function dfb_ratio_cache_set($key, $data) {
	$config = dfb_config();
	$ttl = intval($config['dfb_ratio_cache_ttl']);
	if($ttl <= 0 || !is_array($data)) {
		return;
	}
	$cache = dfb_ratio_cache_read();
	$now = time();
	foreach($cache['entries'] as $k => $entry) {
		if(!is_array($entry) || !isset($entry['expire']) || intval($entry['expire']) <= $now) {
			unset($cache['entries'][$k]);
		}
	}
	$cache['entries'][$key] = array(
		'expire' => $now + $ttl,
		'data'   => $data,
	);
	$json = dfb_json_pretty($cache);
	$content = "<?php exit; ?>\n".$json."\n";
	@file_put_contents(dfb_ratio_cache_path(), $content, LOCK_EX);
	dfb_ratio_cache_read(true);
}

/**
 * 解析天梯响应。
 *
 * @param array $resp
 * @return array|null
 */
function dfb_parse_ladder_response($resp) {
	if(!is_array($resp) || !isset($resp['status']) || intval($resp['status']) !== 200 || !isset($resp['data']) || !is_array($resp['data'])) {
		return null;
	}
	foreach($resp['data'] as $item) {
		if(!is_array($item) || empty($item['IsCurrent'])) {
			continue;
		}
		return array(
			'matchName'  => isset($item['MatchName']) ? (string)$item['MatchName'] : '',
			'timeText'   => isset($item['MatchTimeText']) ? (string)$item['MatchTimeText'] : '',
			'levelPoint' => isset($item['LevelPoint']) ? intval($item['LevelPoint']) : 0,
			'levelGrade' => isset($item['LevelGrade']) ? intval($item['LevelGrade']) : 0,
		);
	}
	return null;
}

/**
 * 解析历史战力响应。
 *
 * @param array $resp
 * @return array
 */
function dfb_parse_history_response($resp) {
	if(!is_array($resp) || !isset($resp['status']) || intval($resp['status']) !== 200 || !isset($resp['data']) || !is_array($resp['data'])) {
		return array();
	}
	$list = array();
	foreach($resp['data'] as $item) {
		if(!is_array($item)) {
			continue;
		}
		$list[] = array(
			'ratio' => isset($item['LvRatio']) ? intval($item['LvRatio']) : 0,
			'time'  => isset($item['LogTime']) ? (string)$item['LogTime'] : '',
		);
	}
	return $list;
}

/**
 * 把 5 个 musicIndex 的榜单响应合并成行。
 *
 * @param array $responses 键名 rank2 ~ rank6
 * @return array
 */
function dfb_parse_rank_rows_from_responses($responses) {
	$rows = array();
	$index = 0;
	for($i = 2; $i <= 6; $i++) {
		$key = 'rank'.$i;
		if(!isset($responses[$key]) || !is_array($responses[$key])) {
			continue;
		}
		$resp = $responses[$key];
		if(!isset($resp['status']) || intval($resp['status']) !== 200 || !isset($resp['data']) || !is_array($resp['data'])) {
			continue;
		}
		foreach($resp['data'] as $obj) {
			if(!is_array($obj)) {
				continue;
			}
			$items = isset($obj['ItemRankList']) && is_array($obj['ItemRankList']) ? $obj['ItemRankList'] : array();
			foreach($items as $item) {
				if(!is_array($item)) {
					continue;
				}
				$rows[] = dfb_rank_row($obj, $item, $index);
				$index++;
			}
		}
	}
	return $rows;
}

/**
 * 解析最近游玩响应。
 *
 * @param array $resp
 * @return array
 */
function dfb_parse_recent_response($resp) {
	if(!is_array($resp) || !isset($resp['status']) || intval($resp['status']) !== 200 || !isset($resp['data']) || !is_array($resp['data'])) {
		return array();
	}
	$rows = array();
	$index = 0;
	foreach($resp['data'] as $item) {
		if(!is_array($item)) {
			continue;
		}
		$rows[] = array(
			'idx'        => $index,
			'id'         => isset($item['MusicID']) ? intval($item['MusicID']) : 0,
			'name'       => isset($item['MusicName']) ? (string)$item['MusicName'] : '',
			'difficulty' => isset($item['MusicLevOld']) ? intval($item['MusicLevOld']) : 0,
			'level'      => isset($item['MusicLevel']) ? intval($item['MusicLevel']) : 0,
			'levelType'  => isset($item['MusicLev']) ? intval($item['MusicLev']) : 0,
			'acc'        => isset($item['PlayerPercent']) ? round(floatval($item['PlayerPercent']) / 100, 4) : 0.0,
			'score'      => isset($item['PlayerScore']) ? intval($item['PlayerScore']) : 0,
			'combo'      => isset($item['ComboCount']) ? intval($item['ComboCount']) : 0,
			'miss'       => isset($item['PlayerMiss']) ? intval($item['PlayerMiss']) : 0,
			'perfect'    => isset($item['PlayerPerfect']) ? intval($item['PlayerPerfect']) : 0,
			'great'      => isset($item['PlayerGreat']) ? intval($item['PlayerGreat']) : 0,
			'good'       => isset($item['PlayerGood']) ? intval($item['PlayerGood']) : 0,
			'time'       => isset($item['RecordTime']) ? (string)$item['RecordTime'] : '',
			'official'   => false,
			'source'     => 'recent',
		);
		$index++;
	}
	return $rows;
}

/**
 * 一次并发取齐战力分析需要的全部原始数据。
 *
 * profile / ladder / history / recent / rank2~rank6 共 8 条请求，
 * 正常情况下总耗时接近最慢的一条，而不是 8 条相加。
 *
 * @param array $token
 * @return array
 */
function dfb_fetch_ratio_bundle($token) {
	$headers = dfb_auth_headers($token);
	$base = DFB_CUBE_BASE;
	$userId = intval($token['userId']);
	$requests = array(
		'profile' => array(
			'method'  => 'GET',
			'url'     => $base.'/api/User/GetInfo?userId='.$userId,
			'headers' => $headers,
		),
		'ladder' => array(
			'method'  => 'GET',
			'url'     => $base.'/api/Match/GetQuanMinSeasons',
			'headers' => $headers,
		),
		'history' => array(
			'method'  => 'GET',
			'url'     => $base.'/api/User/GetLvRatioHistory?userId='.$userId,
			'headers' => $headers,
		),
		'recent' => array(
			'method'  => 'GET',
			'url'     => $base.'/api/User/GetLastPlay',
			'headers' => $headers,
		),
	);
	for($i = 2; $i <= 6; $i++) {
		$requests['rank'.$i] = array(
			'method'  => 'GET',
			'url'     => $base.'/api/User/GetMyRankNew?musicIndex='.$i,
			'headers' => $headers,
		);
	}
	$responses = dfb_http_json_multi($requests);

	$profile = dfb_empty_profile('nonexistent');
	$profileOk = false;
	if(isset($responses['profile']) && intval($responses['profile']['status']) === 200) {
		$profile = dfb_parse_profile($responses['profile']['body']);
		$profileOk = true;
	}
	if(!$profile['userId']) {
		$profile['userId'] = $userId;
	}

	$rankRows = dfb_parse_rank_rows_from_responses($responses);

	return array(
		'ok'         => $profileOk,
		'profile'    => $profile,
		'ladder'     => dfb_parse_ladder_response(isset($responses['ladder']) ? $responses['ladder'] : array()),
		'history'    => dfb_parse_history_response(isset($responses['history']) ? $responses['history'] : array()),
		'rankRows'   => $rankRows,
		'recentRows' => dfb_parse_recent_response(isset($responses['recent']) ? $responses['recent'] : array()),
	);
}

/**
 * 战力分析（B15 / R15 / B30 / AP30）。
 *
 * @param string $account
 * @param array  $token
 * @param string $mode b15 | b30 | ap30
 * @return array
 */
function dfb_data_ratio($account, $token, $mode) {
	$cacheKey = 'ratio:'.sha1($account.'|'.intval($token['userId']).'|'.(isset($token['accessToken']) ? $token['accessToken'] : ''));
	$bundle = dfb_ratio_cache_get($cacheKey);
	$cached = is_array($bundle);
	if(!$cached) {
		$bundle = dfb_fetch_ratio_bundle($token);
		if(!empty($bundle['ok'])) {
			dfb_ratio_cache_set($cacheKey, $bundle);
		}
	}
	$profile = isset($bundle['profile']) && is_array($bundle['profile']) ? $bundle['profile'] : dfb_empty_profile('nonexistent');
	if(!$profile['userId']) {
		$profile['userId'] = intval($token['userId']);
	}
	$ladder = isset($bundle['ladder']) && is_array($bundle['ladder']) ? $bundle['ladder'] : null;
	$history = isset($bundle['history']) && is_array($bundle['history']) ? $bundle['history'] : array();
	$rankRows = isset($bundle['rankRows']) && is_array($bundle['rankRows']) ? $bundle['rankRows'] : array();
	$recentRows = isset($bundle['recentRows']) && is_array($bundle['recentRows']) ? $bundle['recentRows'] : array();

	$lvRatio = intval($profile['lvRatio']);
	$last = dfb_last_ratio($history, $lvRatio);

	if($mode === 'b15') {
		$rankRows = dfb_sort_by_ratio($rankRows);
		$main = array();
		foreach($rankRows as $row) {
			if(count($main) >= 15) {
				break;
			}
			if(dfb_row_ratio_valid($row)) {
				$main[] = $row;
			}
		}
		$sub = array_slice($recentRows, 0, 15);

		$avgMain = dfb_ratio_average($main);
		$avgSub = dfb_ratio_average($sub);
		$avgAll = ($avgMain + $avgSub) / 2;

		return array(
			'ok'      => true,
			'mode'    => 'b15',
			'cached'  => $cached,
			'profile' => $profile,
			'ladder'  => $ladder,
			'summary' => array(
				'title'     => 'B15 / R15 战力分析',
				'lvRatio'   => $lvRatio,
				'lastRatio' => $last,
				'mainLabel' => 'B-15 战力',
				'subLabel'  => 'R-15 战力',
				'avgMain'   => round($avgMain, 4),
				'avgSub'    => round($avgSub, 4),
				'avgAll'    => round($avgAll, 5),
				'comment'   => dfb_ratio_comment($lvRatio),
				'countMain' => count($main),
				'countSub'  => count($sub),
			),
			'songs'    => dfb_songs_payload($main, $lvRatio),
			'songsSub' => dfb_songs_payload($sub, $lvRatio),
		);
	}

	$rankRows = dfb_sort_by_ratio($rankRows);
	$picked = array();
	$limit = 30;
	foreach($rankRows as $row) {
		if(count($picked) >= $limit) {
			break;
		}
		if(!dfb_row_ratio_valid($row)) {
			continue;
		}
		if($mode === 'ap30' && abs($row['acc'] - 100) > 0.0001) {
			continue;
		}
		$picked[] = $row;
	}
	$avg = dfb_ratio_average($picked);

	return array(
		'ok'      => true,
		'mode'    => $mode,
		'cached'  => $cached,
		'profile' => $profile,
		'ladder'  => $ladder,
		'summary' => array(
			'title'     => $mode === 'b30' ? 'B30 战力分析' : 'AP30 战力分析',
			'lvRatio'   => $lvRatio,
			'lastRatio' => $last,
			'mainLabel' => $mode === 'b30' ? 'B-30 战力' : 'AP-30 战力',
			'subLabel'  => null,
			'avgMain'   => round($avg, 4),
			'avgSub'    => null,
			'avgAll'    => null,
			'comment'   => dfb_ratio_comment($lvRatio),
			'countMain' => count($picked),
			'countSub'  => null,
		),
		'songs'    => dfb_songs_payload($picked, $lvRatio),
		'songsSub' => array(),
	);
}

/**
 * 指定等级分数列表（每页 30 首）。
 *
 * @param string $account
 * @param array  $token
 * @param int    $level
 * @param int    $page
 * @return array
 */
function dfb_data_level_scores($account, $token, $level, $page) {
	$level = intval($level);
	if($level < 0) {
		$level = 0;
	}
	if($level > 20) {
		$level = 20;
	}
	$page = intval($page);
	if($page < 1) {
		$page = 1;
	}
	if($page > 100) {
		$page = 100;
	}

	$profile = dfb_fetch_self_profile($token);
	$lvRatio = intval($profile['lvRatio']);

	$all = dfb_fetch_rank_rows($token);
	$filtered = array();
	foreach($all as $row) {
		if($row['level'] !== $level || !$row['official']) {
			continue;
		}
		$filtered[] = $row;
	}
	usort($filtered, 'dfb_cmp_acc');
	$total = count($filtered);
	$totalPages = $total > 0 ? intval(ceil($total / 30)) : 0;

	$slice = array_slice($filtered, ($page - 1) * 30, 30);

	return array(
		'ok'         => true,
		'level'      => $level,
		'page'       => $page,
		'totalPages' => $totalPages,
		'total'      => $total,
		'profile'    => $profile,
		'avg'        => round(dfb_ratio_average($slice), 4),
		'songs'      => dfb_songs_payload($slice, $lvRatio),
	);
}

/**
 * 规范战队文本。
 *
 * @param mixed $value
 * @return string
 */
function dfb_team_text($value) {
	return dfb_utf8_clean(trim((string)$value));
}

/**
 * 解析战队成员。
 *
 * @param array $data
 * @return array
 */
function dfb_parse_team_member($data) {
	if(!is_array($data)) {
		return array();
	}
	return array(
		'teamMemberId'   => isset($data['TeamMemberID']) ? intval($data['TeamMemberID']) : 0,
		'teamId'         => isset($data['TeamID']) ? intval($data['TeamID']) : 0,
		'userId'         => isset($data['UserID']) ? intval($data['UserID']) : 0,
		'headimgUrl'     => isset($data['HeadimgURL']) ? (string)$data['HeadimgURL'] : '',
		'userName'       => dfb_team_text(isset($data['UserName']) ? $data['UserName'] : ''),
		'memberType'     => isset($data['MemberType']) ? intval($data['MemberType']) : 0,
		'memberTypeText' => dfb_team_text(isset($data['MemberTypeText']) ? $data['MemberTypeText'] : ''),
		'addTime'        => dfb_team_text(isset($data['AddTime']) ? $data['AddTime'] : ''),
		'pointTotal'     => isset($data['PointTotal']) ? intval($data['PointTotal']) : 0,
		'pointMonth'     => isset($data['PointCurMonth']) ? intval($data['PointCurMonth']) : 0,
		'goldTotal'      => isset($data['GoldTotal']) ? intval($data['GoldTotal']) : 0,
		'goldMonth'      => isset($data['GoldCurMonth']) ? intval($data['GoldCurMonth']) : 0,
		'gold'           => isset($data['Gold']) ? intval($data['Gold']) : 0,
		'sex'            => isset($data['Sex']) ? intval($data['Sex']) : 0,
		'isVip'          => !empty($data['IsVIP']),
		'lvRatio'        => isset($data['LvRatio']) ? intval($data['LvRatio']) : 0,
		'lastAccess'     => dfb_team_text(isset($data['LastAccess']) ? $data['LastAccess'] : ''),
		'lastAccessText' => dfb_team_text(isset($data['LastAccessText']) ? $data['LastAccessText'] : ''),
	);
}

/**
 * 解析 GetTeamInfo。
 *
 * @param array $data
 * @return array|null
 */
function dfb_parse_team_info($data) {
	if(!is_array($data)) {
		return null;
	}
	if(isset($data['Data']) && is_array($data['Data'])) {
		$data = $data['Data'];
	}
	if(!isset($data['TeamID']) && !isset($data['TeamName'])) {
		return null;
	}
	$intro = isset($data['Intro']) ? (string)$data['Intro'] : '';
	$intro = str_replace(array("\r\n", "\r"), "\n", $intro);
	$team = array(
		'id'            => isset($data['TeamID']) ? intval($data['TeamID']) : 0,
		'name'          => dfb_team_text(isset($data['TeamName']) ? $data['TeamName'] : ''),
		'logo'          => isset($data['Logo']) ? (string)$data['Logo'] : '',
		'addTime'       => dfb_team_text(isset($data['AddTime']) ? $data['AddTime'] : ''),
		'intro'         => dfb_utf8_clean(trim($intro)),
		'point'         => isset($data['TeamPoint']) ? intval($data['TeamPoint']) : 0,
		'gold'          => isset($data['TeamGold']) ? intval($data['TeamGold']) : 0,
		'level'         => isset($data['TeamLv']) ? intval($data['TeamLv']) : 0,
		'maxMembers'    => isset($data['MaxMember']) ? intval($data['MaxMember']) : 0,
		'currentMembers'=> isset($data['CurrentCount']) ? intval($data['CurrentCount']) : 0,
		'rank'          => isset($data['TeamRank']) ? intval($data['TeamRank']) : 0,
		'isBelong'      => !empty($data['IsBelong']),
		'isRequesting'  => !empty($data['IsRequesting']),
		'me'            => isset($data['UserInfo']) && is_array($data['UserInfo']) ? dfb_parse_team_member($data['UserInfo']) : null,
	);
	return $team;
}

/**
 * 解析 GetTeamMemberList（数组或 List/Data/Rows 包装）。
 *
 * @param array $data
 * @return array
 */
function dfb_parse_team_members($data) {
	if(!is_array($data)) {
		return array();
	}
	if(isset($data['List']) && is_array($data['List'])) {
		$data = $data['List'];
	} elseif(isset($data['Data']) && is_array($data['Data'])) {
		$data = $data['Data'];
	} elseif(isset($data['Rows']) && is_array($data['Rows'])) {
		$data = $data['Rows'];
	} elseif(isset($data['Items']) && is_array($data['Items'])) {
		$data = $data['Items'];
	}
	$members = array();
	foreach($data as $item) {
		if(!is_array($item) || (!isset($item['UserID']) && !isset($item['TeamMemberID']))) {
			continue;
		}
		$members[] = dfb_parse_team_member($item);
	}
	return $members;
}

/**
 * 查询当前账号的战队详情（战队信息 + 当前页成员）。
 *
 * 战队 ID 来自 mydc 个人信息的 TeamID；每页 30 人。
 *
 * @param string $account
 * @param array  $token
 * @param int    $page
 * @return array
 */
function dfb_data_team($account, $token, $page, $sort = 'default', $order = 'desc') {
	$page = intval($page);
	if($page < 1) {
		$page = 1;
	}
	if($page > 1000) {
		$page = 1000;
	}
	$pageSize = 30;
	$allowedSorts = array('default', 'month', 'total', 'join', 'ratio', 'gold');
	$sort = in_array($sort, $allowedSorts, true) ? $sort : 'default';
	$order = strtolower((string)$order) === 'asc' ? 'asc' : 'desc';

	$profile = dfb_fetch_self_profile($token);
	$teamId = isset($profile['teamId']) ? intval($profile['teamId']) : 0;
	$teamName = isset($profile['teamName']) ? (string)$profile['teamName'] : '';

	if($teamId <= 0) {
		return array(
			'ok'           => true,
			'hasTeam'      => false,
			'teamId'       => 0,
			'teamName'     => $teamName,
			'team'         => null,
			'me'           => null,
			'members'      => array(),
			'page'         => 1,
			'pageSize'     => $pageSize,
			'memberPages'  => 0,
			'sort'         => $sort,
			'order'        => $order,
			'hasMore'      => false,
		);
	}

	$headers = dfb_auth_headers($token);
	$base = DFB_CUBE_BASE;
	$responses = dfb_http_json_multi(array(
		'info' => array(
			'method'  => 'GET',
			'url'     => $base.'/api/Team/GetTeamInfo?teamid='.$teamId,
			'headers' => $headers,
		),
		'members' => array(
			'method'  => 'GET',
			'url'     => $base.'/api/Team/GetTeamMemberList?teamid='.$teamId
				.'&orderby=0&ordertype=0&pageSize='.$pageSize
				.'&getTotalCount=true&pageIndex='.$page,
			'headers' => $headers,
		),
	));

	$team = null;
	if(isset($responses['info']) && intval($responses['info']['status']) === 200) {
		$team = dfb_parse_team_info($responses['info']['data']);
	}
	$members = array();
	if(isset($responses['members']) && intval($responses['members']['status']) === 200) {
		$members = dfb_parse_team_members($responses['members']['data']);
	}

	/* 成员列表接口偶尔不返回当前账号的 PointCurMonth，
	   但 TeamInfo.UserInfo 里有真实值；合并进当前账号那一行。 */
	$me = $team && isset($team['me']) ? $team['me'] : null;
	$currentMonth = date('Y-m');
	foreach($members as $idx => $member) {
		/* 本月入队的成员，入队前不可能有战队贡献，
		   此时月贡献 = 总贡献；接口未回填时据此补齐。 */
		if(intval($member['pointMonth']) <= 0 && intval($member['pointTotal']) > 0
			&& substr((string)$member['addTime'], 0, 7) === $currentMonth) {
			$members[$idx]['pointMonth'] = intval($member['pointTotal']);
		}
	}
	if($me && !empty($me['userId'])) {
		foreach($members as $idx => $member) {
			if(intval($member['userId']) !== intval($me['userId'])) {
				continue;
			}
			if(intval($member['pointMonth']) <= 0 && intval($me['pointMonth']) > 0) {
				$members[$idx]['pointMonth'] = intval($me['pointMonth']);
			}
			if(intval($member['pointTotal']) <= 0 && intval($me['pointTotal']) > 0) {
				$members[$idx]['pointTotal'] = intval($me['pointTotal']);
			}
			if(intval($member['lvRatio']) <= 0 && intval($me['lvRatio']) > 0) {
				$members[$idx]['lvRatio'] = intval($me['lvRatio']);
			}
			break;
		}
	}

	$memberPages = 0;
	if($team && intval($team['currentMembers']) > 0) {
		$memberPages = intval(ceil(intval($team['currentMembers']) / $pageSize));
	}

	/* 上游 GetTeamMemberList 当前会忽略 pageSize、一次返回全部成员；
	   这里按 30 人本地分页，避免前端每页渲染 700+ 行。 */
	$allMemberCount = count($members);
	if($allMemberCount > $pageSize) {
		$members = array_slice($members, ($page - 1) * $pageSize, $pageSize);
	}
	if($memberPages <= 0 && $allMemberCount > 0) {
		$memberPages = intval(ceil($allMemberCount / $pageSize));
	}

	/* 排序必须在分页前完成，保证跨页顺序连续 */
	if($sort !== 'default' && $allMemberCount > 1) {
		usort($members, function ($a, $b) use ($sort, $order) {
			if($sort === 'join') {
				$va = strtotime($a['addTime']);
				$vb = strtotime($b['addTime']);
				if($va === false) $va = 0;
				if($vb === false) $vb = 0;
			} elseif($sort === 'month') {
				$va = intval($a['pointMonth']);
				$vb = intval($b['pointMonth']);
			} elseif($sort === 'total') {
				$va = intval($a['pointTotal']);
				$vb = intval($b['pointTotal']);
			} elseif($sort === 'ratio') {
				$va = intval($a['lvRatio']);
				$vb = intval($b['lvRatio']);
			} else {
				$va = intval($a['goldTotal']);
				$vb = intval($b['goldTotal']);
			}
			if($va == $vb) {
				/* 月贡献字段可能整队都是 0（上游未回填），用总贡献做稳定的次级排序 */
				if($sort === 'month') {
					$va = intval($a['pointTotal']);
					$vb = intval($b['pointTotal']);
				}
				if($va == $vb) {
					return intval($a['userId']) < intval($b['userId']) ? -1 : 1;
				}
			}
			$cmp = ($va < $vb) ? -1 : 1;
			return $order === 'asc' ? $cmp : -$cmp;
		});
	}

	return array(
		'ok'          => $team ? true : false,
		'hasTeam'     => $team ? true : false,
		'teamId'      => $teamId,
		'teamName'    => $team ? $team['name'] : $teamName,
		'team'        => $team,
		'me'          => $team && isset($team['me']) ? $team['me'] : null,
		'members'     => $members,
		'page'        => $page,
		'pageSize'    => $pageSize,
		'memberPages' => $memberPages,
		'sort'        => $sort,
		'order'       => $order,
		'hasMore'     => $memberPages > 0 ? $page < $memberPages : count($members) >= $pageSize,
		'message'     => $team ? '' : '战队信息读取失败，请稍后重试。',
	);
}
