<?php

/* This file parses an lti call and produces a bebras API token */

require_once __DIR__.'/../vendor/autoload.php';
require_once __DIR__.'/../shared/connect.php';
require_once __DIR__.'/../shared/TokenGenerator.php';
require_once __DIR__.'/../shared/common.php';
require_once 'LTI_Tool_Provider.php';

$taskUrl = isset($_GET['taskUrl']) ? $_GET['taskUrl'] : null;
if (!$taskUrl) {
	die("Vous devez spécifier l'url d'un exercice dans le paramètre taskUrl!");
}

$themeName = isset($_GET['theme']) ? $_GET['theme'] : 'default';

$viewNames = [
	'task' => 'Exercice',
    'solution' => 'Solution',
    'editor' => 'Résoudre',
    'hints' => 'Conseils'
];
$themeCss = '';
$themeButtonsPosition = 'top';

if ($themeName == 'funtelecom') {
	$viewNames = [
		'task' => 'Consignes',
	    'solution' => 'Solution',
	    'editor' => 'Soumettre',
	    'hints' => 'Indices'
	];	
	$themeCss = '.choose-view-button {margin-left: 10px;margin-bottom: 10px;margin-top:10px;height: 40px;vertical-align: middle;text-transform: uppercase;font-weight: 600;border: 1px solid #D2C9C9;border-radius: 3px;box-shadow: 0px 1px 0px 0px #FFF inset;color: #333;display: inline-block;font-weight: bold;background-color: #F1F1F1;background-image: linear-gradient(#F1F1F1, #D9D1D1);padding: 7px 18px;text-decoration: none;text-shadow: 0px 1px 0px #FCFBFB;background-clip: padding-box;font-size: 0.8125em;cursor: pointer;text-align: center;font: 1.2rem/1.6rem "Open Sans",Verdana,Geneva,sans-serif,sans-serif;}'."\n";
	$themeCss .= '.btn-info {color:white !important;background-color:#004e73 !important;background-image: linear-gradient(#036592, #004769) !important;}';
	$themeButtonsPosition = 'topbottom';
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
	global $taskUrl, $db;
	$userId = saveUser($user);
	if (!$userId) {
		die('impossible d\'enegistrer l\'utilisateur');
	}
	$userTask = getUserTask($taskUrl, $userId);
	$platformData = getApiPlatform($user->getResourceLink()->getKey());
	if (!$platformData) {
		die('impossible de trouver la plateforme correspondante');
	}
	$taskPlatform = getTaskPlatform($taskUrl);
	if (!$taskPlatform) {
		die('impossible de trouver la platforme d\'exercices');
	}
	$token = generateToken($userId, $userTask, $platformData, $taskUrl);
	if (!$token) {
		die('impossible de générer le token');
	}
	$stmt = $db->prepare('select sAnswer from api_submissions where idUser = :idUser and sTaskTextId = :idTask order by sDate desc limit 1;');
	$stmt->execute(['idUser' => $userId, 'idTask' => $taskUrl]);
	$lastAnswer = $stmt->fetchColumn();
	printPage($token, $taskUrl, $platformData['name'], $taskPlatform['name'], $taskPlatform['bUsesTokens'], $userTask, $lastAnswer);
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

function printPage($token, $taskUrl, $platformName, $taskPlatformName, $bUsesTokens, $userTask, $lastAnswer) {
	global $config, $viewNames, $themeCss, $themeButtonsPosition;
	$state = ($userTask && isset($userTask['sState'])) ? $userTask['sState'] : '';
	$state = $state ? $state : '';
	$lastAnswer = $lastAnswer ? : '';
	$returnUrl = $config->baseUrl . '/api-entry.php'.'?taskPlatformName='.$taskPlatformName;
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
    <style><?= $themeCss ?></style>
    <script type="text/javascript">
    	var token = '<?= $token ?>';
    	var taskUrl = '<?= $taskUrl; ?>';
    	var platformName = '<?= $platformName; ?>';
    	var lastAnswer = '<?= $lastAnswer; ?>';
    	var lastState = '<?= $state ?>';
    	var taskPlatformName = '<?= $taskPlatformName; ?>';
    	var usesTokens = <?= $bUsesTokens ?>;
    	var returnUrl = '<?= $returnUrl ?>';
    	var bAccessSolution = <?= $userTask['bAccessSolution'] ?>;
    	var viewNames = <?= json_encode($viewNames); ?>;
    	var buttonsPosition = <?= json_encode($themeButtonsPosition); ?>;
    </script>
  </head>
  <body>
    <div id="choose-view-top"></div>
    <iframe style="width:800px;height:800px;" id="taskIframe" src="<?= $taskUrl; ?>?sToken=<?= $token ?>&sPlatform=<?= $platformName ?>&channelId=<?= $taskPlatformName; ?>"></iframe>
    <div id="choose-view-bottom"></div>
  </body>
</html>
<?php
}