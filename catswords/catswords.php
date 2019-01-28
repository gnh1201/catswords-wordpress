<?php
/*
Plugin Name: Catswords CLI
Plugin URI: https://exts.kr/wiki/wordpress
Description: Catswords CLI communication driver for wordpress
Version: 0.2
Author: Catswords Research
Author URI: https://github.com/gnh1201
License: GPL3
*/

function cwds_get_config($key) {
	$configs = array(
		"interval" => 180, // seconds,
		"email" => "git@catswords.com",
		"password" => "dAyk5bCuLdJQ9ip3",
		"host" => "2s.re.kr",
		"network_id" => "wordpress",
		"wp_user_id" => 1,
		"dir_path" => plugin_dir_path(__FILE__),
		"timezone" => "Asia/Seoul",
		"timefile" => "time.txt",
		"cmd1" => "%sbin/catswords-cli",
		"cmd2" => "%s --email %s --password %s --host %s --action refresh",
		"cmd3" => "%s --action recv --network-id %s",
	);

	return (array_key_exists($key, $configs) ? $configs[$key]: false);
}

function cwds_get_current_datetime() {
	return date("Y-m-d H:i:s");
}

function cwds_get_bin_path() {
	return sprintf(cwds_get_config("cmd1"), cwds_get_config("dir_path"));
}

function cwds_exec($cmd) {
	return shell_exec($cmd);
}

function cwds_authenticate($email, $password, $host) {
	$cmd = sprintf(cwds_get_config("cmd2"), cwds_get_bin_path(), $email, $password, $host);
	return cwds_exec($cmd);
}

function cwds_get_messages($network_id) {
	$cmd = sprintf(cwds_get_config("cmd3"), cwds_get_bin_path(), $network_id);
	return cwds_exec($cmd);
}

function cwds_wp_insert_post($data, $created_on="") {
	$result = false;

	// set post context
	$post_context = array(
		"post_title"    => "",
		"post_content"  => "",
		"post_status"   => "draft",
		"post_author"   => cwds_get_config("wp_user_id"),
		"post_category" => array(),
	);

	if(is_array($data)) {
		foreach($data as $k=>$v) {
			if(array_key_exists($k, $post_context)) {
				if($k == "post_category") {
					$post_context['post_category'] = explode(",", $data['post_category']);
				} else {
					$post_context[$k] = $v;
				}
			}
		}
	} elseif(is_string($data)) {
		$post_context['post_status'] = "publish";
		$post_context['post_title'] = cwds_get_current_datetime();
		$post_context['post_content'] = $data;
	}

	$result = wp_insert_post($post_context);

	return $result;
}

function cwds_parse_datetime($datetime, $timezone) {
	$d = array_map("intval", explode(' ', str_replace(array('-', ':'), ' ', $datetime))); // Y-m-d H:i:s (0-1-2 3:4:5)
	return cwds_convert_timezone(mktime($d[3], $d[4], $d[5], $d[1], $d[2], $d[0]), $timezone);
}

function cwds_convert_timezone($time, $timezone) {
	$result = $time;
/*
	if($timezone != cwds_get_config("timezone")) {
		$dt = new DateTime(sprintf("@%s", $time), new DateTimeZone($timezone));
		$dt->setTimeZone(new DateTimeZone(cwds_get_config("timezone")));
		$result = $dt->getTimestamp();
	}
*/

	if($timezone == "UTC" && cwds_get_config("timezone") == "Asia/Seoul") {
		$result = $time + 32400;
	}

	return $result;
}

function cwds_get_lasttime() {
	$result = 0;

	$timefile_file_path = cwds_get_config("dir_path") . cwds_get_config("timefile");

        $timefile = NULL;
        $timefile_size = 0;
        if(file_exists($timefile_file_path)) {
                $timefile = fopen($timefile_file_path, 'r');
                $timefile_size = filesize($timefile_file_path);
        }

	if($timefile_size > 0) {
		$result = intval(fread($timefile, $timefile_size));
	}

	if(!is_null($timefile)) {
		fclose($timefile);
	}

	return $result;
}

function cwds_set_lasttime($time) {
	$result = false;

	$timefile_file_path = cwds_get_config("dir_path") . cwds_get_config("timefile");
	$timefile = fopen($timefile_file_path, 'w');
	if(!is_null($timefile)) {
		$result = fwrite($timefile, strval($time));
	}

	return $result;
}

function cwds_check_time($datetime, $timezone) {
	$result = false;

	$last_time = cwds_get_lasttime();
	$now_time = time();

	if($now_time > ($last_time + cwds_get_config("interval"))) {
		$each_time = cwds_parse_datetime($datetime, $timezone);

		if($last_time < $each_time) {
			$result = true;
		}

		//cwds_set_lasttime($now_time);
	}

	return $result;
}

function cwds_do() {
	$result = false;

	// set timezone
	date_default_timezone_set(cwds_get_config("timezone"));

	// check interval
	if(!cwds_check_time(cwds_get_current_datetime(), cwds_get_config("timezone"))) {
		return $result;
	}

	// do authenticate
	cwds_authenticate(cwds_get_config("email"), cwds_get_config("password"), cwds_get_config("host"));

	// get raw data and processing data
	$raw_data = cwds_get_messages(cwds_get_config("network_id"));
	$data = json_decode($raw_data, true);

	// handling error code
	if(array_key_exists("error", $data)) {
		if($data['error']['code'] == 102) {
			return cwds_do(); // do retry authenticate
		} else {
			// skip
		}
	}

	// get items and post
	$items = $data['data'];
	foreach($items as $item) {
		if(cwds_check_time($item['created_on'], "UTC")) {
			if($item['mime'] == "application/json") {
				cwds_wp_insert_post(json_decode($item['message'], true), cwds_parse_datetime($item['created_on'], "UTC"));
			} else {
				cwds_wp_insert_post($item['message'], cwds_parse_datetime($item['created_on'], "UTC"));
			}
		}
	}

	// set last time
	cwds_set_lasttime(time());

	return $result;
}

// run do
add_action("init", "cwds_do");
