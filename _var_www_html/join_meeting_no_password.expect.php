#!/usr/bin/php
<?php

$username = $argv[1] ;
$password = $argv[2] ;
$host = $argv[3] ;
$meeting_id = $argv[4] ;
$meeting_password = $argv[5] ;

ini_set( "default_socket_timeout", 2 ) ;

function ssh_server_disconnected( $reason, $message, $language ) {
    echo "server disconnected with reason code:{$reason} and message: {$message}\n" ;
}
$callbacks = ['disconnect'=>"ssh_server_disconnected"] ;
$methods = null ;

function run_command( $connection, $command ) {
    if( !($stream = ssh2_exec($connection, $command)) ) {
        echo "error: unable to run command: {$command}\n" ;
        exit( 1 ) ;
    }
    stream_set_blocking( $stream, true ) ;
    $data = "";
    while( $buf = fread($stream, 4096) ) {
        $data .= $buf ;
    }
    fclose( $stream ) ;
    return $data ;
}


function run_interactive_command( $stream, $command=false, $stop_on=false, $retry_count=0 ) {
    if( $command!==false ) {
        fwrite( $stream, "{$command}\r\n" ) ;
    }

    $total_time = 0 ;
    while( ($line=fgets($stream, 1024))===false ) {
        flush() ;
        usleep( 100000 ) ; // 0.1 second
        $total_time += 0.1 ;
        if( $total_time>1 ) { // 1 second
            break ;
        }
    }
    
    $data = $line ;
    $total_time = 0 ;
    $done = false ;
    while( true ) {
        while( ($line=fgets($stream, 1024))!==false ) {
            flush() ;
            $data .= "{$line}" ;
        }

        if( $stop_on===false ) {
            $data .= "\n\n---------- stop_on: false\n\n" ;
            $done = true ;
        } else if( gettype($stop_on)=="string" && substr_count($data, $stop_on)>0 ) {
            $data .= "\n\n---------- stop_on: string match\n\n" ;
            $done = true ;
        } else if( gettype($stop_on)=="array" ) {
             // other scripts stop on ALL match, this one stops on ANY match
            $match = false ;
            foreach( $stop_on as $stop_on_item ) {
                if( substr_count($data, $stop_on_item)>0 ) {
                    $match = true ;
                    break ;
                }
            }
            if( $match ) {
                $data .= "\n\n---------- stop_on: array match\n\n" ;
                $done = true ;
            }
        }
        if( $done ) {
            break ;
        } else if( $total_time>6 ) { // 6 seconds
            if( $retry_count>0 ) {
                return run_interactive_command( $stream, $command, $stop_on, $retry_count-1 ) ;
            } else {
                return "" ;
            }
        } else {
            usleep( 100000 ) ; // 0.1 second
            $total_time += 0.1 ;
        }
    }
  
    return $data ;
}


$connection = ssh2_connect( $host, 2244, $methods, $callbacks ) ;
if( $connection===false ) {
    echo "error: unable to establish ssh connection\n" ;
    exit( 1 ) ;
}

if( !ssh2_auth_password($connection, $username, $password) ) {
    echo "error: unable to authenticate\n" ;
    exit( 1 ) ;
}

if( !($shell = ssh2_shell($connection, "xterm")) ) {
    echo "error: unable to get interactive shell\n" ;
    exit( 1 ) ;
}
stream_set_blocking( $shell, false ) ;
stream_set_timeout( $shell, 5 ) ;
flush() ;

// just to flush the initial data dump
echo run_interactive_command( $shell ) ;

$command = "zCommand Dial Start meetingNumber: {$meeting_id}\r" ;
echo "---------- running command: {$command}\n" ;
echo run_interactive_command( $shell, $command, ['"MeetingNeedsPassword":','"Status": "IN_MEETING"', '"CallConnectError":'], 1 ) ;
sleep( 1 ) ; // yes... otherwise the zoom room stays stuck in the "CONNECTING_MEETING" status, which shows up as "Joining Meeting..." on the Zoom Room computer

fclose( $shell ) ;
ssh2_disconnect( $connection ) ;
exit( 0 ) ;


?>