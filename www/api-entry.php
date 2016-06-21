<?php

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../shared/connect.php';
require_once __DIR__.'/../shared/TokenGenerator.php';
require_once __DIR__.'/../shared/TokenParser.php';
require_once __DIR__.'/../shared/common.php';
require_once 'LTI_Tool_Provider.php';

header('Content-Type: application/json');

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

function getPlatformPublicKey($platformName) {
	global $db;
	$stmt = $db->prepare('select public_key from api_platforms where name = :name;');
	$stmt->execute(['name' => $platformName]);
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
	$params['taskPlatformName'] = $taskPlatformName;
	return $params;
}

function getPlatformTokenParams($platformName, $token) {
	$publicKey = getPlatformPublicKey($platformName);
	if (!$publicKey) {
		die(json_encode(['success' => false, 'error' => 'impossible to find public key of platform '.$platformName]));
	}
	$tokenParser = new TokenParser($publicKey, $platformName);
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
	$stmt = $db->prepare('update api_users_tasks set nbHintsGiven = :askedHint where idUser = :idUser and sTaskTextId = :idTask;');
	$stmt->execute(['askedHint' => $params['askedHint'], 'idUser' => $params['idUser'], 'idTask' => $params['itemUrl']]);
	$platformData = getUserPlatformData($params['idUser']);
	if (!$platformData) {
		die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
	}
	$userTask = getUserTask($params['itemUrl'], $params['idUser']);
	$token = generateToken($params['idUser'], $userTask, $platformData, $params['itemUrl'], $taskPlatformName);
	echo json_encode(['success' => true, 'token' => $token]);
}

function getPreviousBestScore($params) {
	global $db;
	$stmt = $db->prepare('select max(score) as score from api_submissions where idUser = :idUser and sTaskTextId = :itemUrl;');
	$stmt->execute(['idUser' => $params['idUser'], 'itemUrl' => $params['itemUrl']]);
	return intval($stmt->fetchColumn());
}

function graderReturnNoToken($score,$message,$sAnswer,$sToken,$platformName) {
	global $db;
	$params = getPlatformTokenParams($platformName, $sToken);
	$oldScore = getPreviousBestScore($params);
	$stmt = $db->prepare('update api_submissions set score = :score, message = :message, state = \'evaluated\', sDate = NOW() where idUser = :idUser and sTaskTextId = :itemUrl and sAnswer = :sAnswer;');
	$stmt->execute(['sAnswer' => $sAnswer, 'idUser' => $params['idUser'], 'itemUrl' => $params['itemUrl'], 'score' => $score, 'message' => $message]);
	$token = '';
	if ($score == 100 && $oldScore < 100) {
		$stmt = $db->prepare('update api_users_tasks set bAccessSolution = 1 where idUser = :idUser and sTaskTextId = :itemUrl;');
		$stmt->execute(['idUser' => $params['idUser'], 'itemUrl' => $params['itemUrl']]);
		$platformData = getUserPlatformData($params['idUser']);
		if (!$platformData) {
			die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
		}
		$userTask = getUserTask($params['itemUrl'], $params['idUser']);
		$token = generateToken($params['idUser'], $userTask, $platformData, $params['itemUrl'], $taskPlatformName);
	}
	$maxScore = max($score, $oldScore);
	sendLISResult($params['idUser'], $maxScore);
	echo json_encode(['success' => true, 'score' => $score, 'token' => $token]);
}

function graderReturn($score,$message,$scoreToken,$taskPlatformName) {
	global $db;
	$params = getTaskTokenParams($taskPlatformName, $scoreToken);
	$score = intval($params['score']);
	if (!isset($params['score']) || !isset($params['sAnswer']))  {
		die(json_encode(['success' => false, 'error' => 'no "score" or "sAnswer" field in hint token']));
	}
	$oldScore = getPreviousBestScore($params);
	$stmt = $db->prepare('select ID from api_submissions where idUser = :idUser and sTaskTextId = :itemUrl and sAnswer = :sAnswer;');
	$stmt->execute(['sAnswer' => $params['sAnswer'], 'idUser' => $params['idUser'], 'itemUrl' => $params['itemUrl']]);
	$submissionID = $stmt->fetchColumn();
	if ($submissionID) {
		$stmt = $db->prepare('update api_submissions set score = :score, message = :message, state = \'evaluated\', sDate = NOW() where ID = :ID;');
		$stmt->execute(['score' => $score, 'message' => $message, 'ID' => $submissionID]);
	} else {
		$stmt = $db->prepare('insert into api_submissions (idUser, sTaskTextId, sAnswer, score, message, state, sDate) values (:idUser, :itemUrl, :sAnswer, :score, :message, \'evaluated\', NOW());');
		$stmt->execute(['sAnswer' => $params['sAnswer'], 'idUser' => $params['idUser'], 'itemUrl' => $params['itemUrl'], 'score' => $score, 'message' => $message]);
	}
	$token = '';
	if ($score == 100 && $oldScore < 100) {
		$stmt = $db->prepare('update api_users_tasks set bAccessSolution = 1 where idUser = :idUser and sTaskTextId = :itemUrl;');
		$stmt->execute(['idUser' => $params['idUser'], 'itemUrl' => $params['itemUrl']]);
		$platformData = getUserPlatformData($params['idUser']);
		if (!$platformData) {
			die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
		}
		$userTask = getUserTask($params['itemUrl'], $params['idUser']);
		$token = generateToken($params['idUser'], $userTask, $platformData, $params['itemUrl'], $taskPlatformName);
	}
	$maxScore = max($score, $oldScore);
	//sendLISResult($params['idUser'], $maxScore);
	sendLISResult($params['idUser'], $maxScore);
	echo json_encode(['success' => true, 'score' => $score, 'token' => $token, 'oldScore' => $oldScore, 'params' => $params]);
}

function sendLISResult($userId, $score) {
	global $db;
	$stmt = $db->prepare('select lti_user.user_id, api_users.lti_consumer_key, api_users.lti_context_id, lti_user.lti_result_sourcedid from  api_users join lti_user on api_users.lti_consumer_key = lti_user.consumer_key and api_users.lti_user_id = lti_user.user_id and lti_user.context_id = api_users.lti_context_id where api_users.ID = :idUser;');
	$stmt->execute(['idUser' => $userId]);
	$LISInfos = $stmt->fetch();
	if (!$LISInfos) {
		die(json_encode(['success' => false, 'error' => 'impossible to find consumer data for user '.$params['idUser']]));
	}
	$dbConn = LTI_Data_Connector::getDataConnector($db, 'PDO');
	$consumer = new LTI_Tool_Consumer($LISInfos['lti_consumer_key'], $dbConn);
	$resourceLink = new LTI_Resource_Link($consumer,$LISInfos['lti_context_id']);
	$outcome = new LTI_Outcome();
	$scoreOnOne = $score / 100;
	$outcome->setValue($scoreOnOne);
	$user = new LTI_User($resourceLink, $LISInfos['user_id']);
	$ok = $resourceLink->doOutcomesService(LTI_Resource_Link::EXT_WRITE, $outcome, $user);
	if (!$ok) {
		error_log('something went wrong when sending results! idUser: '.json_encode($userId).' score: '.$score);
	}
	//file_put_contents(__DIR__.'/../logs/lis-answers.txt', "\nlis return for user id ".$userId." with score ".$score.":\n".$resourceLink->ext_response."\n\n".$resourceLink->ext_response_headers."\n", FILE_APPEND);
}

function getAnswerToken($token, $taskPlatformName, $answer) {
	global $db;
	$params = getTaskTokenParams($taskPlatformName, $token);
	if (!isset($params['idUser']) || !isset($params['itemUrl']))  {
		die(json_encode(['success' => false, 'error' => 'no idUser nor itemUrl in token']));
	}
	$stmt = $db->prepare('update api_users_tasks set nbSubmissions = nbSubmissions + 1 where idUser = :idUser and sTaskTextId = :idTask;');
	$stmt->execute(['idUser' => $params['idUser'], 'idTask' => $params['itemUrl']]);
	$stmt = $db->prepare('insert into api_submissions (idUser, sTaskTextId, sAnswer, state, sDate) values (:idUser, :idTask, :answer, \'validated\', NOW())');
	$stmt->execute(['answer' => $answer, 'idUser' => $params['idUser'], 'idTask' => $params['itemUrl']]);
	$platformData = getUserPlatformData($params['idUser']);
	if (!$platformData) {
		die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
	}
	$tokenGenerator = new TokenGenerator($platformData['private_key'], $platformData['name'], null);
	$params = [
		'itemUrl' => $params['itemUrl'],
		'idUser' => $params['idUser'],
		'sAnswer' => $answer
	];
	$token = $tokenGenerator->encodeJWS($params);
	echo json_encode(['success' => true, 'token' => $token]);
}

function saveState($token, $platformName, $sState) {
	global $db;
	$params = getPlatformTokenParams($platformName, $token);
	if (!isset($params['idUser']) || !isset($params['itemUrl']))  {
		die(json_encode(['success' => false, 'error' => 'no idUser nor itemUrl in token']));
	}
	$stmt = $db->prepare('update api_users_tasks set sState = :sState where idUser = :idUser and sTaskTextId = :idTask;');
	$stmt->execute(['idUser' => $params['idUser'], 'idTask' => $params['itemUrl'], 'sState' => $sState]);
	echo json_encode(['success' => true]);
}

if ($_POST['action'] == 'askHint') {
	if (!isset($_POST['hintToken']) || !isset($_POST['taskPlatformName'])) {
		die(json_encode(['success' => false, 'error' => 'missing token or taskPlatformName']));
	}
	askHint($_POST['hintToken'], $_POST['taskPlatformName']);
}
elseif ($_POST['action'] == 'saveState') {
	if (!isset($_POST['sState']) || !isset($_POST['platformName'])) {
		die(json_encode(['success' => false, 'error' => 'missing state or platformName']));
	}
	saveState($_POST['sToken'], $_POST['platformName'], $_POST['sState']);
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
	graderReturn(intval($_POST['score']), $_POST['message'], $_POST['scoreToken'], $taskPlatformName);
}
elseif ($_POST['action'] == 'graderReturnNoToken') {
	if (!isset($_POST['score']) || !isset($_POST['sToken']) || !isset($_POST['sAnswer']) || !isset($_POST['platformName'])) {
		die(json_encode(['success' => false, 'error' => 'missing score, message, sAnswer, platformName or sToken']));
	}
	$message = isset($_POST['message']) ? $_POST['message'] : '';
	graderReturnNoToken(intval($_POST['score']), $message, $_POST['sAnswer'], $_POST['sToken'], $_POST['platformName']);
}
else {
	die(json_encode(['success' => false, 'error' => 'missing or unknown action']));
}