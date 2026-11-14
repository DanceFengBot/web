<?php

/**
 * 站点开关（关闭后仅管理员和登录/退出可用）。
 *
 * 状态存放在 data/site.php，避免和登录 Token 大文件一起重写。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

function dfb_site_path() {
	return dfb_data_dir().'site.php';
}

function dfb_site_default() {
	return array('closed' => false, 'updated' => 0, 'by' => '');
}

function dfb_site_read() {
	$raw = @file_get_contents(dfb_site_path());
	if(!is_string($raw) || $raw === '') {
		return dfb_site_default();
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return dfb_site_default();
	}
	$data = json_decode(substr($raw, $pos), true);
	if(!is_array($data)) {
		return dfb_site_default();
	}
	return array(
		'closed'  => !empty($data['closed']),
		'updated' => isset($data['updated']) ? intval($data['updated']) : 0,
		'by'      => isset($data['by']) ? (string)$data['by'] : '',
	);
}

function dfb_site_is_closed() {
	$data = dfb_site_read();
	return !empty($data['closed']);
}

function dfb_site_write($closed, $by = '') {
	$dir = dfb_data_dir();
	if(!dfb_dir_writable($dir)) {
		return false;
	}
	$data = array(
		'closed'  => (bool)$closed,
		'updated' => time(),
		'by'      => (string)$by,
	);
	$path = dfb_site_path();
	$tmp = $path.'.'.substr(md5(uniqid('', true)), 0, 8).'.tmp';
	if(@file_put_contents($tmp, "<?php exit; ?>\n".dfb_json_pretty($data), LOCK_EX) === false) {
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
 * 已登录用户（按 userId 合并多个浏览器 / 设备）。
 *
 * 只返回展示信息，绝不返回 access_token / refresh_token。
 *
 * @return array
 */
function dfb_logged_in_users() {
	$state = dfb_state_read();
	$tokens = isset($state['tokens']) && is_array($state['tokens']) ? $state['tokens'] : array();
	$adminIds = function_exists('dfb_admin_user_ids') ? dfb_admin_user_ids() : array();
	$users = array();
	foreach($tokens as $key => $token) {
		if(!is_array($token)) {
			continue;
		}
		$userId = isset($token['userId']) ? intval($token['userId']) : 0;
		if($userId <= 0) {
			continue;
		}
		$updated = isset($token['updated']) ? intval($token['updated']) : (isset($token['recTime']) ? intval(intval($token['recTime']) / 1000) : 0);
		$name = isset($token['userName']) ? trim((string)$token['userName']) : '';
		$available = !empty($token['available']);
		if(!isset($users[$userId])) {
			$users[$userId] = array(
				'userId'    => $userId,
				'userName'  => $name,
				'updated'   => $updated,
				'available' => $available,
				'sessions'  => 0,
				'isAdmin'   => in_array($userId, $adminIds, true),
			);
		}
		$users[$userId]['sessions']++;
		$users[$userId]['updated'] = max($users[$userId]['updated'], $updated);
		$users[$userId]['available'] = $users[$userId]['available'] || $available;
		if($name !== '') {
			$users[$userId]['userName'] = $name;
		}
	}
	$list = array_values($users);
	usort($list, function ($a, $b) {
		if($a['updated'] === $b['updated']) {
			return $a['userId'] < $b['userId'] ? -1 : 1;
		}
		return $a['updated'] > $b['updated'] ? -1 : 1;
	});
	return $list;
}
