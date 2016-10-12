<?php

define( "MSG_TIMEOUT", ZM_WEB_AJAX_TIMEOUT );
define( "MSG_DATA_SIZE", 4+256 );

if ( !($_REQUEST['connkey'] && $_REQUEST['command']) )
{
    ajaxError( "Unexpected received message type '$type'" );
}

if ( !($socket = @socket_create( AF_UNIX, SOCK_DGRAM, 0 )) )
{
    ajaxError( "socket_create() failed: ".socket_strerror(socket_last_error()) );
}
$locSockFile = ZM_PATH_SOCKS.'/zms-'.sprintf("%06d",$_REQUEST['connkey']).'w.sock';
if ( !@socket_bind( $socket, $locSockFile ) )
{
    ajaxError( "socket_bind( $locSockFile ) failed: ".socket_strerror(socket_last_error()) );
}

switch ( $_REQUEST['command'] )
{
    case CMD_VARPLAY :
        Debug( "Varplaying to ".$_REQUEST['rate'] );
        $msg = pack( "lcn", MSG_CMD, $_REQUEST['command'], $_REQUEST['rate']+32768 );
        break;
    case CMD_ZOOMIN :
        Debug( "Zooming to ".$_REQUEST['x'].",".$_REQUEST['y'] );
        $msg = pack( "lcnn", MSG_CMD, $_REQUEST['command'], $_REQUEST['x'], $_REQUEST['y'] );
        break;
    case CMD_PAN :
        Debug( "Panning to ".$_REQUEST['x'].",".$_REQUEST['y'] );
        $msg = pack( "lcnn", MSG_CMD, $_REQUEST['command'], $_REQUEST['x'], $_REQUEST['y'] );
        break;
    case CMD_SCALE :
        Debug( "Scaling to ".$_REQUEST['scale'] );
        $msg = pack( "lcn", MSG_CMD, $_REQUEST['command'], $_REQUEST['scale'] );
        break;
    case CMD_SEEK :
        Debug( "Seeking to ".$_REQUEST['offset'] );
        $msg = pack( "lcN", MSG_CMD, $_REQUEST['command'], $_REQUEST['offset'] );
        break;
    default :
        $msg = pack( "lc", MSG_CMD, $_REQUEST['command'] );
        break;
}

$remSockFile = ZM_PATH_SOCKS.'/zms-'.sprintf("%06d",$_REQUEST['connkey']).'s.sock';
$max_socket_tries = 10;
while ( !file_exists($remSockFile) && $max_socket_tries-- ) //sometimes we are too fast for our own good, if it hasn't been setup yet give it a second.
	usleep(200000);

if ( !@socket_sendto( $socket, $msg, strlen($msg), 0, $remSockFile ) )
{
    ajaxError( "socket_sendto( $remSockFile ) failed: ".socket_strerror(socket_last_error()) );
}

$rSockets = array( $socket );
$wSockets = NULL;
$eSockets = NULL;
$numSockets = @socket_select( $rSockets, $wSockets, $eSockets, intval(MSG_TIMEOUT/1000), (MSG_TIMEOUT%1000)*1000 );

if ( $numSockets === false )
{
    ajaxError( "socket_select failed: ".socket_strerror(socket_last_error()) );
}
else if ( $numSockets < 0 )
{
    ajaxError( "Socket closed $remSockFile"  );
}
else if ( $numSockets == 0 )
{
    ajaxError( "Timed out waiting for msg $remSockFile"  );
}
else if ( $numSockets > 0 )
{
    if ( count($rSockets) != 1 )
        ajaxError( "Bogus return from select, ".count($rSockets)." sockets available" );
}

switch( $nbytes = @socket_recvfrom( $socket, $msg, MSG_DATA_SIZE, 0, $remSockFile ) )
{
    case -1 :
    {
        ajaxError( "socket_recvfrom( $remSockFile ) failed: ".socket_strerror(socket_last_error()) );
        break;
    }
    case 0 :
    {
        ajaxError( "No data to read from socket" );
        break;
    }
    default :
    {
        if ( $nbytes != MSG_DATA_SIZE )
            ajaxError( "Got unexpected message size, got $nbytes, expected ".MSG_DATA_SIZE );
        break;
    }
}

$data = unpack( "ltype", $msg );
switch ( $data['type'] )
{
    case MSG_DATA_WATCH :
    {
        $data =  unpack( "ltype/imonitor/istate/dfps/ilevel/irate/ddelay/izoom/Cdelayed/Cpaused/Cenabled/Cforced", $msg );
        $data['fps'] = sprintf( "%.2f", $data['fps'] );
        $data['rate'] /= RATE_BASE;
        $data['delay'] = round( $data['delay'], 2 );
        $data['zoom'] = round( $data['zoom']/SCALE_BASE, 1 );
        if ( ZM_OPT_USE_AUTH && ZM_AUTH_RELAY == "hashed" ) {
          $time = time();
          // Regenerate auth hash after half the lifetime of the hash
          if ( $_SESSION['AuthHashGeneratedAt'] < $time - (ZM_AUTH_HASH_TTL * 1800) ) {
            $data['auth'] = generateAuthHash( ZM_AUTH_HASH_IPS );
          } 
        }
        ajaxResponse( array( 'status'=>$data ) );
        break;
    }
    case MSG_DATA_EVENT :
    {
        $data =  unpack( "ltype/ievent/iprogress/irate/izoom/Cpaused", $msg );
        //$data['progress'] = sprintf( "%.2f", $data['progress'] );
        $data['rate'] /= RATE_BASE;
        $data['zoom'] = round( $data['zoom']/SCALE_BASE, 1 );
        if ( ZM_OPT_USE_AUTH && ZM_AUTH_RELAY == "hashed" ) {
          $time = time();
          // Regenerate auth hash after half the lifetime of the hash
          if ( $_SESSION['AuthHashGeneratedAt'] < $time - (ZM_AUTH_HASH_TTL * 1800) ) {
            $data['auth'] = generateAuthHash( ZM_AUTH_HASH_IPS );
          } 
        }
        ajaxResponse( array( 'status'=>$data ) );
        break;
    }
    default :
    {
        ajaxError( "Unexpected received message type '$type'" );
    }
}

ajaxError( 'Unrecognised action or insufficient permissions' );

function ajaxCleanup()
{
    global $socket, $locSockFile;
    if ( !empty( $socket ) )
        @socket_close( $socket );
    if ( !empty( $locSockFile ) )
        @unlink( $locSockFile );
}
?>
