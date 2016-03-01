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
	die('missing action!');
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
	$tokenParser = new TokenParser($publicKey);
	try {
		$params = $tokenParser->decodeToken($token);
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
	$stmt->execute(['askedHint' => $params['askedHint'], 'idUser' => $params['idUser'], 'idTask' => $params['idTask']]);
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
	$stmt = $db->prepare('insert ignore into api_submissions (idUser, sTaskTextId, sAnswer, score, message) values (:idUser, :idItem, :sAnswer, :score, :message) where idUser = :idUser and idTask = :idTask;');
	$stmt->execute(['sAnswer' => $params['sAnswer'], 'idUser' => $params['idUser'], 'idItem' => $params['idItem'], 'score' => $params['score'], 'message' => $message]);
	$stmt->execute('update api_users_tasks set nbSubmissions = nbSubmissions + 1 where idUser = :idUser and sTaskTextId = :idItem;');
	$stmt->execute(['idUser' => $params['idUser'], 'idItem' => $params['idItem']]);
	// TODO: send score through LIS
}

if ($_POST['action'] == 'askHint') {
	if (!isset($_POST['hintToken']) || !isset($_POST['taskPlatformName'])) {
		die(json_encode(['success' => false, 'error' => 'missing token or taskPlatformName']));
	}
	askHint($_POST['hintToken'], $_POST['taskPlatformName']);
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