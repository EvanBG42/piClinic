<?php
/*
 *	Copyright (c) 2018, Robert B. Watson
 *
 *	This file is part of the piClinic Console.
 *
 *  piClinic Console is free software: you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation, either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  piClinic Console is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  You should have received a copy of the GNU General Public License
 *  along with piClinic Console software at https://github.com/MercerU-TCO/CTS/blob/master/LICENSE. 
 *	If not, see <http://www.gnu.org/licenses/>.
 *
 */
/*******************
 *
 *	Retrieves info about a queued textmsg
 * 		or an HTML error message
 *
 *	GET: Returns textmsg information
 *
 *		Query paramters:
 *			'Token' - the session token with permission to read messages
 *          patientID={{thisPatientID}}     returns text messages queued for this patient
 *          filter={next, ready, sent}      default = all, next = queued and ready, ready = only ready, sent = only sent
 *          count= max objects to return    default & max = 100, must be > 0
 *
 *		Response:
 *			textmsg data object
 *
 *		Returns:
 *			200: the textmsg object matching the token
 *			400: required field is missing or $_SERVER values did not match
 *			404: no matching textmsg found
 *			500: server error information
 *
 *********************/
require_once 'api_common.php';
exitIfCalledFromBrowser(__FILE__);

/*
 *  Queries a token and returns its access if it's valid
 */
function _textmsg_get ($dbLink, $apiUserToken, $requestArgs) {
    /*
     *      Initialize profiling if enabled in piClinicConfig.php
     */
	$profileData = array();
	profileLogStart ($profileData);

	// format not found return value
	$notFoundReturnValue = array();
	$notFoundReturnValue['contentType'] = CONTENT_TYPE_HTML;
	$notFoundReturnValue['data'] = NULL;
	$notFoundReturnValue['httpResponse'] = 404;
	$notFoundReturnValue['httpReason']	= "Resource not found.";
	$notFoundReturnValue['format'] = 'json';
	$notFoundReturnValue['count'] = 0;
	
	$dbInfo = array();
	$dbInfo ['requestArgs'] = $requestArgs;
	$dbInfo ['apiUserToken'] = $apiUserToken;

    $logData = createLogEntry ('API',
        __FILE__,
        'session',
        $_SERVER['REQUEST_METHOD'],
        null,
        $_SERVER['QUERY_STRING'],
        json_encode(getallheaders ()),
        null,
        null,
        null);

	profileLogCheckpoint($profileData,'PARAMETERS_VALID');

	// if no token parameter, get the caller's session as identified by the access token
    // if a token parameter is present and is not the caller's, make sure the caller has SystemAdmin access

    $callerAccess = 0;
    $apiReturnValue = array();
    $qtReturnValue = array();

    // confirm that the caller has permission to access the session they are querying
    //  they need admin access if the session they are querying is not their own.
    // create query string for get operation
    $getApiQueryString = 'SELECT * FROM `'. DB_TABLE_SESSION . '` WHERE `token` = \''.  $apiUserToken . '\';';
    $dbInfo ['apiQueryString'] = $getApiQueryString;

    // get the session record that matches--there should be only one
    $apiReturnValue = getDbRecords($dbLink, $getApiQueryString);
    $dbInfo ['apiReturnValue'] = $apiReturnValue;
    if ($apiReturnValue['count'] == 1) {
        if ($apiReturnValue['data']['accessGranted'] == 'SystemAdmin') {
            $callerAccess = 2;
        } else {
            // if not an admin, they can only see their own token
            if (empty($requestArgs['token']) || ($apiUserToken == $requestArgs['token'])) {
                $callerAccess = 1;
            }
        }
    } // else not found so no access

    if ($callerAccess > 0) {
        if (empty($requestArgs['token'])) {
            // the call is looking up their own session so return the one collected above
            $qtReturnValue = $apiReturnValue;
            $callerAccess = 1;
        } else {
            if ($apiUserToken == $requestArgs['token']) {
                // the call is looking up their own session so return the one collected above
                $qtReturnValue = $apiReturnValue;
            } else {
                // look up the session being queried
                // create query string for get operation
                $qtQueryString = 'SELECT * FROM `'. DB_TABLE_SESSION . '` WHERE `token` = \''.  $requestArgs['token'] . '\';';
                $dbInfo ['qtQueryString'] = $qtQueryString;

                // get the session record that matches--there should be only one
                $qtReturnValue = getDbRecords($dbLink, $qtQueryString);
                $dbInfo['qtReturnValue'] = $qtReturnValue;

                if ($qtReturnValue['count'] != 1) {
                    //return 404
                    // add debug info to the list
                    if (API_DEBUG_MODE) {
                        $notFoundReturnValue['debug'] = $dbInfo;
                    }
                    // target not found so return here with a 404
                    return $notFoundReturnValue;
                } // else format qtReturnValue
            }
        }

        if ($callerAccess == 2) {
            // show full session
            $sessionInfo['data'] = $qtReturnValue['data'];
            $sessionInfo['httpResponse'] = 200;
            $sessionInfo['httpReason'] = 'Success';
        } else if ($callerAccess == 1) {
            $validSession = 1;
            // validate and show partial access
            // getting their own session
            $sessionExpirationTime = strtotime($qtReturnValue['data']['expiresOnDate']);
            $timeNow = time();
            if ($timeNow > $sessionExpirationTime) {
                // session expired
                $validSession = false;
            }

            if ($qtReturnValue['data']['loggedIn'] == 0) {
                $validSession = false;
            }

            // confirm that this request is from the IP that created the token
            if ($_SERVER['REMOTE_ADDR'] != $qtReturnValue['data']['sessionIP']) {
                // token is being used from another IP
                $validSession = false;
            }

            // confirm that this request has the same User Agent string as the browser that created the token
            $sessionUA = (!empty($qtReturnValue['data']['sessionUA']) ? $qtReturnValue['data']['sessionUA'] : '');
            $localUA = (!empty($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : '');
            if ($localUA != $sessionUA) {
                // token could be used from another browser
                $dbInfo['thisUserAgent'] = $localUA ;
                $validSession = false;
            }

            $sessionInfo['contentType'] = CONTENT_TYPE_JSON;
            $sessionInfo['count'] = 1;
            if ($validSession) {
                $sessionInfo['data']['token'] = $qtReturnValue['data']['token'];
                $sessionInfo['data']['username'] = $qtReturnValue['data']['username'];
                $sessionInfo['data']['accessGranted'] = $qtReturnValue['data']['accessGranted'];
                $sessionInfo['data']['sessionLanguage'] = $qtReturnValue['data']['sessionLanguage'];
                $sessionInfo['data']['sessionClinicPublicID'] = $qtReturnValue['data']['sessionClinicPublicID'];
                $sessionInfo['httpResponse'] = 200;
                $sessionInfo['httpReason'] = 'Success';
            } else {
                // this is a stale token so no access anymore
                $sessionInfo['data']['token'] = 0;
                $sessionInfo['data']['username'] = '';
                $sessionInfo['data']['accessGranted'] = 0;
                $sessionInfo['data']['sessionLanguage'] = '';
                $sessionInfo['data']['sessionClinicPublicID'] = '';
                $sessionInfo['httpResponse'] = 404;
                $sessionInfo['httpReason'] = 'Session not found.';
            }
        } // else ???
    } else {
        // no access
        // can't validate permission so not a valid session
        // return an empty session info record
        $sessionInfo['data']['token'] = 0;
        $sessionInfo['data']['username'] = '';
        $sessionInfo['data']['accessGranted'] = 0;
        $sessionInfo['data']['sessionLanguage'] = '';
        $sessionInfo['data']['sessionClinicPublicID'] = '';
        $sessionInfo['httpResponse'] = 401;
        $sessionInfo['httpReason'] = 'Not authorized.';
    }
    // and return here
    profileLogClose($profileData, __FILE__, $requestArgs);
    if (API_DEBUG_MODE  /*&&  $callerAccess == 2 */) {
        // only send this back to SystemAdmin queries
        $sessionInfo['debug'] = $dbInfo;
    }

    return $sessionInfo;
}
//EOF