<?php

// config
$refresh_data_every = 1 ; // minutes
$refresh_for_how_long = 5 ; // minutes
$maintenance_every = 10 ; // iteration
$zoom_room_command_retry_count = 2 ;

// functions which may not exist on older version of PHP
if( !function_exists('str_starts_with') ) {
	function str_starts_with ( $haystack, $needle ) {
	  return strpos( $haystack , $needle ) === 0;
	}
}

require_once( "database.php" ) ;


if( is_cli() ) {
	$db = new db_wrapper( true ) ;
	
	if( isset($argv[1]) ) {
		$id = $argv[1] ;
		$task = $db->prepared_query( "SELECT * FROM `microservice`.`tasks` WHERE `id`=?", array($id), "#ekuyghvw7irlu" ) ;
		if( count($task)==1 ) {
			$task = $task[0] ;

			$function_name = "" ;
			if( $task['method']=="GET" ) {
				$function_name .= "get_" ;
			} else if( $task['method']=="PATCH" ) {
				$function_name .= "set_" ;
			}
			$function_name .= str_replace( "/", "_", $task['path'] ) ;

			if( function_exists($function_name) ) {
				$result = call_user_func( $function_name, $task['device'], json_decode($task['datum'], true) ) ;
				if( $task['method']=="GET" ) {
					$db->prepared_query( "UPDATE `microservice`.`data` SET `datum`=?,
																		   `last_refreshed_timestamp`=NOW() WHERE `device`=? AND
																												  `path`=?", array(json_encode($result),
																												                   $task['device'],
																												                   $task['path']), "#geiortn", 0 ) ;
				}
				$db->prepared_query( "DELETE FROM `microservice`.`tasks` WHERE `id`=?", array($id), "#qker7ghiw7e", 0 ) ;
			} else {
				add_error( $task['device'], "function: {$function_name} not defined" ) ;
			}

		} else {
			add_error( "all", "didn't find exactly 1 task for task id: {$id}" ) ;
		}
	} else {
		$maintenance_every_counter = $maintenance_every ;
		while( true ) {
			$devices_currently_in_process = $db->prepared_query( "SELECT DISTINCT(`device`) AS `distinct_device` FROM `microservice`.`tasks` WHERE `in_process`='true'", array(), "#liturhw", 1 ) ;
			$devices_currently_in_process_for_query = array() ;
			foreach( $devices_currently_in_process as $device_currently_in_process ) {
				$devices_currently_in_process_for_query[] = $device_currently_in_process['distinct_device'] ;
			}
			$devices_currently_in_process_for_query = "'" . implode( "','", $devices_currently_in_process_for_query ) . "'" ;
			
			// first priority goes to PATCHes
			$query = "SELECT `id` FROM `microservice`.`tasks` WHERE " ;
			if( count($devices_currently_in_process)>0 ) {
				$query .= "`device` NOT IN ({$devices_currently_in_process_for_query}) AND " ;
			}
			$query .= "`in_process`='false' AND `method`='PATCH' AND `process_at_timestamp`<=NOW() ORDER BY `added_timestamp`, `id` ASC LIMIT 1" ;
			// echo $query ;
			$task = $db->prepared_query( $query, array(), "#3g8yhetriuk", 1 ) ;

			// second goes to GETs
			if( count($task)==0 ) {
				$query = "SELECT `id` FROM `microservice`.`tasks` WHERE " ;
				if( count($devices_currently_in_process)>0 ) {
					$query .= "`device` NOT IN ({$devices_currently_in_process_for_query}) AND " ;
				}
				$query .= "`in_process`='false' AND `method`='GET' AND `process_at_timestamp`<=NOW() ORDER BY `added_timestamp`, `id` ASC LIMIT 1" ;
				// echo $query ;
				$task = $db->prepared_query( $query, array(), "#3g8yhetriuk", 1 ) ;
			}
			
			if( count($task)>0 ) {
				// run tasks if any
				$id = $task[0]['id'] ;
				$db->prepared_query( "UPDATE `microservice`.`tasks` SET `in_process`='true',
																        `in_process_since_timestamp`=NOW() WHERE `id`=?", array($id), "#retor9wy89s", 0 ) ;
				echo "> " . date( "Y-m-d H:i:s" ) . " - forking task {$id}, log @ /var/log/task.{$id}.log\n" ;
				shell_exec( "/usr/bin/timeout 10 php /var/www/html/api.php {$id} > /var/log/task.{$id}.log 2>&1 &" ) ;
			} else {
				$maintenance_every_counter = $maintenance_every_counter - 1 ;
				if( $maintenance_every_counter==0 ) {
					$maintenance_every_counter = $maintenance_every ;
					// maintenance and sleeping a little
					
					//   cleaning up obsolete data
					$db->prepared_query( "DELETE FROM `microservice`.`data` WHERE `last_queried_timestamp`<(NOW() - INTERVAL {$refresh_for_how_long} MINUTE)", array(), "#thwrd", 0 ) ;
					$obsolete_tasks = $db->prepared_query( "SELECT * FROM `microservice`.`tasks` WHERE `in_process`='true' AND (`in_process_since_timestamp`<(NOW() - INTERVAL 20 SECOND) OR `in_process_since_timestamp` IS NULL)", array(), "#5eujh42w53uet", 1 ) ;
					if( count($obsolete_tasks)>0 ) {
						add_error( "all", "found obsolete tasks: " . json_encode($obsolete_tasks, JSON_PRETTY_PRINT) ) ;
						$db->prepared_query( "DELETE FROM `microservice`.`tasks` WHERE `in_process`='true' AND (`in_process_since_timestamp`<(NOW() - INTERVAL 20 SECOND) OR `in_process_since_timestamp` IS NULL)", array(), "#wy5htue", 0 ) ;
					}
					
					//   adding tasks for data that is still being queried
					$data_still_being_queried = $db->prepared_query( "SELECT * FROM `microservice`.`data` WHERE `no_refresh`='false' AND (`last_queried_timestamp`>(NOW() - INTERVAL {$refresh_for_how_long} MINUTE) AND (`last_refreshed_timestamp`<(NOW() - INTERVAL {$refresh_data_every} MINUTE) OR `last_refreshed_timestamp` IS NULL))", array(), "#3itrwuk", 1 ) ;
					foreach( $data_still_being_queried as $datum ) {
						$in_tasks_count = $db->prepared_query( "SELECT count(1) FROM `microservice`.`tasks` WHERE `device`=? AND
																												  `path`=? AND
																												  `method`='GET'", array($datum['device'],
																																		 $datum['path']), "#3058jetro", 3 ) ;
						if( $in_tasks_count===0 ) {
							echo "> " . date( "Y-m-d H:i:s" ) . " - adding task GET {$datum['device']}/{$datum['path']}\n" ;
							$db->prepared_query( "INSERT INTO `microservice`.`tasks` SET `device`=?,
																						 `path`=?,
																						 `method`='GET'", array($datum['device'],
																												$datum['path']), "#low7ih8i", 0 ) ;
						}
					}
					
					// 1% chance of removing task log files older than 10 days
					if( mt_rand(0,99)==0 ) {
						echo "> " . date( "Y-m-d H:i:s" ) . " - removing task log files older than 10 days\n" ;
						shell_exec( '/usr/bin/find /var/log -name "task.*.log" -type f -mtime +10 -exec rm \{\} \\;' ) ;
					}
				}
				usleep( 100000 ) ; // microseconds
			}
		}
	}
	$db->close() ;
	exit( 0 ) ;
}


header( "Access-Control-Allow-Origin: *" ) ;
header( "Access-Control-Allow-Credentials: true" ) ;
header( "Access-Control-Allow-Methods: GET,HEAD,OPTIONS,POST,PUT,PATCH,DELETE,EXPORT" ) ;
header( "Access-Control-Allow-Headers: Access-Control-Allow-Headers, Origin,Accept, X-Requested-With, Content-Type, Access-Control-Request-Method, Access-Control-Request-Headers, Authorization" ) ;

if( $_SERVER['REQUEST_METHOD']=="OPTIONS" ) {
	http_response_code( 200 ) ;
	exit( 0 ) ;
}




// routing
$method = $_SERVER['REQUEST_METHOD'] ;
$request_uri = $_SERVER['REQUEST_URI'] ;
$request_uri = explode( "/", $request_uri ) ;
$device = $request_uri[1] ;
$path = implode( "/", array_slice($request_uri, 2) ) ;
$path = explode( "?", $path ) ;
$path = $path[0] ;

// echo "method: {$method}, device: {$device}, path: {$path}" ; exit( 0 ) ;                             

if( $path=="camera_mute" &&
	$method=="GET" ) {
	get() ;
}
if( $path=="mute" &&
	$method=="GET" ) {
	get() ;
}
if( $path=="mute" &&
	$method=="PATCH" ) {
	set() ;
}
if( $path=="camera_mute" &&
	$method=="PATCH" ) {
	set() ;
}
if( $path=="mute_after_delay" &&
	$method=="PATCH" ) {
	set_with_delay() ;
}
if( $path=="camera_mute_after_delay" &&
	$method=="PATCH" ) {
	set_with_delay() ;
}
if( $path=="meeting" &&
	$method=="GET" ) {
	get() ;
}
if( $path=="meeting" &&
	$method=="PATCH" ) {
	set() ;
}
if( $path=="meeting" &&
	$method=="REFRESH" ) {
	refresh() ;
}
if( $path=="meeting/last_join_status" &&
	$method=="GET" ) {
	get_standalone() ;
}
if( $path=="sharing" &&
	$method=="GET" ) {
	get() ;
}
if( $path=="sharing" &&
	$method=="REFRESH" ) {
	refresh() ;
}
if( $path=="meeting/last_interaction" &&
	$method=="GET" ) {
	get_standalone() ;
}
// if( $path=="meeting/last_interaction" &&
// 	$method=="PATCH" ) {
// 	set( true ) ;
// }
if( $path=="bookings_list" &&
	$method=="GET" ) {
	get() ;
}


if( $path=="errors" &&
	$method=="GET" ) {
	get_errors() ;
}

close_with_400( "unknown path" ) ;
exit( 1 ) ;


function clean_up_line( $line ) {
	$line = str_replace( "\t", "", $line ) ;
	$line = str_replace( "\n", "", $line ) ;
	$line = str_replace( "\r", "", $line ) ;
	$line = str_replace( "\0", "", $line ) ;
	$line = str_replace( "\v", "", $line ) ;

	return $line ;
}

function parse_jsons( $response ) {
	$response = explode( "\n", $response ) ;
	$parsed_jsons = array() ;

	$a_json = "" ;
	$going_through_json = false ;
	for( $i=0 ; $i<count($response) ; $i++ ) {
		$line = clean_up_line( $response[$i] ) ;
		// echo "||{$line}||".strlen($line)."||\n";
		if( $line=="{" ) {
			// echo "here" ;
			$a_json .= $line ;
			$going_through_json = true ;
		} else if( $line=="}" ) {
			// echo "close" ;
			$a_json .= $line ;
			$parsed_jsons[] = $a_json ;
			$a_json = "" ;
			$going_through_json = false ;
		} else if( $going_through_json ) {
			$a_json .= $line ;
		}
	}

	for( $i=0 ; $i<count($parsed_jsons) ; $i++ ) {
		$parsed_jsons[$i] = json_decode( $parsed_jsons[$i], true ) ;
	}

	return $parsed_jsons ;
}


function parse_clis( $response, $wanted_results ) {
	$response = explode( "\n", $response ) ;

	$results = array() ;
	foreach( $wanted_results as $wanted_result_name=>$wanted_result_expression ) {
		$results[$wanted_result_name] = null ;
		foreach( $response as $line ) {
			if( str_starts_with($line, $wanted_result_expression) ) {
				$results[$wanted_result_name] = trim( explode(":", $line )[1]) ;
			}
		}
	}

	return array_filter( $results ) ;
}


function get_only_relevant_response( $parsed_jsons, $extra_explicit=false, $extra_explicit_type=false ) {
	$all_to_return = array() ;
	foreach( $parsed_jsons as $parsed_json ) {
		if( !(
			  (isset($parsed_json['type']) && $parsed_json['type']=="zStatus" && isset($parsed_json['Login'])) ||
			  (isset($parsed_json['type']) && $parsed_json['type']=="zEvent" && isset($parsed_json['PhonebookBasicInfoChange'])) ||
			  (isset($parsed_json['type']) && $parsed_json['type']=="zStatus" && isset($parsed_json['NumberOfScreens']))
			 ) ) {
			if( $extra_explicit===false ) {
				return $parsed_json ;
			} else if( gettype($extra_explicit)=="array" ) {
				foreach( $extra_explicit as $extra_explicit_item ) {
					if( isset($parsed_json[$extra_explicit_item]) ) {
						if( !array_key_exists($extra_explicit_item, $all_to_return) ) {
							$all_to_return[$extra_explicit_item] = array() ;
						}
						$all_to_return[$extra_explicit_item][] = $parsed_json ;
					}
				}
			} else if( isset($parsed_json[$extra_explicit]) ) {
				if( $extra_explicit_type===false ) {
					return $parsed_json ;
				} else {
					if( isset($parsed_json['type']) &&
						$parsed_json['type']==$extra_explicit_type ) {
						return $parsed_json ;
					}
				}
			}
		}
	}

	if( gettype($extra_explicit)=="array" ) {
		return $all_to_return ;
	}

	return false ;
}


function get_meeting( $device, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;

	$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/get_meeting_status.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
	// file_put_contents( "/tmp/get_meeting_status.".date("Y-m-d-H-i-s"), $response_raw ) ;
	$response = get_only_relevant_response( parse_jsons($response_raw), "Call", "zStatus" ) ;
	if( isset($response['Call']) &&
		isset($response['Call']['Status']) ) {
		if( $response['Call']['Status']=="NOT_IN_MEETING" ) {
			return false ;
		} else if( $response['Call']['Status']=="CONNECTING_MEETING" ) {
			return "connecting" ;
		} else if( $response['Call']['Status']=="IN_MEETING" ) {
			$get_meeting_try_count = 5 ;
			while( $get_meeting_try_count>0 ) {
				$info_response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/get_meeting_info.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
				// file_put_contents( "/tmp/get_meeting_info.".date("Y-m-d-H-i-s"), $info_response_raw ) ;
				$info_response = get_only_relevant_response( parse_jsons($info_response_raw), "InfoResult" ) ;
				
				if( isset($info_response['InfoResult']) ) {
					$new_data = array() ;
					$new_data['meeting_id'] = "" ;
					if( isset($info_response['InfoResult']['meeting_id']) ) {
						$new_data['meeting_id'] = $info_response['InfoResult']['meeting_id'] ;
					}
					$new_data['password'] = "" ;
					if( isset($info_response['InfoResult']['meeting_password']) ) {
						$new_data['password'] = $info_response['InfoResult']['meeting_password'] ;
					}
					$new_data['meeting_name'] = "" ;
					if( isset($info_response['InfoResult']['meeting_list_item']['meetingName']) ) {
						$new_data['meeting_name'] = $info_response['InfoResult']['meeting_list_item']['meetingName'] ;
					}
					$new_data['meeting_type'] = "" ;
					if( isset($info_response['InfoResult']['meeting_type']) ) {
						$new_data['meeting_type'] = $info_response['InfoResult']['meeting_type'] ;
					}
					return $new_data ;
				} else {
					sleep( 1 ) ;
					$get_meeting_try_count-- ;
				}
			}
			add_error( $device_original, "unknown call info for device: {$device_original}, got output:\n\n{$info_response_raw}" ) ;
			return false ;
		} else {
			add_error( $device_original, "unknown call status for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
			return false ;
		}
	}

	if( $retry_count<$zoom_room_command_retry_count ) {
		sleep( $retry_count ) ;
		return get_meeting( $device_original, $retry_count+1 ) ;
	} else {
		add_error( $device_original, "unable to retrieve meeting info for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
		return false ;
	}
	
	add_error( $device_original, "unreachable point #dfiwrh78oed" ) ;
	return false ;
}


function get_sharing( $device, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;

	$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/get_sharing_info.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
	$response = get_only_relevant_response( parse_jsons($response_raw), "Sharing", "zStatus" ) ;
	if( isset($response['Sharing']) &&
		isset($response['Sharing']['directPresentationPairingCode']) &&
		isset($response['Sharing']['directPresentationSharingKey']) ) {
		return array( "sharing_key"=>$response['Sharing']['directPresentationSharingKey'],
					  "pairing_code"=>$response['Sharing']['directPresentationPairingCode'] ) ;
	} else {
		add_error( $device_original, "unknown sharing status for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
		return false ;
	}

	if( $retry_count<$zoom_room_command_retry_count ) {
		sleep( $retry_count ) ;
		return get_sharing( $device_original, $retry_count+1 ) ;
	} else {
		add_error( $device_original, "unable to retrieve sharing info for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
		return false ;
	}

	add_error( $device_original, "unreachable point #wreutbhwr9iued" ) ;
	return false ;
}


function set_meeting( $device, $datum, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;
	if( $device!==false ) {
		if( isset($datum['meeting_id']) && isset($datum['password']) ) {
			set_standalone( $device_original, "meeting/last_interaction", floor(microtime(true) * 1000) ) ;
			set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																				"message"=>"Connecting to meeting",
																				"timestamp"=>floor(microtime(true) * 1000)) ) ;
			if( strpos($datum['meeting_id'], "@")!== false){
				set_standalone( $device_original, "meeting/last_join_status", array("success"=>true,
																				"message"=>"Invited SIP participant to meeting",
																				"timestamp"=>floor(microtime(true) * 1000)) ) ;
				if( $datum['password']=="" ) {
					$response_raw = shell_exec( "/usr/bin/timeout 10 /var/www/html/join_sip_call_no_password.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']} \"{$datum['meeting_id']}\"" ) ;
				} else {
					$response_raw = shell_exec( "/usr/bin/timeout 10 /var/www/html/join_sip_call.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']} \"{$datum['meeting_id']}\" \"{$datum['password']}\"" ) ;
				}
			} else {
				if( $datum['password']=="" ) {
					$response_raw = shell_exec( "/usr/bin/timeout 8 /var/www/html/join_meeting_no_password.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']} \"{$datum['meeting_id']}\"" ) ;
				} else {
					$response_raw = shell_exec( "/usr/bin/timeout 8 /var/www/html/join_meeting.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']} \"{$datum['meeting_id']}\" \"{$datum['password']}\"" ) ;
				}
			}
			$responses = get_only_relevant_response( parse_jsons($response_raw), array("Call", "CallConnectError", "MeetingNeedsPassword", "H323SIPRoomCallingStatus") ) ;
			// if (( isset($responses['DialStartResult'][0]['DialStartResult']) &&
			// 	isset($responses['DialStartResult'][0]['Status']) &&
			// 	isset($responses['DialStartResult'][0]['Status']['state']) &&
			// 	$responses['DialStartResult'][0]['Status']['state']=="OK" ) || ( isset($responses['DialJoinResult'][0]['DialJoinResult']) &&
			// 	isset($responses['DialJoinResult'][0]['Status']) &&
			// 	isset($responses['DialJoinResult'][0]['Status']['state']) &&
			// 	$responses['DialJoinResult'][0]['Status']['state']=="OK" ) ){

			if( isset($responses['MeetingNeedsPassword']) ) {
				set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																					"message"=>"Wrong password for meeting",
																				    "timestamp"=>floor(microtime(true) * 1000)) ) ;
				set_standalone( $device_original, "meeting", false ) ;
				set_meeting( $device, false ) ;
			} else if( isset($responses['CallConnectError']) ) {
				$message = "unavailable meeting" ;
				if( isset($responses['CallConnectError']['error_message']) ) {
					$message = $responses['CallConnectError']['error_message'] ;
				}
				set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																					"message"=>$message,
																				    "timestamp"=>floor(microtime(true) * 1000)) ) ;
				set_standalone( $device_original, "meeting", false ) ;
			} else if (isset($responses['H323SIPRoomCallingStatus']) && isset($responses['H323SIPRoomCallingStatus'][count($responses['H323SIPRoomCallingStatus'])-1]) && isset($responses['H323SIPRoomCallingStatus'][count($responses['H323SIPRoomCallingStatus'])-1]['H323SIPRoomCallingStatus']) && isset($responses['H323SIPRoomCallingStatus'][count($responses['H323SIPRoomCallingStatus'])-1]['H323SIPRoomCallingStatus']['call_status'])){
				if ($responses['H323SIPRoomCallingStatus'][count($responses['H323SIPRoomCallingStatus'])-1]['H323SIPRoomCallingStatus']['call_status'] == "CallingStatus_Accepted"){
					set_standalone( $device_original, "meeting/last_join_status", array("success"=>true,
																						"message"=>"",
																						"timestamp"=>floor(microtime(true) * 1000)) ) ;
				} else if ($responses['H323SIPRoomCallingStatus'][count($responses['H323SIPRoomCallingStatus'])-1]['H323SIPRoomCallingStatus']['call_status'] == "CallingStatus_Ringing"){
					//Set the last join time to 55 seconds before now so that it will check again soon
					set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																						"message"=>"SIP call ringing",
																						"timestamp"=>(floor(microtime(true)) -55 ) * 1000)) ;
				} else if ($responses['H323SIPRoomCallingStatus'][count($responses['H323SIPRoomCallingStatus'])-1]['H323SIPRoomCallingStatus']['call_status'] == "CallingStatus_Failed") {
					set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																						"message"=>"SIP call failed",
																					    "timestamp"=>floor(microtime(true) * 1000)) ) ;
					$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/leave_meeting.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
					$response = get_only_relevant_response( parse_jsons($response_raw), "CallDisconnect" ) ;
					set_standalone( $device_original, "meeting/last_interaction", null ) ;
					set_standalone( $device_original, "meeting/last_join_status", null ) ;
					if( isset($response['CallDisconnect']) &&
						isset($response['Status']) &&
						isset($response['Status']['state']) &&
						$response['Status']['state']=="OK" ) {
					} else {
						if( $retry_count<$zoom_room_command_retry_count ) {
							sleep( $retry_count ) ;
							set_meeting( $device_original, $datum, $retry_count+1 ) ;
						} else {
							add_error( $device_original, "haven't gotten the expected response when leaving meeting with:\n\n" . $response_raw ) ;
						}
					}
				} else {
					add_error( $device_original, "haven't gotten the expected response when joining sip meeting with:\n\n" . $response_raw ) ;
					set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																						"message"=>"General error",
																						"timestamp"=>floor(microtime(true) * 1000)) ) ;
					$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/leave_meeting.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
					$response = get_only_relevant_response( parse_jsons($response_raw), "CallDisconnect" ) ;
					set_standalone( $device_original, "meeting/last_interaction", null ) ;
					set_standalone( $device_original, "meeting/last_join_status", null ) ;
					if( isset($response['CallDisconnect']) &&
						isset($response['Status']) &&
						isset($response['Status']['state']) &&
						$response['Status']['state']=="OK" ) {
					} else {
						if( $retry_count<$zoom_room_command_retry_count ) {
							sleep( $retry_count ) ;
							set_meeting( $device_original, $datum, $retry_count+1 ) ;
						} else {
							add_error( $device_original, "haven't gotten the expected response when leaving meeting with:\n\n" . $response_raw ) ;
						}
					}
				}
			} else if( isset($responses['Call']) ) {
				$in_meeting = false ;
				foreach( $responses['Call'] as $call ) {
					if( isset($call['Call']) &&
						isset($call['Call']['Status']) &&
						$call['Call']['Status']=="IN_MEETING" ) {
						$in_meeting = true ;
					}
				}
				if( $in_meeting ) {
					set_standalone( $device_original, "meeting/last_join_status", array("success"=>true,
																						"message"=>"",
																						"timestamp"=>floor(microtime(true) * 1000)) ) ;
				} else {
					if( $retry_count<$zoom_room_command_retry_count ) {
						sleep( $retry_count ) ;
						set_meeting( $device_original, $datum, $retry_count+1 ) ;
					} else {
						add_error( $device_original, "haven't gotten the expected response when joining meeting with:\n\n" . $response_raw ) ;
						set_standalone( $device_original, "meeting/last_join_status", array("success"=>false,
																							"message"=>"general error",
																							"timestamp"=>floor(microtime(true) * 1000)) ) ;
					}
				}
			}
		} else if( $datum===false ) {
			$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/leave_meeting.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
			$response = get_only_relevant_response( parse_jsons($response_raw), "CallDisconnect" ) ;
			// set_standalone( $device_original, "meeting/last_interaction", null ) ;
			// set_standalone( $device_original, "meeting/last_join_status", null ) ;
			set_standalone( $device_original, "meeting/last_interaction", floor(microtime(true) * 1000) ) ;
			set_standalone( $device_original, "meeting", false ) ;
			if( isset($response['CallDisconnect']) &&
				isset($response['Status']) &&
				isset($response['Status']['state']) &&
				$response['Status']['state']=="OK" ) {
			} else {
				if( $retry_count<$zoom_room_command_retry_count ) {
					sleep( $retry_count ) ;
					set_meeting( $device_original, $datum, $retry_count+1 ) ;
				} else {
					add_error( $device_original, "haven't gotten the expected response when leaving meeting with:\n\n" . $response_raw ) ;
				}
			}
		} else {
			add_error( $device_original, "unable to set join/leave meeting for device: {$device_original}, passed information is erroneous" ) ;
		}
	}

	return false ;
}


function get_mute( $device, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;

	$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/is_muted_microphone.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
	$response = get_only_relevant_response( parse_jsons($response_raw), "Call" ) ;
	if( isset($response['Call']) &&
		isset($response['Call']['Microphone']) &&
		isset($response['Call']['Microphone']['Mute']) &&
		is_bool($response['Call']['Microphone']['Mute']) ) {
		return( $response['Call']['Microphone']['Mute'] ) ;
	}


	if( $retry_count<$zoom_room_command_retry_count ) {
		sleep( $retry_count ) ;
		return get_mute( $device_original, $retry_count+1 ) ;
	} else {
		add_error( $device_original, "unable to retrieve mute for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
		return false ;
	}

	add_error( $device_original, "unreachable point #sourygw478ey" ) ;
	return false ;
}

function set_mute( $device, $datum, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;
	if( $device!==false ) {
		if( $datum===true ) {
			$response = shell_exec( "/usr/bin/timeout 6 /var/www/html/mute_microphone.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
			if( substr_count($response, "zConfiguration Call Microphone Mute: on")==0 ) {
				if( $retry_count<$zoom_room_command_retry_count ) {
					sleep( $retry_count ) ;
					set_mute( $device_original, $datum, $retry_count+1 ) ;
				} else {
					add_error( $device_original, "haven't gotten the expected response when setting mute with:\n\n" . var_export($response, true) ) ;
				}
			}
		} else if( $datum===false ) {
			$response = shell_exec( "/usr/bin/timeout 6 /var/www/html/unmute_microphone.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
			if( substr_count($response, "zConfiguration Call Microphone Mute: off")==0 ) {
				if( $retry_count<$zoom_room_command_retry_count ) {
					sleep( $retry_count ) ;
					set_mute( $device_original, $datum, $retry_count+1 ) ;
				} else {
					add_error( $device_original, "haven't gotten the expected response when setting mute with:\n\n" . var_export($response, true) ) ;
				}
			}
		} else {
			add_error( $device_original, "unable to set mute for device: {$device_original}, new state is supposed to be boolean true or false" ) ;
		}
	}
}

function get_camera_mute( $device, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;

	$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/is_muted_camera.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
	$response = get_only_relevant_response( parse_jsons($response_raw), "Call" ) ;
	if( isset($response['Call']) &&
		isset($response['Call']['Camera']) &&
		isset($response['Call']['Camera']['Mute']) &&
		is_bool($response['Call']['Camera']['Mute']) ) {
		return( $response['Call']['Camera']['Mute'] ) ;
	}

	if( $retry_count<$zoom_room_command_retry_count ) {
		sleep( $retry_count ) ;
		return get_camera_mute( $device_original, $retry_count+1 ) ;
	} else {
		add_error( $device_original, "unable to retrieve camera mute for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
		return false ;
	}

	add_error( $device_original, "unreachable point #ewto8uyehol" ) ;
	return false ;
}

function set_camera_mute( $device, $datum, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;
	if( $device!==false ) {
		if( $datum===true ) {
			$response = shell_exec( "/usr/bin/timeout 6 /var/www/html/mute_camera.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
			if( substr_count($response, "zConfiguration Call Camera Mute: on")==0 ) {
				if( $retry_count<$zoom_room_command_retry_count ) {
					sleep( $retry_count ) ;
					set_camera_mute( $device_original, $datum, $retry_count+1 ) ;
				} else {
					add_error( $device_original, "haven't gotten the expected response when setting camera mute with:\n\n" . var_export($response, true) ) ;
				}
			}
		} else if( $datum===false ) {
			$response = shell_exec( "/usr/bin/timeout 6 /var/www/html/unmute_camera.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
			if( substr_count($response, "zConfiguration Call Camera Mute: off")==0 ) {
				if( $retry_count<$zoom_room_command_retry_count ) {
					sleep( $retry_count ) ;
					set_camera_mute( $device_original, $datum, $retry_count+1 ) ;
				} else {
					add_error( $device_original, "haven't gotten the expected response when setting camera mute with:\n\n" . var_export($response, true) ) ;
				}
			}
		} else {
			add_error( $device_original, "unable to set camera mute for device: {$device_original}, new state is supposed to be boolean true or false" ) ;
		}
	}
}


function set_mute_after_delay( $device, $datum ) {
	$device_original = $device ;
	$device = get_device_info( $device ) ;
	if( $device!==false ) {
		if( isset($datum['delay']) &&
			is_numeric($datum['delay']) ) {
			// sleep( $datum['delay'] ) ; // the delay was moved to the task processing queue
			set_mute( $device_original, $datum['new_state'] ) ;
		} else {
			add_error( $device_original, "unable to set mute after delay for device: {$device_original}, delay is supposed to be defined and numeric" ) ;
		}
	}
}


function set_camera_mute_after_delay( $device, $datum ) {
	$device_original = $device ;
	$device = get_device_info( $device ) ;
	if( $device!==false ) {
		if( isset($datum['delay']) &&
			is_numeric($datum['delay']) ) {
			sleep( $datum['delay'] ) ;
			set_camera_mute( $device_original, $datum['new_state'] ) ;
		} else {
			add_error( $device_original, "unable to set camera mute after delay for device: {$device_original}, delay is supposed to be defined and numeric" ) ;
		}
	}
}


function get_device_info( $device ) {
	$device_original = $device ;
	$device = explode( "@", $device ) ;
	if( count($device)==1 ) {
		return array( 'username'=>"",
			          'password'=>"",
			          'fqdn'=>$device[0] ) ;
	} else if( count($device)==2 ) {
		$credentials = explode( ":", $device[0] ) ;
		if( count($credentials)==2 ) {
			return array( 'username'=>$credentials[0],
				          'password'=>urldecode($credentials[1]),
				          'fqdn'=>$device[1] ) ;
		} else {
			add_error( "all", "unparseable device credentials: {$device_original}" ) ;
		}
	} else {
		add_error( "all", "unparseable device: {$device_original}" ) ;
	}

	add_error( "all", "unreachable point #qeitrw7oe" ) ;
	return false ;
}

function get_bookings_list( $device, $retry_count=0 ) {
	global $zoom_room_command_retry_count ;

	$device_original = $device ;
	$device = get_device_info( $device ) ;

	$response_raw = shell_exec( "/usr/bin/timeout 6 /var/www/html/get_bookings_list.expect.php {$device['username']} \"{$device['password']}\" {$device['fqdn']}" ) ;
	$response = get_only_relevant_response( parse_jsons($response_raw), "BookingsListResult" ) ;
	if( isset($response['BookingsListResult'])) {
		return( $response['BookingsListResult'] ) ;
	}

	if( $retry_count<$zoom_room_command_retry_count ) {
		sleep( $retry_count ) ;
		return get_bookings_list( $device_original, $retry_count+1 ) ;
	} else {
		add_error( $device_original, "unable to retrieve bookings list for device: {$device_original}, got output:\n\n{$response_raw}" ) ;
		return false ;
	}

	add_error( $device_original, "unreachable point #ewto8uyehol" ) ;
	return false ;
}

function add_error( $device, $message ) {
	global $db ;
	$db->prepared_query( "INSERT INTO `microservice`.`errors` SET `device`=?,
	 															  `message`=?", array($device,
	 															  					  $message), "#e8gh487", 0 ) ;
}
function get_errors() {
	global $db, $device  ;
	$db = new db_wrapper( true ) ;

	$errors = $db->prepared_query( "SELECT `message` FROM `microservice`.`errors` WHERE `device`=?", array($device), "#e9t87hr9g", 1 ) ;
	$db->prepared_query( "DELETE FROM `microservice`.`errors` WHERE `device`=?", array($device), "#qeioruhtwo8e", 0 ) ;
	$new_errors = array() ;
	foreach( $errors as $error ) {
		$new_errors[] = $error['message'] ;
	}
	$errors = $new_errors ;

	if( count($errors)==0 ) {
		close_with_200( "no errors" ) ;
	} else {
		close_with_500( $errors ) ;
	}
}

function get() {
	global $db, $device, $path, $method ;
	$db = new db_wrapper( true ) ;

	$entry_count = $db->prepared_query( "SELECT count(1) FROM `microservice`.`data` WHERE `device`=? AND
																						  `path`=?", array($device,
																						  				   $path), "#39tghbrgiufgjkn", 3 ) ;
	if( $db->last_query_success===false ) {
		close_with_500( "database error" ) ;
	}
	if( $entry_count==0 ) {
		$db->prepared_query( "INSERT INTO `microservice`.`data` SET `device`=?,
																	`path`=?", array($device,
																				     $path), "#euibrwy87ei", 0 ) ;
		close_with_204() ;
	}
	if( $entry_count>0 ) {
		$datum = $db->prepared_query( "SELECT `datum` FROM `microservice`.`data` WHERE `device`=? AND
																					   `path`=? LIMIT 1", array($device,
																						  				        $path), "#3ogiwhrie", 3 ) ;
		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
		$db->prepared_query( "UPDATE `microservice`.`data` SET `last_queried_timestamp`=NOW() WHERE `device`=? AND
																						            `path`=?", array($device,
																						  				             $path), "#3rgobe4nrkj", 0 ) ;

		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
		if( $entry_count>1 ) {
			// found bug with very quick initial queries to same path/device, not worth fixing with SDK around the corner, workaround in the interim
			$db->prepared_query( "DELETE FROM `microservice`.`data` WHERE `device`=? AND
																		  `path`=?", array($device,
																						   $path), "#g984yh359", 0 ) ;
			// add_error( $device, "more than 1 entry count for {$method} {$path} with: {$entry_count}" ) ;
		}

		if( $datum===null || $datum==="" ) {
			close_with_204() ;
		} else {
			close_with_200( json_decode($datum, true) ) ;
		}
	}
	

	close_with_500( "we shouldn't reach this point" ) ;
}


function delete() {
	global $db, $device, $path, $method ;
	$db = new db_wrapper( true ) ;

	$db->prepared_query( "DELETE FROM `microservice`.`data` WHERE `device`=? AND
																  `path`=?", array($device,
																 		  		   $path), "#84o7r65wye", 3 ) ;
	if( $db->last_query_success===false ) {
		close_with_500( "database error" ) ;
	}
	
	close_with_200( "ok" ) ;
}


function refresh() {
	global $db, $device, $path, $method ;
	$db = new db_wrapper( true ) ;

	// $db->prepared_query( "DELETE FROM `microservice`.`data` WHERE `device`=? AND
	// 															  `path`=?", array($device,
	// 															 		  		   $path), "#84o7r65wye", 3 ) ;
	// if( $db->last_query_success===false ) {
	// 	close_with_500( "database error" ) ;
	// }
	$in_tasks_count = $db->prepared_query( "SELECT count(1) FROM `microservice`.`tasks` WHERE `device`=? AND
																							  `path`=? AND
																							  `method`='GET'", array($device,
																													 $path), "#846eutyye", 3 ) ;
	if( $in_tasks_count===0 ) {
		$db->prepared_query( "INSERT INTO `microservice`.`tasks` SET `device`=?,
																	 `path`=?,
																	 `method`='GET'", array($device,
																							$path), "#3h4etg3t4y4", 0 ) ;
	}
	
	close_with_200( "ok" ) ;
}


function get_standalone() {
	global $db, $device, $path, $method ;
	$db = new db_wrapper( true ) ;

	$entry_count = $db->prepared_query( "SELECT count(1) FROM `microservice`.`data` WHERE `device`=? AND
																						  `path`=?", array($device,
																						  				   $path), "#thwy4rtehwrg", 3 ) ;
	if( $db->last_query_success===false ) {
		close_with_500( "database error" ) ;
	}
	if( $entry_count==0 ) {
		// can't close with a 204 because these "standalone" variable will not get set shortly after having been queried so the 204 will keep occuring which will cause errors up the stack.
		// close_with_204() ;
		close_with_200( false ) ;
	}
	if( $entry_count>0 ) {
		$datum = $db->prepared_query( "SELECT `datum` FROM `microservice`.`data` WHERE `device`=? AND
																					   `path`=? LIMIT 1", array($device,
																						  				        $path), "#qh4twyehntdgh", 3 ) ;
		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
		$db->prepared_query( "UPDATE `microservice`.`data` SET `last_queried_timestamp`=NOW() WHERE `device`=? AND
																						            `path`=?", array($device,
																						  				             $path), "#h4qthwryh", 0 ) ;

		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
		if( $entry_count>1 ) {
			add_error( $device, "more than 1 entry count for {$method} {$path} with: {$entry_count}" ) ;
		}

		if( $datum===null || $datum==="" ) {
			close_with_204() ;
		} else {
			close_with_200( json_decode($datum, true) ) ;
		}
	}
	

	close_with_500( "we shouldn't reach this point" ) ;
}


function set_standalone( $device, $standalone_path, $standalone_data ) {
	global $db ;
	$db = new db_wrapper( true ) ;

	$standalone_data = json_encode( $standalone_data ) ;

	// insert or update?
	$entry_count = $db->prepared_query( "SELECT count(1) FROM `microservice`.`data` WHERE `device`=? AND
														                                  `path`=?", array($device,
																						  	               $standalone_path), "#426tyw5rets", 3 ) ;
	if( $entry_count===0 ) {
		// insert
		$db->prepared_query( "INSERT INTO `microservice`.`data` SET `last_queried_timestamp`=NOW(),
																	`no_refresh`='true',
															        `datum`=?,
															        `device`=?,
															        `path`=?", array($standalone_data,
														                   	         $device,
																		             $standalone_path), "#3qt4wryeh", 0 ) ;
		if( $db->last_query_success===false ) {
			return false ;
		}
	} else if( $entry_count>0 ) {
		// update
		$db->prepared_query( "UPDATE `microservice`.`data` SET `last_queried_timestamp`=NOW(),
															   `datum`=? WHERE `device`=? AND
															                   `path`=?", array($standalone_data,
															                   	                $device,
																							  	$standalone_path), "#gqwetrd", 0 ) ;
		if( $db->last_query_success===false ) {
			return false ;
		}
	}

	return true ;
}


function set( $no_refresh=false ) {
	global $db, $device, $path, $method ;
	$db = new db_wrapper( true ) ;

	$db_data = get_request_body() ;

	if( $path=="meeting" &&
		$method=="PATCH" ) {
		// exception
		$db_data = json_decode( $db_data, true ) ;
		if( is_array($db_data) &&
			array_key_exists('meeting_id', $db_data) ) {
			// joining a meeting, we need a couple of extra default data points
			$db_data['meeting_name'] = "" ;
			$db_data['meeting_type'] = "NORMAL" ;
		}
		$db_data = json_encode( $db_data ) ;
	}

	if( $no_refresh===true ) {
		$no_refresh = "true" ;
	} else {
		$no_refresh = "false" ;
	}

	// we need to handle more than just true & false
	// $db_data = json_encode( false ) ;
	// if( $data===true ) {
	// 	$db_data = json_encode( true ) ;
	// }

	// insert or update?
	$entry_count = $db->prepared_query( "SELECT count(1) FROM `microservice`.`data` WHERE `device`=? AND
														                                  `path`=?", array($device,
																						  	               $path), "#rkuyiwgured", 3 ) ;
	if( $entry_count===0 ) {
		// insert
		$db->prepared_query( "INSERT INTO `microservice`.`data` SET `last_queried_timestamp`=NOW(),
															        `datum`=?,
															        `device`=?,
															        `path`=?,
															        `no_refresh`=?", array($db_data,
												                   	                       $device,
																				  	       $path,
																				  	       $no_refresh), "#rqtiwhykqrw", 0 ) ;
		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
	} else if( $entry_count>0 ) {
		// update
		$db->prepared_query( "UPDATE `microservice`.`data` SET `last_queried_timestamp`=NOW(),
															   `datum`=? WHERE `device`=? AND
															                   `path`=? AND
															                   `no_refresh`=?", array($db_data,
															                   	                      $device,
																							  	      $path,
																							  	      $no_refresh), "#f65rjyt", 0 ) ;
		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
	}

	$db->prepared_query( "DELETE FROM `microservice`.`tasks` WHERE `device`=? AND
										                           `path`=? AND
										                           `method`=?", array($device,
										                   	                          $path,
																		  	          $method), "#kgiukgki", 0 ) ;
	if( $db->last_query_success===false ) {
		close_with_500( "database error" ) ;
	}

	if( $no_refresh==="false" ) {
		$db->prepared_query( "INSERT INTO `microservice`.`tasks` SET `device`=?,
															         `path`=?,
															         `method`=?,
															         `datum`=?", array($device,
										                   	                           $path,
																		  	           $method,
																		  	           $db_data), "#luwyeo35qlw", 0 ) ;
		if( $db->last_query_success===false ) {
			close_with_500( "database error" ) ;
		}
	}

	close_with_200( "ok" ) ;
}


function set_with_delay() {
	global $db, $device, $path, $method ;
	$db = new db_wrapper( true ) ;

	$data = get_request_body() ;
	$parsed_data = json_decode( $data, true ) ;
	$delay = 0 ;
	if( !isset($parsed_data['delay']) ) {
		add_error( $device, "need delay variable when calling set_with_delay" ) ;
	} else {
		$delay = $parsed_data['delay'] ;
	}

	$db->prepared_query( "INSERT INTO `microservice`.`tasks` SET `device`=?,
														         `path`=?,
														         `method`=?,
														         `datum`=?,
														         `process_at_timestamp`=TIMESTAMPADD(SECOND,{$delay},NOW())", array($device,
																					                   	                            $path,
																													  	            $method,
																													  	            $data), "#354w9teh", 0 ) ;
	if( $db->last_query_success===false ) {
		close_with_500( "database error" ) ;
	}

	close_with_200( "ok" ) ;
}




//  ____                               _     _____                 _   _                 
// / ___| _   _ _ __  _ __   ___  _ __| |_  |  ___|   _ _ __   ___| |_(_) ___  _ __  ___ 
// \___ \| | | | '_ \| '_ \ / _ \| '__| __| | |_ | | | | '_ \ / __| __| |/ _ \| '_ \/ __|
//  ___) | |_| | |_) | |_) | (_) | |  | |_  |  _|| |_| | | | | (__| |_| | (_) | | | \__ \
// |____/ \__,_| .__/| .__/ \___/|_|   \__| |_|   \__,_|_| |_|\___|\__|_|\___/|_| |_|___/
// 			   |_|   |_|                                                                 


function close_with_500( $message ) {
	global $db, $path ;

	http_response_code( 500 ) ;

	header( "Content-Type: application/json" ) ;

	$to_return = array( "success"=>false, "message"=>$message ) ;
	echo json_encode( $to_return ) ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 1 ) ;
}


function close_with_501( $message ) {
	global $db, $path ;

	http_response_code( 501 ) ;

	header( "Content-Type: application/json" ) ;

	$to_return = array( "success"=>false, "message"=>$message ) ;
	echo json_encode( $to_return ) ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 1 ) ;
}


function close_with_404( $message ) {
	global $db, $path ;

	http_response_code( 404 ) ;

	header( "Content-Type: application/json" ) ;

	$to_return = array( "success"=>false, "message"=>$message ) ;
	echo json_encode( $to_return ) ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 1 ) ;
}


function close_with_400( $message ) {
	global $db, $path ;

	http_response_code( 400 ) ;

	header( "Content-Type: application/json" ) ;

	$to_return = array( "success"=>false, "message"=>$message ) ;
	echo json_encode( $to_return ) ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 1 ) ;
}


function close_with_401( $message ) {
	global $db, $path ;

	http_response_code( 401 ) ;

	echo "Unauthorized: {$message}" ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 1 ) ;
}


function close_with_204() {
	global $db, $path ;

	http_response_code( 204 ) ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 1 ) ;
}


function close_with_200( $data ) {
	global $db, $path ;

	header( "Content-Type: application/json; charset=utf-8" ) ;

	echo json_encode( $data ) ;

	if( $db!==null ) {
		@$db->close() ;
	}
	exit( 0 ) ;
}


function get_request_body() {
	$input = file_get_contents( "php://input" ) ;
	// $input = json_decode( $input, true ) ;
	return $input ;
}


function is_cli() {
	return php_sapi_name()==="cli" ;
}


?>