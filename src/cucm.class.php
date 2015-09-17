<?php

/**
 * php5-callmanager-axl/cucm.class.php
 *
 * This class connects to Cisco Call Manager via the AXL SOAP/XML interface
 *
 * PHP version 5
 *
 * This library is free software; you can redistribute it and/or
 * modify it under the terms of the GNU Lesser General Public
 * License as published by the Free Software Foundation; either
 * version 3.0 of the License, or (at your option) any later version.
 * This library is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the GNU
 * Lesser General Public License for more details.
 * You should have received a copy of the GNU Lesser General Public
 * License along with this library; if not, write to the Free Software
 * Foundation, Inc., 51 Franklin Street, Fifth Floor, Boston, MA  02110-1301  USA
 *
 * @category  default
 * @package   none
 * @author    John Lavoie, Travis Riesenberg
 * @copyright 2015 @authors
 * @license   http://www.gnu.org/copyleft/lesser.html The GNU LESSER GENERAL PUBLIC LICENSE, Version 3.0
 */

namespace CallmanagerAXL;

class cucm
{
	// PHP XML SOAP Client library instance connected to one specific callmanager
    public $SOAPCLIENT;
	// Running array of SOAP calls made during this session
    public $SOAPCALLS;

	// Constructor sets up our soap client with WSDL to talk to Call Manager
    public function __construct(
                                $URL,
                                $SCHEMA,
                                $USERNAME = CALLMGR_USER,
                                $PASSWORD = CALLMGR_PASS
                                )
    {
        $OPTIONS = [
                    "trace"				=> true,
                    "exceptions"	    => true,
                    "connection_timeout"=> 10,
                    "location"			=> $URL,
                    "login"				=> $USERNAME,
                    "password"			=> $PASSWORD,
                   ];
        $this->SOAPCLIENT	= new SoapClient($SCHEMA,$OPTIONS);
        $this->SOAPCALLS = array();
    }

	// Keep track of our soap calls for performance and debugging
	private function log_soap_call($CALL,$TIME,$QUERY,$REPLY)
	{
		array_push($this->SOAPCALLS,
					[
						"call"	=> $CALL,
						"time"	=> $TIME,
						"query"	=> $QUERY,
						"reply"	=> $REPLY,
					]
			);
		return count($this->SOAPCALLS);
	}

	// This converts an object returned by a soap reply from StdClass to associative array
	public function object_to_assoc($OBJECT)
	{
		return json_decode( json_encode( $OBJECT ) , true );
	}

	// This decodes soap reply objects and ensures the format is correct
	// Every response will be an array of the responses, even if its just one response
    public function decode_soap_reply($SOAPREPLY)
    {
		if ( !is_object($SOAPREPLY) ) {
			throw new \Exception("SOAP reply is not an object");
		}
		if ( !property_exists($SOAPREPLY,"return") ) {
			throw new \Exception("SOAP reply does not have the property return");
		}
		$RETURN = reset(get_object_vars($SOAPREPLY->return));
		if ( is_object($RETURN) ) {
			// Single objects mean we recieved exactly one element in the reply
			$RETURN = array( $this->object_to_assoc($RETURN) );
		}else{
			// Otherwise we recieved multiple elements in the reply.
			$RETURN = $this->object_to_assoc($RETURN);
		}
		return $RETURN;
    }

	// This converts an array of key=>value pairs into a flat array of one of the keys values
	public function assoc_key_values_to_array($ASSOC,$AKEY, $STOPONERROR = true)
	{
		$RETURN = array();
		// Loop through the array of key=>value pairs
		foreach ($ASSOC as $KEY => $VALUE)
		{
			if ( isset($VALUE[$AKEY]) && $VALUE[$AKEY] ) {
				array_push($RETURN,$VALUE[$AKEY]);
			}else if ($STOPONERROR) {
				throw new \Exception("Assoc array value does not have key {$KEY}");
			}
		}
		return $RETURN;
	}

	// This builds a searchCriteria & returnedTags array pair for soap requests against CUCM AXL
	public function axl_search_return_array($SEARCH, $RETURN)
	{
		return array(
                    "searchCriteria"=> $SEARCH,
                    "returnedTags"	=> $RETURN,
                    );
	}

	// Get a complete list of the names of all phones
    public function get_phone_names()
    {
		$SEARCH = $this->axl_search_return_array( ["devicePoolName" => "%"] , ["name" => ""] );
		// Search the CUCM for all phones
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->listPhone( $SEARCH );
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("listPhone",$DIFFTIME,$SEARCH,$RETURN);
		// Decode the reply into an array of results
		$RETURN = $this->decode_soap_reply($RETURN);
		// Turn the associative arrays into a single simensional array list
		$RETURN = $this->assoc_key_values_to_array($RETURN,"name");
		return $RETURN;
	}

	// Get all the information regarding one specific phone by name
    public function get_phone_by_name($NAME)
    {
		$SEARCH = [ "name" => $NAME ];
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->getPhone( $SEARCH );
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("getPhone",$DIFFTIME,$SEARCH,$RETURN);
		// Decode the reply into an array of results
		$RETURN = $this->decode_soap_reply($RETURN);
		// Count the number of replies we recieved...
		$COUNT = count($RETURN);
		// It should be EXACTLY one result
		if ( $COUNT !== 1 ) {
			throw new \Exception("Search returned {$COUNT} results, not exactly 1 as expected");
		}
		// Strip off the outer array
		$RETURN = reset($RETURN);
		return $RETURN;
    }

	// Get an array of directory numbers by phone name
    public function get_directory_numbers_by_name($NAME)
    {
		// Get our phone by name from the previous function
        $PHONE = $this->get_phone_by_name($NAME);
		if ( !isset($PHONE["lines"]) ) {
			throw new \Exception("Phone record does not contain the lines element");
		}
		if ( !is_array($PHONE["lines"]) ) {
			throw new \Exception("Phone record lines element is not an array");
		}
		// Suck out the array of phone numbers
        $RETURN = reset($PHONE["lines"]);
		// Turn them into a flat array of DIRNs
		$RETURN = $this->assoc_key_values_to_array($RETURN,"dirn");
		// Turn the DIRNs into patterns of phone numbers
		$RETURN = $this->assoc_key_values_to_array($RETURN,"pattern");
		return $RETURN;
    }

	// Get an array of every device pool name
    public function get_device_pool_names()
    {
		$SEARCH = $this->axl_search_return_array( ["name" => "%"] , ["name" => ""] );
		// Search the CUCM for all device pools
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->listDevicePool( $SEARCH );
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("listDevicePool",$DIFFTIME,$SEARCH,$RETURN);
		// Count our soap call
		$this->CALLS++;
		// Decode the reply into an array of results
		$RETURN = $this->decode_soap_reply($RETURN);
		// Turn the associative arrays into a single simensional array list
		$RETURN = $this->assoc_key_values_to_array($RETURN,"name");
		return $RETURN;
	}

	// Get an array of site names
    public function get_site_names()
    {
		// Get the list of device pools
        $DEVICEPOOLS = $this->get_device_pool_names();
		$SITES = array();
		// Loop through all of the device pools
        foreach ($DEVICEPOOLS as $DP)
		{
			// Detect the DP_SITECODE format and put it into an array
			$REGEX = "/^DP_(\w+)/";
			if ( preg_match( $REGEX, $DP, $HITS ) )
			{
				array_push($SITES,$HITS[1]);
			}else{
				//print "{$DP} did not match {$REGEX}!<br>\n";
			}
		}
		// Return our array of sites
		return $SITES;
    }

	public function get_srst_routers_by_site($SITE)
	{
		$SEARCH = $this->axl_search_return_array( ["name" => "SRST_{$SITE}%"],
												  ["name" => ""] );
		// Search the CUCM for matching SRST devices
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->listSrst( $SEARCH ); // listSrst
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("listSrst",$DIFFTIME,$SEARCH,$RETURN);
		// Decode the reply into an array of results
		$RETURN = $this->decode_soap_reply($RETURN);
		// Turn the associative arrays into a single simensional array list
		$RETURN = $this->assoc_key_values_to_array($RETURN,"name");
        return $RETURN;
	}

	public function get_srst_router_by_name($NAME)
	{
		$SEARCH = [ "name" => $NAME ];
		// Search the CUCM for matching SRST devices
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->getSrst( $SEARCH ); // getSrst
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("getSrst",$DIFFTIME,$SEARCH,$RETURN);
		// Decode the reply into an array of results
		$RETURN = $this->decode_soap_reply($RETURN);
        return $RETURN;
	}

	// This builds a $type array for soap add requests against CUCM AXL
	public function axl_add_query_array($TYPE, $DATA)
	{
		return array(
					$TYPE => $DATA
					);
	}

	// Handle adding a new SRST router to a site with specific IPv4 address
	public function add_srst_router($SITE,$IP)
	{
		// Do all of our input validation
		if ( !$SITE ) {
			// And we need to add more checks to make sure the site code is a valid CORPORATE site code
			throw new \Exception("Site name provided {$SITE} is not valid");
		}
		if ( !filter_var($IP, FILTER_VALIDATE_IP) ) {
			throw new \Exception("IP address provided {$IP} is not valid");
		}
		$QUERY = [ "name" => "SRST_{$SITE}", "ipAddress" => $IP, "port" => 2000, "SipPort" => 5060 ];
		$QUERY = $this->axl_add_query_array("srst", $QUERY);
		// Add our new SRST router
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->addSrst( $QUERY ); // addSrst
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("addSrst",$DIFFTIME,$QUERY,$RETURN);
		return $RETURN;
	}

	// Handle removing a named SRST router
	public function delete_srst_router($NAME)
	{
		$SRST = $this->get_srst_router_by_name($NAME);
		$SRST = reset($SRST);
		print "Found SRST router to remove:\n"; dumper($SRST);
		// TODO: Make sure this is a valid SRST router? Do some other checks?

		$QUERY = [ "name" => $NAME ];
		// Remove our SRST router
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->removeSrst( $QUERY ); // removeSrst
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("removeSrst",$DIFFTIME,$QUERY,$RETURN);
		return $RETURN;
	}

	// Handle updating a named SRST router
	public function update_srst_router($NAME,$DATA)
	{
		$SRST = $this->get_srst_router_by_name($NAME);
		$SRST = reset($SRST);
		// TODO: Make sure this is a valid SRST router? Do some other checks?
		print "Found SRST router to update:\n"; dumper($SRST);
		// Force the query to use our name as search criteria
		$QUERY = array( "name" => $NAME );
		// Loop through SRST keys and see if the value passed has changed
		foreach ($SRST as $KEY => $VALUE)
		{
			// Make sure the SRST router key val pair is also defined in the new data passed
			if ( isset($DATA[$KEY]) ) {
				// Check if the value of the new data has changed from the original
				if ( $DATA[$KEY] != $VALUE ) {
					// Build our update query of different values
					$QUERY[$KEY] = $DATA[$KEY];
				}
			}
		}
		// Update our SRST router
		$BASETIME = Utility::microtime_ticks();
		$RETURN = $this->SOAPCLIENT->updateSrst( $QUERY ); // updateSrst
		$DIFFTIME = Utility::microtime_ticks() - $BASETIME;
		// log our soap call
		$this->log_soap_call("updateSrst",$DIFFTIME,$QUERY,$RETURN);
		return $RETURN;
	}

}
