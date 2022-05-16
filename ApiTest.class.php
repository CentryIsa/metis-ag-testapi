<?php

class ApiTest {

	private $aClientHeaders							= [
		'Content-Type'									=> 'application/json'
	];
	private static $bDebug							= FALSE;
	private $oCurl									= NULL;
	private static $oObject 						= NULL;
	private $oRequest								= NULL;
	private $sApiToken								= '';
	private $sApiTokenType							= 'Bearer';
	private $iApiTokenExpires						= 0;
	private $sApiRegion								= 'EMEA';
	private $bSslVerifyHost							= FALSE;
	private $bSslVerifyPeer							= FALSE;

	private function __construct() {
		self::debug('Constructor requested');
		if(!empty($_ENV['API_REGION']))
			$this->sApiRegion						= $_ENV['API_REGION'];
		if(!empty($_ENV['SSL_VERIFY_HOST']))
			$this->bSslVerifyHost					= 2;
		if(!empty($_ENV['SSL_VERIFY_PEER']))
			$this->bSslVerifyPeer					= TRUE;
		$this->oCurl								= curl_init();
		if(empty($this->sApiToken)) {
			self::debug('No token present, requesting new one');
			$this->apiAuthenticate();
		}
	}

	public function __destruct() {
		curl_close($this->oCurl);
		self::debug('Destructing object, bye bye...'.PHP_EOL.'------------------------------------------------------'.PHP_EOL.PHP_EOL);
	}

	public static function getInstance() {
		if(self::$oObject === NULL) {
			self::$oObject = new self;
		}
		return self::$oObject;
	}

	/**
	 * @description Quick'n'dirty, sorry for that
	 */
	public static function debug($sString, $bPrint=FALSE) {
		if(self::$bDebug !== TRUE) return;
		file_put_contents(__DIR__.'/log.txt', date('Y-m-d H:i:s').' '.$sString.PHP_EOL, FILE_APPEND);
		if($bPrint === TRUE)
			echo $sString;
	}

	/**
	 * @TODO We should check wether we got a valid response (and token).
	 * We'll do that later - if we've time for that
	 * Scopes are not minimized properly - running out of time, so get along with it ^^
	 */
	private function apiAuthenticate() {
		if(empty($_ENV['CLIENT_ID']) || empty($_ENV['CLIENT_SECRET'])) {
			throw new Exception('ERROR: No credentials provided');
		}
		$aOriginalHeaders							= $this->aClientHeaders;
		try {
			$oResponse								= $this->clientRequest(
				'POST',
				'/authentication/v1/authenticate',
				[
					'headers'							=> [
						'Content-Type'						=> 'application/x-www-form-urlencoded'
					],
					'data'						=> [
						'client_id'							=> $_ENV['CLIENT_ID'],
						'client_secret'						=> $_ENV['CLIENT_SECRET'],
						'grant_type'						=> 'client_credentials',
						'scope'								=> 'data:read data:write data:create data:search bucket:create bucket:read bucket:update bucket:delete code:all account:read account:write user-profile:read viewables:read'
					]
				]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(empty($oResponse->access_token) || empty($oResponse->expires_in)) {
			throw new Exception('ERROR: Invalid response or values while authenticating - "access token" or "expires in" value not valid');
		}
		$this->sApiToken							= $oResponse->access_token;
		$this->iApiTokenExpires						= $oResponse->expires_in;
		foreach($aOriginalHeaders as $sIndex => $sValue) {
			$this->clientSetHeader($sIndex, $sValue);
		}
		$this->clientSetHeader('Authorization', 'Bearer '.$this->sApiToken);
		$this->clientSetHeader('x-ads-region', $this->sApiRegion);
		return TRUE;
	}

	public function apiCreateBucket($sBucket, $sPolicyKey='transient') {
		$sRequestBucket								= strtolower($this->getApiClientId().'_'.$sBucket);
		try {
			$oResponse								= $this->clientRequest(
				'POST',
				'/oss/v2/buckets',
				[
					'body'								=> '{"bucketKey": "'.$sRequestBucket.'", "policyKey": "'.$sPolicyKey.'"}'
				]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		$aBuckets									= $this->apiGetBuckets();
		if(!isset($aBuckets[$sBucket])) {
			throw new Exception('ERROR: Bucket ('.$sBucket.') could not be created');
		}
		return $aBuckets[$sBucket];
	}

	public function apiGetBuckets() {
		try {
			$oResponse								= $this->clientRequest(
				'GET',
				'/oss/v2/buckets',
				[]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(!isset($oResponse->items)) {
			throw new Exception('ERROR: Something went wrong, no buckets received');
		}

		// We are providing extended bucket array for better readabilty.
		// Extended (not original) keys are prefixed by '_'
		$aReturnBuckets								= array();
		foreach((array)$oResponse->items as $iLoop => $oBucket) {
			$sClientId								= $this->getApiClientId();
			$sBucketKey								= $oBucket->bucketKey;
			$sBucketName							= substr($sBucketKey, (strlen($sClientId)+1), strlen($sBucketKey));
			$oBucket->_name							= $sBucketName;
			$aReturnBuckets[$sBucketName]			= $oBucket;
		}
		return $aReturnBuckets;
	}

	public function apiGetBucketInfo($sBucket) {
		$sClientId									= $this->getApiClientId();
		$sRequestBucket								= strtolower($this->getApiClientId().'_'.$sBucket);
		try {
			$oResponse								= $this->clientRequest(
				'GET',
				'/oss/v2/buckets/'.$sRequestBucket.'/details',
				[]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(!isset($oResponse->bucketKey)) {
			throw new Exception('ERROR: Something went wrong, no bucket received');
		}
		
		// We are providing extended bucket data for better readabilty.
		// Extended (not original) keys are prefixed by '_'
		$oBucket									= $oResponse;
		$sBucketKey									= $oBucket->bucketKey;
		$sBucketName								= substr($sBucketKey, (strlen($sClientId)+1), strlen($sBucketKey));
		$oBucket->_name								= $sBucketName;
		return $oBucket;
	}

	public function apiGetObject($sBucket, $sObjectName) {
		if(empty($sBucket)) {
			throw new Exception('ERROR: No bucket provided');
		}
		if(empty($sObjectName)) {
			throw new Exception('ERROR: No object name provided');
		}
		$sRequestBucket								= strtolower($this->getApiClientId().'_'.$sBucket);
		try {
			$oResponse								= $this->clientRequest(
				'GET',
				'/oss/v2/buckets/'.$sRequestBucket.'/objects/'.$sObjectName.'/details',
				[]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(!isset($oResponse->objectKey)) {
			throw new Exception('ERROR: Something went wrong, no object received');
		}
		return $oResponse;
	}

	public function apiDeleteTranslationRequest($sUrn) {
		if(empty($sUrn)) {
			throw new Exception('ERROR: No urn provided');
		}
		$sRequestUrn								= base64_encode($sUrn);
		try {
			$oResponse								= $this->clientRequest(
				'DELETE',
				'/modelderivative/v2/designdata/'.$sRequestUrn.'/manifest',
				[],
				TRUE
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(isset($oResponse->result) && $oResponse->result == 'success') {
			return TRUE;
		}
		return FALSE;
	}

	public function apiGetTranslationStatus($sUrn) {
		if(empty($sUrn)) {
			throw new Exception('ERROR: No urn provided');
		}
		$sRequestUrn								= base64_encode($sUrn);
		try {
			$oResponse								= $this->clientRequest(
				'GET',
				'/modelderivative/v2/designdata/'.$sRequestUrn.'/manifest',
				[],
				TRUE
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(isset($oResponse->http_code) && $oResponse->http_code == 404) {
			return NULL;
		}
		if(!isset($oResponse->status)) {
			print_r($oResponse); exit;
			throw new Exception('ERROR: Something went wrong, no object received');
		}
		return $oResponse;
	}

	public function apiRequestTranslation($sUrn) {
		if(empty($sUrn)) {
			throw new Exception('ERROR: No urn provided');
		}
		$mTranslationStatus							= $this->apiGetTranslationStatus($sUrn);
		if($mTranslationStatus !== NULL) {
			return $mTranslationStatus;
		}
		$sRequestUrn								= base64_encode($sUrn);
		$aJson										= [
			'input'										=> [
				'urn'										=> $sRequestUrn,
				'compressedUrn'								=> FALSE
			],
			'output'									=> [
				'destination'								=> [
					'region'									=> $this->sApiRegion
				],
				'formats'									=> [[
					'type'										=> 'svf2', // svf2 is in public beta at no cost, better performance
					'views'										=> ['2d', '3d'],
					'advanced'									=> [
						'conversionMethod'							=> 'modern',
						'buildingStoreys'							=> 'hide',
						'spaces'									=> 'hide',
						'openingElements'							=> 'hide' // there's is a typo in the docs missing the trailing s
					]
				]]
			]
		];
		$sJson										= json_encode($aJson);
		try {
			$oResponse								= $this->clientRequest(
				'POST',
				'/modelderivative/v2/designdata/job',
				[
					'body'								=> $sJson
				]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(!isset($oResponse->result)) {
			throw new Exception('ERROR: Something went wrong, could not create hob');
		}
		return $this->apiGetTranslationStatus($sUrn);
	}

	public function clientDownloadFile($sLocalFile, $sRemoteFile) {
		if(file_exists($sLocalFile)) {
			throw new Exception('ERROR: Local file already exists');
		}
		$hLocalFile									= fopen($sLocalFile, "w");
		if(!$hLocalFile) {
			throw new Exception('ERROR: Local file cannot be writtem');
		}
		curl_setopt($this->oCurl, CURLOPT_URL, $sRemoteFile);
		curl_setopt($this->oCurl, CURLOPT_FILE, $hLocalFile);
		curl_exec($this->oCurl);
		fclose($hLocalFile);
		if(curl_errno($this->oCurl)) {
			throw new Exception('ERROR: An error occured, error code: '.curl_error($this->oCurl));
		} else {
			$aHttpStatus							= curl_getinfo($this->oCurl);
			if($aHttpStatus["http_code"] == 200) {
				// Fine, everything worked as expected
			} else {
				throw new Exception('ERROR: An error occured, http error code: '.$aHttpStatus["http_code"]);
			}
		}
		if(!file_exists($sLocalFile)) {
			throw new Exception('ERROR: Download of remote ressource failed');
		}
	}

	private function clientPrepare() {
		$aHeaders									= array_map(function($sKey, $sValue){return $sKey.": ".$sValue;}, array_keys($this->aClientHeaders), array_values($this->aClientHeaders));
		curl_setopt($this->oCurl, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($this->oCurl, CURLOPT_SSL_VERIFYHOST, $this->bSslVerifyHost);
		curl_setopt($this->oCurl, CURLOPT_SSL_VERIFYPEER, $this->bSslVerifyPeer);
		curl_setopt($this->oCurl, CURLOPT_HTTPHEADER, $aHeaders);
	}

	private function clientRequest($sMethod, $sUri, $aOptions=[], $bReturnOnError=FALSE) {
		$sMethod									= strtoupper($sMethod);
		$sCallUrl									= $_ENV['API_URL'].$sUri;
		if(isset($aOptions['headers'])) {
			foreach($aOptions['headers'] as $sIndex => $mValue) {
				$this->clientSetHeader($sIndex, $mValue);
			}
		}
		$this->clientPrepare();
		if($sMethod === 'POST' || $sMethod == 'PUT') {
			curl_setopt($this->oCurl, CURLOPT_CUSTOMREQUEST, $sMethod);
			curl_setopt($this->oCurl, CURLOPT_POST, true);
			if(isset($aOptions['data']))
				curl_setopt($this->oCurl, CURLOPT_POSTFIELDS, http_build_query($aOptions['data']));
			if(isset($aOptions['body']))
				curl_setopt($this->oCurl, CURLOPT_POSTFIELDS, $aOptions['body']);
		} else {
			curl_setopt($this->oCurl, CURLOPT_CUSTOMREQUEST, $sMethod);
			#curl_setopt($this->oCurl, CURLOPT_POSTFIELDS, "{}");
		}
		curl_setopt($this->oCurl, CURLOPT_URL, $sCallUrl);
		curl_setopt($this->oCurl, CURLOPT_HEADER, 1);

		$sResponse									= curl_exec($this->oCurl);
		$iHttpHeaderSize							= curl_getinfo($this->oCurl, CURLINFO_HEADER_SIZE);
		$aHttpHeaders								= $this->httpParseHeaders(substr($sResponse, 0, $iHttpHeaderSize));
		$sHttpBody									= trim(substr($sResponse, $iHttpHeaderSize));
		$aResponseInfo								= curl_getinfo($this->oCurl);

		if($aResponseInfo['http_code'] != 200) {
			if($bReturnOnError === TRUE) {
				return (object)[
					'http_code'							=> $aResponseInfo['http_code'],
					'headers'							=> $aHttpHeaders
				];
			}
			$sCurlError								= curl_error($this->oCurl);
			print_r($this->aClientHeaders);
			echo $sMethod.' '.$sCallUrl.PHP_EOL.PHP_EOL;
			if(!empty($sCurlError))
				echo $sCurlError.PHP_EOL.PHP_EOL;
			echo $sResponse.PHP_EOL.PHP_EOL;
			throw new Exception('ERROR: Something went wrong, request returned -'.$aResponseInfo['http_code'].'-');
		}
		return json_decode($sHttpBody);
	}

	private function clientSetHeader($sIndex, $sValue) {
		$this->aClientHeaders[$sIndex]				= $sValue;
	}

	public function clientUploadFile($sBucket, $sLocalFile) {
		if(empty($sBucket)) {
			throw new Exception('ERROR: No bucket provided');
		}
		if(!file_exists($sLocalFile)) {
			throw new Exception('ERROR: Local file does not exists');
		}
		$sRequestBucket								= strtolower($this->getApiClientId().'_'.$sBucket);
		$sFileName									= basename($sLocalFile);
		try {
			$oResponse								= $this->clientRequest(
				'PUT',
				'/oss/v2/buckets/'.$sRequestBucket.'/objects/'.$sFileName,
				[
					'data'								=> [
						'file_contents'						=> $sLocalFile
					]
				]
			);
		} catch(Exception|\Exception|Throwable|\Throwable $oException) {
			self::debug('ERROR: '.$oException->getMessage(), TRUE);
			return FALSE;
		}
		if(!isset($oResponse->objectKey)) {
			throw new Exception('ERROR: Something went wrong, no bucket received');
		}
		return $oResponse;
	}

	public function getApiClientId() {
		return $_ENV['CLIENT_ID'];
	}

	/**
	 * @see ApiClient - Stolen method, I'm sorry
	 */
	protected function httpParseHeaders($raw_headers) {
		// ref/credit: http://php.net/manual/en/function.http-parse-headers.php#112986
		$headers = [];
		$key = '';
		foreach (explode("\n", $raw_headers) as $h) {
			$h = explode(':', $h, 2);
			if (isset($h[1])) {
				if ( ! isset($headers[$h[0]])) {
					$headers[$h[0]] = trim($h[1]);
				} elseif (is_array($headers[$h[0]])) {
					$headers[$h[0]] = array_merge($headers[$h[0]], [trim($h[1])]);
				} else {
					$headers[$h[0]] = array_merge([$headers[$h[0]]], [trim($h[1])]);
				}
				$key = $h[0];
			} else {
				if (substr($h[0], 0, 1) === "\t") {
					$headers[$key] .= "\r\n\t" . trim($h[0]);
				} elseif ( ! $key) {
					$headers[0] = trim($h[0]);
				}
				trim($h[0]);
			}
		}
	}

}

?>