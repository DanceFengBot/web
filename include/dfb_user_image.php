<?php

/**
 * 个人信息图（mydc），逐行对应 Python 版 image/user_info.py。
 *
 * 605×900 Background2；头像 / 头像框 / 称号 / 徽章；
 * 左侧用户资料，右侧积分、全连率、排名、游玩次数、天梯分。
 *
 * @package dancefengbot
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

require_once __DIR__.'/dfb_ratio_image.php';

/**
 * 渲染个人信息图。
 *
 * @param string $account
 * @param int    $targetId 0 表示自己
 * @return string PNG bytes
 * @throws Exception
 */
function dfb_render_user_image($account, $targetId) {
	$token = dfb_require_token($account);
	$data = dfb_data_user_info($account, $token, intval($targetId));
	$profile = isset($data['profile']) && is_array($data['profile']) ? $data['profile'] : array();
	$extra = isset($data['extra']) && is_array($data['extra']) ? $data['extra'] : null;
	$isSelf = !empty($data['isSelf']);
	$status = isset($profile['status']) ? $profile['status'] : 'ok';
	if($status === 'nonexistent') {
		throw new DfbError('这个账号不存在哦，检查一下舞立方 ID 吧。', 404);
	}
	if(empty($profile['headimgUrl'])) {
		throw new DfbError('该账号缺少头像地址，无法生成个人信息图。', 400);
	}

	$path = DFB_ROOT_DIR.'assets/user/Background2.png';
	if(!is_file($path)) {
		throw new Exception('个人信息模板素材缺失：assets/user/Background2.png');
	}
	$bg = @imagecreatefrompng($path);
	if(!$bg) {
		throw new Exception('个人信息背景读取失败。');
	}

	$rank = $isSelf && $extra && isset($extra['ladderGrade']) && $extra['ladderGrade'] !== null
		? intval($extra['ladderGrade']) : -1;
	$badgeUrl = dfb_ratio_image_badge_url($rank);

	$urls = array(
		isset($profile['headimgUrl']) ? $profile['headimgUrl'] : '',
		isset($profile['headimgBoxPath']) ? $profile['headimgBoxPath'] : '',
		isset($profile['titleUrl']) ? $profile['titleUrl'] : '',
	);
	if($badgeUrl !== '') {
		$urls[] = $badgeUrl;
	}
	dfb_ratio_image_prefetch(array_values(array_unique($urls)));

	$im = imagecreatetruecolor(605, 900);
	imagealphablending($im, false);
	$white = imagecolorallocate($im, 255, 255, 255);
	imagefilledrectangle($im, 0, 0, 605, 900, $white);
	imagealphablending($im, true);
	dfb_ratio_image_copy($im, $bg, 0, 0);

	/* 头像 / 头像框 / 称号 / 段位徽章 */
	$avatar = dfb_ratio_image_load_url(isset($profile['headimgUrl']) ? $profile['headimgUrl'] : '');
	if(!$avatar) {
		imagedestroy($im);
		throw new DfbError('该账号头像下载失败，无法生成个人信息图。', 502);
	}
	dfb_ratio_image_copy($im, $avatar, 120, 150, 137, 137);
	$box = dfb_ratio_image_load_url(isset($profile['headimgBoxPath']) ? $profile['headimgBoxPath'] : '');
	dfb_ratio_image_copy($im, $box, 74, 104, 230, 230);
	$title = dfb_ratio_image_load_url(isset($profile['titleUrl']) ? $profile['titleUrl'] : '');
	dfb_ratio_image_copy($im, $title, 95, 300, 190, 68);
	$badge = dfb_ratio_image_load_url($badgeUrl);
	dfb_ratio_image_copy($im, $badge, 25, 95, 183, 120);

	$black = dfb_ratio_image_color($im, '#000000');
	$font = dfb_ratio_image_font('info');

	$userName = isset($profile['userName']) && $profile['userName'] !== '' ? $profile['userName'] : '舞立方用户';
	$teamName = isset($profile['teamName']) && $profile['teamName'] !== '' ? $profile['teamName'] : '无';
	$cityName = isset($profile['cityName']) ? (string)$profile['cityName'] : '';
	$lvRatio = isset($profile['lvRatio']) ? intval($profile['lvRatio']) : 0;
	$userId = isset($profile['userId']) ? intval($profile['userId']) : 0;
	$musicScore = isset($profile['musicScore']) ? intval($profile['musicScore']) : 0;
	$comboPercent = isset($profile['comboPercent']) ? floatval($profile['comboPercent']) : 0;
	$rankNation = isset($profile['rankNation']) ? intval($profile['rankNation']) : 0;

	if($status === 'private') {
		$region = $cityName !== '' ? $cityName : '无';
		$main = $userName."\n\n地区：".$region."\n战力：".$lvRatio;
		dfb_ratio_image_lines($im, $main, 36, 293, 137, $font, $black, 44, 235);
		dfb_ratio_image_text($im, '该账号已设置隐私', 36, 106, 472, $font, $black);
		dfb_ratio_image_text($im, 'ID：'.$userId, 20, 293, 170, $font, $black);
	} else {
		$gold = $isSelf && $extra && isset($extra['gold']) ? $extra['gold'] : '不可见';
		$playedTimes = $isSelf && $extra && isset($extra['playedTimes']) ? $extra['playedTimes'] : '不可见';
		$ladderScore = $isSelf && $extra && isset($extra['ladderScore']) && $extra['ladderScore'] !== null
			? $extra['ladderScore'] : '不可见';
		$region = $isSelf ? ($cityName !== '' ? $cityName : '无') : '无';

		$main = $userName."\n\n战队：".$teamName."\n战力：".$lvRatio."\n金币：".$gold;
		dfb_ratio_image_lines($im, $main, 36, 293, 137, $font, $black, 44, 235);

		$stats = '积分：'.$musicScore."\n"
			.'全连率：'.number_format($comboPercent, 2, '.', '').'%'."\n"
			.'全国排名：'.$rankNation."\n"
			.'游玩次数：'.$playedTimes."\n"
			.'天梯分：'.$ladderScore;
		dfb_ratio_image_lines($im, $stats, 36, 106, 472, $font, $black, 44, 235);

		$idText = 'ID：'.$userId."\n".'地区：'.$region;
		dfb_ratio_image_lines($im, $idText, 20, 293, 175, $font, $black, 25, 235);
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
function dfb_act_user_image($input, $account) {
	return array('detail' => 'user.image 由 api.php 的二进制分支输出');
}
