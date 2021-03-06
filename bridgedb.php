<?php


/**
 * Simple proxy to support remote bridgedb web service calls
 * from javascript.
 */
ini_set( "error_reporting", 0 );

$m = [];
$wpiBridgeURL = 'http://webservice.bridgedb.org/';
$url = $wpiBridgeURL;
preg_match( '#bridgedb.php/?(.*)#', $_SERVER['REQUEST_URI'], $m );
if ( isset( $m[1] ) && $m[1] ) {
	$url = $wpiBridgeURL . $m[1];
	header( 'Content-type: text/plain' );
} else {
	header( 'Content-type: text/html' );
}

$handle = fopen( $url, "r" );

if ( $handle ) {
	while ( !feof( $handle ) ) {
		$buffer = fgets( $handle, 4096 );
		echo $buffer;
	}
	fclose( $handle );
} else {
	header( 'HTTP/1.1 500 Internal Server Error', true, 500 );
	echo( "Error getting data from " . $url );
}
