<?php

define( "DB_USER", "root" ) ;
define( "DB_PASS", "password" ) ;
define( "DB_HOST", "localhost" ) ;
define( "ERROR_MAIL_TRIGGER", false ) ;
define( "DB_ERROR_MAIL_VERBOSE_LEVEL", 3 ) ;
define( "DB_ERROR_DISPLAY", false ) ;
define( "DB_ERROR_DISPLAY_VERBOSE_LEVEL", 3 ) ;

class db_wrapper {

    // variable declaration
    private $db_host ;
    private $db_port ;
    private $db_username ;
    private $db_password ;
    private $db_link ;

    private $last_query ;
    public $last_query_success ;


    /**
    * @desc constructor
    */
    function __construct( $connect=false ) {
        $this->db_host     = DB_HOST ;
        $this->db_port     = 3306 ;
        $this->db_username = DB_USER ;
        $this->db_password = DB_PASS ;

        $this->last_query = null ;

        if( $this->db_host=='' ||
            $this->db_port=='' ||
            $this->db_username=='' ||
            $this->db_password=='' ) {
            $this->error_handling( 'database initialization', ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, false ) ;
            // TODO call destructor?
            return null ;
        }

        if( $connect ) {
            $this->connect() ;
        }
    }


    function connect() {
        if( $this->db_link==null ) {
            $this->last_query = 'database connection' ;
            $this->db_link = mysqli_connect( $this->db_host, $this->db_username, $this->db_password, '', $this->db_port ) or $this->error_handling( 'database connection', ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, false ) ;
        }
    }


    function close() {
        if( $this->db_link!=null ) {
            $this->last_query = 'database connection closing' ;
            mysqli_close( $this->db_link ) or $this->error_handling( 'database connection closing', ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, false ) ;
            $this->db_link = null ;
        }
    }


    function prepared_query( $query, $parameters, $error_reference, $handling_level=1, $lethal=false ) {
        $result = null ;
        $this->last_query = $query ;
        if( substr_count($query, "?")!=count($parameters) ) {
            $this->error_handling( $error_reference . " - parameter count" , ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, $lethal ) ;
            return false ;
        }

        $close_when_done = false ;
        if( $this->db_link==null ) {
            $this->connect() ;
            $close_when_done = true ;
        }

        if( !($stmt = $this->db_link->prepare($query)) ) {
            //echo "Prepare failed: (" . $mysqli->errno . ") " . $mysqli->error;
            $this->error_handling( $error_reference . " - prepare" , ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, $lethal ) ;
            $this->close() ;
            return false ;
        } else {
            if( count($parameters)>0 ) {
                $param_types = "" ;
                $params = array() ;
                foreach( $parameters as &$parameter ) {
                    switch( gettype($parameter) ) {
                        case 'integer':
                            $param_types .= "i" ;
                            $params[] = &$parameter ;
                            break ;
                        case 'double':
                        case 'float':
                            $param_types .= "d" ;
                            $params[] = &$parameter ;
                            break ;
                        case 'string':
                            $param_types .= "s" ;
                            $params[] = &$parameter ;
                            break ;
                        default:
                            $param_types .= "b" ;
                            $params[] = &$parameter ;
                            break ;
                    }
                }
                $params = array_merge( array($param_types), $params ) ;
                //echo "<pre>" ; print_r( $params ) ; echo "</pre>" ;
                if( !call_user_func_array( array($stmt, "bind_param"), $params) ) {
                    $this->error_handling( $error_reference . " - bind" , ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, $lethal ) ;
                    $stmt->close() ;
                    $this->close() ;
                    return false ;
                }
            }
            if( !($result = $stmt->execute()) ) {
                $this->error_handling( $error_reference . " - execute" , ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, $lethal ) ;
                $stmt->close() ;
                $this->close() ;
                return false ;
            } else {
                $result = $stmt->get_result() ;
            }
            //if( !$stmt->bind_param("i", $id) ) {
        }

        if( $close_when_done ) {
            $this->close() ;
        }

        // handling
        if( $this->last_query_success===false ) {
            return null ;
        } else {
            $this->last_query_success = true ;
            if( $handling_level==0 ) {
                return $result ;
            } else {
                $to_return = $this->handle_type( $result, $handling_level ) ;
                mysqli_free_result( $result ) ;
                return $to_return ;
            }
        }
    }


    function get_insert_id() {
        return mysqli_insert_id( $this->db_link ) ;
    }


    function __destruct() {
        $this->close() ;
        unset( $this->db_host,
               $this->db_port,
               $this->db_username,
               $this->db_password,
               $this->db_link,
               $this->last_query,
               $this->last_query_success ) ;
    }



    /**
    * @desc error_handling
    * verbose_levels:
    *   1 - only displays/mails the reference
    *   2 - displays/mails the reference & the mysqli_error
    *   3 - displays/mails the reference, mysqli_error, query & other things.
    */
    function error_handling( $reference, $mail, $mail_verbose_level, $display, $display_verbose_level, $lethal ) {
        $this->last_query_success = false ;
        if( $mail ) {
            $explanation = '' ;
            switch( $mail_verbose_level ) {
                case 1:
                    $explanation .= "database error reference: $reference\n" ;
                    // require_once( "slack.php" ) ;
                    // slack_message( "db error: " . $explanation ) ;
                    break ;
                case 2:
                    $explanation .= "database error reference: $reference\nmysql_error: ".mysqli_error( $this->db_link ) ;
                    // require_once( "slack.php" ) ;
                    // slack_message( "db error: " . $explanation ) ;
                    break ;
                case 3:
                    $explanation .= "database error reference: $reference\nmysql_error: ".mysqli_error( $this->db_link )."\nquery: {$this->last_query}\ndate: ".date( "Y-m-d H:i:s" )."\nscript_name: {$_SERVER['SCRIPT_NAME']}" ;
                    // require_once( "slack.php" ) ;
                    // slack_message( "db error: " . $explanation ) ;
                    break ;
                default:
                    // require_once( "slack.php" ) ;
                    // slack_message( "db error: invalid verbose level passed" ) ;
                    //echo "invalid xxxx_verbose_level passed\n" ;
                    break ;
            }
        }
        if( $display ) {
            $explanation = '' ;
            switch( $display_verbose_level ) {
                case 1:
                    $explanation .= "database error reference: $reference\n" ;
                    echo "$explanation\n" ;
                    break ;
                case 2:
                    $explanation .= "database error reference: $reference\nmysql_error: ".mysqli_error( $this->db_link ) ;
                    echo "$explanation\n" ;
                    break ;
                case 3:
                    $explanation .= "database error reference: $reference\nmysql_error: ".mysqli_error( $this->db_link )."\nquery: {$this->last_query}\ndate: ".date( "Y-m-d H:i:s" )."\nserver_name: {$_SERVER['SERVER_NAME']}\nserver_ip: {$_SERVER['SERVER_ADDR']}\nscript_name: {$_SERVER['SCRIPT_NAME']}" ;
                    echo "$explanation\n" ;
                    break ;
                default:
                    // require_once( "slack.php" ) ;
                    // slack_message( "db error: invalid verbose level passed" ) ;
                    echo "invalid xxxx_verbose_level passed\n" ;
                    break ;
            }
        }

        if( $lethal ) {
            die() ;
        }
    }


    function handle_type( $result, $handling_level ) {
        switch( $handling_level ) {
            case 0:
                return $result ;
                break ;
            case 1:
                $to_return = array() ;
                while( $row = mysqli_fetch_assoc($result) ) {
                    $to_return[] = $row ;
                }
                return $to_return ;
                break ;
            case 2:
                $to_return = array() ;
                while( $row = mysqli_fetch_assoc($result) ) {
                    $to_return[] = $row ;
                }

                /* depth reducing */
                if( gettype($to_return=='array') && count($to_return)==1 ) {
                    $keys = array_keys( $to_return ) ;
                    $to_return = $to_return[$keys[0]] ;
                    if( gettype($to_return=='array') && count($to_return)==1 ) {
                        $keys = array_keys( $to_return ) ;
                        $to_return = $to_return[$keys[0]] ;
                    }
                } else if( gettype($to_return=='array') ) {
                    $allSize1 = true ;
                    for( $i=0 ; $i<count($to_return) ; $i++ ) {
                        if( gettype($to_return[$i])!='array' ) {
                            $allSize1 = false ;
                            break ;
                        } else if( count($to_return[$i])!=1 ) {
                            $allSize1 = false ;
                            break ;
                        }
                    }
                    if( $allSize1 ) {
                        for( $i=0 ; $i<count($to_return) ; $i++ ) {
                            $id = array_keys( $to_return[$i] ) ;
                            $id = $id[0] ;
                            $to_return[$i] = $to_return[$i][$id] ;
                        }
                    }
                }
                if( gettype($to_return)=='array' && count($to_return)==0 ) {
                    $to_return=null ;
                }
                return $to_return ;
                break ;
            case 3:
                /* getting info about the fields */
                $fieldTypes = array() ;
                for( $i=0 ; $i<mysqli_num_fields($result) ; $i++ ) {
                    $fieldInfo = mysqli_fetch_field_direct($result, $i) ;
                    $type = $fieldInfo->type ;
                    if( $type==MYSQLI_TYPE_DECIMAL ||
                        $type==MYSQLI_TYPE_NEWDECIMAL ||
                        $type==MYSQLI_TYPE_TINY ||
                        $type==MYSQLI_TYPE_SHORT ||
                        $type==MYSQLI_TYPE_LONG ||
                        $type==MYSQLI_TYPE_TIMESTAMP ||
                        $type==MYSQLI_TYPE_LONGLONG ||
                        $type==MYSQLI_TYPE_INT24 ) {
                        $type = 'integer' ;
                    } else if( $type==MYSQLI_TYPE_FLOAT ||
                               $type==MYSQLI_TYPE_DOUBLE ) {
                        $type = 'float' ;
                    } else if( $type==MYSQLI_TYPE_DATE ||
                               $type==MYSQLI_TYPE_TIME ||
                               $type==MYSQLI_TYPE_DATETIME ||
                               $type==MYSQLI_TYPE_YEAR ||
                               $type==MYSQLI_TYPE_YEAR ||
                               $type==MYSQLI_TYPE_NEWDATE ||
                               $type==MYSQLI_TYPE_SET ||
                               $type==MYSQLI_TYPE_TINY_BLOB ||
                               $type==MYSQLI_TYPE_MEDIUM_BLOB ||
                               $type==MYSQLI_TYPE_LONG_BLOB ||
                               $type==MYSQLI_TYPE_BLOB ||
                               $type==MYSQLI_TYPE_VAR_STRING ) {
                        $type = 'string' ;
                    } else if( $type==MYSQLI_TYPE_ENUM ||
                               $type==MYSQLI_TYPE_STRING ) {
                        $isBoolean = true ;
                        while( $row = mysqli_fetch_array($result, MYSQLI_NUM) ) {
                            if( !(strtolower($row[$i])=='true' || strtolower($row[$i])=='false' || $row[$i]=='') ) {
                                $isBoolean = false ;
                                break ;
                            }
                        }
                        mysqli_data_seek( $result, 0) ;
                        if( $isBoolean ) {
                            $type = 'boolean' ;
                        } else {
                            $type = 'string' ;
                        }
                    } else {
                        $this->error_handling( 'g3309jk3r', ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, false ) ;
                        $type = 'string' ;
                    }
                    $fieldTypes[$i] = $type ;
                }

                $to_return = array() ;
                while( $row = mysqli_fetch_assoc($result) ) {
                    $temp = array() ;
                    $i = 0 ;
                    foreach( $row as $key=>$value ) {
                        if( $fieldTypes[$i]=='boolean' ) {
                            if( strtolower($value)=='true' ) {
                                $value = true ;
                            } else if( strtolower($value)=='false' || $value=='' ) {
                                $value = false ;
                            } else {
                                $this->error_handling( 'kj318nb985', ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, false ) ;
                                $value = false ;
                            }
                        }
                        settype( $value, $fieldTypes[$i] ) ;
                        $temp[$key] = $value ;
                        $i++ ;
                    }
                    $to_return[] = $temp ;
                }

                /* depth reducing */
                if( gettype($to_return=='array') && count($to_return)==1 ) {
                    $keys = array_keys( $to_return ) ;
                    $to_return = $to_return[$keys[0]] ;
                    if( gettype($to_return=='array') && count($to_return)==1 ) {
                        $keys = array_keys( $to_return ) ;
                        $to_return = $to_return[$keys[0]] ;
                    }
                } else if( gettype($to_return=='array') ) {
                    $allSize1 = true ;
                    for( $i=0 ; $i<count($to_return) ; $i++ ) {
                        if( gettype($to_return[$i])!='array' ) {
                            $allSize1 = false ;
                            break ;
                        } else if( count($to_return[$i])!=1 ) {
                            $allSize1 = false ;
                            break ;
                        }
                    }
                    if( $allSize1 ) {
                        for( $i=0 ; $i<count($to_return) ; $i++ ) {
                            $id = array_keys( $to_return[$i] ) ;
                            $id = $id[0] ;
                            $to_return[$i] = $to_return[$i][$id] ;
                        }
                    }
                }
                if( gettype($to_return)=='array' && count($to_return)==0 ) {
                    $to_return=null ;
                }
                return $to_return ;
                break ;
            default:
                $this->error_handling( 'oiut28nv278', ERROR_MAIL_TRIGGER, DB_ERROR_MAIL_VERBOSE_LEVEL, DB_ERROR_DISPLAY, DB_ERROR_DISPLAY_VERBOSE_LEVEL, false ) ;
                return null ;
                break ;
        }
    }
}

?>
