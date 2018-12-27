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
 *	Processes authorization and authentication functions
 *
 *********************/
// check to make sure this file wasn't called directly
//  it must be called from a script that supports access checking
if (basename(__FILE__) == basename($_SERVER['SCRIPT_NAME'])) {
	// the file was not included so return an error
	http_response_code(404);
	header(CONTENT_TYPE_HEADER_HTML);
	exit;	
}

require_once 'piClinicConfig.php';
require_once 'dbUtils.php';
require_once '../api/api_common.php';
require_once '../api/session_common.php';
require_once '../api/session_get.php';
require_once 'profile.php';

define('PAGE_ACCESS_ADMIN', 32, false); 	// SystemAdmin
define('PAGE_ACCESS_CLINIC', 16, false); 	// ClinicAdmin
define('PAGE_ACCESS_STAFF', 8, false); 		// ClinicStaff
define('PAGE_ACCESS_READONLY', 4, false); 	// ClinicReadOnly (Any authorized user)
define('PAGE_ACCESS_NONE', 0, false);		// no access

function getTokenFromHeaders() {
    if (!empty($_SERVER['HTTP_X_PICLINIC_TOKEN'])) {
        return $_SERVER['HTTP_X_PICLINIC_TOKEN'];
    } else {
        return null;
    }
}

function getSessionInfo ($dbLink, $sessionToken) {
    $httpReason = '';
    $dbOpenedHere = false;
    $sessionInfo = array();

    if (empty($dbLink)){
        // open DB to check for access
        $dbLink = _openDB();
        $dbOpenError = mysqli_connect_errno();
        if ( $dbOpenError  == 0  ) {
            $dbOpenedHere = true;
        }
    }

    if ($dbLink) {
        $requestParams = array();
        $requestParams['token'] = $sessionToken;
        $sessionData = _session_get($dbLink, $sessionToken, $requestParams);
        if ($sessionData['httpResponse'] == 200) {
            // successful call, now check the response\
            $sessionInfo = $sessionData['data'];
        }
    }

    if ($dbOpenedHere) {
        // close the db if we opened it for this check
        @mysqli_close($dbLink);
    }
    return $sessionInfo;
}

function checkUiSessionAccess($dbLink, $sessionToken, $pageAccess) {
	$profileData = [];
	profileLogStart ($profileData);
    // assume access is granted until a test fails
    $dbAccessGranted = true;

    $sessionInfo = getSessionInfo ($dbLink, $sessionToken);

    // 0 means session is not valid
    if ($sessionInfo['token'] != '0') {
        // valid session so check page access
        switch ($sessionInfo['accessGranted']) {
            case 'ClinicStaff':
                $sessionAccess = PAGE_ACCESS_STAFF;
                break;

            case 'ClinicAdmin':
                $sessionAccess = PAGE_ACCESS_CLINIC;
                break;

            case 'SystemAdmin':
                $sessionAccess = PAGE_ACCESS_ADMIN;
                break;

            case 'ClinicReadOnly':
                $sessionAccess = PAGE_ACCESS_READONLY;
                break;

            default:
                // unrecognized
                $sessionAccess = PAGE_ACCESS_NONE;
                break;
        }
        if ($sessionAccess < $pageAccess) {
            $dbAccessGranted = false;
            if (API_DEBUG_MODE) {
                header('Debug_SECURITY_AccessDenied: '.strval($sessionAccess).'<'.strval($pageAccess));
            }
        }
    } else {
        $dbAccessGranted = false;
        if (API_DEBUG_MODE) {
            header('Debug_SECURITY_Token: (cookie != DB  )'. $sessionToken. ' != '.$sessionInfo['token']);
        }
    }

    profileLogClose($profileData, __FILE__);
	return ($dbAccessGranted);
}
//EOF