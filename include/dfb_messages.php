<?php

/**
 * 舞小枫 · 留言板
 *
 * 登录用户可发言提建议；纯文本保留换行。数据存 data/messages.php。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

function dfb_messages_path() {
	return dfb_data_dir().'messages.php';
}

function dfb_messages_normalize($data) {
	if(!is_array($data)) {
		return array();
	}
	$list = array();
	foreach($data as $item) {
		if(!is_array($item) || empty($item['id'])) {
			continue;
		}
		$content = isset($item['content']) ? (string)$item['content'] : '';
		$content = str_replace(array("\r\n", "\r"), "\n", $content);
		$content = str_replace("\0", '', $content);
		$content = trim($content);
		if($content === '') {
			continue;
		}
		$list[] = array(
			'id'        => (string)$item['id'],
			'userId'    => isset($item['userId']) ? intval($item['userId']) : 0,
			'userName'  => dfb_utf8_clean(isset($item['userName']) ? (string)$item['userName'] : ''),
			'content'   => dfb_utf8_clean($content),
			'createdAt' => isset($item['createdAt']) ? intval($item['createdAt']) : 0,
		);
	}
	usort($list, function ($a, $b) {
		if($a['createdAt'] === $b['createdAt']) {
			return strcmp($b['id'], $a['id']);
		}
		return $a['createdAt'] > $b['createdAt'] ? -1 : 1;
	});
	return $list;
}

function dfb_messages_read() {
	$raw = @file_get_contents(dfb_messages_path());
	if(!is_string($raw) || $raw === '') {
		return array();
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return array();
	}
	$data = json_decode(substr($raw, $pos), true);
	return dfb_messages_normalize(isset($data['messages']) ? $data['messages'] : $data);
}

function dfb_messages_write($messages) {
	$dir = dfb_data_dir();
	if(!dfb_dir_writable($dir)) {
		return false;
	}
	$messages = array_slice(dfb_messages_normalize($messages), 0, 500);
	$content = "<?php exit; ?>\n".dfb_json_pretty(array('messages' => $messages));
	$path = dfb_messages_path();
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
 * @param string $account
 * @param array  $token
 * @param string $content
 * @return array
 * @throws DfbError
 */
function dfb_messages_add($account, $token, $content) {
	$content = str_replace(array("\r\n", "\r"), "\n", (string)$content);
	$content = str_replace("\0", '', $content);
	$content = trim($content);
	if($content === '') {
		throw new DfbError('留言内容不能为空。', 400);
	}
	$len = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
	if($len > 500) {
		throw new DfbError('留言最多 500 个字符。', 400);
	}

	$messages = dfb_messages_read();
	$userId = isset($token['userId']) ? intval($token['userId']) : 0;
	$now = time();
	foreach($messages as $item) {
		if(intval($item['userId']) !== $userId) {
			continue;
		}
		if($now - intval($item['createdAt']) < 20) {
			throw new DfbError('发言太快了，请等待 20 秒后再试。', 429);
		}
		break;
	}

	$userName = isset($token['userName']) ? trim((string)$token['userName']) : '';
	if($userName === '' && $userId > 0) {
		$userName = '舞立方用户 '.$userId;
	}
	$item = array(
		'id'        => date('YmdHis').'-'.dfb_random_hex(4),
		'userId'    => $userId,
		'userName'  => dfb_utf8_clean($userName),
		'content'   => dfb_utf8_clean($content),
		'createdAt' => $now,
	);
	array_unshift($messages, $item);
	if(!dfb_messages_write($messages)) {
		throw new DfbError('留言保存失败：data/ 目录不可写。', 500);
	}
	return $item;
}

/**
 * @param string $id
 * @return bool
 */
function dfb_messages_delete($id) {
	$id = (string)$id;
	$messages = dfb_messages_read();
	$kept = array();
	$found = false;
	foreach($messages as $item) {
		if((string)$item['id'] === $id) {
			$found = true;
			continue;
		}
		$kept[] = $item;
	}
	if(!$found) {
		return false;
	}
	return dfb_messages_write($kept);
}
