<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../shared/connect.php';
require_once __DIR__.'/../shared/TokenGenerator.php';
require_once __DIR__.'/../shared/TokenParser.php';
require_once __DIR__.'/../shared/common.php';

// askValidation(token,answer) return token (graderToken)
// askHint(token) -> return token
// graderReturn(score,message,scoreToken) -> rien

if (!isset($_POST) || !isset($_POST['action'])) {
	die(json_encode(['success' => false, 'error' => 'missing action']));
}

function getTaskPlatformPublicKey($taskPlatformName) {
	global $db;
	$stmt = $db->prepare('select public_key from api_task_platforms where name = :name;');
	$stmt->execute(['name' => $taskPlatformName]);
	return $stmt->fetchColumn();
}

function getTaskTokenParams($taskPlatformName, $token) {
	$publicKey = getTaskPlatformPublicKey($taskPlatformName);
	if (!$publicKey) {
		die(json_encode(['success' => false, 'error' => 'impossible to find public key of platform '.$taskPlatformName]));
	}
	$tokenParser = new TokenParser($publicKey, $taskPlatformName);
	try {
		$params = $tokenParser->decodeJWS($token);
	} catch (Exception $e) {
		die(json_encode(['success' => false, 'error' => 'cannot verify token: '.$e->getMessage()]));
	}
	return $params;
}

function getUserPlatformData($idUser) {
	global $db;
	$stmt = $db->prepare('select api_platforms.name, api_platforms.private_key from api_platforms join api_pl_lti_tc on api_pl_lti_tc.idPlatform = api_platforms.ID join api_users on api_users.lti_consumer_key = api_pl_lti_tc.lti_consumer_key where api_users.ID = :idUser;');
	$stmt->execute(['idUser' => $idUser]);
	return $stmt->fetch();
}

function askHint($hintToken, $taskPlatformName) {
	global $db;
	$params = getTaskTokenParams($taskPlatformName, $hintToken);
	if (!$params['askedHint']) {
		die(json_encode(['success' => false, 'error' => 'no "askedHint" field in hint token']));
	}
	$stmt = $db->prepare('update api_users_tasks set nbHintsGiven = :askedHint where idUser = :idUser and idTask = :idTask;');
	$stmt->execute(['askedHint' => $params['askedHint'], 'idUser' => $params['idUser'], 'idTask' => $params['idItem']]);
	$platformData = getUserPlatformData($params['idUser']);
	if (!$platformData) {
		die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
	}
	$userTask = getUserTask($params['idTask'], $params['idUser']);
	$token = generateToken($params['idUser'], $userTask, $platformData, $params['idTask']);
	echo json_encode(['success' => true, 'token' => $token]);
}

function graderReturn($score,$message,$scoreToken,$taskPlatformName) {
	$params = getTaskTokenParams($taskPlatformName, $token);
	if (!isset($params['score']) || !isset($params['sAnswer']))  {
		die(json_encode(['success' => false, 'error' => 'no "score" or "sAnswer" field in hint token']));
	}
	$stmt = $db->prepare('update api_submissions set score = :score, message = :message, state = \'evaluated\' where idUser = :idUser and idTask = :idTask and sAnswer = :sAnswer;');
	$stmt->execute(['sAnswer' => $params['sAnswer'], 'idUser' => $params['idUser'], 'idItem' => $params['idItem'], 'score' => $params['score'], 'message' => $message]);
	$stmt = $db->prepare('select api_users.lis_return_url, api_users.lis_result_sourcedid, lti_consumer.secret, api_users.lti_consumer_key from lti_consumer join api_users on api_users.lti_consumer_key = lti_consumer.consumer_key where api_users.ID = :userId;');
	$stmt->execute(['idUser' => $params['idUser']]);
	$LISInfos = $stmt->fetch();
	if (!$LISInfos) {
		die(json_encode(['success' => false, 'error' => 'impossible to find consumer data for user '.$params['idUser']]));
	}
	sendLISResult($LISInfos['lis_return_url'], $LISInfos['lis_result_sourcedid'], $score, $LISInfos['secret'], $LISInfos['lti_consumer_key']);
}

function sendLSIResult($url, $sourcedId, $score, $secret, $key) {
	$messageId = mt_rand(100000000,999999999);
	$message = '<?xml version = "1.0" encoding = "UTF-8"?><imsx_POXEnvelopeRequest xmlns = "http://www.imsglobal.org/services/ltiv1p1/xsd/imsoms_v1p0"><imsx_POXHeader><imsx_POXRequestHeaderInfo><imsx_version>V1.0</imsx_version><imsx_messageIdentifier>'.$messageId.'</imsx_messageIdentifier></imsx_POXRequestHeaderInfo></imsx_POXHeader><imsx_POXBody><replaceResultRequest><resultRecord><sourcedGUID><sourcedId>'.
	$sourcedId.'</sourcedId></sourcedGUID><result><resultScore><language>en</language><textString>'.$score.'</textString></resultScore></result></resultRecord></replaceResultRequest></imsx_POXBody></imsx_POXEnvelopeRequest>';
		$oauth = new OAuth1(array(
	    'consumerKey' => $key,
	    'consumerSecret' => $secret,
	    'requestTokenUrl' => $url,
	    'accessTokenUrl' => $url,
	));
	$response = $oauth->post($url, $message);
	$parsedResponse = new SimpleXMLElement($response->body);
	$statusInfo = $parsedResponse->imsx_POXHeader->imsx_POXResponseHeaderInfo->imsx_statusInfo;
	if ($statusInfo->imsx_codeMajor == 'failure') {	
		error_log('lsi failed: '.$statusInfo->asXML());
	}
}

function getAnswerToken($token, $taskPlatformName, $answer) {
	global $db;
	$params = getTaskTokenParams($taskPlatformName, $token);
	if (!isset($params['idUser']) || !isset($params['idItem']))  {
		die(json_encode(['success' => false, 'error' => 'no idUser nor idItem in token']));
	}
	$stmt = $db->prepare('update api_users_tasks set nbSubmissions = nbSubmissions + 1 where idUser = :idUser and sTaskTextId = :idTask;');
	$stmt->execute(['idUser' => $params['idUser'], 'idTask' => $params['idItem']]);
	$stmt = $db->prepare('insert into api_submissions (idUser, sTaskTextId, sAnswer, state) values (:idUser, :idTask, :answer, \'validated\')');
	$stmt->execute(['answer' => $answer, 'idUser' => $params['idUser'], 'idTask' => $params['idItem']]);
	$platformData = getUserPlatformData($params['idUser']);
	if (!$platformData) {
		die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
	}
	$tokenGenerator = new TokenGenerator($platformData['private_key'], $platformData['name'], null);
	$params = [
		'idItem' => $params['idItem'],
		'idUser' => $params['idUser'],
		'sAnswer' => $answer
	];
	$token = $tokenGenerator->encodeJWS($params);
	echo json_encode(['success' => true, 'token' => $token]);
}

if ($_POST['action'] == 'askHint') {
	if (!isset($_POST['hintToken']) || !isset($_POST['taskPlatformName'])) {
		die(json_encode(['success' => false, 'error' => 'missing token or taskPlatformName']));
	}
	askHint($_POST['hintToken'], $_POST['taskPlatformName']);
}
elseif ($_POST['action'] == 'getAnswerToken') {
	if (!isset($_POST['sToken']) || !isset($_POST['taskPlatformName']) || !isset($_POST['sAnswer'])) {
		die(json_encode(['success' => false, 'error' => 'missing sToken, taskPlatformName or sAnswer']));
	}
	getAnswerToken($_POST['sToken'], $_POST['taskPlatformName'], $_POST['sAnswer']);
}
elseif ($_POST['action'] == 'graderReturn') {
	if (!isset($_POST['score']) || !isset($_POST['message']) || !isset($_POST['scoreToken'])) {
		die(json_encode(['success' => false, 'error' => 'missing score, message or scoreToken']));
	}
	if (!isset($_POST['taskPlatformName'])) {
		if (isset($_GET['taskPlatformName'])) {
			$taskPlatformName = $_GET['taskPlatformName'];
		} else {
			die(json_encode(['success' => false, 'error' => 'missing taskPlatformName']));		
		}
	} else {
		$taskPlatformName = $_POST['taskPlatformName'];
	}
	graderReturn($_POST['score'], $_POST['message'], $_POST['scoreToken'], $taskPlatformName);
}