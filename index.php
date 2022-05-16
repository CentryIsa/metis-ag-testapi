<?php

/**
https://forge.autodesk.com/developer/documentation

Warning! Edit the .env file to fit your needs!
With my account the file is not translated properly, I did not try yours.
Call this script via:
php index.php test6 example.ifc https://downloads.ueberbau.tech/AC-20-Smiley-West-10-Bldg.ifc
*/

/* Just for testing, sorry for that */
if(!isset($argv)) $argv							= array();
/*
$argv[0]										= basename(__FILE__);
$argv[1]										= 'test6';
$argv[2]										= 'example.ifc';
$argv[3]										= 'https://downloads.ueberbau.tech/AC-20-Smiley-West-10-Bldg.ifc';
*/

$mRegisterArg									= ini_get('register_argc_argv');
if(empty($mRegisterArg) && !isset($argv)) {
	die('ERROR: You must enable "register_argc_argv" to use this script');
} elseif(count($argv) < 4) {
	die('ERROR: Please specify all 3 arguments when calling this script');
}
$sBucket										= $argv[1];
// Specify another (standard) directory if it's needed
$sLocalFile										= __DIR__.'/'.$argv[2];
$sRemoteFile									= $argv[3];

set_time_limit(0);
session_start();

// If you do not want to use Composer provide data in the .env file to $_ENV
require_once(__DIR__.'/vendor/autoload.php');
require_once(__DIR__.'/ApiTest.class.php');

$oEnv											= Dotenv\Dotenv::createImmutable(__DIR__);
$oEnv->safeLoad();
$oEnv->required(['API_URL', 'CLIENT_ID', 'CLIENT_SECRET'])->notEmpty();

$oApiTest										= ApiTest::getInstance();
$aBuckets										= $oApiTest->apiGetBuckets();
if(!isset($aBuckets[$sBucket])) {
	// Hint: Your bucket name is prefixed by your API key!
	$oBucket									= $oApiTest->apiCreateBucket($sBucket);
} else {
	$oBucket									= $aBuckets[$sBucket];
}
$oBucketInfo									= $oApiTest->apiGetBucketInfo($oBucket->_name);
if(!isset($oBucketInfo->bucketKey)) {
	die('ERROR: Bucket does not exist or data could not be retrieved');
}

// Quick'm'dirty again, we're running out of time
if(!file_exists($sLocalFile)) {
	$oApiTest->clientDownloadFile($sLocalFile, $sRemoteFile);
}
$sFileName										= basename($sLocalFile);
try {
	$oUploadInfo								= $oApiTest->apiGetObject($sBucket, $sFileName);
} catch(Exception $oError) {
	$oUpload									= $oApiTest->clientUploadFile($sBucket, $sLocalFile);
	$oUploadInfo								= $oApiTest->apiGetObject($sBucket, $sFileName);
}

#$bTranslationDelete								= $oApiTest->apiDeleteTranslationRequest($oUploadInfo->objectId);
#print($bTranslationDelete === TRUE ? 'Deletion DONE' : 'Deletion FAILED'); exit;

$mTranslationStatus								= $oApiTest->apiGetTranslationStatus($oUploadInfo->objectId);
if($mTranslationStatus === NULL) {
	// Job not created yet
	$mTranslationStatus							= $oApiTest->apiRequestTranslation($oUploadInfo->objectId);
}

print('Status: '.$mTranslationStatus->status.', Progress: '.$mTranslationStatus->progress);

?>