<?php

/**
 * 舞小枫 · 更新日志
 *
 * 内容存放在 data/changelog.json，后台/维护人员可直接编辑。
 */

if(!defined('DFB_STANDALONE')) {
	exit('Access Denied');
}

function dfb_changelog_path() {
	return dfb_data_dir().'changelog.json';
}

function dfb_changelog_read() {
	$raw = @file_get_contents(dfb_changelog_path());
	if(!is_string($raw) || trim($raw) === '') {
		return array();
	}
	$data = json_decode($raw, true);
	if(!is_array($data)) {
		return array();
	}
	if(isset($data['entries']) && is_array($data['entries'])) {
		$data = $data['entries'];
	}
	$items = array();
	foreach($data as $entry) {
		if(!is_array($entry)) {
			continue;
		}
		$version = isset($entry['version']) ? dfb_utf8_clean(trim((string)$entry['version'])) : '';
		$date = isset($entry['date']) ? dfb_utf8_clean(trim((string)$entry['date'])) : '';
		$title = isset($entry['title']) ? dfb_utf8_clean(trim((string)$entry['title'])) : '';
		$list = isset($entry['items']) && is_array($entry['items']) ? $entry['items'] : array();
		$lines = array();
		foreach($list as $line) {
			$line = dfb_utf8_clean(trim((string)$line));
			if($line !== '') {
				$lines[] = $line;
			}
		}
		if($version === '' && $title === '' && !$lines) {
			continue;
		}
		$items[] = array(
			'version' => $version,
			'date'    => $date,
			'title'   => $title,
			'items'   => $lines,
		);
		if(count($items) >= 100) {
			break;
		}
	}
	return $items;
}
