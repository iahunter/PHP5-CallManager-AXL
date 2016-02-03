<?php

/**
 * src/callmanager.php.
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
 *
 * @author    John Lavoie, Travis Riesenberg
 * @copyright 2015 @authors
 * @license   http://www.gnu.org/copyleft/lesser.html The GNU LESSER GENERAL PUBLIC LICENSE, Version 3.0
 */

namespace CallmanagerAXL;

class Callmanager
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
                                ) {
        $OPTIONS = [
                    'trace'                => true,
                    'exceptions'           => true,
                    'connection_timeout'   => 10,
                    'location'             => $URL,
                    'login'                => $USERNAME,
                    'password'             => $PASSWORD,
                   ];
        $this->SOAPCLIENT = new \SoapClient($SCHEMA, $OPTIONS);
        $this->SOAPCALLS = [];
    }

    // Keep track of our soap calls for performance and debugging

    private function log_soap_call($CALL, $TIME, $QUERY, $REPLY)
    {
        array_push($this->SOAPCALLS,
                    [
                        'call'     => $CALL,
                        'time'     => $TIME,
                        'query'    => $QUERY,
                        'reply'    => $REPLY,
                    ]
            );

        return count($this->SOAPCALLS);
    }

    // This converts an object returned by a soap reply from StdClass to associative array

    public function object_to_assoc($OBJECT)
    {
        return json_decode(json_encode($OBJECT), true);
    }

    // This decodes soap reply objects and ensures the format is correct
    // Every response will be an array of the responses, even if its just one response

    public function decode_soap_reply($SOAPREPLY)
    {
        if (!is_object($SOAPREPLY)) {
            throw new \Exception('SOAP reply is not an object');
        }
        if (!property_exists($SOAPREPLY, 'return')) {
            throw new \Exception('SOAP reply does not have the property return');
        }
        $RETURN = reset(get_object_vars($SOAPREPLY->return));
        if (is_object($RETURN)) {
            // Single objects mean we recieved exactly one element in the reply
            $RETURN = [$this->object_to_assoc($RETURN)];
        } else {
            // Otherwise we recieved multiple elements in the reply.
            $RETURN = $this->object_to_assoc($RETURN);
        }

        return $RETURN;
    }

    // This converts an array of key=>value pairs into a flat array of one of the keys values

    public function assoc_key_values_to_array($ASSOC, $AKEY, $STOPONERROR = true)
    {
        $RETURN = [];
        // Loop through the array of key=>value pairs
        foreach ($ASSOC as $KEY => $VALUE) {
            if (isset($VALUE[$AKEY]) && $VALUE[$AKEY] !== '') {
                if (isset($VALUE['uuid']) && $VALUE['uuid']) {
                    // If the query returns a UUID, use that as our array key!
                    $RETURN[$VALUE['uuid']] = $VALUE[$AKEY];
                } else {
                    // If the query does NOT return a UUID, use sequencial keys
                    array_push($RETURN, $VALUE[$AKEY]);
                }
            } elseif ($STOPONERROR) {
                throw new \Exception("Assoc array value does not have key {$KEY}");
            }
        }

        return $RETURN;
    }

    // This builds a searchCriteria & returnedTags array pair for soap requests against CUCM AXL

    public function axl_search_return_array($SEARCH, $RETURN)
    {
        return [
                    'searchCriteria'  => $SEARCH,
                    'returnedTags'    => $RETURN,
                    ];
    }

    // Get a complete list of the names of all phones

    public function get_phone_names()
    {
        $SEARCH = $this->axl_search_return_array(['devicePoolName' => '%'], ['name' => '']);
        // Search the CUCM for all phones
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->listPhone($SEARCH);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        // log our soap call
        $this->log_soap_call('listPhone', $DIFFTIME, $SEARCH, $RETURN);
        // Decode the reply into an array of results
        $RETURN = $this->decode_soap_reply($RETURN);
        // Turn the associative arrays into a single simensional array list
        $RETURN = $this->assoc_key_values_to_array($RETURN, 'name');

        return $RETURN;
    }

    // Get all the information regarding one specific phone by name

    public function get_phone_by_name($NAME)
    {
        $SEARCH = ['name' => $NAME];
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->getPhone($SEARCH);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        // log our soap call
        $this->log_soap_call('getPhone', $DIFFTIME, $SEARCH, $RETURN);
        // Decode the reply into an array of results
        $RETURN = $this->decode_soap_reply($RETURN);
        // Count the number of replies we recieved...
        $COUNT = count($RETURN);
        // It should be EXACTLY one result
        if ($COUNT !== 1) {
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
        if (!isset($PHONE['lines'])) {
            throw new \Exception('Phone record does not contain the lines element');
        }
        if (!is_array($PHONE['lines'])) {
            throw new \Exception('Phone record lines element is not an array');
        }
        // Suck out the array of phone numbers
        $RETURN = reset($PHONE['lines']);
        // Turn them into a flat array of DIRNs
        $RETURN = $this->assoc_key_values_to_array($RETURN, 'dirn');
        // Turn the DIRNs into patterns of phone numbers
        $RETURN = $this->assoc_key_values_to_array($RETURN, 'pattern');

        return $RETURN;
    }

    // Get an array of every device pool name

    public function get_device_pool_names()
    {
        $SEARCH = $this->axl_search_return_array(['name' => '%'], ['name' => '']);
        // Search the CUCM for all device pools
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->listDevicePool($SEARCH);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        // log our soap call
        $this->log_soap_call('listDevicePool', $DIFFTIME, $SEARCH, $RETURN);
        // Count our soap call
        $this->CALLS++;
        // Decode the reply into an array of results
        $RETURN = $this->decode_soap_reply($RETURN);
        // Turn the associative arrays into a single simensional array list
        $RETURN = $this->assoc_key_values_to_array($RETURN, 'name');

        return $RETURN;
    }

    // Manage the list of types valid for our generalized dosomething_objecttypexyz_bysomething($1,$2)

    public function object_types()
    {
        // Valid object types this function works for
        $TYPES = [	'DevicePool',
                    'Srst',
                    'RoutePartition',
                    'Css',
                    'Location',
                    'Region',
                    'CallManagerGroup',
                    'DevicePool',
                    'ConferenceBridge',
                    'Mtp',
                    'MediaResourceGroup',
                    'MediaResourceList',
                    'H323Gateway',
                    'RouteGroup',
                    'TransPattern',
                    'DateTimeGroup',
                    'Phone',
					'Line',
                ];

        return $TYPES;
    }

    // Get an array of site names

    public function get_site_names()
    {
        // Get the list of device pools
        $DEVICEPOOLS = $this->get_device_pool_names();
        $SITES = [];
        // Loop through all of the device pools
        foreach ($DEVICEPOOLS as $DP) {
            // Detect the DP_SITECODE format and put it into an array
            $REGEX = "/^DP_(\w+)/";
            if (preg_match($REGEX, $DP, $HITS)) {
                array_push($SITES, $HITS[1]);
            } else {
                //print "{$DP} did not match {$REGEX}!<br>\n";
            }
        }
        // Return our array of sites
        return $SITES;
    }

    // LIST STUFF IN SITES

    // Generalized function to return any type of object using the list/search functionality for a site

    public function get_object_type_by_site($SITE, $TYPE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();

        // Check to see if the one we were passed is valid for this function
        if (!in_array($TYPE, $TYPES)) {
            throw new \Exception("Object type provided {$TYPE} is not supported");
        }

		// Lines is a special case due to highly nested indirect nature of its search selector relationships
		if($TYPE == 'Line') { return $this->get_lines_by_site($SITE); }
        //$TYPES = array_diff($TYPES, ['Line']);

        //	This is the default search and return criteria for MOST object types. There are a few exceptions
        $FIND = ['name' => "%{$SITE}%"];
        $RETR = ['name' => ''];
        // Phone search uses a different search name field
        if ($TYPE == 'Phone') {
            $FIND = ['devicePoolName' => "%{$SITE}%"];
        // H323 Gateway search uses a different search name field
        } elseif ($TYPE == 'H323Gateway') {
            $FIND = ['devicePoolName' => "%{$SITE}%"];
        // So does translation pattern search and returns a different field
        } elseif ($TYPE == 'TransPattern') {
            $FIND = ['routePartitionName' => "%{$SITE}%"];
            $RETR = ['pattern' => ''];
        }
        $SEARCH = $this->axl_search_return_array($FIND, $RETR);
        $FUNCTION = 'list'.$TYPE;
        // Search the CUCM for matching SRST devices
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->$FUNCTION($SEARCH);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        // log our soap call
        $this->log_soap_call($FUNCTION, $DIFFTIME, $SEARCH, $RETURN);
        // Decode the reply into an array of results
        $RETURN = $this->decode_soap_reply($RETURN);
        // Turn the associative arrays into a single simensional array list
        $RETURN = $this->assoc_key_values_to_array($RETURN, reset(array_keys($RETR)));

        return $RETURN;
    }

	// List lines by site is indirect and dumb. phones are identified by device pool name of DP_SITE, lines are identified by owning phones.
	// This function will need to get every phone in a device pool and then build a list of every LINE that is returned.
	public function get_lines_by_site($SITE)
	{
		// Get all the phone object uuid=>names for a given site (based on device pool)
		$PHONES = $this->get_object_type_by_site($SITE, 'Phone');
		$LINES = [];
		// Loop through our phones at a site and use the UUID to get its detailed object information
		foreach($PHONES as $UUID => $NAME) {
			$PHONE = $this->get_object_type_by_uuid($UUID, 'Phone');
			// if there are lines in the phone go get the uuid and name for each line dirn
			if( isset($PHONE['lines']) && is_array($PHONE['lines']) && count($PHONE['lines']) ) {
				// loop through each line on the phone and suck out the uuid and dial pattern
				foreach($PHONE['lines'] as $PHONELINE) {
					// If the phone has a dirn element with elements
					if( isset($PHONELINE['dirn']) && is_array($PHONELINE['dirn']) && count($PHONELINE['dirn']) ) {
						// And the dirn has a uuid and pattern with value
						if( isset($PHONELINE['dirn']['pattern']) && $PHONELINE['dirn']['pattern'] &&	isset($PHONELINE['dirn']['uuid']) && $PHONELINE['dirn']['uuid']	) {
							// Save this line to the list of site lines we return
							$LINES[$PHONELINE['dirn']['uuid']] = $PHONELINE['dirn']['pattern'];
						}
					}
				}
			}
		}
		return $LINES;
	}

    // This returns an associative array for each of the above types

    public function get_all_object_types_by_site($SITE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();

        $RETURN = [];
        foreach ($TYPES as $TYPE) {
            $RETURN[$TYPE] = $this->get_object_type_by_site($SITE, $TYPE);
        }

        return $RETURN;
    }

    public function get_all_object_type_details_by_site($SITE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();

        $RETURN = [];
        foreach ($TYPES as $TYPE) {
            try {
                $RETURN[$TYPE] = $this->get_object_type_by_site($SITE, $TYPE);
                foreach ($RETURN[$TYPE] as $INDEX => $NAME) {
                    unset($RETURN[$TYPE][$INDEX]);
                    $RETURN[$TYPE][$INDEX] = $this->get_object_type_by_uuid($INDEX, $TYPE);
                }
            } catch (\Exception $E) {
                // If we encounter a specific error getting one TYPE of thing, continue on to the NEXT type of thing
                $RETURN[$TYPE] = [];
            }
        }

        return $RETURN;
    }

    // GET DETAILED STUFF

    public function get_object_type_by_name($NAME, $TYPE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();
        // TransPattern is not valid for get-item-by-NAME, must use UUID or a combination of name and routepartitionname
        $TYPES = array_diff($TYPES, ['TransPattern']);
		// Lines is not valid for get-item-by-name, must be UUID
        $TYPES = array_diff($TYPES, ['Line']);
        // Check to see if the one we were passed is valid for this function
        if (!in_array($TYPE, $TYPES)) {
            throw new \Exception("Object type provided {$TYPE} is not supported");
        }

        $QUERY = ['name' => $NAME];
        $FUNCTION = 'get'.$TYPE;
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->$FUNCTION($QUERY);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        $this->log_soap_call($FUNCTION, $DIFFTIME, $QUERY, $RETURN);
        $RETURN = $this->decode_soap_reply($RETURN);
        $RETURN = reset($RETURN);

        return $RETURN;
    }

    public function get_object_type_by_uuid($UUID, $TYPE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();
        // Check to see if the one we were passed is valid for this function
        if (!in_array($TYPE, $TYPES)) {
            throw new \Exception("Object type provided {$TYPE} is not supported");
        }

        $QUERY = ['uuid' => $UUID];
        $FUNCTION = 'get'.$TYPE;
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->$FUNCTION($QUERY);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        $this->log_soap_call($FUNCTION, $DIFFTIME, $QUERY, $RETURN);
        $RETURN = $this->decode_soap_reply($RETURN);
        $RETURN = reset($RETURN);

        return $RETURN;
    }

    // DELETE STUFF

    // Only way I want to support removing items is by UUID, this is for safety because object names may not be unique

    public function delete_object_type_by_uuid($UUID, $TYPE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();
        // Check to see if the one we were passed is valid for this function
        if (!in_array($TYPE, $TYPES)) {
            throw new \Exception("Object type provided {$TYPE} is not supported");
        }

        $QUERY = ['uuid' => $UUID];
        $FUNCTION = 'remove'.$TYPE;
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->$FUNCTION($QUERY);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        $this->log_soap_call($FUNCTION, $DIFFTIME, $QUERY, $RETURN);

        return $RETURN;
    }

    public function delete_all_object_types_by_site($SITE)
    {
        // This works - but do not call it!
/*		throw new \Exception("DO NOT CALL THIS FUNCTION");
        return;
/**/
        // The order of this list is critical to successfully remove all the objects in a given site...
        $ORDER = [	'TransPattern',
                    'updateDevicePool',
                    'RouteGroup',
                    'H323Gateway',
                    'MediaResourceList',
                    'MediaResourceGroup',
                    'Mtp',
                    'ConferenceBridge',
                    'DevicePool',
                    'CallManagerGroup',
                    'Region',
                    'Location',
                    'Css',
                    'RoutePartition',
                    'Srst',
                    ];
        $OBJECTS = $this->get_all_object_types_by_site($SITE);
        foreach ($ORDER as $STEP) {
            // This step is special
            if ($STEP == 'updateDevicePool') {
                // Go through all the device pools
                foreach ($OBJECTS['DevicePool'] as $UUID => $DP) {
                    // Pull the device pool out of the database - do i even need to do this?
                    //$DP = $this->get_object_type_by_uuid($UUID,'DevicePool');
                    // Build a query to blank out the mediaResourceListName and localRouteGroup['value'] properties
                    $QUERY = ['uuid' => $UUID];
                    $QUERY['mediaResourceListName'] = '';
                    $QUERY['localRouteGroup'] = ['name' => 'Standard Local Route Group', 'value' => ''];
                    $BASETIME = \Utility::microtime_ticks();
                    // Remove references to objects we plan to delete shortly from this
                    $RETURN = $this->SOAPCLIENT->updateDevicePool($QUERY);
                    $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
                    $this->log_soap_call('updateSrst', $DIFFTIME, $QUERY, $RETURN);
                    // Now we can continue deleting the other object types
                }
            } else {
                foreach ($OBJECTS[$STEP] as $UUID => $NAME) {
                    print "Attempting to delete object type {$STEP} name {$NAME} UUID {$UUID}\n";
                    try {
                        $this->delete_object_type_by_uuid($UUID, $STEP);
                    } catch (\Exception $E) {
                        print "Error deleteing object! {$E->getmessage()}\n";
                    }
                }
            }
        }
    }

    // ADD STUFF

    // This generalized add function expects $DATA to be correct for $TYPE objects

    public function add_object_type_by_assoc($DATA, $TYPE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();
        // Check to see if the one we were passed is valid for this function
        if (!in_array($TYPE, $TYPES)) {
            throw new \Exception("Object type provided {$TYPE} is not supported");
        }

        // Only the FIRST letter in the type needs to be lower case
        // so we cant do $TYPE = strtolower($TYPE);
        $TYPE = lcfirst($TYPE);
        $QUERY = [$TYPE => $DATA];
        //dumper($QUERY);
        $FUNCTION = 'add'.$TYPE;
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->$FUNCTION($QUERY);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        $this->log_soap_call($FUNCTION, $DIFFTIME, $QUERY, $RETURN);
        $RETURN = $this->object_to_assoc($RETURN);
        $RETURN = reset($RETURN);

        return $RETURN;
    }

    // UPDATE STUFF

    // This generalized update function expects $DATA to be correct for $TYPE objects

    public function update_object_type_by_assoc($DATA, $TYPE)
    {
        // Get our valid object types
        $TYPES = $this->object_types();
        // Check to see if the one we were passed is valid for this function
        if (!in_array($TYPE, $TYPES)) {
            throw new \Exception("Object type provided {$TYPE} is not supported");
        }
        // There may be a case where the name is not actually called name
        $NAMEFIELD = 'name';
        if (!isset($DATA[$NAMEFIELD]) || !$DATA[$NAMEFIELD]) {
            throw new \Exception('Data does not contain a valid name to update');
        }
        $NAME = $DATA[$NAMEFIELD];
        // Get their object information out of the database
        $OBJECT = $this->get_object_type_by_name($NAME, $TYPE);
        //print "DUMP OF OBJECT WE FOUND TO EDIT:\n"; dumper($OBJECT);

        // TODO: Make sure this is a valid object? Do some other checks?

        // Force the query to use our name as search criteria
        $QUERY = [$NAMEFIELD => $NAME];
        // Loop through object keys and see if the value passed has changed
        foreach ($OBJECT as $KEY => $VALUE) {
            // Make sure the object key val pair is defined in the new data passed
            // AND check if the value of the new data has changed from the original
            if (isset($DATA[$KEY]) && $DATA[$KEY] != $VALUE) {
                // Build our update query of different values
                $QUERY[$KEY] = $DATA[$KEY];
            }
        }
        //print "QUERY CALCULATED ON OBJECT TO UPDATE:\n"; dumper($QUERY);
        // Update our object
        $FUNCTION = 'update'.$TYPE;
        $BASETIME = \Utility::microtime_ticks();
        $RETURN = $this->SOAPCLIENT->$FUNCTION($QUERY);
        $DIFFTIME = \Utility::microtime_ticks() - $BASETIME;
        $this->log_soap_call($FUNCTION, $DIFFTIME, $QUERY, $RETURN);
        $RETURN = $this->object_to_assoc($RETURN);
        $RETURN = reset($RETURN);

        return $RETURN;
    }
}
