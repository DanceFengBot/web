<?php

/**
 * 舞小枫 · 站点公告
 *
 * 公告是纯文本（保留换行），存放在 data/announcement.php，只有管理员能改。
 * 公开数据通过 bootstrap / window.DFB 下发给 Web 与 App。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

/**
 * 公告文件路径。
 *
 * @return string
 */
function dfb_announcement_path() {
	return dfb_data_dir().'announcement.php';
}

/**
 * 空公告。
 *
 * @return array
 */
function dfb_announcement_empty() {
	return array(
		'id'        => '',
		'content'   => '',
		'closeable' => false,
		'enabled'   => false,
		'updated'   => 0,
		'author'    => '',
	);
}

/**
 * 规范化公告字段。
 *
 * @param array $data
 * @return array
 */
function dfb_announcement_normalize($data) {
	$base = dfb_announcement_empty();
	if(!is_array($data)) {
		return $base;
	}
	$content = isset($data['content']) ? (string)$data['content'] : '';
	$content = str_replace(array("\r\n", "\r"), "\n", $content);
	$content = str_replace("\0", '', $content);
	$base['id'] = isset($data['id']) ? trim((string)$data['id']) : '';
	$base['content'] = $content;
	$base['closeable'] = !empty($data['closeable']);
	$base['enabled'] = !empty($data['enabled']) && trim($content) !== '';
	$base['updated'] = isset($data['updated']) ? intval($data['updated']) : 0;
	$base['author'] = isset($data['author']) ? (string)$data['author'] : '';
	return $base;
}

/**
 * 读取公告。
 *
 * @return array
 */
function dfb_announcement_read() {
	$raw = @file_get_contents(dfb_announcement_path());
	if(!is_string($raw) || $raw === '') {
		return dfb_announcement_empty();
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return dfb_announcement_empty();
	}
	$data = json_decode(substr($raw, $pos), true);
	return dfb_announcement_normalize($data);
}

/**
 * 写公告（临时文件 + rename）。
 *
 * @param array $data
 * @return bool
 */
function dfb_announcement_write($data) {
	$dir = dfb_data_dir();
	if(!dfb_dir_writable($dir)) {
		return false;
	}
	$data = dfb_announcement_normalize($data);
	$content = "<?php exit; ?>\n".dfb_json_pretty($data);
	$path = dfb_announcement_path();
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
 * 公开公告（未启用 / 空内容返回 null）。
 *
 * @return array|null
 */
function dfb_announcement_public() {
	$data = dfb_announcement_read();
	if(empty($data['enabled']) || trim($data['content']) === '') {
		return null;
	}
	return array(
		'id'        => $data['id'],
		'content'   => $data['content'],
		'closeable' => $data['closeable'],
		'updated'   => $data['updated'],
	);
}

/**
 * 发布 / 更新公告。
 *
 * @param string $content
 * @param bool   $closeable
 * @param string $author
 * @return array
 * @throws DfbError
 */
function dfb_announcement_save($content, $closeable, $author = '') {
	$content = str_replace(array("\r\n", "\r"), "\n", (string)$content);
	$content = str_replace("\0", '', $content);
	$content = trim($content);
	if($content === '') {
		throw new DfbError('公告内容不能为空。', 400);
	}
	$length = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
	if($length > 4000) {
		$content = function_exists('mb_substr') ? mb_substr($content, 0, 4000, 'UTF-8') : substr($content, 0, 4000);
	}
	$data = array(
		'id'        => date('YmdHis').'-'.dfb_random_hex(4),
		'content'   => $content,
		'closeable' => (bool)$closeable,
		'enabled'   => true,
		'updated'   => time(),
		'author'    => (string)$author,
	);
	if(!dfb_announcement_write($data)) {
		throw new DfbError('公告保存失败：data/ 目录不可写。', 500);
	}
	return dfb_announcement_public();
}

/**
 * 清除公告。
 *
 * @return void
 */
function dfb_announcement_clear() {
	$path = dfb_announcement_path();
	if(is_file($path)) {
		@unlink($path);
	}
}
