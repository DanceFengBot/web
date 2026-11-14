<?php

/**
 * 舞小枫 · 场地排除申请 / 审核
 *
 * 普通用户只能提交 pending；管理员可直接 approved，或审核 pending。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

function dfb_machine_exclusions_path() {
	return dfb_data_dir().'machine_exclusions.php';
}

function dfb_machine_exclusion_normalize($data) {
	if(!is_array($data)) {
		return array();
	}
	$out = array();
	foreach($data as $item) {
		if(!is_array($item) || empty($item['terminalId'])) {
			continue;
		}
		$terminalId = trim((string)$item['terminalId']);
		if($terminalId === '' || strlen($terminalId) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $terminalId)) {
			continue;
		}
		$status = isset($item['status']) ? (string)$item['status'] : 'pending';
		if($status !== 'pending' && $status !== 'approved' && $status !== 'rejected') {
			$status = 'pending';
		}
		$out[$terminalId] = array(
			'terminalId' => $terminalId,
			'placeName'  => dfb_utf8_clean(isset($item['placeName']) ? (string)$item['placeName'] : ''),
			'address'    => dfb_utf8_clean(isset($item['address']) ? (string)$item['address'] : ''),
			'reason'     => dfb_utf8_clean(isset($item['reason']) ? (string)$item['reason'] : ''),
			'status'     => $status,
			'userId'     => isset($item['userId']) ? intval($item['userId']) : 0,
			'userName'   => dfb_utf8_clean(isset($item['userName']) ? (string)$item['userName'] : ''),
			'createdAt'  => isset($item['createdAt']) ? intval($item['createdAt']) : 0,
			'reviewedBy' => dfb_utf8_clean(isset($item['reviewedBy']) ? (string)$item['reviewedBy'] : ''),
			'reviewedAt' => isset($item['reviewedAt']) ? intval($item['reviewedAt']) : 0,
		);
	}
	return $out;
}

function dfb_machine_exclusions_read() {
	$raw = @file_get_contents(dfb_machine_exclusions_path());
	if(!is_string($raw) || $raw === '') {
		return array();
	}
	$pos = strpos($raw, '{');
	if($pos === false) {
		return array();
	}
	$data = json_decode(substr($raw, $pos), true);
	return dfb_machine_exclusion_normalize(isset($data['entries']) ? $data['entries'] : $data);
}

function dfb_machine_exclusions_write($entries) {
	$dir = dfb_data_dir();
	if(!dfb_dir_writable($dir)) {
		return false;
	}
	$entries = dfb_machine_exclusion_normalize($entries);
	$content = "<?php exit; ?>\n".dfb_json_pretty(array('entries' => $entries));
	$path = dfb_machine_exclusions_path();
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

function dfb_machine_exclusions_pending() {
	$out = array();
	foreach(dfb_machine_exclusions_read() as $item) {
		if($item['status'] === 'pending') {
			$out[] = $item;
		}
	}
	usort($out, function ($a, $b) {
		return $a['createdAt'] > $b['createdAt'] ? -1 : 1;
	});
	return $out;
}

/**
 * 普通用户提交排除申请。
 *
 * @param array  $token
 * @param string $terminalId
 * @param string $placeName
 * @param string $address
 * @param string $reason
 * @return array
 * @throws DfbError
 */
function dfb_machine_exclusion_request($token, $terminalId, $placeName, $address, $reason) {
	$terminalId = trim((string)$terminalId);
	if($terminalId === '' || strlen($terminalId) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $terminalId)) {
		throw new DfbError('场地 ID 不正确。', 400);
	}
	$reason = trim(str_replace("\0", '', (string)$reason));
	if($reason === '') {
		throw new DfbError('请填写排除理由。', 400);
	}
	$len = function_exists('mb_strlen') ? mb_strlen($reason, 'UTF-8') : strlen($reason);
	if($len > 300) {
		throw new DfbError('排除理由最多 300 个字符。', 400);
	}
	$entries = dfb_machine_exclusions_read();
	$userId = isset($token['userId']) ? intval($token['userId']) : 0;
	if(isset($entries[$terminalId])) {
		$existing = $entries[$terminalId];
		if($existing['status'] === 'approved') {
			throw new DfbError('该场地已经被排除。', 409);
		}
		if($existing['status'] === 'pending') {
			if(intval($existing['userId']) === $userId) {
				throw new DfbError('你已提交过该场地的排除申请，请等待审核。', 409);
			}
			throw new DfbError('该场地已有待审核的排除申请。', 409);
		}
	}
	$userName = isset($token['userName']) ? trim((string)$token['userName']) : '';
	if($userName === '' && $userId > 0) {
		$userName = '舞立方用户 '.$userId;
	}
	$entries[$terminalId] = array(
		'terminalId' => $terminalId,
		'placeName'  => dfb_utf8_clean(trim((string)$placeName)),
		'address'    => dfb_utf8_clean(trim((string)$address)),
		'reason'     => dfb_utf8_clean($reason),
		'status'     => 'pending',
		'userId'     => $userId,
		'userName'   => dfb_utf8_clean($userName),
		'createdAt'  => time(),
		'reviewedBy' => '',
		'reviewedAt' => 0,
	);
	if(!dfb_machine_exclusions_write($entries)) {
		throw new DfbError('排除申请保存失败。', 500);
	}
	return $entries[$terminalId];
}

/**
 * 管理员审核排除申请。
 *
 * @param string $terminalId
 * @param string $action approve|reject
 * @param string $adminName
 * @return array
 * @throws DfbError
 */
function dfb_machine_exclusion_review($terminalId, $action, $adminName) {
	$terminalId = trim((string)$terminalId);
	$action = $action === 'approve' ? 'approve' : ($action === 'reject' ? 'reject' : '');
	if($terminalId === '' || $action === '') {
		throw new DfbError('审核参数不正确。', 400);
	}
	$entries = dfb_machine_exclusions_read();
	if(!isset($entries[$terminalId]) || $entries[$terminalId]['status'] !== 'pending') {
		throw new DfbError('该场地没有待审核的排除申请。', 404);
	}
	$entries[$terminalId]['status'] = $action === 'approve' ? 'approved' : 'rejected';
	$entries[$terminalId]['reviewedBy'] = dfb_utf8_clean((string)$adminName);
	$entries[$terminalId]['reviewedAt'] = time();
	if(!dfb_machine_exclusions_write($entries)) {
		throw new DfbError('排除审核保存失败。', 500);
	}
	return $entries[$terminalId];
}

/**
 * 管理员直接设置 / 取消排除。
 *
 * @param string $terminalId
 * @param bool   $excluded
 * @param string $reason
 * @param string $placeName
 * @param string $address
 * @param string $adminName
 * @return array
 * @throws DfbError
 */
function dfb_machine_exclusion_set($terminalId, $excluded, $reason, $placeName, $address, $adminName) {
	$terminalId = trim((string)$terminalId);
	if($terminalId === '' || strlen($terminalId) > 80 || !preg_match('/^[A-Za-z0-9_-]+$/', $terminalId)) {
		throw new DfbError('场地 ID 不正确。', 400);
	}
	$entries = dfb_machine_exclusions_read();
	if(!$excluded) {
		if(isset($entries[$terminalId])) {
			unset($entries[$terminalId]);
			if(!dfb_machine_exclusions_write($entries)) {
				throw new DfbError('取消排除失败。', 500);
			}
		}
		return array('terminalId' => $terminalId, 'status' => '');
	}
	$reason = trim((string)$reason);
	if($reason === '') {
		$reason = '管理员直接排除';
	}
	$entries[$terminalId] = array(
		'terminalId' => $terminalId,
		'placeName'  => dfb_utf8_clean(trim((string)$placeName)),
		'address'    => dfb_utf8_clean(trim((string)$address)),
		'reason'     => dfb_utf8_clean($reason),
		'status'     => 'approved',
		'userId'     => 0,
		'userName'   => dfb_utf8_clean((string)$adminName),
		'createdAt'  => time(),
		'reviewedBy' => dfb_utf8_clean((string)$adminName),
		'reviewedAt' => time(),
	);
	if(!dfb_machine_exclusions_write($entries)) {
		throw new DfbError('直接排除失败。', 500);
	}
	return $entries[$terminalId];
}
