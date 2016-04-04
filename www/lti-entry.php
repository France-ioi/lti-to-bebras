<?php

/* This file parses an lti call and produces a bebras API token */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../shared/connect.php';
require_once __DIR__.'/../shared/TokenGenerator.php';
require_once __DIR__.'/../shared/common.php';
require_once 'LTI_Tool_Provider.php';

$taskId = isset($_GET['taskId']) ? $_GET['taskId'] : null;
if (!$taskId) {
	echo "Vous devez spécifier un taskId!";
}

$taskPlatformName = isset($_GET['taskPlatformName']) ? $_GET['taskPlatformName'] : null;
if (!$taskPlatformName) {
	echo "Vous devez spécifier un taskPlatformName!";
}

function saveUser($user) {
	global $db;
	//$firstName = $user->firstName;
	//$lastName = $user->lastName;
	// TODO
	$firstName = '';
	$lastName = '';
	$email = $user->email;
	$lti_user_id = $user->getId();
	$lti_context_id = $user->getResourceLink()->lti_context_id;
	$lti_consumer_key = $user->getResourceLink()->getConsumer()->getKey();
	// TODO: update name if different?
	$stmt = $db->prepare('insert ignore into api_users (lti_context_id, lti_consumer_key, lti_user_id, firstName, lastName, email) values (:lticontextid, :lticonsumerkey, :ltiuserid, :firstName, :lastName, :email);');
	$stmt->execute([
		'lticontextid' => $lti_context_id,
		'lticonsumerkey' => $lti_consumer_key,
		'ltiuserid' => $lti_user_id,
		'firstName' => $firstName,
		'lastName' => $lastName,
		'email' => $email,
	]);
	$stmt = $db->prepare('select ID from api_users where lti_context_id = :lticontextid and lti_consumer_key = :lticonsumerkey and lti_user_id = :ltiuserid;');
	$stmt->execute([
		'lticontextid' => $lti_context_id,
		'lticonsumerkey' => $lti_consumer_key,
		'ltiuserid' => $lti_user_id
	]);
	return $stmt->fetchColumn();
}

function handleLtiResources($user) {
	global $taskId, $taskPlatformName, $db;
	$userId = saveUser($user);
	if (!$userId) {
		die('impossible d\'enegistrer l\'utilisateur');
	}
	$userTask = getUserTask($taskId, $userId);
	$platformData = getApiPlatform($user->getResourceLink()->getKey());
	if (!$platformData) {
		die('impossible de trouver la plateforme correspondante');
	}
	$taskPlatform = getTaskPlatform($taskPlatformName);
	if (!$taskPlatform) {
		die('impossible de trouver la platforme d\'exercices');
	}
	$token = generateToken($userId, $userTask, $platformData, $taskId);
	if (!$token) {
		die('impossible de générer le token');
	}
	$stmt = $db->prepare('select sAnswer from api_submissions where idUser = :idUser and sTaskTextId = :idTask order by sDate desc limit 1;');
	$stmt->execute(['idUser' => $userId, 'idTask' => $taskId]);
	$lastAnswer = $stmt->fetchColumn();
	$url = $taskPlatform['url'];
	if ($taskPlatform['bAppendIdToUrl']) {
		$url = $url.$taskId;
	}
	printPage($token, $url, $platformData['name'], $taskPlatform['name'], $taskPlatform['bUsesTokens'], $userTask, $lastAnswer);
}

// actual lti handling:
class MyToolProvider extends LTI_Tool_Provider {
  	function onLaunch() {
 	 	handleLtiResources($this->user);
  	}
}

$dbConn = LTI_Data_Connector::getDataConnector($db, 'PDO');
$tool = new MyToolProvider($dbConn);
$tool->execute();

// TODO: getLastAnswer, getLastState, synchronise state

function printPage($token, $taskPlatformUrl, $platformName, $taskPlatformName, $bUsesTokens, $userTask, $lastAnswer) {
	global $config;
	$state = ($userTask && isset($userTask['sState'])) ? $userTask['sState'] : '';
	$state = $state ? $state : '';
	$lastAnswer = $lastAnswer ? : '';
	$returnUrl = $config->baseUrl . '/api-entry.php';
?>

<!DOCTYPE html>
<html>
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <title>LTI to Bebras API wrapper</title>
    <link href="bower_components/bootstrap/dist/css/bootstrap.min.css" type="text/css" rel="stylesheet">
    <link href="local.css" type="text/css" rel="stylesheet">
    <script type="text/javascript" src="bower_components/jquery/dist/jquery.min.js"></script>
    <script type="text/javascript" src="bower_components/pem-platform/task-xd-pr.js"></script>
    <script type="text/javascript" src="bower_components/jschannel/src/jschannel.js"></script>
    <script type="text/javascript" src="ltitobebras.js"></script>
    <script type="text/javascript">
    	var token = '<?= $token ?>';
    	var taskPlatformUrl = '<?= $taskPlatformUrl; ?>';
    	var platformName = '<?= $platformName; ?>';
    	var lastAnswer = '<?= $lastAnswer; ?>';
    	var lastState = '<?= $state ?>';
    	var taskPlatformName = '<?= $taskPlatformName; ?>';
    	var usesTokens = <?= $bUsesTokens ?>;
    	var returnUrl = '<? $returnUrl ?>';
    </script>
    </head>
    <body>
    <div id="choose-view"></div>
    <iframe style="width:800px;height:800px;" id="taskIframe" src="<?= $taskPlatformUrl; ?>?sToken=<?= $token ?>&sPlatform=<?= $platformName ?>&channelId=<?= $taskPlatformName; ?>"></iframe>
    </body>
    </html>
<?php
}