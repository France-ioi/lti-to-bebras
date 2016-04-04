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
	$stmt = $db->prepare('update api_users_tasks set nbHintsGiven = :askedHint where idUser = :idUser and sTaskTextId = :idTask;');
	$stmt->execute(['askedHint' => $params['askedHint'], 'idUser' => $params['idUser'], 'idTask' => $params['idItem']]);
	$platformData = getUserPlatformData($params['idUser']);
	if (!$platformData) {
		die(json_encode(['success' => false, 'error' => 'impossible to find platform data for user '.$params['idUser']]));
	}
	$userTask = getUserTask($params['idItem'], $params['idUser']);
	$token = generateToken($params['idUser'], $userTask, $platformData, $params['idItem']);
	echo json_encode(['success' => true, 'token' => $token]);
}

function graderReturn($score,$message,$scoreToken,$taskPlatformName) {
	global $db;
	$params = getTaskTokenParams($taskPlatformName, $scoreToken);
	if (!isset($params['score']) || !isset($params['sAnswer']))  {
		die(json_encode(['success' => false, 'error' => 'no "score" or "sAnswer" field in hint token']));
	}
	$stmt = $db->prepare('update api_submissions set score = :score, message = :message, state = \'evaluated\' where idUser = :idUser and sTaskTextId = :idItem and sAnswer = :sAnswer;');
	$stmt->execute(['sAnswer' => $params['sAnswer'], 'idUser' => $params['idUser'], 'idItem' => $params['idItem'], 'score' => $params['score'], 'message' => $message]);
	sendLISResult($params['idUser'], $score);
}

function sendLISResult($userId, $score) {
	global $db;
	$stmt = $db->prepare('select lti_user.user_id, api_users.lti_consumer_key, lti_context.lti_resource_id, lti_user.lti_result_sourcedid from lti_context join api_users on api_users.lti_consumer_key = lti_context.consumer_key and api_users.lti_context_id = lti_context.lti_context_id join lti_user on api_users.lti_consumer_key = lti_user.consumer_key and api_users.lti_user_id = lti_user.user_id and lti_user.context_id = lti_context.context_id where api_users.ID = :idUser;');
	$stmt->execute(['idUser' => $userId]);
	$LISInfos = $stmt->fetch();
	if (!$LISInfos) {
		die(json_encode(['success' => false, 'error' => 'impossible to find consumer data for user '.$params['idUser']]));
	}
	$dbConn = LTI_Data_Connector::getDataConnector($db, 'PDO');
	$consumer = new LTI_Tool_Consumer($LISInfos['lti_consumer_key'], $dbConn);
	$resourceLink = new LTI_Resource_Link($consumer,$LISInfos['lti_resource_id']);
	$outcome = new LTI_Outcome();
	$scoreOnOne = $score / 100;
	$outcome->setValue($scoreOnOne);
	$user = new LTI_User($resourceLink, $LISInfos['user_id']);
	$ok = $resourceLink->doOutcomesService(LTI_Resource_Link::EXT_WRITE, $outcome, $user);
	echo json_encode(['success' => true]);
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