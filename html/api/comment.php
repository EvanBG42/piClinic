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
 *	Creates/Returns comment resources from the database 
 * 		or an HTML error message
 *
 *	POST: Adds a new comment record to the database
 * 		input data:
 *   		`CommentDate` - (Optional) date comment was started
 *  		`Username` - (Required) Username creating this session.
 *  		`ReferringUrl` - (Optional) Page from which comment page was called.
 *			`ReferringPage` - (Optional) Page name from path.
 *  		`ReturnUrl` - (Optional) Page to which user was sent after making the comment.
 *  		`CommentText` - (0Optional) User comment text.
 *
 *		Returns:
 *			201: the new comment record created
 *			400: required field is missing
 *			409: record already exists error
 *			500: server error information
 *
 *
 *	GET: Returns comment records that match the specified query parameters
 *
 *	Identification query parameters:
 *		The comment record(s) will be returned that the fields specified in the query parameter.
 * 		
 *   		`CommentDate` - (Optional) date comment was started
 *  		`Username` - (Required) Username creating this session.
 *  		`ReferringUrl` - (Optional) Page URL from which comment page was called.
 *			`ReferringPage` - (Optional) Page name from path.
 *  		`ReturnUrl` - (Optional) Page to which user was sent after making the comment.
 *  		`CommentText` - (0Optional) User comment text.
 *			`createdDate` - Date user account was created
 *
 *		Returns:
 *			200: the matching comment record(s)
 *			404: no record found that matches the query parameters
 *			500: server error information
 *
 *********************/
require_once '../shared/piClinicConfig.php';
require_once '../shared/dbUtils.php';
require_once 'api_common.php';
require_once '../shared/security.php';
require_once '../shared/profile.php';
require_once '../shared/logUtils.php';
require_once 'comment_common.php';
require_once 'comment_post.php';
require_once 'comment_get.php';
/*
 *  Initialize profiling when enabled in piClinicConfig.php
 */
$profileData = array();
profileLogStart ($profileData);

// get the query paramater data from the request 
$requestData = readRequestData();
$dbLink = _openDB();
$dbOpenError = mysqli_connect_errno();
if ( $dbOpenError  != 0  ) {
	$retVal = array();
	// database not opened.
	$retVal['contentType'] = 'Content-Type: application/json; charset=utf-8';
	$dbInfo['sqlError'] = 'Error: '. $dbOpenError .', '.
			mysqli_connect_error();
	$retVal['error'] = $dbInfo;
	$retVal['httpResponse'] = 500;
	$retVal['httpReason']   = 'Server Error - Database not opened.';
    logApiError($requestData,
        $retVal['httpResponse'],
        __FILE__ ,
        (!empty($requestData['token']) ? $requestData['token'] : "NotSpecified"),
        'staff',
        $retVal['httpReason']);
	outputResults( $retVal);
    exit; // this is the end of the line if there's no DB access
}

profileLogCheckpoint($profileData,'DB_OPEN');

if (empty($requestData['token'])){
    // caller does not have a valid security token
    $retVal['httpResponse'] = 400;
    $retVal['httpReason']	= "Unable to access comment resources. Missing token.";
} else {
    if (!validTokenString($requestData['token'])) {
        $retVal['contentType'] = CONTENT_TYPE_JSON;
        $retVal['httpResponse'] = 400;
        $retVal['httpReason'] = "Unable to access comment resources. Invalid token.";
        $logData['LogStatusCode'] = $retVal['httpResponse'];
        $logData['LogStatusMessage'] = $retVal['httpReason'];
        writeEntryToLog($dbLink, $logData);
    } else {
        if (checkUiSessionAccess($dbLink, $requestData['token'], PAGE_ACCESS_READONLY)) {
            switch ($_SERVER['REQUEST_METHOD']) {
                case 'POST':
                    $retVal = _comment_post($dbLink, $requestData);
                    break;

                case 'GET':
                    $retVal = _comment_get($dbLink, $requestData);
                    break;

                default:
                    $retVal['contentType'] = 'Content-Type: application/json';
                    if (API_DEBUG_MODE) {
                        $retVal['error'] = $requestData;
                    }
                    $retVal['httpResponse'] = 405;
                    $retVal['httpReason'] = 'Method not supported.';
                    break;
            }
        } else {
            // caller does not have a valid security token
            $retVal['httpResponse'] = 403;
            $retVal['httpReason'] = "User account is not authorized to create this resource.";
        }
    }
}
// close the DB link until next time
@mysqli_close($dbLink);
$profileTime = profileLogClose($profileData, __FILE__, $requestData);
if ($profileTime !== false) {
    if (empty($retVal['debug'])) {
        $retVal['debug'] = array();
    }
    $retVal['debug']['profile'] = $profileTime;
}
outputResults ($retVal);
//EOF