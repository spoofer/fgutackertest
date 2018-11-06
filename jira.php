<?php
// Array of Jira events to react to
const ARRAY_JIRA_EVENTS		= array("jira:issue_created");

// Data files. Please make sure, that they are existing, and writeable.
const STRING_FILE_JIRA		= "JIRA.txt";
const STRING_FILE_SIGNL4	= "SIGNL4.txt";

// Jira user data (e. g. your login email, and password). Assigns, and comments Jira issues.
const STRING_JIRA_ADMIN 	= "JIRA-USER";
const STRING_JIRA_PASSWORD 	= "JIRA-PASS";

$aData = (array) json_decode(file_get_contents("php://input"));

$sSignlUrl = "";
foreach($_REQUEST as $sKey => $sValue) {
	$sValue = urldecode($sValue);
	// Check if redirect string to SIGNL4 exists
    if ($sKey == "redirect" && strpos("connect.signl4.com", $sValue) == 0) {
        $sSignlUrl = (string) $sValue;
    }
    $aData[$sKey] = (string) $sValue;
}

if ($sSignlUrl) {
	// Handle Jira to SIGNL4 request
    foreach ($aData as $sKey => $sValue) {
        if ($sKey == "webhookEvent" && !in_array($sValue, ARRAY_JIRA_EVENTS)) {
			stop("Jira event not allowed");
		}
	}

    $oCurl = curl_init($sSignlUrl);
	setCurlOptions($oCurl, $aData);
    curl_setopt($oCurl, CURLOPT_POST, true);
    $sRes = curl_exec($oCurl);
    $aRes = json_decode($sRes, true);
    curl_close($oCurl);

	// Log data
    file_put_contents(STRING_FILE_JIRA, json_encode($aData) . ";" . json_encode($aRes) . "\n", FILE_APPEND);
} else {
	// Handle SIGNL4 to Jira request
	$aReq = $aRes = array();
	$sUrl = "";
    $oData = json_decode(json_encode($aData));
	$bHasSendRequest = false;
	
	// If a SIGNL gets acknowledged, assign the Jira issue to the depending user
    if ($oData->eventType == 201 && $oData->alert->statusCode == 2) {
		// Find issue data
		$oIssue = findIssue($oData->alert->eventId);
		
		// Find Jira user by email
		$sUrl = explode("?", $oIssue->user->self)[0] . "/assignable/search?issueKey=" . $oIssue->issue->key;
		$oCurl = curl_init($sUrl);
		curl_setopt($oCurl, CURLOPT_FOLLOWLOCATION, true);
		curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, true);
		setCurlAuthentication($oCurl);
		$oRes = json_decode(curl_exec($oCurl));
		curl_close($oCurl);
		
		$sName = "";
		if ($oRes) {
			foreach ($oRes as $oSingleRes) {
				if ($oSingleRes->emailAddress == $oData->user->mailaddress) {
					$sName = $oSingleRes->name;
					break;
				}
			}
		}
		
		// If user was found, assign Jira issue to user
		if ($sName) {
			$aReq["name"] = $sName;
			$sUrl = $oIssue->issue->self . "/assignee";
			$oCurl = curl_init($sUrl);
			setCurlOptions($oCurl, $aReq);
			setCurlAuthentication($oCurl);
			curl_setopt($oCurl, CURLOPT_CUSTOMREQUEST, 'PUT');
			curl_setopt($oCurl, CURLINFO_HEADER_OUT , true);
			$sRes = curl_exec($oCurl);
			$aRes = json_decode($sRes, true);
			$aInfo = curl_getinfo($oCurl);
			if (!$aInfo) {
				$aInfo = array(curl_error($oCurl));
			}
			file_put_contents(STRING_FILE_SIGNL4, "--> " . json_encode($aInfo) . "\n", FILE_APPEND);
			curl_close($oCurl);
		}
		
		$bHasSendRequest = true;
	
	// If a SIGNL gets a comment, transfer this comment to the depending Jira issue
	} else if ($oData->eventType == 203 && $oData->alert->statusCode == 0) {
		// Find issue data
		$oIssue = findIssue($oData->alert->eventId);
		
		// Here you can format the message content, which will be sent to Jira
		$aReq["body"] = "SIGNL4 comment '" . $oData->annotation->message . "' by SIGNL4 user " . $oData->user->id;

		// Send comment to Jira
		$sUrl = $oIssue->issue->self . "/comment";
		$oCurl = curl_init($sUrl);
		setCurlOptions($oCurl, $aReq);
		setCurlAuthentication($oCurl);
		curl_setopt($oCurl, CURLOPT_POST, true);
		$sRes = curl_exec($oCurl);
		$aRes = json_decode($sRes, true);
		curl_close($oCurl);
		$bHasSendRequest = true;
	}
	
	if ($bHasSendRequest) {
		file_put_contents(STRING_FILE_SIGNL4, $sUrl .";" . $oData->eventType . ";" . $oData->alert->statusCode . ";" . json_encode($aReq) . ";" . json_encode($aRes) . ";" . json_encode($oData) . "\n", FILE_APPEND);
	}
}

function setCurlAuthentication($oCurl) {
	curl_setopt($oCurl, CURLOPT_HTTPAUTH, CURLAUTH_BASIC);
	curl_setopt($oCurl, CURLOPT_USERPWD, STRING_JIRA_ADMIN . ":" . STRING_JIRA_PASSWORD);
}

function setCurlOptions($oCurl, $aReq) {
    curl_setopt($oCurl, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($oCurl, CURLOPT_HTTPHEADER, array("Content-type: application/json"));
	curl_setopt($oCurl, CURLOPT_POSTFIELDS, json_encode($aReq));
	curl_setopt($oCurl, CURLOPT_RETURNTRANSFER, true);
}

function findIssue($sEventId) {
	$rFile = fopen(STRING_FILE_JIRA, "r");
	$sLine = "";
    while (($sLine = fgets($rFile)) !== false && !feof($rFile)) {
		if (strpos($sLine, $sEventId) !== false) {
			$sLine = explode(";", $sLine)[0];
			break;
		}
		$sLine = "";
    }
	fclose($rFile);
	
	return json_decode($sLine);
}

function stop($sMessage) {
    header ("Content-type: application/json");
    echo "{ 'message': '" . $sMessage . "' }\n";
    exit;
}

stop("OK");
