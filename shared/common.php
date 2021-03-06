<?php

function getApiPlatform($consumerKey) {
	global $db;
	$stmt = $db->prepare('select private_key, name from api_platforms join api_pl_lti_tc on api_pl_lti_tc.idPlatform = api_platforms.ID where api_pl_lti_tc.lti_consumer_key = :consumerKey;');
	$stmt->execute(['consumerKey' => $consumerKey]);
	return $stmt->fetch();
}

function getUserTask($taskId, $userId) {
	global $db;
	$stmt = $db->prepare('select * from api_users_tasks where idUser = :idUser and sTaskTextId = :idTask;');
	$stmt->execute(['idUser' => $userId, 'idTask' => $taskId]);
	$userTask = $stmt->fetch();
	if (!$userTask) {
		$stmt = $db->prepare('insert ignore into api_users_tasks (idUser, sTaskTextId, nbHintsGiven, nbSubmissions, bAccessSolution) values (:idUser, :idTask, 0, 0, 0);');
		$stmt->execute(['idUser' => $userId, 'idTask' => $taskId]);
		return [
			'idUser' => $userId,
			'sTaskTextId' => $taskId,
			'sHintsRequested' => '', 
			'nbHintsGiven' => 0,
			'nbSubmissions' => 0,
			'bAccessSolution' => 0
		];
	} else {
		return $userTask;
	}
}

function generateToken($userId, $userTask, $platformData, $taskUrl, $taskPlatformName, $user=null) {
	global $config;
	$tokenGenerator = new TokenGenerator($platformData['private_key'], $platformData['name'], null);
	$returnUrl = $config->baseUrl . '/api-entry.php?taskPlatformName='.$taskPlatformName;
	$params = [
		'bAccessSolutions' => $userTask['bAccessSolution'],
		'bSubmissionPossible' => true,
		'bHintsAllowed' => true,
		'bHasSolvedTask' => false,
		'sHintsRequested' => $userTask['sHintsRequested'],
		'nbHintsGiven' => $userTask['nbHintsGiven'],
		'bHintPossible' => true,
		'itemUrl' => $taskUrl,
		'idUser' => $userId,
		'bIsAdmin' => false,
		'bIsDefault' => false,
		'returnUrl' => $returnUrl,
		'sSupportedLangProg' => '*',
		'sLogin' => '',
		'randomSeed' => 0
	];
	if ($user) {
		$params['loginData'] = [
			'type' => 'lti',
			'email' => $user->email,
			'firstName' => $user->firstname,
			'lastName' => $user->lastname,
			'login' => null,
			'lti_consumer_key' => $user->getResourceLink()->getConsumer()->getKey(),
			'lti_user_id' => $user->getId()
		];
	}
	return $tokenGenerator->encodeJWS($params);
}

function getTaskPlatform($taskUrl) {
	global $db;
	$host = parse_url($taskUrl, PHP_URL_HOST);
	if (!$host) {
		die('impossible to find host of url '+$taskUrl);
	}
	$stmt = $db->prepare('select * from api_task_platforms where domain = :host;');
	$stmt->execute(['host' => $host]);
	return $stmt->fetch();
}
