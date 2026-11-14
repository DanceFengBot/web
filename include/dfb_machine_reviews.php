<?php

/**
 * 舞小枫 · 场地评价
 *
 * 按上游 MachineTerminalID 存储本地评价，与机台图片一起展示。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

function dfb_machine_reviews_path() {
	return dfb_data_dir().'machine_reviews.php';
}

/** 评价照片目录（公开静态图片，只允许图片扩展名）。 */
function dfb_machine_review_images_dir() {
	$dir = DFB_ROOT_DIR.'assets/machine_reviews/';
	if(!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	return $dir;
}

/**
 * 把相对路径补成当前站点的绝对地址，App 的 image 也能直接加载。
 *
 * @param string $relative
 * @return string
 */
function dfb_machine_review_absolute_url($relative) {
	$relative = ltrim((string)$relative, '/');
	$scheme = 'http';
	if((!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
		|| (isset($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower((string)$_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')) {
		$scheme = 'https';
	}
	$host = isset($_SERVER['HTTP_HOST']) ? (string)$_SERVER['HTTP_HOST'] : '';
	if($host === '') {
		return dfb_root_relative_path().$relative;
	}
	return $scheme.'://'.$host.dfb_root_relative_path().$relative;
}

/**
 * 保存 base64 评价照片，压缩为 JPEG。
 *
 * @param string $dataURL
 * @return string 图片绝对地址
 * @throws DfbError
 */
function dfb_machine_review_save_image($dataURL) {
	$dataURL = trim((string)$dataURL);
	if($dataURL === '') {
		throw new DfbError('图片内容为空。', 400);
	}
	if(strpos($dataURL, 'data:') === 0) {
		$comma = strpos($dataURL, ',');
		if($comma === false) {
			throw new DfbError('图片格式不正确。', 400);
		}
		$dataURL = substr($dataURL, $comma + 1);
	}
	if(strlen($dataURL) > 8 * 1024 * 1024) {
		throw new DfbError('图片太大了，请选择 4MB 以内的照片。', 413);
	}
	$raw = base64_decode($dataURL, true);
	if($raw === false || strlen($raw) < 100) {
		throw new DfbError('图片解码失败。', 400);
	}
	if(strlen($raw) > 6 * 1024 * 1024) {
		throw new DfbError('图片太大了，请选择 4MB 以内的照片。', 413);
	}
	if(substr($raw, 0, 4) === 'RIFF' && substr($raw, 8, 4) === 'WEBP') {
		throw new DfbError('暂不支持 WebP 图片，请选择 JPG / PNG。', 400);
	}
	$info = @getimagesizefromstring($raw);
	if(!$info || empty($info[0]) || empty($info[1])) {
		throw new DfbError('不是有效的图片文件。', 400);
	}
	$type = isset($info[2]) ? intval($info[2]) : 0;
	if($type !== IMAGETYPE_JPEG && $type !== IMAGETYPE_PNG) {
		throw new DfbError('只支持 JPG / PNG 图片。', 400);
	}
	$src = @imagecreatefromstring($raw);
	if(!$src) {
		throw new DfbError('图片读取失败。', 400);
	}
	$w = imagesx($src);
	$h = imagesy($src);
	$max = 1600;
	if($w > $max || $h > $max) {
		$scale = min($max / $w, $max / $h);
		$nw = max(1, intval(round($w * $scale)));
		$nh = max(1, intval(round($h * $scale)));
	} else {
		$nw = $w;
		$nh = $h;
	}
	$dst = imagecreatetruecolor($nw, $nh);
	$white = imagecolorallocate($dst, 255, 255, 255);
	imagefilledrectangle($dst, 0, 0, $nw, $nh, $white);
	imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
	imagedestroy($src);

	$name = 'review_'.date('YmdHis').'_'.dfb_random_hex(6).'.jpg';
	$path = dfb_machine_review_images_dir().$name;
	if(!@imagejpeg($dst, $path, 82)) {
		imagedestroy($dst);
		throw new DfbError('图片保存失败，请稍后重试。', 500);
	}
	imagedestroy($dst);
	return dfb_machine_review_absolute_url('assets/machine_reviews/'.$name);
}

function dfb_machine_reviews_clean_images($images) {
	if(!is_array($images)) {
		return array();
	}
	$out = array();
	foreach($images as $url) {
		$url = trim((string)$url);
		if($url === '' || strlen($url) > 600) {
			continue;
		}
		if(!preg_match('~^(?:https?://[^\s]+)?/?assets/machine_reviews/[A-Za-z0-9_.-]+\.jpg$~i', $url)) {
			continue;
		}
		$out[] = dfb_utf8_clean($url);
		if(count($out) >= 3) {
			break;
		}
	}
	return $out;
}

function dfb_machine_reviews_normalize($data) {
	if(!is_array($data)) {
		return array();
	}
	$list = array();
	foreach($data as $item) {
		if(!is_array($item) || empty($item['id']) || empty($item['terminalId'])) {
			continue;
		}
		$content = str_replace(array("\r\n", "\r"), "\n", isset($item['content']) ? (string)$item['content'] : '');
		$content = trim(str_replace("\0", '', $content));
		if($content === '') {
			continue;
		}
		$rating = isset($item['rating']) ? intval($item['rating']) : 0;
		if($rating < 1 || $rating > 5) {
			$rating = 5;
		}
		$list[] = array(
			'id'         => (string)$item['id'],
			'terminalId' => dfb_utf8_clean((string)$item['terminalId']),
			'userId'     => isset($item['userId']) ? intval($item['userId']) : 0,
			'userName'   => dfb_utf8_clean(isset($item['userName']) ? (string)$item['userName'] : ''),
			'rating'     => $rating,
			'content'    => dfb_utf8_clean($content),
			'images'     => dfb_machine_reviews_clean_images(isset($item['images']) ? $item['images'] : array()),
			'createdAt'  => isset($item['createdAt']) ? intval($item['createdAt']) : 0,
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

function dfb_machine_reviews_read($reload = false) {
	$cache = array();
	$raw = @file_get_contents(dfb_machine_reviews_path());
	if(!is_string($raw) || $raw === '') {
		return $cache;
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return $cache;
	}
	$data = json_decode(substr($raw, $pos), true);
	$cache = dfb_machine_reviews_normalize(isset($data['reviews']) ? $data['reviews'] : $data);
	return $cache;
}

function dfb_machine_reviews_write($reviews) {
	$dir = dfb_data_dir();
	if(!dfb_dir_writable($dir)) {
		return false;
	}
	$reviews = array_slice(dfb_machine_reviews_normalize($reviews), 0, 1000);
	$content = "<?php exit; ?>\n".dfb_json_pretty(array('reviews' => $reviews));
	$path = dfb_machine_reviews_path();
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

function dfb_machine_reviews_for($terminalId) {
	$terminalId = trim((string)$terminalId);
	$out = array();
	foreach(dfb_machine_reviews_read() as $item) {
		if($item['terminalId'] === $terminalId) {
			$out[] = $item;
		}
	}
	return $out;
}

function dfb_machine_reviews_summary($terminalId) {
	$list = dfb_machine_reviews_for($terminalId);
	$count = count($list);
	$sum = 0;
	foreach($list as $item) {
		$sum += intval($item['rating']);
	}
	return array(
		'count' => $count,
		'avg'   => $count ? round($sum / $count, 1) : 0,
	);
}

/**
 * @param array  $token
 * @param string $terminalId
 * @param int    $rating
 * @param string $content
 * @return array
 * @throws DfbError
 */
function dfb_machine_reviews_add($token, $terminalId, $rating, $content, $images = array()) {
	$terminalId = trim((string)$terminalId);
	if($terminalId === '' || strlen($terminalId) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $terminalId)) {
		throw new DfbError('场地 ID 不正确。', 400);
	}
	$rating = intval($rating);
	if($rating < 1 || $rating > 5) {
		throw new DfbError('请选择 1~5 星评价。', 400);
	}
	$content = str_replace(array("\r\n", "\r"), "\n", (string)$content);
	$content = trim(str_replace("\0", '', $content));
	if($content === '') {
		throw new DfbError('评价内容不能为空。', 400);
	}
	$len = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
	if($len > 300) {
		throw new DfbError('评价最多 300 个字符。', 400);
	}

	$reviews = dfb_machine_reviews_read();
	$userId = isset($token['userId']) ? intval($token['userId']) : 0;
	$now = time();
	foreach($reviews as $item) {
		if(intval($item['userId']) !== $userId) {
			continue;
		}
		if($now - intval($item['createdAt']) < 20) {
			throw new DfbError('评价太快了，请等待 20 秒后再试。', 429);
		}
		break;
	}

	$userName = isset($token['userName']) ? trim((string)$token['userName']) : '';
	if($userName === '' && $userId > 0) {
		$userName = '舞立方用户 '.$userId;
	}
	$item = array(
		'id'         => date('YmdHis').'-'.dfb_random_hex(4),
		'terminalId' => $terminalId,
		'userId'     => $userId,
		'userName'   => dfb_utf8_clean($userName),
		'rating'     => $rating,
		'content'    => dfb_utf8_clean($content),
		'images'     => dfb_machine_reviews_clean_images($images),
		'createdAt'  => $now,
	);
	array_unshift($reviews, $item);
	if(!dfb_machine_reviews_write($reviews)) {
		throw new DfbError('评价保存失败：data/ 目录不可写。', 500);
	}
	return $item;
}

function dfb_machine_reviews_delete($id) {
	$id = (string)$id;
	$reviews = dfb_machine_reviews_read();
	$kept = array();
	$found = false;
	foreach($reviews as $item) {
		if((string)$item['id'] === $id) {
			$found = true;
			continue;
		}
		$kept[] = $item;
	}
	if(!$found) {
		return false;
	}
	return dfb_machine_reviews_write($kept);
}
