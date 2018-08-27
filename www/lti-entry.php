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

$sLocale = isset($_GET['sLocale']) ? $_GET['sLocale'] : '';
$viewNames = [
    'fr' => [
        'task' => 'Exercice',
        'editor' => 'Résoudre',
        'hints' => 'Conseils',
        'solution' => 'Solution',
        ],
    'en' => [
        'task' => 'Task',
        'editor' => 'Solve',
        'hints' => 'Hints',
        'solution' => 'Solution',
        ]
];
$viewOrder = ['task', 'hints', 'editor', 'solution'];
$themeCss = '';
$themeButtonsPosition = 'top';

if ($themeName == 'funtelecom') {
    $viewNames = [
        'fr' => [
            'task' => 'Consignes',
            'solution' => 'Solution',
            'editor' => 'À vous de jouer',
            'hints' => 'Indices'
            ],
        'en' => [
            'task' => 'Problem statement',
            'solution' => 'Solution',
            'editor' => 'Submission',
            'hints' => 'Hints'
            ]
    ];
	$themeCss = "
body {
    padding-top: 30px;
}
#choose-view-top {
	display: flex;
    width: 100%;
    position: fixed;
    top: 0px;
    left: 0px;
    background-color: white;
    z-index: 9999;
}
iframe {
    position: relative;
}
#choose-view-bottom {
	display: none;
	width: 100%;
	padding-top: 10px;
    padding-bottom: 10px;
}
.btn-info {
  border-bottom: 3px solid #2aa3ff !important;
  color: #333 !important;
  background-color:white !important;
}
.btn-default:hover {
  color: #333 !important;
  background-color: white !important;
  border-top:0px!important;
  border-left:0px!important;
  border-right:0px!important;
  border-bottom: 1px solid grey;
}
.choose-view-button {
  flex: 1 0 auto;
  border: 0px;
  border-bottom: 1px solid grey;
  height: 40px;
  vertical-align: middle;
  font-size: 14pt;
  border-radius: 0;
  color: #333;
  font-size: 14pt;
  cursor: pointer;
  text-align: center;
  box-shadow:none !important;
  -webkit-box-shadow:none !important;
  outline:none !important;
}
.choose-view-button:focus {
  border-top: 0px;
  border-left:0px;
  border-right:0px;
}
.btn-info:hover {
  color: #fff;
  background-color: #31b0d5;
  border-bottom: 3px solid #2aa3ff !important;
}
";
	$themeButtonsPosition = 'topbottom';
}

if(isset($viewNames[substr($sLocale, 0, 2)])) {
    $viewNames = $viewNames[substr($sLocale, 0, 2)];
} else {
    $viewNames = $viewNames['fr'];
}

function redirectPost($url, array $data) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, 1);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, 1);
    curl_setopt($ch, CURLOPT_HEADER, 0);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1);

    $response = curl_exec($ch);
    die($response);
}

function saveUser($user) {
	global $db;
  //var_dump($user);
	//$firstName = $user->firstName;
	//$lastName = $user->lastName;
	// TODO
	$firstName = '';
	$lastName = '';
	$email = $user->email;
	$lti_user_id = $user->getId();
    if(!isset($user->getResourceLink()->lti_resource_link_id)) {
        // When the link is called for the very first time from edX, it doesn't
        // work and needs reloading. We simulate the reloading ourselves.

        // We already reloaded once, avoid looping
        if(isset($_GET['reload']) && $_GET['reload']) {
            die('Erreur lors de la connexion LTI. Veuillez recharger la page.');
        }

        // We're replaying the OAuth request, so we need to remove the fact the
        // nonce was already used
        $stmt = $db->prepare("DELETE FROM lti_nonce WHERE consumer_key = :consumer_key AND value = :value;");
        $stmt->execute(['consumer_key' => $_POST['oauth_consumer_key'], 'value' => $_POST['oauth_nonce']]);

        // Replay the OAuth request
        $url = (isset($_SERVER['HTTPS']) ? "https" : "http") . '://' . $_SERVER['HTTP_HOST'] . $_SERVER['REQUEST_URI'];
        redirectPost($url, $_POST);
        die();
    }
	$lti_context_id = $user->getResourceLink()->lti_resource_link_id;
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
	global $taskUrl, $db, $sLocale;
	$userId = saveUser($user);
	if (!$userId) {
		die('Une erreur est survenue, merci de recharger la page (ERR01)');
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
	$token = generateToken($userId, $userTask, $platformData, $taskUrl, $taskPlatform['name'], $user);
	if (!$token) {
		die('impossible de générer le token');
	}
	$stmt = $db->prepare('select sAnswer from api_submissions where idUser = :idUser and sTaskTextId = :idTask order by sDate desc limit 1;');
	$stmt->execute(['idUser' => $userId, 'idTask' => $taskUrl]);
	$lastAnswer = $stmt->fetchColumn();
	printPage($token, $taskUrl, $platformData['name'], $taskPlatform['name'], $sLocale, $taskPlatform['bUsesTokens'], $userTask, $lastAnswer);
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

function printPage($token, $taskUrl, $platformName, $taskPlatformName, $sLocale, $bUsesTokens, $userTask, $lastAnswer) {
	global $config, $viewNames, $themeCss, $themeButtonsPosition, $viewOrder;
	$state = ($userTask && isset($userTask['sState'])) ? $userTask['sState'] : '';
	$state = $state ? $state : '';
	$lastAnswer = $lastAnswer ? : '';
    $containedUrl = $taskUrl . (strpos($taskUrl, '?') === false ? '?' : '&') . 'sToken=' . $token . '&sPlatform=' . $platformName . '&channelId=' . $taskPlatformName;
    if($sLocale) {
       $containedUrl .= '&sLocale=' . $sLocale;
    }
	$returnUrl = $config->baseUrl . '/api-entry.php?taskPlatformName='.$taskPlatformName;
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
    	var lastAnswer = <?= json_encode($lastAnswer); ?>;
    	var lastState = <?= json_encode($state) ?>;
    	var taskPlatformName = '<?= $taskPlatformName; ?>';
    	var usesTokens = <?= $bUsesTokens ?>;
    	var returnUrl = '<?= $returnUrl ?>';
    	var bAccessSolution = <?= $userTask['bAccessSolution'] ?>;
    	var viewNames = <?= json_encode($viewNames); ?>;
      var viewOrder = <?= json_encode($viewOrder); ?>;
    	var buttonsPosition = <?= json_encode($themeButtonsPosition); ?>;
    </script>
  </head>
  <body>
    <div id="choose-view-top"></div>
    <iframe style="width: 100%; height:800px;" id="taskIframe" src="<?= $containedUrl ?>"></iframe>
    <div id="choose-view-bottom"></div>
  </body>
</html>
<?php
}
