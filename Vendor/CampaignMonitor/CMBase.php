<?php
/**
* LICENSE
* -------------------
* Copyright (c) 2007-2008, Kaiser Shahid <knitcore@yahoo.com>
* All rights reserved.
*
* This software is licensed under the BSD License:
*
* Redistribution and use in source and binary forms, with or without
* modification, are permitted provided that the following conditions are met:
*     * Redistributions of source code must retain the above copyright
*       notice, this list of conditions and the following disclaimer.
*     * Redistributions in binary form must reproduce the above copyright
*       notice, this list of conditions and the following disclaimer in the
*       documentation and/or other materials provided with the distribution.
*     * Neither the name of Kaiser Shahid or Campaign Monitor nor the
*       names of its contributors may be used to endorse or promote products
*       derived from this software without specific prior written permission.
*
* THIS SOFTWARE IS PROVIDED BY Kaiser Shahid "AS IS" AND ANY
* EXPRESS OR IMPLIED WARRANTIES, INCLUDING, BUT NOT LIMITED TO, THE IMPLIED
* WARRANTIES OF MERCHANTABILITY AND FITNESS FOR A PARTICULAR PURPOSE ARE
* DISCLAIMED. IN NO EVENT SHALL Kaiser Shahid BE LIABLE FOR ANY
* DIRECT, INDIRECT, INCIDENTAL, SPECIAL, EXEMPLARY, OR CONSEQUENTIAL DAMAGES
* (INCLUDING, BUT NOT LIMITED TO, PROCUREMENT OF SUBSTITUTE GOODS OR SERVICES;
* LOSS OF USE, DATA, OR PROFITS; OR BUSINESS INTERRUPTION) HOWEVER CAUSED AND
* ON ANY THEORY OF LIABILITY, WHETHER IN CONTRACT, STRICT LIABILITY, OR TORT
* (INCLUDING NEGLIGENCE OR OTHERWISE) ARISING IN ANY WAY OUT OF THE USE OF THIS
* SOFTWARE, EVEN IF ADVISED OF THE POSSIBILITY OF SUCH DAMAGE.
*
* @package CampaignMonitorLib
*/

/**
* This is an all-inclusive package for interfacing with Campaign Monitor's services. It
* supports SOAP, GET, and POST seamlessly (just set the $method property to 'soap', 'get', 
* or 'post' before making a call) and always returns the same view of data regardless of
* the method used to call the service.
*
* See README for more information on usage and details.
*
* CHANGES: 2008-04-28
* -------------------
* - Now compatible with PHP4. Biggest changes include removing reliance on
*   enhanced OOP syntax, and using XML Parser functions instead of SimpleXML.
* - Base class (CMBase) branches into CampaignMonitor and MailBuild
* - CMBase contains all the shared API calls (and extended functionality 
*   related to those) between both classes.
*
* @package CampaignMonitorLib
* @subpackage CMBase
* @version 1.0
* @author Kaiser Shahid <knitcore@yahoo.com> (www.qaiser.net)
* @copyright 2007-2008
* @see http://www.campaignmonitor.com/api/
*/

define( 'PHPVER', phpversion() );

// WARNING: this is needed to keep the socket from apparently hanging (even when it should be done reading)
// NOTE: using a timeout (SOCKET_TIMEOUT) that's passed when calling fsockopen. safer thing to do.
//ini_set( 'default_socket_timeout', 1 );
define( 'SOCKET_TIMEOUT', 1 );

class CMBase
{
	var /*@ protected */
		$api = ''
		, $campaign_id = 0
		, $client_id = 0
		, $list_id = 0
	;
	
	var /*@ public */
		$method = 'get'
		, $url = ''
		, $soapAction = ''
		, $curl = true
		, $curlExists = true
	;
	
	// debugging options
	var /*@ public */
		$debug_level = 0
		, $debug_request = ''
		, $debug_response = ''
		, $debug_url = ''
		, $debug_info = array()
		, $show_response_headers = 0
	;
	
	/**
	* @param string $api Your API key.
	* @param string $client The default ClientId you're going to work with.
	* @param string $campaign The default CampaignId you're going to work with.
	* @param string $list The default ListId you're going to work with.
	* @param string $method Determines request type. Values are either get, post, or soap.
	*/
	
	function CMBase( $api = null, $client = null, $campaign = null, $list = null, $method = 'get' )
	{
		$this->api = $api;
		$this->client_id = $client;
		$this->campaign_id = $campaign;
		$this->list_id = $list;
		$this->method = $method;
		
		$this->curlExists = function_exists( 'curl_init' ) && function_exists( 'curl_setopt' );
	}
	
	/**
	* The direct way to make an API call. This allows developers to include new API
	* methods that might not yet have a wrapper method as part of the package.
	*
	* @param string $action The API call.
	* @param array $options An associative array of values to send as part of the request.
	* @return array The parsed XML of the request.
	*/
	
	function makeCall( $action = '', $options = array() )
	{
		// NEW [2008-06-24]: switch to soap automatically for these calls
		$old_method = $this->method;
		if ( $action == 'Subscriber.AddWithCustomFields' || $action == 'Subscriber.AddAndResubscribeWithCustomFields' || $action == 'Campaign.Create')
			$this->method = 'soap';
		
		if ( !$action ) return null;
		$url = $this->url;
		
		// DONE: like facebook's client, allow for get/post through the file wrappers
		// if curl isn't available. (or maybe have curl-emulating functions defined 
		// at the bottom of this script.)
		
		//$ch = curl_init();
		if ( !isset( $options['header'] ) )
			$options['header'] = array();
		
		$options['header'][] = 'User-Agent: CMBase URL Handler 1.5';
		
		$postdata = '';
		$method = 'GET';
		
		if ( $this->method == 'soap' )
		{
			$options['header'][] = 'Content-Type: text/xml; charset=utf-8';
			$options['header'][] = 'SOAPAction: "' . $this->soapAction . $action . '"';
			
			$postdata = "<?xml version=\"1.0\" encoding=\"utf-8\"?>\n";
			$postdata .= "<soap:Envelope xmlns:xsi=\"http://www.w3.org/2001/XMLSchema-instance\"";
			$postdata .= " xmlns:xsd=\"http://www.w3.org/2001/XMLSchema\"";
			$postdata .= " xmlns:soap=\"http://schemas.xmlsoap.org/soap/envelope/\">\n";
			$postdata .= "<soap:Body>\n";
			$postdata .= "	<{$action} xmlns=\"{$this->soapAction}\">\n";
			$postdata .= "		<ApiKey>{$this->api}</ApiKey>\n";
			
			if ( isset( $options['params'] ) )
				$postdata .= array2xml( $options['params'], "\t\t" );
			
			$postdata .= "	</{$action}>\n";
			$postdata .= "</soap:Body>\n";
			$postdata .= "</soap:Envelope>";
			
			$method = 'POST';
			
			//curl_setopt( $ch, CURLOPT_POST, 1 );
			//curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
		}
		else
		{
			$postdata = "ApiKey={$this->api}";
			$url .= "/{$action}";
			
			// NOTE: since this is GET, the assumption is that params is a set of simple key-value pairs.
			if ( isset( $options['params'] ) )
			{
				foreach ( $options['params'] as $k => $v )
					$postdata .= '&' . $k . '=' .rawurlencode(utf8_encode($v));
			}
			
			if ( $this->method == 'get' )
			{
				$url .= '?' . $postdata;
				$postdata = '';
			}
			else
			{
 				$options['header'][] = 'Content-Type: application/x-www-form-urlencoded';
				$method = 'POST';
				//curl_setopt( $ch, CURLOPT_POST, 1 );
				//curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			}
		}
					
		$res = '';
		
		// WARNING: using fopen() does not recognize stream contexts in PHP 4.x, so
		// my guess is using fopen() in PHP 4.x implies that POST is not supported
		// (otherwise, how do you tell fopen() to use POST?). tried fsockopen(), but
		// response time was terrible. if someone has more experience with working
		// directly with streams, please troubleshoot that.
		// NOTE: fsockopen() needs a small timeout to force the socket to close.
		// it's defined in SOCKET_TIMEOUT. 
		
		// preferred method is curl, only if it exists and $this->curl is true.
		if ( $this->curl && $this->curlExists )
		{
			$ch = curl_init();
			if ( $this->method != 'get' )
			{
				curl_setopt( $ch, CURLOPT_POST, 1 );
				curl_setopt( $ch, CURLOPT_POSTFIELDS, $postdata );
			}
			
			curl_setopt( $ch, CURLOPT_URL, $url );
			curl_setopt( $ch, CURLOPT_RETURNTRANSFER, 1 );
			curl_setopt( $ch, CURLOPT_HTTPHEADER, $options['header'] );
			curl_setopt( $ch, CURLOPT_HEADER, $this->show_response_headers );
			
			// except for the response, all other information will be stored when debugging is on.
			$res = curl_exec( $ch );
			if ( $this->debug_level )
			{
				$this->debug_url = $url;
				$this->debug_request = $postdata;
				$this->debug_info = curl_getinfo( $ch );
				$this->debug_info['headers_sent'] = $options['header'];
			}
			$this->debug_response = $res;
			curl_close( $ch );
		}
		else
		{
			// 'header' is actually the entire HTTP payload. as such, you need
			// Content-Length header, otherwise you'll get errors returned/emitted.
			
			$postLen = strlen( $postdata );
			$ctx = array(
				'method' => $method
				, 'header' => implode( "\n", $options['header'] ) 
					. "\nContent-Length: " . $postLen
					. "\n\n" . $postdata
			);
			
			if ( $this->debug_level )
			{
				$this->debug_url = $url;
				$this->debug_request = $postdata;
				$this->debug_info['overview'] = 'Used stream_context_create()/fopen() to make request. Content length=' . $postLen;
				$this->debug_info['headers_sent'] = $options['header'];
				//$this->debug_info['complete_content'] = $ctx;
			}
			
			$pv = PHPVER;
			
			// the preferred non-cURL way if user is using PHP 5.x
			if ( $pv{0} == '5' )
			{
				$context = stream_context_create( array( 'http' => $ctx ) );
				$fp = fopen( $url, 'r', false, $context );
				ob_start();
				fpassthru( $fp );
				fclose( $fp );
				$res = ob_get_clean();
			}
			else
			{
				// this should work with PHP 4, but it seems to take forever to get data back this way
				// NOTE: setting the default_socket_timeout seems to alleviate this issue [finally].
				list( $protocol, $url ) = explode( '//', $url, 2 );
				list( $domain, $path ) = explode( '/', $url, 2 );
				$fp = fsockopen( $domain, 80, $tvar, $tvar2, SOCKET_TIMEOUT );
			
				if ( $fp )
				{
					$payload = "$method /$path HTTP/1.1\n"
					 	. "Host: $domain\n"
						. $ctx['header']
					;
					fwrite( $fp, $payload );
				
					// even with the socket timeout set, using fgets() isn't playing nice, but
					// fpassthru() seems to be doing the right thing.
				
					ob_start();
					fpassthru( $fp );
					list( $headers, $res ) = explode( "\r\n\r\n", ob_get_clean(), 2 );
				
					if ( $this->debug_level )
						$this->debug_info['headers_received'] = $headers;
				
					fclose( $fp );
				}
				elseif ( $this->debug_level )
					$this->debug_info['overview'] .= "\nOpening $domain/$path failed!";
			}
		}
		
		//$this->method = $old_method;
		
		if ( $res )
		{
			if ( $this->method == 'soap' )
			{
				$tmp = xml2array( $res, '/soap:Envelope/soap:Body' );
				if ( !is_array( $tmp ) )
					return $tmp;
				else
					return $tmp[$action.'Response'][$action.'Result'];
			}
			else
			{
				return xml2array( $res );
			}
		}
		else
			return null;
	}
	
	/**
	* Wrapper for Subscribers.GetActive. This method triples as Subscribers.GetUnsubscribed 
	* and Subscribers.GetBounced when the very last parameter is overridden.
	*
	* @param mixed $date If a string, should be in the date() format of 'Y-m-d H:i:s', otherwise, a Unix timestamp.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param string $action (Optional) Set the actual API method to call. Defaults to Subscribers.GeActive if no other valid value is given.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetActive.aspx
	*/
	
	function subscribersGetActive( $date  = 0, $list_id = null, $action = 'Subscribers.GetActive' )
	{
		if ( !$list_id )
			$list_id = $this->list_id;
		
		if ( is_numeric( $date ) )
			$date = date( 'Y-m-d H:i:s', $date );
		
		$valid_actions = array( 'Subscribers.GetActive' => '', 'Subscribers.GetUnsubscribed' => '', 'Subscribers.GetBounced' => '' );
		if ( !isset( $valid_actions[$action] ) )
			$action = 'Subscribers.GetActive';
		
		return $this->makeCall( $action
			, array( 
				'params' => array( 
					'ListID' => $list_id 
					, 'Date' => $date
				)
			)
		);
	}
	
	/**
	* @param mixed $date If a string, should be in the date() format of 'Y-m-d H:i:s', otherwise, a Unix timestamp.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetUnsubscribed.aspx
	*/
	
	function subscribersGetUnsubscribed( $date  = 0, $list_id = null )
	{
		return $this->subscribersGetActive( $date, $list_id, 'Subscribers.GetUnsubscribed' );
	}
	
	/**
	* @param mixed $date If a string, should be in the date() format of 'Y-m-d H:i:s', otherwise, a Unix timestamp.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetBounced.aspx
	*/
	
	function subscribersGetBounced( $date  = 0, $list_id = null )
	{
		return $this->subscribersGetActive( $date, $list_id, 'Subscribers.GetBounced' );
	}
	
	/**
	* subscriberAdd()
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $resubscribe If true, does an equivalent 'AndResubscribe' API method.
	* @see http://www.campaignmonitor.com/api/Subscriber.Add.aspx
	*/
	
	function subscriberAdd( $email, $name, $list_id = null, $resubscribe = false )
	{
		if ( !$list_id )
			$list_id = $this->list_id;
		
		$action = 'Subscriber.Add';
		if ( $resubscribe ) $action = 'Subscriber.AddAndResubscribe';
		
		return $this->makeCall( $action
			, array(
				'params' => array(
					'ListID' => $list_id
					, 'Email' => $email
					, 'Name' => $name
				)
			)
		);
	}
	
	/**
	* This encapsulates the check of whether this particular user unsubscribed once.
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	*/
	
	function subscriberAddRedundant( $email, $name, $list_id = null )
	{
		$added = $this->subscriberAdd( $email, $name, $list_id );        
	        
		if ( $added && $added['Result']['Code'] == '204' )
		{
			$subscribed = $this->subscribersGetIsSubscribed( $email, $list_id );    
	    
			// Must have unsubscribed, so resubscribe
			if ( $subscribed['anyType'] == 'False' )
			{
				// since we're internal, we'll just call the method with full parameters rather
				// than go through a secondary wrapper function.
				$added = $this->subscriberAdd( $email, $name, $list_id, true );
				return $added;
			}
		}
		
		return $added;
	}
	
	/**
	* @param string $email Email address.
	* @param string $name User's name.
	* @param mixed $fields Should be a $key => $value mapping. If there are more than one items for $key, let
	*        $value be a list of scalar values. Example: array( 'Interests' => array( 'xbox', 'wii' ) )
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $resubscribe If true, does an equivalent 'AndResubscribe' API method.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscriber.AddWithCustomFields.aspx
	*/
	
	function subscriberAddWithCustomFields( $email, $name, $fields, $list_id = null, $resubscribe = false )
	{
		if ( !$list_id )
			$list_id = $this->list_id;
		
		$action = 'Subscriber.AddWithCustomFields';
		if ( $resubscribe ) $action = 'Subscriber.AddAndResubscribeWithCustomFields';
		
		if ( !is_array( $fields ) )
			$fields = array();
		
		$_fields = array( 'SubscriberCustomField' => array() );
		foreach ( $fields as $k => $v )
		{
			if ( is_array( $v ) )
			{
				foreach ( $v as $nv )
					$_fields['SubscriberCustomField'][] = array( 'Key' => $k, 'Value' => $nv );
			}
			else
				$_fields['SubscriberCustomField'][] = array( 'Key' => $k, 'Value' => $v );
		}
		return $this->makeCall( $action
			, array(
				'params' => array(
					'ListID' => $list_id
					, 'Email' => $email
					, 'Name' => $name
					, 'CustomFields' => $_fields
				)
			)
		);
	}
	
	/**
	* Same as subscriberAddRedundant() except with CustomFields.
	*
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	*/
	
	function subscriberAddWithCustomFieldsRedundant( $email, $name, $fields, $list_id = null )
	{
		$added = $this->subscriberAddWithCustomFields( $email, $name, $fields, $list_id );
		if ( $added && $added['Code'] == '0' )
		{
			$subscribed = $this->subscribersGetIsSubscribed( $email );
			if ( $subscribed == 'False' )
			{
				$added = $this->subscriberAddWithCustomFields( $email, $name, $fields, $list_id, true );
				return $added;
			}
		}
		
		return $added;
	}
	
	/**
	* @param string $email Email address.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $check_subscribed If true, does the Subscribers.GetIsSubscribed API method instead.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscriber.Unsubscribe.aspx
	*/
	
	function subscriberUnsubscribe( $email, $list_id = null, $check_subscribed = false )
	{
		if ( !$list_id )
			$list_id = $this->list_id;
		
		$action = 'Subscriber.Unsubscribe';
		if ( $check_subscribed ) $action = 'Subscribers.GetIsSubscribed';
		
		return $this->makeCall( $action
			, array(
				'params' => array(
					'ListID' => $list_id
					, 'Email' => $email
				)
			)
		);
	}
	
	/**
	* @return string A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscribers.GetIsSubscribed.aspx
	*/
	
	function subscribersGetIsSubscribed( $email, $list_id = null )
	{
		return $this->subscriberUnsubscribe( $email, $list_id, true );
	}
	
	/**
	* Given an array of lists, indicate whether the $email is subscribed to each of those lists.
	*
	* @param string $email User's email
	* @param mixed $lists An associative array of lists to check against. Each key should be a List ID
	* @param boolean $no_assoc If true, only returns an array where each value indicates that the user is subscribed
	*        to that particular list. Otherwise, returns a fully associative array of $list_id => true | false.
	* @return mixed An array corresponding to $lists where true means the user is subscribed to that particular list.
	*/
	
	function checkSubscriptions( $email, $lists, $no_assoc = true )
	{
		$nlist = array();
		foreach ( $lists as $lid => $misc )
		{
			$val = $this->subscribersGetIsSubscribed( $email, $lid );
			$val = $val != 'False';
			if ( $no_assoc && $val ) $nlist[] = $lid;
			elseif ( !$no_assoc ) $nlist[$lid] = $val;
		}
		
		return $nlist;
	}
}

/**
* XMLArrayInstance, xml2array() and array2xml() will be used to convert data
* between XML and Array. We're using a class because that is the only way we can
* safely use xml_parse() and other related functions (the alternative is to use
* $GLOBALS to hold data).
* 
* XMLArrayInstance can be initialized to start processing from a specific path
* in the XML. In the case of Campaign Monitor, we want to start from 
* '/soap:Envelope/soap:Body', which is the default 2nd parameter for xml2array().
*
* @package CampaignMonitorLib
* @subpackage CMBase
*/

class XMLArrayInstance
{
	var $root = '/';
	var $rootStart = 0;
	var $stack = array();
	var $stack_path = '';
	var $stack_sz = 0;
	var $array = array();
	var $arrCurrent = null;
	var $inRoot = false;
	var $counter = 0;
	
	var $stack_count = array();
	var $stack_prefix = '/';
	var $data_buffer = array();
	
	/**
	* @param string $root An absolute path that points to where XML data
	*        should start being converted to an array. Do not add an ending '/'.
	*/
	
	function XMLArrayInstance( $root = null )
	{
		if ( $root != null && $root != '/' )
		{
			$this->root = $root;
			$this->rootStart = substr_count( $root, '/' );
			$this->stack_prefix = $root . '/';
		}
	}
	
	// function default( $parser, $data ) {}
	
	// function startNS( $parser, $userdata, $prefix, $uri ) {}
	
	function start( $parser, $ele, $atts )
	{
		$this->stack[] = $ele;
		$this->stack_path = '/' . implode( '/', $this->stack );
		$this->stack_sz++;
		
		if ( !isset( $this->stack_count[$this->stack_path] ) )
			$this->stack_count[$this->stack_path] = -1;
		
		$this->stack_count[$this->stack_path]++;
		$this->data_buffer[$this->stack_path] = '';
	}
	
	function data( $parser, $data )
	{
		$data = trim( $data );
		
		// if $data is empty or we're not within start path, skip
		if ( (strpos( $this->stack_path, $this->root ) !== 0) || $data == '' )
			return;
		
		// delay inserting the data, because the parser handles entities as a
		// separate call. that meant that before buffering data, a simple text node
		// would be broken up into multiple parts because of the entities.
		$this->data_buffer[$this->stack_path] .= $data;
	}
	
	// function ddefault( $parser, $data ) { }
	
	function end( $parser, $ele )
	{
		if ( $this->data_buffer[$this->stack_path] != '' )
			$this->setArrValue( $this->data_buffer[$this->stack_path] );
		
		array_pop( $this->stack );
		$this->stack_path = '/' . implode( '/', $this->stack );
		$this->stack_sz--;
	}
	
	function setArrValue( $data )
	{
		// iterate through $arr to find the right element and populate it.
		// if the element doesn't exist, set it as a scalar. otherwise, 
		// convert it to an array first and then append the value.
		
		$ptr = & $this->array;
		
		// the depth to insert the value at
		$depth = $this->stack_sz - 1;
		$sep = '';
		$partial = '';
		
		for ( $i = $this->rootStart; $i < $this->stack_sz; $i++ )
		{
			$key = $this->stack[$i];
			$partial .= $sep . $key;
			$sep = '/';
			$_c = $this->stack_count[$this->stack_prefix . $partial];
			
			//echo "$key : [{$this->stack_prefix}]$partial\n";
			
			// this current path doesn't exist. either we're going deeper
			// into the XML (and the paths haven't been created yet), or the
			// node we're on hasn't been created.
			
			if ( !isset( $ptr[$key] ) )
			{
				if ( $i == $depth )
					$ptr[$key] = $data;
				else
					$ptr[$key] = array();
				
				$ptr = & $ptr[$key];
			}
			elseif ( $i < $depth )
			{
				// if the absolute path of this node has more than one occurrence, $_c > 0.
				// if that's the case, then, we need to test if the node is currently a hash
				// or a list. if it's a hash, convert it to a list. then, check that the list
				// item exists (create it if it doesn't).
				
				// if it's just a single occurrence, set the pointer to the hash.
				
				if ( $_c > 0 )
				{
					if ( !isset( $ptr[$key][0] ) )
					{
						$tmp = $ptr[$key];
						$ptr[$key] = array( $tmp );
					}
					
					if ( !isset( $ptr[$key][$_c] ) )
						$ptr[$key][$_c] = array();
					
					$ptr = & $ptr[$key][$_c];
				}
				else
					$ptr = & $ptr[$key];
			}
			elseif ( $i == $depth )
			{
				// the current node exists. but, since it's not an array, we need
				// to convert it first.
				
				if ( !is_array( $ptr[$key] ) )
				{
					$tmp = $ptr[$key];
					$ptr[$key] = array( $tmp );
				}
				elseif ( !isset( $ptr[$key][0] ) )
				{
					$tmp = $ptr[$key];
					$ptr[$key] = array( $tmp );
				}
				
				$ptr[$key][] = $data;
				$ptr = & $ptr[$key];
			}
		}
	}
	
	// function endNS( $parser, $userdata, $prefix ) { }
	
	//function externalRef( $parser, $op_ent_names, $base, $sys_id, $pub_id ) { }
	}





/**
* Converts an XML document to an array. Note that this only recognizes element names
* (which become keys) and text data (which become values)--attributes are not saved.
* Note also that if two or more elements exist at the same depth, the value for that 
* key becomes a list of values. For example,
*
* <code><xml><item>1</item></xml>"</code>
* is equivalent to
*
* <code>array( 'xml' => array( 'item' => '1' ) )</code>
* 
* However, 
* 
* <code><xml><item>1</item><item>2</item></xml></code>
*
* is equivalent to 
* 
* <code>array( 'xml' => array( 'item' => array( '1', '2' ) ) )</code>
*
* Lastly, a node must either contain other nodes or non-empty text, not both. So
* 
* <code><xml><item> hii there<subitem>1</subitem></item></xml></code>
*
* is incorrect, but
*
* <code><xml><item><subitem>1</subitem><text>hii there</text></item></xml></code>
*
* is correct. This won't crash the code, but it won't be put in to the array
* as you'd expect (and the inverse function array2xml() won't return the XML
* you'd expect).
*
* @param string $str The XML body.
* @param string $root (Optional) The start path within the XML body.
* @param string $charset (Optional) The character set of the XML document.
* @return array The converted XML document.
*/

function xml2array( $str, $root = '/', $charset = 'utf-8' )
{
	$xmlp = xml_parser_create( $charset );
	
	$xmlarr = new XMLArrayInstance( $root );

	xml_parser_set_option( $xmlp, XML_OPTION_CASE_FOLDING, false );
	xml_set_element_handler( $xmlp, array( & $xmlarr, 'start' ), array( & $xmlarr, 'end' ) );
	xml_set_character_data_handler( $xmlp, array( & $xmlarr, 'data' ) );
	//xml_set_default_handler( $xmlp, array( $xmlarr, 'ddefault' ) );
	
	$cur = 0;
	$len = strlen( $str );
	$c_sz = 2048;
	
	while ( $cur < $len )
	{
		$chunk = substr( $str, $cur, $c_sz );
		$cur += $c_sz;
		
		if ( !xml_parse( $xmlp, $chunk, !($cur < $len) ) )
		{
			echo "xml2array() error: Line ", xml_get_current_line_number( $xmlp ), ': ', xml_error_string( $xmlp );
			break;
		}
	}
	
	return $xmlarr->array;
}

/**
* Converts an array to XML. This is the inverse to xml2array(). Values
* are automatically escaped with htmlentities(), so you don't need to escape 
* values ahead of time. If you have, just set the third parameter to false.
* This is an all-or-nothing deal.
*
* @param mixed $arr The associative to convert to an XML fragment
* @param string $indent (Optional) Starting identation of each element
* @param string $escape (Optional) Determines whether or not to escape a text node.
* @return string An XML fragment.
*/

function array2xml( $arr, $indent = '', $escape = true )
{
	$buff = '';
	
	foreach ( $arr as $k => $v )
	{
		if ( !is_array( $v ) )
			$buff .= "$indent<$k>" . ($escape ? utf8_encode( $v ) : $v ) . "</$k>\n";
		else
		{
			/*
			Encountered a list. The primary difference between the two branches is that
			in the 'if' branch, a $k element is generated for each item in $v, whereas
			in the 'else' branch, a single $k element encapsulates $v.
			*/
			
			if ( isset( $v[0] ) )
			{
				foreach ( $v as $_k => $_v )
				{
					if ( is_array( $_v ) )
				 		$buff .= "$indent<$k>\n" . array2xml( $_v, $indent . "\t", $escape ) . "$indent</$k>\n";
					else
						$buff .= "$indent<$k>" . ($escape ? utf8_encode( $_v ) : $_v ) . "</$k>\n";
				}
			}
			else
				$buff .= "$indent<$k>\n" . array2xml( $v, $indent . "\t", $escape ) . "$indent</$k>\n";
		}
	}
	
	return $buff;
}





/**
* The new CampaignMonitor class that now extends from CMBase. This should be 
* backwards compatible with the original (PHP5) version.
* 
* If you used the static functions convertXMLToArray() and convertArrayToXML() 
* in other contexts, you'll need to replace them with xml2array() and array2xml().
* They share the same core parameters, so this should be a simple 1-to-1 mapping.
*
* Of course, you could write the convert*() static methods back into the class
* as wrappers for xml2array() and array2xml().
*
* @package CampaignMonitorLib
* @subpackage CampaignMonitor
* @version 1.5
* @author Kaiser Shahid <knitcore@yahoo.com> (www.qaiser.net)
* @copyright 2007-2008
* @see http://www.campaignmonitor.com/api/
*/

// this assumes CMBase.php is in the same directory as this file
//require_once( dirname( __FILE__ ) . '/CMBase.php' );

class CampaignMonitor extends CMBase
{
	var /*@ protected */
		$url = 'http://api.createsend.com/api/api.asmx'
		, $soapAction = 'http://api.createsend.com/api/'
	;
	
	/**
	* @param string $api Your API key.
	* @param string $client The default ClientId you're going to work with.
	* @param string $campaign The default CampaignId you're going to work with.
	* @param string $list The default ListId you're going to work with.
	* @param string $method Determines request type. Values are either get, post, or soap.
	*/
	
	function CampaignMonitor( $api = null, $client = null, $campaign = null, $list = null, $method = 'get' )
	{
		CMBase::CMBase( $api, $client, $campaign, $list, $method );
	}
	
	/**
	* @param string $email Email address.
	* @param string $name User's name.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @see http://www.campaignmonitor.com/api/Subscriber.AddAndResubscribe.aspx
	*/
	
	function subscriberAddAndResubscribe( $email, $name, $list_id = null )
	{
		return $this->subscriberAdd( $email, $name, $list_id, true );
	}
	
	/**
	* @param string $email Email address.
	* @param string $name User's name.
	* @param mixed $fields Should only be a single-dimension array of key-value pairs.
	* @param int $list_id (Optional) A valid List ID to check against. If not given, the default class property is used.
	* @param boolean $resubscribe If true, does an equivalent 'AndResubscribe' API method.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Subscriber.AddAndResubscribeWithCustomFields.aspx
	*/
	
	function subscriberAddAndResubscribeWithCustomFields( $email, $name, $fields, $list_id = null )
	{
		return $this->subscriberAddWithCustomFields( $email, $name, $fields, $list_id, true );
	}
	

	
	/*
	* A generic wrapper to feed Client.* calls.
	*
	* @param string $method The API method to call.
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	*/
	
	function clientGeneric( $method, $client_id = null )
	{
		if ( !$client_id )
			$client_id = $this->client_id;
		
		return $this->makeCall( 'Client.' . $method
			, array(
				'params' => array(
					'ClientID' => $client_id
				)
			)
		);
	}
	
	/**
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Client.GetLists.aspx
	*/
	
	function clientGetLists( $client_id = null )
	{
		return $this->clientGeneric( 'GetLists', $client_id );
	}
	
	/**
	* Creates an associative array with list_id => List_label pairings.
	*
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	*/
	
	function clientGetListsDropdown( $client_id = null )
	{
		$lists = $this->clientGetLists( $client_id );
		if ( !isset( $lists['List'] ) )
			return null;
		else
			$lists = $lists['List'];
		
		$_lists = array();
		
		if ( isset( $lists[0] ) )
		{
			foreach ( $lists as $list )
				$_lists[$list['ListID']] = $list['Name'];
		}
		else
			$_lists[$lists['ListID']] = $lists['Name'];
		
		return $_lists;
	}
	
	/**
	* Creates an associative array with list_id:List_Label => (list_id) List_label pairings.
	* Remember that you'll need to split the key on ':' only once to get the appropriate ListID
	* and Segment Name.
	*
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	*/
	
	function clientGetSegmentsDropdown( $client_id = null )
	{
		$lists = $this->clientGetSegments( $client_id );
		if ( !isset( $lists['List'] ) )
			return null;
		else
			$lists = $lists['List'];
		
		$_lists = array();
		
		if ( isset( $lists[0] ) )
		{
			foreach ( $lists as $list )
				$_lists[$list['ListID'].':'.$list['Name']] = '(' . $list['ListID'] . ') ' . $list['Name'];
		}
		else
			$_lists[$lists['ListID'].':'.$lists['Name']] = '(' . $lists['ListID'] . ') ' . $lists['Name'];
		
		return $_lists;
	}
	
	/**
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Client.GetCampaigns.aspx
	*/
	
	function clientGetCampaigns( $client_id = null )
	{
		return $this->clientGeneric( 'GetCampaigns', $client_id );
	}
	
	/**
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Client.GetSegments.aspx
	*/
	
	function clientGetSegments( $client_id = null )
	{
		return $this->clientGeneric( 'GetSegments', $client_id );
	}
	
	/**
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/client-getsuppressionlist/
	*/
	function clientGetSuppressionList( $client_id = null )
	{
		return $this->clientGeneric( 'GetSuppressionList', $client_id );
	}
	
	/**
	* @param int $client_id (Optional) A valid Client ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/client-getdetail/
	*/
	function clientGetDetail( $client_id = null )
	{
		return $this->clientGeneric( 'GetDetail', $client_id );
	}
	
	/**
	* @param string $companyName (CompanyName) Company name of the client to be added
	* @param string $contactName (ContactName) Contact name of the client to be added
	* @param string $emailAddress (EmailAddress) Email Address of the client to be added
	* @param string $country (Country) Country of the client to be added
	* @param string $timezone (Timezone) Timezone of the client to be added
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/client-create/
	*/
	function clientCreate( $companyName, $contactName, $emailAddress, $country, $timezone )
	{
		return $this->makeCall( 'Client.Create'
			, array(
				'params' => array(
					'CompanyName' => $companyName
					, 'ContactName' => $contactName
					, 'EmailAddress' => $emailAddress
					, 'Country' => $country
					, 'Timezone' => $timezone
				)
			)
		);
	}
	
	/**
	* @param int $client_id (ClientID) ID of the client to be updated
	* @param string $companyName (CompanyName) Company name of the client to be updated
	* @param string $contactName (ContactName) Contact name of the client to be updated
	* @param string $emailAddress (EmailAddress) Email Address of the client to be updated
	* @param string $country (Country) Country of the client to be updated
	* @param string $timezone (Timezone) Timezone of the client to be updated
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/client-create/
	*/
	function clientUpdateBasics( $client_id, $companyName, $contactName, $emailAddress, $country, $timezone )
	{
		return $this->makeCall( 'Client.UpdateBasics'
			, array(
				'params' => array(
					'ClientID' => $client_id
					, 'CompanyName' => $companyName
					, 'ContactName' => $contactName
					, 'EmailAddress' => $emailAddress
					, 'Country' => $country
					, 'Timezone' => $timezone
				)
			)
		);
	}
	
	/**
	* @param int $client_id (ClientID) ID of the client to be updated
	* @param string $accessLevel (AccessLevel) AccessLevel of the client
	* @param string $username (Username) Clients username
	* @param string $password (Password) Password of the client
	* @param string $billingType (BillingType) BillingType that the client will be set as
	* @param string $currency (Currency) Currency that the client will pay in
	* @param string $deliveryFee (DeliveryFee) Per campaign deliivery fee for the campaign
	* @param string $costPerRecipient (CostPerRecipient) Per email fee for the client
	* @param string $designAndSpamTestFee (DesignAndSpamTestFee) Amount the client will
	*				be charged if they have access to send design/spam tests
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/client-updateaccessandbilling/
	*/
	function clientUpdateAccessAndBilling( $client_id, $accessLevel, $username, $password, $billingType, $currency, $deliveryFee, $costPerRecipient, $designAndSpamTestFee )
	{
		return $this->makeCall( 'Client.UpdateAccessAndBilling'
			, array(
				'params' => array(
					'ClientID' => $client_id
					, 'AccessLevel' => $accessLevel
					, 'Username' => $username
					, 'Password' => $password
					, 'BillingType' => $billingType
					, 'Currency' => $currency
					, 'DeliveryFee' => $deliveryFee
					, 'CostPerRecipient' => $costPerRecipient
					, 'DesignAndSpamTestFee' => $designAndSpamTestFee
				)
			)
		);
	}
	
	
	
	/**
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/User.GetClients.aspx
	*/
	
	function userGetClients()
	{
		return $this->makeCall( 'User.GetClients' );
	}
	
	/**
	* @return string A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/User.GetSystemDate.aspx
	*/
	
	function userGetSystemDate()
	{
		return $this->makeCall( 'User.GetSystemDate' );
	}
	
	/**
	* @return string A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/user-gettimezones/
	*/
	
	function userGetTimezones()
	{
		return $this->makeCall( 'User.GetTimezones' );
	}
	
	/**
	* @return string A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/user-getcountries/
	*/
	
	function userGetCountries()
	{
		return $this->makeCall( 'User.GetCountries' );
	}
	
	/**
	* A generic wrapper to feed Campaign.* calls.
	*
	* @param string $method The API method to call.
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	*/
	
	function campaignGeneric( $method, $campaign_id = null )
	{
		if ( !$campaign_id )
			$campaign_id = $this->campaign_id;
		
		return $this->makeCall( 'Campaign.' . $method
			, array(
				'params' => array(
					'CampaignID' => $campaign_id
				)
			)
		);
	}
	
	/**
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.GetSummary.aspx
	*/
	
	function campaignGetSummary( $campaign_id = null )
	{
		return $this->campaignGeneric( 'GetSummary', $campaign_id );
	}
	
	/**
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.GetOpens.aspx
	*/
	
	function campaignGetOpens( $campaign_id = null )
	{
		return $this->campaignGeneric( 'GetOpens', $campaign_id );
	}
	
	/**
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.GetBounces.aspx
	*/
	
	function campaignGetBounces( $campaign_id = null )
	{
		return $this->campaignGeneric( 'GetBounces', $campaign_id );
	}
	
	/**
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.GetSubscriberClicks.aspx
	*/
	
	function campaignGetSubscriberClicks( $campaign_id = null )
	{
		return $this->campaignGeneric( 'GetSubscriberClicks', $campaign_id );
	}
	
	/**
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.GetUnsubscribes.aspx
	*/
	
	function campaignGetUnsubscribes( $campaign_id = null )
	{
		return $this->campaignGeneric( 'GetUnsubscribes', $campaign_id );
	}
	
	/**
	* @param int $campaign_id (Optional) A valid Campaign ID to check against. If not given, the default class property is used.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.GetLists.aspx
	*/
	
	function campaignGetLists( $campaign_id = null )
	{
		return $this->campaignGeneric( 'GetLists', $campaign_id );
	}
	
	/**
	* @param int $client_id The ClientID you wish to use; set it to null to use the default class property.
	* @param string $name (CampaignName) Name of campaign
	* @param string $subject (CampaignSubject) Subject of campaign mailing
	* @param string $fromName (FromName) The From name of the sender
	* @param string $fromEmail (FromEmail) The email of the sender
	* @param string $replyTo (ReplyTo) An alternate email to send replies to
	* @param string $htmlUrl (HtmlUrl) Location of HTML body of email
	* @param string $textUrl (TextUrl) Location of plaintext body of email
	* @param array $subscriberListIds (SubscriberListIDs) An array of ListIDs. This will automatically be converted to the right format
	* @param array $listSegments (ListSegments) An array of segment names and their corresponding ListIDs. Each element needs to
	*        be an associative array with keys ListID and Name.
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/Campaign.Create.aspx
	*/
	
	function campaignCreate( $client_id, $name, $subject, $fromName, $fromEmail, $replyTo, $htmlUrl, $textUrl, $subscriberListIds, $listSegments )
	{
		if ( $client_id == null )
			$client_id = $this->client_id;
		
		$_subListIds = '';
		if ($subscriberListIds != "")
		{
			$_subListIds = array( 'string' => array() );
			if ( is_array( $subscriberListIds ) )
			{
				foreach ( $subscriberListIds as $lid )
				{
					$_subListIds['string'] = $lid;
				}
			}
		}
		
		$_seg = '';
		if ($listSegments != "")
		{
			$_seg = array( 'List' => array() );
			if ( is_array( $listSegments ) )
			{
				foreach ( $listSegments as $seg )
					$_seg['List'][] = $seg;
			}
		}
		
		return $this->makeCall( 'Campaign.Create', array(
			'params' => array(
				'ClientID' => $client_id
				, 'CampaignName' => $name
				, 'CampaignSubject' => $subject
				, 'FromName' => $fromName
				, 'FromEmail' => $fromEmail
				, 'ReplyTo' => $replyTo
				, 'HtmlUrl' => $htmlUrl
				, 'TextUrl' => $textUrl
				, 'SubscriberListIDs' => $_subListIds
				, 'ListSegments' => $_seg
				)
			)
		);
	}
	
	/**
	* @param int $client_id The CampaignID you wish to use; set it to null to use the default class property
	* @param string $confirmEmail (ConfirmationEmail) Email address to send confirmation of campaign send to
	* @param string $sendDate (SendDate) The timestamp to send the campaign. It must be formatted as YYY-MM-DD HH:MM:SS 
	*               and should correspond to user's timezone.
	*/
	
	function campaignSend( $campaign_id, $confirmEmail, $sendDate )
	{
		if ( $campaign_id == null )
			$campaign_id = $this->campaign_id;
		
		return $this->makeCall( 'Campaign.Send', array(
			'params' => array(
				'CampaignID' => $campaign_id
				, 'ConfirmationEmail' => $confirmEmail
				, 'SendDate' => $sendDate
				)
			)
		);
	}
	
	/**
	* @param int $client_id (ClientID) ID of the client the list will be created for
	* @param string $title (Title) Name of the new list
	* @param string $unsubscribePage (UnsubscribePage) URL of the page users will be 
	*				directed to when they unsubscribe from this list.
	* @param string $confirmOptIn (ConfirmOptIn) If true, the user will be sent a confirmation
	*				email before they are added to the list. If they click the link to confirm
	*				their subscription they will be added to the list. If false, they will be
	*				added automatically.
	* @param string $confirmationSuccessPage (ConfirmationSuccessPage) URL of the page that
	*				users will be sent to if they confirm their subscription. Only required when
					$confirmOptIn is true.
	* @see http://www.campaignmonitor.com/api/method/list-create/
	*/
	function listCreate( $client_id, $title, $unsubscribePage, $confirmOptIn, $confirmationSuccessPage )
	{
		if ( $confirmOptIn == 'false' )
			$confirmationSuccessPage = '';
			
		return $this->makeCall( 'List.Create', array(
			'params' => array(
				'ClientID' => $client_id
				, 'Title' => $title
				, 'UnsubscribePage' => $unsubscribePage
				, 'ConfirmOptIn' => $confirmOptIn
				, 'ConfirmationSuccessPage' => $confirmationSuccessPage
				)
			)
		);
	}
	
	/**
	* @param int $list_id (List) ID of the list to be updated
	* @param string $title (Title) Name of the new list
	* @param string $unsubscribePage (UnsubscribePage) URL of the page users will be 
	*				directed to when they unsubscribe from this list.
	* @param string $confirmOptIn (ConfirmOptIn) If true, the user will be sent a confirmation
	*				email before they are added to the list. If they click the link to confirm
	*				their subscription they will be added to the list. If false, they will be
	*				added automatically.
	* @param string $confirmationSuccessPage (ConfirmationSuccessPage) URL of the page that
	*				users will be sent to if they confirm their subscription. Only required when
					$confirmOptIn is true.
	* @see http://www.campaignmonitor.com/api/method/list-update/
	*/
	function listUpdate( $list_id, $title, $unsubscribePage, $confirmOptIn, $confirmationSuccessPage )
	{
		if ( $confirmOptIn == 'false' )
			$confirmationSuccessPage = '';
			
		return $this->makeCall( 'List.Update', array(
			'params' => array(
				'ListID' => $list_id
				, 'Title' => $title
				, 'UnsubscribePage' => $unsubscribePage
				, 'ConfirmOptIn' => $confirmOptIn
				, 'ConfirmationSuccessPage' => $confirmationSuccessPage
				)
			)
		);
	}
	
	/**
	* @param int $list_id (List) ID of the list to be deleted
	* @see http://www.campaignmonitor.com/api/method/list-delete/
	*/
	function listDelete( $list_id )
	{			
		return $this->makeCall( 'List.Delete', array(
			'params' => array(
				'ListID' => $list_id
				)
			)
		);
	}
	
	/**
	* @param int $list_id (List) ID of the list to be deleted
	* @see http://www.campaignmonitor.com/api/method/list-getdetail/
	*/
	function listGetDetail( $list_id )
	{			
		return $this->makeCall( 'List.GetDetail', array(
			'params' => array(
				'ListID' => $list_id
				)
			)
		);
	}
	
	/**
	* @param int $list_id (ListID) A valid list ID to check against. 
	* @param string $fieldName (FieldName) Name of the new custom field
	* @param string $dataType (DataType) Data type of the field. Options are Text, Number, 
	*				MultiSelectOne, or MultiSelectMany
	* @param string $Options (Options) The available options for a multi-valued custom field. 
	*				Options should be separated by a double pipe “||”. This field must be null 
	*				for Text and Number custom fields
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/list-createcustomfield/
	*/
	
	function listCreateCustomField( $list_id, $fieldName, $dataType, $options )
	{
		if ( $dataType == 'Text' || $dataType == 'Number' )
			$options = null;
			
		return $this->makeCall( 'List.CreateCustomField', array(
			'params' => array(
				'ListID' => $list_id
				, 'FieldName' => $fieldName
				, 'DataType' => $dataType
				, 'Options' => $options
				)
			)
		);
	}
	
	/**
	* @param int $list_id (ListID) A valid list ID to check against. 
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/list-getcustomfields/
	*/
	
	function listGetCustomFields( $list_id )
	{			
		return $this->makeCall( 'List.GetCustomFields', array(
			'params' => array(
				'ListID' => $list_id
				)
			)
		);
	}
	
	/**
	* @param int $list_id (ListID) A valid list ID to check against. 
	* @param int $key (Key) The Key of the field we want to delete. 
	* @return mixed A parsed response from the server, or null if something failed.
	* @see http://www.campaignmonitor.com/api/method/list-deletecustomfield/
	*/
	
	function listDeleteCustomField( $list_id, $key )
	{		
		return $this->makeCall( 'List.DeleteCustomField', array(
			'params' => array(
				'ListID' => $list_id
				, 'Key' => $key
				)
			)
		);
	}
}