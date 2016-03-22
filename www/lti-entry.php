<?php

use Franzl\Lti;
use Zend\Diactoros\ServerRequest;
use Zend\Diactoros\ServerRequestFactory;

/* This file parses an lti call and produces a bebras API token */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../shared/connect.php';
require_once __DIR__.'/../shared/TokenGenerator.php';
require_once __DIR__.'/../shared/common.php';

$taskId = isset($_GET['taskId']) ? $_GET['taskId'] : null;
if (!$taskId) {
	echo "Vous devez spécifier un taskId!";
}

$taskPlatformName = isset($_GET['taskPlatformName']) ? $_GET['taskPlatformName'] : null;
if (!$taskPlatformName) {
	echo "Vous devez spécifier un taskPlatformName!";
}

function saveUser($user, $returnUrl, $sourcedId) {
	global $db;
	$firstName = $user->firstName;
	$lastName = $user->lastName;
	$email = $user->email;
	$lti_user_id = $user->getId();
	$lti_context_id = $user->getResourceLink()->lti_context_id;
	$lti_consumer_key = $user->getResourceLink()->getConsumer()->getKey();
	// TODO: update name if different?
	$stmt = $db->prepare('insert ignore into api_users (lti_context_id, lti_consumer_key, lti_user_id, firstName, lastName, email, lis_return_url, lis_result_sourcedid) values (:lticontextid, :lticonsumerkey, :ltiuserid, :firstName, :lastName, :email, :returnUrl, :sourcedId);');
	$stmt->execute([
		'lticontextid' => $lti_context_id,
		'lticonsumerkey' => $lti_consumer_key,
		'ltiuserid' => $lti_user_id,
		'firstName' => $firstName,
		'lastName' => $lastName,
		'email' => $email,
		'returnUrl' => $returnUrl,
		'sourcedId' => $sourcedId,
	]);
	$stmt = $db->prepare('select ID from api_users where lti_context_id = :lticontextid and lti_consumer_key = :lticonsumerkey and lti_user_id = :ltiuserid;');
	$stmt->execute([
		'lticontextid' => $lti_context_id,
		'lticonsumerkey' => $lti_consumer_key,
		'ltiuserid' => $lti_user_id
	]);
	return $stmt->fetchColumn();
}

function handleLtiResources($user, $returnUrl, $sourcedId) {
	global $taskId, $taskPlatformName;
	$userId = saveUser($user, $returnUrl, $sourcedId);
	if (!$userId) {
		die('impossible d\'enegistrer l\'utilisateur');
	}
	$userTask = getUserTask($taskId, $userId);
	$platformData = getApiPlatform($user->getResourceLink()->getKey());
	if (!$platformData) {
		die('impossible de trouver la plateforme correspondante');
	}
	$token = generateToken($userId, $userTask, $platformData, $taskId);
	if (!$token) {
		die('impossible de générer le token');
	}
	$taskPlatform = getTaskPlatform($taskPlatformName);
	if (!$taskPlatform) {
		die('impossible de trouver la platforme d\'exercices');
	}
	printPage($token, $taskPlatform['url'], $platformData['name'], $taskPlatform['name']);
}

// actual lti handling:
class MyToolProvider extends Franzl\Lti\ToolProvider {
  	function onLaunch() {
 	 	handleLtiResources($this->user, $this->user->getResourceLink()->settings['lis_outcome_service_url'], $this->user->getResourceLink()->settings['lis_result_sourcedid']);
  	}
}

$dbConn = Franzl\Lti\Storage\AbstractStorage::getStorage($db, 'PDO');
// create a PsrServerRequest throught Zend\Diactoros
$request = ServerRequestFactory::fromGlobals($_SERVER, [], $_POST, $_COOKIE, $_FILES);
$tool = new MyToolProvider($dbConn);
$tool->handleRequest($request);

function printPage($token, $taskPlatformUrl, $platformName, $taskPlatformName) {
	global $config;
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
    	var taskPlatformName = '<?= $taskPlatformName; ?>';
    	var returnUrl = '<?= $config->baseUrl; ?>/api.php?taskPlatformName=<?= $taskPlatformName; ?>'; // urlencode
    </script>
    </head>
    <body>
    <div id="choose-view"></div>
    <iframe style="width:800px;height:800px;" id="taskIframe" src="<?= $taskPlatformUrl; ?>?sToken=<?= $token ?>&sPlatform=<?= $platformName ?>&channelId=<?= $taskPlatformUrl; ?>"></iframe>
    </body>
    </html>
<?php
}