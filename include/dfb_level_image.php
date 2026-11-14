<?php

/**
 * 舞小枫 · 分数列表图片（PHP GD）
 *
 * 逐行对应 Python 版 image/level_scores.py：
 *   背景 Background4.png
 *   同一套头像 / 用户信息头部
 *   同一套 draw_music_grid（10 行 × 3 列，每页 30 首）
 *   右侧 720,160 的等级 / 战力 / 页码统计
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

require_once __DIR__.'/dfb_ratio_image.php';

/**
 * 渲染分数列表图片。
 *
 * @param string $account
 * @param int    $level
 * @param int    $page
 * @return string PNG bytes
 * @throws Exception
 */
function dfb_render_level_image($account, $level, $page) {
	$level = intval($level);
	if($level < 1) {
		$level = 1;
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

	$token = dfb_require_token($account);
	$data = dfb_data_level_scores($account, $token, $level, $page);
	$profile = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
	$songs = isset($data['songs']) && is_array($data['songs']) ? $data['songs'] : array();
	$totalPages = isset($data['totalPages']) ? intval($data['totalPages']) : 0;
	$avg = isset($data['avg']) ? floatval($data['avg']) : 0;
	$lvRatio = isset($profile['lvRatio']) ? intval($profile['lvRatio']) : 0;

	$bg = dfb_ratio_image_asset('Background4.png');
	if(!$bg) {
		throw new Exception('分数列表模板素材缺失：Background4.png');
	}

	$ladder = dfb_fetch_ladder($token);
	$rank = $ladder && isset($ladder['levelGrade']) ? intval($ladder['levelGrade']) : -1;
	$badgeUrl = dfb_ratio_image_badge_url($rank);

	$urls = array(
		isset($profile['headimgUrl']) ? $profile['headimgUrl'] : '',
		isset($profile['headimgBoxPath']) ? $profile['headimgBoxPath'] : '',
		isset($profile['titleUrl']) ? $profile['titleUrl'] : '',
	);
	if($badgeUrl !== '') {
		$urls[] = $badgeUrl;
	}
	foreach($songs as $song) {
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

	/* 头像 / 头像框 / 称号 / 段位徽章，与 Python draw_header 一致 */
	$avatar = dfb_ratio_image_load_url(isset($profile['headimgUrl']) ? $profile['headimgUrl'] : '');
	dfb_ratio_image_copy($im, $avatar, 34, 180, 174, 174);
	$box = dfb_ratio_image_load_url(isset($profile['headimgBoxPath']) ? $profile['headimgBoxPath'] : '');
	dfb_ratio_image_copy($im, $box, -24, 122, 290, 290);
	$title = dfb_ratio_image_load_url(isset($profile['titleUrl']) ? $profile['titleUrl'] : '');
	dfb_ratio_image_copy($im, $title, 13, 373, 230, 79);
	$badge = dfb_ratio_image_load_url($badgeUrl);
	dfb_ratio_image_copy($im, $badge, -60, 122, 183, 120);

	/* 左侧用户文本，与 Python draw_user_info_text 一致 */
	$black = dfb_ratio_image_color($im, '#000000');
	$infoFont = dfb_ratio_image_font('info');
	$userName = isset($profile['userName']) && $profile['userName'] !== '' ? $profile['userName'] : '舞立方用户';
	$teamName = isset($profile['teamName']) && $profile['teamName'] !== '' ? $profile['teamName'] : '无';
	$rankNation = isset($profile['rankNation']) ? $profile['rankNation'] : 0;
	$cityName = isset($profile['cityName']) ? $profile['cityName'] : '';
	$userId = isset($token['userId']) ? intval($token['userId']) : 0;

	dfb_ratio_image_text($im, 'ID：'.$userId, 30, 245, 200, $infoFont, $black);
	dfb_ratio_image_text($im, '地区：'.$cityName, 30, 245, 230, $infoFont, $black);
	$userText = $userName."\n\n战队：".$teamName."\n排名：".$rankNation."\n战力：".$lvRatio;
	dfb_ratio_image_lines($im, $userText, 45, 245, 160, $infoFont, $black, 55, 230);

	/* 歌曲网格：每页 30 首，10 行 × 3 列 */
	dfb_ratio_image_grid($im, $songs, $lvRatio, 0, 10);

	/* 右侧统计，与 Python LevelScoresImage.generate 一致 */
	$extra = $level."分数列表\n"
		.'战力：'.number_format($avg, 4, '.', '')."\n"
		.'第'.$page.'页，共'.$totalPages."页\n";
	dfb_ratio_image_lines($im, $extra, 45, 720, 160, $infoFont, $black, 49, 0);

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
function dfb_act_level_image($input, $account) {
	return array('detail' => 'level.image 由 api.php 的二进制分支输出');
}
