<?php
function msgtoolbox_hook_playsmsd() {
	// not used
}

// hook_sendsms
// called by main sms sender
// return true for success delivery
// $mobile_sender	: sender mobile number
// $sms_sender		: sender sms footer or sms sender ID
// $sms_to		: destination sms number
// $sms_msg		: sms message tobe delivered
// $gpid		: group phonebook id (optional)
// $uid			: sender User ID
// $smslog_id		: sms ID
function msgtoolbox_hook_sendsms($mobile_sender,$sms_sender,$sms_to,$sms_msg,$uid='',$gpid=0,$smslog_id=0,$sms_type='text',$unicode=0) {
	// global $msgtoolbox_param;   // global all variables needed, eg: varibles from config.php
	// ...
	// ...
	// return true or false
	// return $ok;
	global $msgtoolbox_param;
	global $gateway_number;
	$ok = false;
	if ($msgtoolbox_param['global_sender']) {
		$sms_from = $msgtoolbox_param['global_sender'];
	} else if ($gateway_number) {
		$sms_from = $gateway_number;
	} else {
		$sms_from = $mobile_sender;
	}
	if ($sms_sender) {
		$sms_msg = $sms_msg.$sms_sender;
	}
	$sms_type = 2; // text
	if ($msg_type=="flash") {
		$sms_type = 1; // flash
	}
	if ($sms_to && $sms_msg) {

		if ($unicode) {
			// fixme anton - this isn't right, encoding should be done in url, not locally
			/*
			if (function_exists('mb_convert_encoding')) {
			$sms_msg = mb_convert_encoding($sms_msg, "UCS-2BE", "auto");
			}
			*/
			$unicode = 1;
		}
		// fixme anton - from playSMS v0.9.5.1 references to input.php replaced with index.php?app=webservices
		// I should add autodetect, if its below v0.9.5.1 should use input.php
		$query_string = "index.php?app=webservices&u=".$msgtoolbox_param['username']."&p=".$msgtoolbox_param['password']."&ta=pv&to=".urlencode($sms_to)."&from=".urlencode($sms_from)."&type=$sms_type&msg=".urlencode($sms_msg)."&unicode=".$unicode;
		$url = $msgtoolbox_param['url']."/".$query_string;

		if ($additional_param = $msgtoolbox_param['additional_param']) {
			$additional_param = "&".$additional_param;
		}
		$url .= $additional_param;
		$url = str_replace("&&", "&", $url);

		logger_print($url, 3, "msgtoolbox outgoing");
		$fd = @implode ('', file ($url));
		if ($fd) {
			$response = split (" ", $fd);
			if ($response[0] == "OK") {
				$remote_slid = $response[1];
				if ($remote_slid) {
					$db_query = "
						INSERT INTO "._DB_PREF_."_gatewayMsgtoolbox (up_local_slid,up_remote_slid,up_status)
						VALUES ('$smslog_id','$remote_slid','0')
					    ";
					$up_id = @dba_insert_id($db_query);
					if ($up_id) {
						$ok = true;
					}
				}
			}
			logger_print("smslog_id:".$smslog_id." response:".$response[0]." ".$response[1], 3, "msgtoolbox outgoing");
		} else {
			// even when the response is not what we expected we still print it out for debug purposes
			$fd = str_replace("\n", " ", $fd);
			$fd = str_replace("\r", " ", $fd);
			logger_print("smslog_id:".$smslog_id." response:".$fd, 3, "clickatell outgoing");
		}
	}
	if (!$ok) {
		$p_status = 2;
		setsmsdeliverystatus($smslog_id,$uid,$p_status);
	}
	return $ok;
}

// hook_getsmsstatus
// called by index.php?app=menu&inc=daemon (periodic daemon) to set sms status
// no returns needed
// $p_datetime	: first sms delivery datetime
// $p_update	: last status update datetime
function msgtoolbox_hook_getsmsstatus($gpid=0,$uid="",$smslog_id="",$p_datetime="",$p_update="") {
	// global $msgtoolbox_param;
	// p_status :
	// 0 = pending
	// 1 = delivered
	// 2 = failed
	// setsmsdeliverystatus($smslog_id,$uid,$p_status);
	global $msgtoolbox_param;
	$db_query = "SELECT * FROM "._DB_PREF_."_gatewayMsgtoolbox WHERE up_local_slid='$smslog_id'";
	$db_result = dba_query($db_query);
	while ($db_row = dba_fetch_array($db_result)) {
		$local_slid = $db_row['up_local_slid'];
		$remote_slid = $db_row['up_remote_slid'];
		// fixme anton - from playSMS v0.9.6 references to input.php replaced with index.php?app=webservices
		// I should add autodetect, if its below v0.9.6 should use input.php
		$query_string = "index.php?app=webservices&u=".$msgtoolbox_param['username']."&p=".$msgtoolbox_param['password']."&ta=ds&slid=".$remote_slid;
		$url = $msgtoolbox_param['url']."/".$query_string;
		$response = @implode ('', file ($url));
		switch ($response) {
			case "1":
				$p_status = 1;
				setsmsdeliverystatus($local_slid,$uid,$p_status);
				$db_query1 = "UPDATE "._DB_PREF_."_gatewayMsgtoolbox SET c_timestamp='".mktime()."',up_status='1' WHERE up_remote_slid='$remote_slid'";
				$db_result1 = dba_query($db_query1);
				break;
			case "3":
				$p_status = 3;
				setsmsdeliverystatus($local_slid,$uid,$p_status);
				$db_query1 = "UPDATE "._DB_PREF_."_gatewayMsgtoolbox SET c_timestamp='".mktime()."',up_status='3' WHERE up_remote_slid='$remote_slid'";
				$db_result1 = dba_query($db_query1);
				break;
			case "2":
			case "ERR 400":
				$p_status = 2;
				setsmsdeliverystatus($local_slid,$uid,$p_status);
				$db_query1 = "UPDATE "._DB_PREF_."_gatewayMsgtoolbox SET c_timestamp='".mktime()."',up_status='2' WHERE up_remote_slid='$remote_slid'";
				$db_result1 = dba_query($db_query1);
				break;
		}
	}
}

// hook_getsmsinbox
// called by incoming sms processor
// no returns needed
function msgtoolbox_hook_getsmsinbox() {
	// global $msgtoolbox_param;
	// $sms_datetime	: incoming sms datetime
	// $message		: incoming sms message
	// setsmsincomingaction($sms_datetime,$sms_sender,$message,$sms_receiver)
	// you must retrieve all informations needed by setsmsincomingaction()
	// from incoming sms, have a look msgtoolbox gateway module
	// fixme anton - msgtoolbox will receive incoming sms by using callback/push url
	/*
	global $msgtoolbox_param;
	$handle = @opendir($msgtoolbox_param['path']);
	while ($sms_in_file = @readdir($handle)) {
	if (eregi("^ERR.in",$sms_in_file) && !eregi("^[.]",$sms_in_file)) {
	$fn = $msgtoolbox_param['path']."/$sms_in_file";
	logger_print("infile:".$fn, 3, "msgtoolbox incoming");
	$tobe_deleted = $fn;
	$lines = @file ($fn);
	$sms_datetime = trim($lines[0]);
	$sms_sender = trim($lines[1]);
	$message = "";
	for ($lc=2;$lc<count($lines);$lc++) {
	$message .= trim($lines[$lc]);
	}
	// collected:
	// $sms_datetime, $sms_sender, $message
	setsmsincomingaction($sms_datetime,$sms_sender,$message,$sms_receiver);
	logger_print("sender:".$sms_sender." dt:".$sms_datetime." msg:".$message, 3, "msgtoolbox incoming");
	@unlink($tobe_deleted);
	}
	}
	*/
}

?>