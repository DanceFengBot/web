<?php

/**
 * 舞小枫 · 战力分析图片渲染（PHP GD）
 *
 * 素材、字体和排版来自上一层的 Python 版：
 *   DcConfig/Images/UserRatioImage/{Background1,2,3,Card1,2,3,AP,SSS,...}.png
 *   DcConfig/Fonts/{SmileySans,PangMen,MSYHBD}
 *
 * 输出固定 1200×2700 PNG，B15 / B30 / AP30 共用同一套模板。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

/** 模板素材目录（已随 web 一起部署）。 */
function dfb_ratio_image_asset_dir() {
	return DFB_ROOT_DIR.'assets/ratio/';
}

/** 远程头像 / 封面缓存目录。 */
function dfb_ratio_image_cache_dir() {
	$dir = dfb_data_dir().'ratio_image_cache/';
	if(!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	return $dir;
}

/** 成品图短缓存目录（重复导出直接复用，避免重新取数与绘制）。 */
function dfb_image_final_cache_dir() {
	$dir = dfb_data_dir().'image_cache/';
	if(!is_dir($dir)) {
		@mkdir($dir, 0755, true);
	}
	return $dir;
}

/**
 * 成品图缓存时长（秒）。
 *
 * 默认 180 秒；如 config.php 提供 dfb_image_cache_ttl，可覆盖。
 *
 * @return int
 */
function dfb_image_final_cache_ttl() {
	static $ttl = null;
	if($ttl !== null) {
		return $ttl;
	}
	$config = dfb_config();
	$ttl = isset($config['dfb_image_cache_ttl']) ? intval($config['dfb_image_cache_ttl']) : 180;
	if($ttl < 30) {
		$ttl = 30;
	}
	if($ttl > 3600) {
		$ttl = 3600;
	}
	return $ttl;
}

/**
 * @param string $key
 * @param int    $ttl 0 表示使用默认时长
 * @return string|null
 */
function dfb_image_final_cache_get($key, $ttl = 0) {
	if($ttl <= 0) {
		$ttl = dfb_image_final_cache_ttl();
	}
	$path = dfb_image_final_cache_dir().sha1($key).'.png';
	if(!is_file($path)) {
		return null;
	}
	if(filemtime($path) + max(1, intval($ttl)) < time()) {
		return null;
	}
	$data = @file_get_contents($path);
	return is_string($data) && $data !== '' ? $data : null;
}

/**
 * @param string $key
 * @param string $png
 * @return void
 */
function dfb_image_final_cache_set($key, $png) {
	if(!is_string($png) || $png === '') {
		return;
	}
	$path = dfb_image_final_cache_dir().sha1($key).'.png';
	@file_put_contents($path, $png, LOCK_EX);
	/* 清理一小时前的旧成品，避免缓存目录无限增长 */
	if(mt_rand(1, 20) === 1) {
		foreach((array)@glob(dfb_image_final_cache_dir().'*.png') as $file) {
			if(is_file($file) && filemtime($file) + 3600 < time()) {
				@unlink($file);
			}
		}
	}
}

/**
 * 模板字体。
 *
 * @param string $key info|title|score
 * @return string
 */
function dfb_ratio_image_font($key) {
	static $resolved = array();
	if(isset($resolved[$key])) {
		return $resolved[$key];
	}
	$dir = dfb_ratio_image_asset_dir().'fonts/';
	$map = array(
		'info'  => 'SmileySans-Oblique.ttf',
		'title' => 'MSYHBD.TTC',
		'score' => 'PANGMENZHENGDAOBIAOTITI-1.TTF',
	);
	$name = isset($map[$key]) ? $map[$key] : $map['info'];
	$path = $dir.$name;
	if(!is_file($path) || !@imagettfbbox(12, 0, $path, '国')) {
		$path = $dir.'SmileySans-Oblique.ttf';
	}
	$resolved[$key] = $path;
	return $path;
}

/**
 * 读取模板 PNG。
 *
 * @param string $name
 * @return resource|null
 */
function dfb_ratio_image_asset($name) {
	static $cache = array();
	if(isset($cache[$name])) {
		return $cache[$name];
	}
	$path = dfb_ratio_image_asset_dir().$name;
	if(!is_file($path)) {
		return null;
	}
	$img = @imagecreatefrompng($path);
	if(!$img) {
		$raw = @file_get_contents($path);
		if(is_string($raw) && $raw !== '') {
			$img = @imagecreatefromstring($raw);
		}
	}
	if($img) {
		$cache[$name] = $img;
		return $img;
	}
	return null;
}

/**
 * 颜色。
 *
 * @param resource $im
 * @param string   $hex
 * @return int
 */
function dfb_ratio_image_color($im, $hex) {
	$hex = ltrim((string)$hex, '#');
	if(strlen($hex) === 3) {
		$hex = $hex[0].$hex[0].$hex[1].$hex[1].$hex[2].$hex[2];
	}
	if(strlen($hex) !== 6) {
		return imagecolorallocate($im, 0, 0, 0);
	}
	return imagecolorallocate(
		$im,
		hexdec(substr($hex, 0, 2)),
		hexdec(substr($hex, 2, 2)),
		hexdec(substr($hex, 4, 2))
	);
}

/**
 * 把相对资源地址补成舞立方 CDN 地址。
 *
 * @param string $url
 * @return string
 */
function dfb_ratio_image_normalize_url($url) {
	$url = trim((string)$url);
	if($url === '') {
		return '';
	}
	if(strpos($url, '//') === 0) {
		return 'https:'.$url;
	}
	if(stripos($url, 'http://') === 0 || stripos($url, 'https://') === 0) {
		return $url;
	}
	$base = 'https://dancewebdemo.shenghuayule.com';
	return $base.'/'.ltrim($url, '/');
}

/**
 * 远程图片缓存路径。
 *
 * @param string $url
 * @return string
 */
function dfb_ratio_image_cache_path($url) {
	return dfb_ratio_image_cache_dir().sha1($url).'.img';
}

/**
 * 失败资源标记（避免每次导出都重试失效封面）。
 *
 * @param string $url
 * @return string
 */
function dfb_ratio_image_miss_path($url) {
	return dfb_ratio_image_cache_dir().sha1($url).'.miss';
}

/**
 * @param string $url
 * @return bool
 */
function dfb_ratio_image_is_miss($url) {
	$path = dfb_ratio_image_miss_path($url);
	return is_file($path) && filemtime($path) + 3600 > time();
}

/**
 * @param string $url
 * @return void
 */
function dfb_ratio_image_mark_miss($url) {
	@file_put_contents(dfb_ratio_image_miss_path($url), (string)time(), LOCK_EX);
}

/**
 * @param string $url
 * @return void
 */
function dfb_ratio_image_clear_miss($url) {
	@unlink(dfb_ratio_image_miss_path($url));
}

/**
 * 并发预取远程图片，避免 30 张封面逐张串行。
 *
 * @param array $urls
 * @return void
 */
function dfb_ratio_image_prefetch($urls) {
	$requests = array();
	$now = time();
	foreach($urls as $url) {
		$url = dfb_ratio_image_normalize_url($url);
		if($url === '') {
			continue;
		}
		$path = dfb_ratio_image_cache_path($url);
		if(dfb_ratio_image_is_miss($url)) {
			continue;
		}
		$fresh = is_file($path) && filesize($path) > 0 && filemtime($path) + 7 * 86400 > $now;
		if($fresh && stripos($url, '.webp') !== false) {
			$head = @file_get_contents($path, false, null, 0, 12);
			if(dfb_ratio_image_is_webp($head)) {
				$fresh = false;
			}
		}
		if($fresh) {
			continue;
		}
		$requests[sha1($url)] = array(
			'method'    => 'GET',
			'url'       => dfb_ratio_image_png_url($url),
			'headers'   => array(),
			'timeout'   => 15,
			'cache_url' => $url,
		);
	}
	if(!$requests) {
		return;
	}
	$responses = dfb_http_multi($requests);
	foreach($responses as $key => $resp) {
		$url = '';
		foreach($requests as $candidate => $request) {
			if($candidate === $key) {
				$url = isset($request['cache_url']) ? $request['cache_url'] : $request['url'];
				break;
			}
		}
		if($url === '') {
			continue;
		}
		if(!isset($resp['status']) || intval($resp['status']) !== 200 || !isset($resp['body']) || $resp['body'] === '') {
			dfb_ratio_image_mark_miss($url);
			continue;
		}
		if(dfb_ratio_image_is_webp($resp['body'])) {
			dfb_ratio_image_mark_miss($url);
			continue;
		}
		$check = @imagecreatefromstring($resp['body']);
		if(!$check) {
			dfb_ratio_image_mark_miss($url);
			continue;
		}
		imagedestroy($check);
		@file_put_contents(dfb_ratio_image_cache_path($url), $resp['body'], LOCK_EX);
		dfb_ratio_image_clear_miss($url);
	}
}

/**
 * 把 OSS 的 WebP 资源转成服务端 PNG 版本。
 *
 * 阿里云 OSS 原对象支持 x-oss-process；去掉 /256 尺寸后缀后请求 PNG，
 * 避免 PHP 7.4 bundled GD 解码 WebP 时崩溃。动图由 OSS 返回首帧。
 *
 * @param string $url
 * @return string
 */
function dfb_ratio_image_png_url($url) {
	$url = trim((string)$url);
	if($url === '' || stripos($url, '.webp') === false) {
		return $url;
	}
	$parts = explode('?', $url, 2);
	$path = $parts[0];
	$query = isset($parts[1]) ? $parts[1] : '';
	$host = parse_url($path, PHP_URL_HOST);
	if(!$host || stripos($host, 'ts-asset.oss-cn-shanghai.aliyuncs.com') === false) {
		return $url;
	}
	$path = preg_replace('~/256$~', '', $path);
	if(stripos($path, '.webp') === false) {
		return $url;
	}
	$process = 'x-oss-process=image/format,png';
	if($query === '') {
		return $path.'?'.$process;
	}
	return $path.'?'.$query.'&'.$process;
}

/**
 * 判断 WebP。PHP 7.4 的 bundled GD 在某些 WebP（尤其无损 WebP）上会直接
 * 崩溃，导致整个图片接口 500；这里跳过 WebP，用默认封面 / 空头像位兜底。
 *
 * @param string $raw
 * @return bool
 */
function dfb_ratio_image_is_webp($raw) {
	return is_string($raw)
		&& strlen($raw) >= 12
		&& substr($raw, 0, 4) === 'RIFF'
		&& substr($raw, 8, 4) === 'WEBP';
}

/**
 * 读取远程图片（优先本地缓存）。
 *
 * @param string $url
 * @return resource|null
 */
function dfb_ratio_image_load_url($url) {
	$url = dfb_ratio_image_normalize_url($url);
	if($url === '') {
		return null;
	}
	$path = dfb_ratio_image_cache_path($url);
	if(dfb_ratio_image_is_miss($url)) {
		return null;
	}
	$fresh = is_file($path) && filesize($path) > 0 && filemtime($path) + 7 * 86400 > time();
	if($fresh && stripos($url, '.webp') !== false) {
		$head = @file_get_contents($path, false, null, 0, 12);
		if(dfb_ratio_image_is_webp($head)) {
			$fresh = false;
		}
	}
	if(!$fresh) {
		$resp = dfb_http('GET', dfb_ratio_image_png_url($url), array(), null, 15);
		if(isset($resp['status']) && intval($resp['status']) === 200 && isset($resp['body']) && $resp['body'] !== '') {
			if(dfb_ratio_image_is_webp($resp['body'])) {
				dfb_ratio_image_mark_miss($url);
				return null;
			}
			@file_put_contents($path, $resp['body'], LOCK_EX);
			dfb_ratio_image_clear_miss($url);
		} else {
			dfb_ratio_image_mark_miss($url);
			return null;
		}
	}
	if(!is_file($path)) {
		return null;
	}
	$raw = @file_get_contents($path);
	if(!is_string($raw) || $raw === '') {
		return null;
	}
	if(dfb_ratio_image_is_webp($raw)) {
		dfb_ratio_image_mark_miss($url);
		return null;
	}
	$img = @imagecreatefromstring($raw);
	if(!$img) {
		@unlink($path);
		dfb_ratio_image_mark_miss($url);
		return null;
	}
	return $img;
}

/**
 * 普通图片合成。
 *
 * @param resource $dst
 * @param resource $src
 * @param int      $x
 * @param int      $y
 * @param int      $w
 * @param int      $h
 * @return void
 */
function dfb_ratio_image_copy($dst, $src, $x, $y, $w = 0, $h = 0) {
	if(!$src) {
		return;
	}
	$sw = imagesx($src);
	$sh = imagesy($src);
	if($w <= 0) {
		$w = $sw;
	}
	if($h <= 0) {
		$h = $sh;
	}
	imagealphablending($dst, true);
	imagesavealpha($dst, true);
	/* 背景图与目标尺寸一致时直接拷贝，避免对 1200×2700 整图做重采样。 */
	if(intval($w) === $sw && intval($h) === $sh) {
		imagecopy($dst, $src, intval($x), intval($y), 0, 0, $sw, $sh);
		return;
	}
	imagecopyresampled($dst, $src, intval($x), intval($y), 0, 0, intval($w), intval($h), $sw, $sh);
}

/**
 * 圆角图片（封面）。
 *
 * @param resource $dst
 * @param resource $src
 * @param int      $x
 * @param int      $y
 * @param int      $w
 * @param int      $h
 * @param int      $radius
 * @return void
 */
function dfb_ratio_image_round_copy($dst, $src, $x, $y, $w, $h, $radius = 18) {
	if(!$src) {
		return;
	}
	$tmp = imagecreatetruecolor($w, $h);
	imagealphablending($tmp, false);
	imagesavealpha($tmp, true);
	$transparent = imagecolorallocatealpha($tmp, 0, 0, 0, 127);
	imagefilledrectangle($tmp, 0, 0, $w, $h, $transparent);
	imagealphablending($tmp, true);
	imagecopyresampled($tmp, $src, 0, 0, 0, 0, $w, $h, imagesx($src), imagesy($src));

	$radius = max(1, intval($radius));
	if($radius * 2 > $w) {
		$radius = intval($w / 2);
	}
	if($radius * 2 > $h) {
		$radius = intval($h / 2);
	}
	imagealphablending($tmp, false);
	for($cy = 0; $cy < $radius; $cy++) {
		for($cx = 0; $cx < $radius; $cx++) {
			$dx = $radius - $cx - 0.5;
			$dy = $radius - $cy - 0.5;
			if(($dx * $dx + $dy * $dy) > $radius * $radius) {
				imagesetpixel($tmp, $cx, $cy, $transparent);
				imagesetpixel($tmp, $w - 1 - $cx, $cy, $transparent);
				imagesetpixel($tmp, $cx, $h - 1 - $cy, $transparent);
				imagesetpixel($tmp, $w - 1 - $cx, $h - 1 - $cy, $transparent);
			}
		}
	}
	imagealphablending($dst, true);
	imagesavealpha($dst, true);
	imagecopy($dst, $tmp, intval($x), intval($y), 0, 0, $w, $h);
	imagedestroy($tmp);
}

/**
 * 单行文字（top 坐标）。
 *
 * @param resource $im
 * @param string   $text
 * @param int      $size
 * @param int      $x
 * @param int      $y
 * @param string   $font
 * @param int      $color
 * @param int      $maxWidth
 * @return void
 */
function dfb_ratio_image_text($im, $text, $size, $x, $y, $font, $color, $maxWidth = 0) {
	$text = (string)$text;
	if($text === '') {
		return;
	}
	/* Pillow 的 size 是像素；GD imagettftext 的 size 是 point（默认 96 DPI），
	   乘以 72/96 才能得到同样的像素高度。 */
	$size = max(1, intval(round(intval($size) * 72 / 96)));
	if($size <= 0) {
		$size = 20;
	}
	if($maxWidth > 0) {
		$box = imagettfbbox($size, 0, $font, $text);
		$width = $box[2] - $box[0];
		if($width > $maxWidth) {
			$ellipsis = '...';
			while(mb_strlen($text, 'UTF-8') > 1) {
				$text = mb_substr($text, 0, -1, 'UTF-8');
				$box = imagettfbbox($size, 0, $font, $text.$ellipsis);
				if(($box[2] - $box[0]) <= $maxWidth) {
					break;
				}
			}
			$text .= $ellipsis;
		}
	}
	$ref = imagettfbbox($size, 0, $font, '国');
	$top = isset($ref[7]) ? abs($ref[7]) : 0;
	imagettftext($im, $size, 0, intval($x), intval($y + $top), $color, $font, $text);
}

/**
 * 多行文字（top 坐标）。
 *
 * @param resource $im
 * @param string   $text
 * @param int      $size
 * @param int      $x
 * @param int      $y
 * @param string   $font
 * @param int      $color
 * @param int      $lineHeight
 * @param int      $maxWidth
 * @return void
 */
function dfb_ratio_image_lines($im, $text, $size, $x, $y, $font, $color, $lineHeight = 0, $maxWidth = 0) {
	$lines = explode("\n", str_replace("\r\n", "\n", (string)$text));
	if($lineHeight <= 0) {
		$lineHeight = intval(round($size * 1.08));
	}
	foreach($lines as $line) {
		dfb_ratio_image_text($im, $line, $size, $x, $y, $font, $color, $maxWidth);
		$y += $lineHeight;
	}
}

/**
 * 按宽度自动换行（用于长评语）。
 *
 * @param string $text
 * @param int    $size
 * @param string $font
 * @param int    $maxWidth
 * @return array
 */
function dfb_ratio_image_wrap($text, $size, $font, $maxWidth) {
	$text = str_replace(array("\r\n", "\r"), "\n", (string)$text);
	$paragraphs = explode("\n", $text);
	$lines = array();
	foreach($paragraphs as $paragraph) {
		$line = '';
		$length = mb_strlen($paragraph, 'UTF-8');
		for($i = 0; $i < $length; $i++) {
			$char = mb_substr($paragraph, $i, 1, 'UTF-8');
			$next = $line.$char;
			$box = imagettfbbox($size, 0, $font, $next);
			if($line !== '' && (($box[2] - $box[0]) > $maxWidth)) {
				$lines[] = $line;
				$line = $char;
			} else {
				$line = $next;
			}
		}
		$lines[] = $line;
	}
	return $lines;
}

/**
 * 评级图。
 *
 * @param string $grade
 * @return string
 */
function dfb_ratio_image_grade_asset($grade) {
	$map = array(
		'AP'  => 'AP.png',
		'SSS' => 'SSS.png',
		'SS'  => 'SS.png',
		'S'   => 'S.png',
		'A'   => 'A.png',
		'B'   => 'B.png',
		'C'   => 'C.png',
		'D'   => 'D.png',
	);
	return isset($map[$grade]) ? $map[$grade] : 'D.png';
}

/**
 * 卡片框。
 *
 * @param int $difficulty
 * @return string
 */
function dfb_ratio_image_card_asset($difficulty) {
	$difficulty = intval($difficulty);
	if($difficulty === 1) {
		return 'Card2.png';
	}
	if($difficulty === 2) {
		return 'Card3.png';
	}
	return 'Card1.png';
}

/**
 * 评级横向偏移（来自 Python 版）。
 *
 * @param string $grade
 * @return int
 */
function dfb_ratio_image_fix($grade) {
	if($grade === 'SSS' || $grade === 'C') {
		return 0;
	}
	if($grade === 'SS') {
		return -17;
	}
	if($grade === 'S') {
		return -6;
	}
	return 5;
}

/**
 * 段位徽章地址。
 *
 * @param int $rank
 * @return string
 */
function dfb_ratio_image_badge_url($rank) {
	$base = 'https://dancewebdemo.shenghuayule.com/dance/static/userCenter_img/';
	$map = array(
		0 => 'quanminxingBadge0.png',
		1 => 'quanminxingBadge1.png',
		2 => 'quanminxingBadge2.png',
		3 => 'quanminxingBadge3.png',
		4 => 'quanminxingBadge5.png',
		5 => 'quanminxingBadge6.png',
		6 => 'quanminxingBadge7.png',
	);
	$rank = intval($rank);
	return isset($map[$rank]) ? $base.$map[$rank] : '';
}

/**
 * 绘制一组歌曲卡片。
 *
 * @param resource $im
 * @param array    $songs
 * @param int      $lvRatio
 * @param int      $dyOffset
 * @param int      $maxRows
 * @return void
 */
function dfb_ratio_image_grid($im, $songs, $lvRatio, $dyOffset = 0, $maxRows = 5) {
	$titleFont = dfb_ratio_image_font('title');
	$scoreFont = dfb_ratio_image_font('score');
	$black = dfb_ratio_image_color($im, '#000000');
	$white = dfb_ratio_image_color($im, '#ffffff');
	$defaultCover = dfb_ratio_image_asset('cover-default.png');
	$count = count($songs);
	$index = 0;
	for($row = 0; $row < $maxRows; $row++) {
		for($col = 0; $col < 3; $col++) {
			if($index >= $count) {
				return;
			}
			$song = $songs[$index];
			$index++;
			$dx = $col * 395;
			$dy = $row * 180 + intval($dyOffset);

			$cover = dfb_ratio_image_load_url(isset($song['cover']) ? $song['cover'] : '');
			if(!$cover) {
				$cover = $defaultCover;
			}
			dfb_ratio_image_round_copy($im, $cover, 16 + $dx, 621 + $dy, 130, 158, 18);

			$card = dfb_ratio_image_asset(dfb_ratio_image_card_asset(isset($song['difficulty']) ? $song['difficulty'] : 0));
			dfb_ratio_image_copy($im, $card, 15 + $dx, 620 + $dy);

			$grade = isset($song['grade']) ? (string)$song['grade'] : 'D';
			$gradeImg = dfb_ratio_image_asset(dfb_ratio_image_grade_asset($grade));
			dfb_ratio_image_copy($im, $gradeImg, 285 + dfb_ratio_image_fix($grade) + $dx, 715 + $dy);

			dfb_ratio_image_text($im, isset($song['name']) ? $song['name'] : '', 32, 160 + $dx, 624 + $dy, $titleFont, $black, 220);
			dfb_ratio_image_text($im, isset($song['score']) ? $song['score'] : '', 52, 160 + $dx, 662 + $dy, $scoreFont, $black);

			$acc = isset($song['acc']) ? floatval($song['acc']) : 0;
			$combo = isset($song['combo']) ? intval($song['combo']) : 0;
			$miss = isset($song['miss']) ? intval($song['miss']) : 0;
			$comboText = $combo."\n".$miss."\n".number_format($acc, 2).'%';
			dfb_ratio_image_lines($im, $comboText, 15, 230 + $dx, 726 + $dy, $scoreFont, $black, 17);

			$ratioInt = isset($song['ratioInt']) ? intval($song['ratioInt']) : 0;
			$diff = isset($song['diff']) ? intval($song['diff']) : 0;
			$diffText = $diff > 0 ? '+'.$diff : (string)$diff;
			dfb_ratio_image_text($im, '> '.$ratioInt.' ('.$diffText.')', 15, 163 + $dx, 708 + $dy, $scoreFont, $black);

			dfb_ratio_image_text($im, isset($song['level']) ? $song['level'] : '', 23, 17 + $dx, 753 + $dy, $scoreFont, $white);
		}
	}
}

/**
 * Python 版 get_ratio_comment 的逐行移植（含引号与换行）。
 *
 * @param int $ratio
 * @return string
 */
function dfb_ratio_image_comment($ratio) {
	$ratio = intval($ratio);
	$comments = array(
		"  \"你已初步了解这款游戏了\n  继续练习吧~\"",
		"  \"你已经适应10级的歌曲了\n  继续练习吧~\"",
		"  \"你正在对线14级的歌了\n  继续加油~\"",
		"  \"你即将迈入大佬的行列\n  加油加油！\"",
		"  \"恭喜突破1800守门员\n  正式成为大佬啦！\"",
		"  \"你即将成神\n  请继续和1819对线\"",
		"  \"你已步入神的行列\n  快快杀19吧~\"",
		"  \"你已经成为外星人\n  正在薄纱一切歌曲\"",
	);
	if($ratio <= 1000) {
		return $comments[0];
	}
	if($ratio < 1300) {
		return $comments[1];
	}
	if($ratio < 1500) {
		return $comments[2];
	}
	if($ratio < 1800) {
		return $comments[3];
	}
	if($ratio < 1900) {
		return $comments[4];
	}
	if($ratio < 2000) {
		return $comments[5];
	}
	if($ratio < 2080) {
		return $comments[6];
	}
	if($ratio <= 2100) {
		return $comments[7];
	}
	return '';
}

/**
 * 渲染战力分析图片。
 *
 * @param string $account
 * @param string $mode b15|b30|ap30
 * @return string PNG bytes
 * @throws Exception
 */
function dfb_render_ratio_image($account, $mode) {
	$mode = in_array($mode, array('b15', 'b30', 'ap30'), true) ? $mode : 'b15';
	$token = dfb_require_token($account);
	$data = dfb_data_ratio($account, $token, $mode);
	$profile = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
	$summary = isset($data['summary']) && is_array($data['summary']) ? $data['summary'] : array();
	$songs = isset($data['songs']) && is_array($data['songs']) ? $data['songs'] : array();
	$songsSub = isset($data['songsSub']) && is_array($data['songsSub']) ? $data['songsSub'] : array();

	$background = $mode === 'b15' ? 'Background1.png' : ($mode === 'b30' ? 'Background2.png' : 'Background3.png');
	$bg = dfb_ratio_image_asset($background);
	if(!$bg) {
		throw new Exception('战力模板素材缺失：'.$background);
	}

	$urls = array(
		isset($profile['headimgUrl']) ? $profile['headimgUrl'] : '',
		isset($profile['headimgBoxPath']) ? $profile['headimgBoxPath'] : '',
		isset($profile['titleUrl']) ? $profile['titleUrl'] : '',
	);
	$ladder = isset($data['ladder']) && is_array($data['ladder']) ? $data['ladder'] : null;
	$rank = $ladder && isset($ladder['levelGrade']) ? intval($ladder['levelGrade']) : -1;
	$badgeUrl = dfb_ratio_image_badge_url($rank);
	if($badgeUrl !== '') {
		$urls[] = $badgeUrl;
	}
	foreach($songs as $song) {
		if(isset($song['cover'])) {
			$urls[] = $song['cover'];
		}
	}
	foreach($songsSub as $song) {
		if(isset($song['cover'])) {
			$urls[] = $song['cover'];
		}
	}
	dfb_ratio_image_prefetch(array_values(array_unique($urls)));

	$im = imagecreatetruecolor(1200, 2700);
	imagealphablending($im, false);
	$white = imagecolorallocate($im, 255, 255, 255);
	imagefilledrectangle($im, 0, 0, 1200, 2700, $white);
	imagealphablending($im, true);
	dfb_ratio_image_copy($im, $bg, 0, 0);

	/* 头像 / 头像框 / 称号 / 段位徽章 */
	$avatar = dfb_ratio_image_load_url(isset($profile['headimgUrl']) ? $profile['headimgUrl'] : '');
	dfb_ratio_image_copy($im, $avatar, 34, 180, 174, 174);
	$box = dfb_ratio_image_load_url(isset($profile['headimgBoxPath']) ? $profile['headimgBoxPath'] : '');
	dfb_ratio_image_copy($im, $box, -24, 122, 290, 290);
	$title = dfb_ratio_image_load_url(isset($profile['titleUrl']) ? $profile['titleUrl'] : '');
	dfb_ratio_image_copy($im, $title, 13, 373, 230, 79);
	$badge = dfb_ratio_image_load_url($badgeUrl);
	dfb_ratio_image_copy($im, $badge, -60, 122, 183, 120);

	$black = dfb_ratio_image_color($im, '#000000');
	$infoFont = dfb_ratio_image_font('info');
	$userName = isset($profile['userName']) && $profile['userName'] !== '' ? $profile['userName'] : '舞立方用户';
	$teamName = isset($profile['teamName']) && $profile['teamName'] !== '' ? $profile['teamName'] : '无';
	$rankNation = isset($profile['rankNation']) ? $profile['rankNation'] : 0;
	$cityName = isset($profile['cityName']) ? $profile['cityName'] : '';
	$lvRatio = isset($summary['lvRatio']) ? intval($summary['lvRatio']) : 0;
	$userId = isset($token['userId']) ? intval($token['userId']) : 0;

	dfb_ratio_image_text($im, 'ID：'.$userId, 30, 245, 200, $infoFont, $black);
	dfb_ratio_image_text($im, '地区：'.$cityName, 30, 245, 230, $infoFont, $black);
	$userText = $userName."\n\n战队：".$teamName."\n排名：".$rankNation."\n战力：".$lvRatio;
	dfb_ratio_image_lines($im, $userText, 45, 245, 160, $infoFont, $black, 55, 230);

	/* Python 版右侧统计；逐行坐标与行距对齐 */
	$lastRatio = isset($summary['lastRatio']) ? intval($summary['lastRatio']) : 0;
	$avgMain = isset($summary['avgMain']) ? floatval($summary['avgMain']) : 0;
	$extra = '上次战力：'.$lastRatio;
	if($mode === 'b15') {
		$avgSub = isset($summary['avgSub']) ? floatval($summary['avgSub']) : 0;
		$avgAll = isset($summary['avgAll']) ? floatval($summary['avgAll']) : 0;
		$extra .= "\nB-15 战力：".number_format($avgMain, 4, '.', '');
		$extra .= "\nR-15 战力：".number_format($avgSub, 4, '.', '');
		$extra .= "\n平均战力：".number_format($avgAll, 5, '.', '');
	} elseif($mode === 'b30') {
		$extra .= "\nB-30 战力：".number_format($avgMain, 4, '.', '');
	} else {
		$extra .= "\nAP-30 战力：".number_format($avgMain, 4, '.', '');
	}
	$comment = dfb_ratio_image_comment($lvRatio);
	if($comment !== '') {
		$extra .= "\n".$comment;
	}
	dfb_ratio_image_lines($im, $extra, 45, 720, 160, $infoFont, $black, 49, 0);

	/* 歌曲网格 */
	if($mode === 'b15') {
		dfb_ratio_image_grid($im, $songs, $lvRatio, 0, 5);
		dfb_ratio_image_grid($im, $songsSub, $lvRatio, 1065, 5);
	} else {
		dfb_ratio_image_grid($im, $songs, $lvRatio, 0, 10);
	}

	ob_start();
	imagepng($im, null, 3);
	$png = ob_get_clean();
	imagedestroy($im);
	return is_string($png) ? $png : '';
}

/**
 * 动作占位：api.php 对该 action 走二进制分支。
 *
 * @param array  $input
 * @param string $account
 * @return array
 */
function dfb_act_ratio_image($input, $account) {
	return array('detail' => 'ratio.image 由 api.php 的二进制分支输出');
}
