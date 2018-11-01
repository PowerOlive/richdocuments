<?php
/**
 * ownCloud - Richdocuments App
 *
 * @author Victor Dubiniuk
 * @copyright 2014 Victor Dubiniuk victor.dubiniuk@gmail.com
 *
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 */

namespace OCA\Richdocuments\Controller;

use \OC\Files\View;
use \OCP\AppFramework\Controller;
use OCP\Files\Folder;
use OCP\Files\NotFoundException;
use \OCP\IRequest;
use \OCP\IConfig;
use \OCP\IL10N;
use \OCP\AppFramework\Http\ContentSecurityPolicy;
use \OCP\AppFramework\Http;
use \OCP\AppFramework\Http\JSONResponse;
use \OCP\AppFramework\Http\TemplateResponse;
use \OCP\ICacheFactory;
use \OCP\ILogger;

use \OCA\Richdocuments\AppConfig;
use \OCA\Richdocuments\Db;
use \OCA\Richdocuments\Helper;
use \OCA\Richdocuments\Storage;
use \OCA\Richdocuments\DownloadResponse;
use OCP\Share\Exceptions\ShareNotFound;

class ResponseException extends \Exception {
	private $hint;

	public function __construct($description, $hint = '') {
		parent::__construct($description);
		$this->hint = $hint;
	}

	public function getHint() {
		return $this->hint;
	}
}

class DocumentController extends Controller {

	private $uid;
	private $l10n;
	private $settings;
	private $appConfig;
	private $cache;
	private $logger;
	private $storage;
	const ODT_TEMPLATE_PATH = '/assets/odttemplate.odt';

	// Signifies LOOL that document has been changed externally in this storage
	const LOOL_STATUS_DOC_CHANGED = 1010;

	public function __construct($appName,
								IRequest $request,
								IConfig $settings,
								AppConfig $appConfig,
								IL10N $l10n,
								$uid,
								ICacheFactory $cache,
								ILogger $logger,
								Storage $storage){
		parent::__construct($appName, $request);
		$this->uid = $uid;
		$this->l10n = $l10n;
		$this->settings = $settings;
		$this->appConfig = $appConfig;
		$this->cache = $cache->create($appName);
		$this->logger = $logger;
		$this->storage = $storage;
	}

	/**
	 * @param \SimpleXMLElement $discovery_parsed
	 * @param string $mimetype
	 */
	private function getWopiSrcUrl($discovery_parsed, $mimetype) {
		if(is_null($discovery_parsed) || $discovery_parsed == false) {
			return null;
		}

		$result = $discovery_parsed->xpath(sprintf('/wopi-discovery/net-zone/app[@name=\'%s\']/action', $mimetype));
		if ($result && count($result) > 0) {
			return array(
				'urlsrc' => (string)$result[0]['urlsrc'],
				'action' => (string)$result[0]['name']
			);
		}

		return null;
	}

	/**
	 * Log the user with given $userid.
	 * This function should only be used from public controller methods where no
	 * existing session exists, for example, when loolwsd is directly calling a
	 * public method with its own access token. After validating the access
	 * token, and retrieving the correct user with help of access token, it can
	 * be set as current user with help of this method.
	 *
	 * @param string $userid
	 */
	private function loginUser($userid) {
		\OC_Util::tearDownFS();

		$users = \OC::$server->getUserManager()->search($userid, 1, 0);
		if (count($users) > 0) {
			$user = array_shift($users);
			if (strcasecmp($user->getUID(), $userid) === 0) {
				// clear the existing sessions, if any
				\OC::$server->getSession()->close();

				// initialize a dummy memory session
				$session = new \OC\Session\Memory('');
				// wrap it
				$cryptoWrapper = \OC::$server->getSessionCryptoWrapper();
				$session = $cryptoWrapper->wrapSession($session);
				// set our session
				\OC::$server->setSession($session);

				\OC::$server->getUserSession()->setUser($user);
			}
		}

		\OC_Util::setupFS();
	}

	/**
	 * Log out the current user
	 * This is helpful when we are artifically logged in as someone
	 */
	private function logoutUser() {
		\OC_Util::tearDownFS();

		\OC::$server->getSession()->close();
	}

	private function responseError($message, $hint = ''){
		$errors = array('errors' => array(array('error' => $message, 'hint' => $hint)));
		$response = new TemplateResponse('', 'error', $errors, 'error');
		return $response;
	}

    /**
     * Return the original wopi url or test wopi url
     * @param boolean $tester
     */
	private function getWopiUrl($tester) {
		$wopiurl = '';
		if ($tester) {
			$wopiurl = $this->appConfig->getAppValue('test_wopi_url');
		} else {
			$wopiurl = $this->appConfig->getAppValue('wopi_url');
		}

		return $wopiurl;
	}

	/**
     * Return true if the currently logged in user is a tester.
     * This depends on whether current user is the member of one of the groups
     * mentioned in settings (test_server_groups)
     */
     private function isTester() {
		 $tester = false;

         $user = \OC::$server->getUserSession()->getUser();
		 if ($user === null) {
			 return false;
		 }

		 $uid = $user->getUID();
         $testgroups = array_filter(explode('|', $this->appConfig->getAppValue('test_server_groups')));
         $this->logger->debug('Testgroups are {testgroups}', [
             'app' => $this->appName,
             'testgroups' => $testgroups
         ]);
         foreach ($testgroups as $testgroup) {
             $test = \OC::$server->getGroupManager()->get($testgroup);
             if ($test !== null && sizeof($test->searchUsers($uid)) > 0) {
                 $this->logger->debug('User {user} found in {group}', [
                     'app' => $this->appName,
                     'user' => $uid,
                     'group' => $testgroup
                 ]);

				 $tester = true;
				 break;
             }
         }

         return $tester;
     }

	/** Return the content of discovery.xml - either from cache, or download it.
	 * @return string
	 */
	private function getDiscovery(){
		$tester = $this->isTester();
		$wopiRemote = $this->getWopiUrl($tester);
		$discoveryKey = 'discovery.xml';
		if ($tester) {
			$discoveryKey = 'discovery.xml_test';
		}
		// Provides access to information about the capabilities of a WOPI client
		// and the mechanisms for invoking those abilities through URIs.
		$wopiDiscovery = $wopiRemote . '/hosting/discovery';

		// Read the memcached value (if the memcache is installed)
		$discovery = $this->cache->get($discoveryKey);

		if (is_null($discovery)) {
			$this->logger->debug('getDiscovery(): Not found in cache; Fetching discovery.xml', ['app' => $this->appName]);

			$contact_admin = $this->l10n->t('Please contact the "%s" administrator.', array($wopiRemote));

			try {
				$wopiClient = \OC::$server->getHTTPClientService()->newClient();
				$discovery = $wopiClient->get($wopiDiscovery)->getBody();
			}
			catch (\Exception $e) {
				$error_message = $e->getMessage();
				if (preg_match('/^cURL error ([0-9]*):/', $error_message, $matches)) {
					$admin_check = $this->l10n->t('Please ask your administrator to check the Collabora Online server setting. The exact error message was: ') . $error_message;

					$curl_error = $matches[1];
					switch ($curl_error) {
					case '1':
						throw new ResponseException($this->l10n->t('Collabora Online: The protocol specified in "%s" is not allowed.', array($wopiRemote)), $admin_check);
					case '3':
						throw new ResponseException($this->l10n->t('Collabora Online: Malformed URL "%s".', array($wopiRemote)), $admin_check);
					case '6':
						throw new ResponseException($this->l10n->t('Collabora Online: Cannot resolve the host "%s".', array($wopiRemote)), $admin_check);
					case '7':
						throw new ResponseException($this->l10n->t('Collabora Online: Cannot connect to the host "%s".', array($wopiRemote)), $admin_check);
					case '60':
						throw new ResponseException($this->l10n->t('Collabora Online: SSL certificate is not installed.'), $this->l10n->t('Please ask your administrator to add ca-chain.cert.pem to the ca-bundle.crt, for example "cat /etc/loolwsd/ca-chain.cert.pem >> <server-installation>/resources/config/ca-bundle.crt" . The exact error message was: ') . $error_message);
					}
				}
				throw new ResponseException($this->l10n->t('Collabora Online unknown error: ') . $error_message, $contact_admin);
			}

			if (!$discovery) {
				throw new ResponseException($this->l10n->t('Collabora Online: Unable to read discovery.xml from "%s".', array($wopiRemote)), $contact_admin);
			}

			$this->logger->debug('Storing the discovery.xml under key ' . $discoveryKey . ' to the cache.', ['app' => $this->appName]);
			$this->cache->set($discoveryKey, $discovery, 3600);
		}
		else {
			$this->logger->debug('getDiscovery(): Found in cache', ['app' => $this->appName]);
		}

		return $discovery;
	}

	/**
	 * Prepare document(s) structure
	 */
	private function prepareDocuments($rawDocuments){
		$discovery_parsed = null;
		try {
			$discovery = $this->getDiscovery();

			$loadEntities = libxml_disable_entity_loader(true);
			$discovery_parsed = simplexml_load_string($discovery);
			libxml_disable_entity_loader($loadEntities);

			if ($discovery_parsed === false) {
				$this->cache->remove('discovery.xml');
				$wopiRemote = $this->getWopiUrl($this->isTester());

				return array(
					'status' => 'error',
					'message' => $this->l10n->t('Collabora Online: discovery.xml from "%s" is not a well-formed XML string.', array($wopiRemote)),
					'hint' => $this->l10n->t('Please contact the "%s" administrator.', array($wopiRemote))
				);
			}
		}
		catch (ResponseException $e) {
			return array(
				'status' => 'error',
				'message' => $e->getMessage(),
				'hint' => $e->getHint()
			);
		}

		$fileIds = array();
		$documents = array();
		$lolang = strtolower(str_replace('_', '-', $this->settings->getUserValue($this->uid, 'core', 'lang', 'en')));
		foreach ($rawDocuments as $key=>$document) {
			if (is_object($document)){
				$documents[] = $document->getData();
			} else {
				$documents[$key] = $document;
			}

			$documents[$key]['icon'] = preg_replace('/\.png$/', '.svg', \OCP\Template::mimetype_icon($document['mimetype']));
			$documents[$key]['hasPreview'] = \OC::$server->getPreviewManager()->isMimeSupported($document['mimetype']);
			$ret = $this->getWopiSrcUrl($discovery_parsed, $document['mimetype']);
			$documents[$key]['urlsrc'] = $ret['urlsrc'];
			$documents[$key]['action'] = $ret['action'];
			$documents[$key]['lolang'] = $lolang;
			$fileIds[] = $document['fileid'];
		}

		usort($documents, function($a, $b){
			return @$b['mtime']-@$a['mtime'];
		});

		return array(
			'status' => 'success', 'documents' => $documents
		);
	}

	/**
	 * Strips the path and query parameters from the URL.
	 *
	 * @param string $url
	 * @return string
	 */
	private function domainOnly($url) {
		$parsed_url = parse_url($url);
		$scheme = isset($parsed_url['scheme']) ? $parsed_url['scheme'] . '://' : '';
		$host   = isset($parsed_url['host']) ? $parsed_url['host'] : '';
		$port   = isset($parsed_url['port']) ? ':' . $parsed_url['port'] : '';
		return "$scheme$host$port";
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 */
	public function index($fileId, $token){
		$wopiRemote = $this->getWopiUrl($this->isTester());
		if (($parts = parse_url($wopiRemote)) && isset($parts['scheme']) && isset($parts['host'])) {
			$webSocketProtocol = "ws://";
			if ($parts['scheme'] == "https") {
				$webSocketProtocol = "wss://";
			}
			$webSocket = sprintf(
				"%s%s%s",
				$webSocketProtocol,
				$parts['host'],
				isset($parts['port']) ? ":" . $parts['port'] : "");
		}
		else {
			return $this->responseError($this->l10n->t('Collabora Online: Invalid URL "%s".', array($wopiRemote)), $this->l10n->t('Please ask your administrator to check the Collabora Online server setting.'));
		}

		\OC::$server->getNavigationManager()->setActiveEntry( 'richdocuments_index' );
		$retVal = array(
			'enable_previews' => $this->settings->getSystemValue('enable_previews', true),
			'allowShareWithLink' => $this->settings->getAppValue('core', 'shareapi_allow_links', 'yes'),
			'wopi_url' => $webSocket,
			'doc_format' => $this->appConfig->getAppValue('doc_format'),
			'instanceId' => $this->settings->getSystemValue('instanceid'),
			'canonical_webroot' => $this->appConfig->getAppValue('canonical_webroot')
		);

		if (!is_null($fileId)) {
			$docRetVal = $this->getDocIndex($fileId, $token);
			$retVal = array_merge($retVal, $docRetVal);
		}

		$response = new TemplateResponse('richdocuments', 'documents', $retVal);
		$policy = new ContentSecurityPolicy();
		$policy->addAllowedFrameDomain($this->domainOnly($wopiRemote));
		$policy->allowInlineScript(true);
		$response->setContentSecurityPolicy($policy);

		return $response;
	}

	private function getDocIndex($fileId, $token) {
		// Get document info
		if ($token == null) {
			$doc = $this->getDocumentByUserAuth($fileId, $this->uid);
		} else {
			$doc = $this->getDocumentByToken($fileId, $token);
		}
		if ($doc == null) {
			return array();
		}

		// Update permissions
		$permissions = $doc['permissions'];
		if (!($doc['action'] === 'edit')) {
			$permissions = $permissions & ~\OCP\Constants::PERMISSION_UPDATE;
		}

		// Get wopi token
		$tokenResult = $this->wopiGetTokenPublic($fileId, $doc['path'], $doc['owner']);

		if ($token == null) {
			// Restrict filesize not possible when edited by public share
			$maxUploadFilesize = \OCP\Util::maxUploadFilesize("/");
		} else {
			// In public links allow max 100MB
			$maxUploadFilesize = 100000000;
		}

		// Create document index
		$docIndex = array(
			'permissions' => $permissions,
			'uploadMaxFilesize' => $maxUploadFilesize,
			'uploadMaxHumanFilesize' => \OCP\Util::humanFileSize($maxUploadFilesize),
			'title' => $doc['name'],
			'fileId' => $doc['fileid'] . '_' . $this->settings->getSystemValue('instanceid'),
			'token' => $tokenResult['token'],
			'urlsrc' => $doc['urlsrc'],
			'path' => $doc['path']
		);

		return $docIndex;
	}


	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 */
	public function publicIndex($token, $fileId){
		return $this->index($fileId, $token);
	}

	/**
	 * @NoAdminRequired
	 */
	public function create(){
		$mimetype = $this->request->post['mimetype'];
		$filename = $this->request->post['filename'];
		$dir = $this->request->post['dir'];

		$view = new View('/' . $this->uid . '/files');
		if (!$dir){
			$dir = '/';
		}

		$basename = $this->l10n->t('New Document.odt');
		switch ($mimetype) {
			case 'application/vnd.oasis.opendocument.spreadsheet':
				$basename = $this->l10n->t('New Spreadsheet.ods');
				break;
			case 'application/vnd.oasis.opendocument.presentation':
				$basename = $this->l10n->t('New Presentation.odp');
				break;
			case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
				$basename = $this->l10n->t('New Document.docx');
				break;
			case 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet':
				$basename = $this->l10n->t('New Spreadsheet.xlsx');
				break;
			case 'application/vnd.openxmlformats-officedocument.presentationml.presentation':
				$basename = $this->l10n->t('New Presentation.pptx');
				break;
			default:
				// to be safe
				$mimetype = 'application/vnd.oasis.opendocument.text';
				break;
		}

		if (!$filename){
			$path = Helper::getNewFileName($view, $dir . '/' . $basename);
		} else {
			$path = $dir . '/' . $filename;
		}

		$content = '';
		if (class_exists('\OC\Files\Type\TemplateManager')){
			$manager = \OC_Helper::getFileTemplateManager();
			$content = $manager->getTemplate($mimetype);
		}

		if (!$content){
			$content = file_get_contents(dirname(__DIR__) . self::ODT_TEMPLATE_PATH);
		}

		$discovery_parsed = null;
		try {
			$discovery = $this->getDiscovery();

			$loadEntities = libxml_disable_entity_loader(true);
			$discovery_parsed = simplexml_load_string($discovery);
			libxml_disable_entity_loader($loadEntities);

			if ($discovery_parsed === false) {
				$this->cache->remove('discovery.xml');
				$wopiRemote = $this->getWopiUrl($this->isTester());

				return array(
					'status' => 'error',
					'message' => $this->l10n->t('Collabora Online: discovery.xml from "%s" is not a well-formed XML string.', array($wopiRemote)),
					'hint' => $this->l10n->t('Please contact the "%s" administrator.', array($wopiRemote))
				);
			}
		}
		catch (ResponseException $e) {
			return array(
				'status' => 'error',
				'message' => $e->getMessage(),
				'hint' => $e->getHint()
			);
		}

		if ($content && $view->file_put_contents($path, $content)){
			$info = $view->getFileInfo($path);
			$ret = $this->getWopiSrcUrl($discovery_parsed, $mimetype);
			$lolang = strtolower(str_replace('_', '-', $this->settings->getUserValue($this->uid, 'core', 'lang', 'en')));
			$response =  array(
				'status' => 'success',
				'fileid' => $info['fileid'],
				'urlsrc' => $ret['urlsrc'],
				'action' => $ret['action'],
				'lolang' => $lolang,
				'data' => \OCA\Files\Helper::formatFileInfo($info)
			);
		} else {
			$response =  array(
				'status' => 'error',
				'message' => (string) $this->l10n->t('Can\'t create document')
			);
		}
		return $response;
	}

	/**
	 * @param array $node
	 * @return null|array
	 */
	private function prepareDocument($node){
		$documents = array();
		$documents[0] = $node;
		$preparedDocuments = $this->prepareDocuments($documents);

		if ($preparedDocuments['status'] === 'success' &&
			$preparedDocuments['documents'] &&
			count($preparedDocuments['documents']) > 0) {
			return $preparedDocuments['documents'][0];
		}

		return null;
	}

	/**
	 * @param $fileId
	 * @param $userId
	 * @return null|array
	 */
	private function getDocumentByUserAuth($fileId, $userId){
		if ($node = $this->storage->getDocumentByUserId($fileId, $userId)) {
			return $this->prepareDocument($node);
		}
		return null;
	}

	/**
	 * @param $fileId
	 * @param $token
	 * @return null|array
	 */
	private function getDocumentByToken($fileId, $token){
		if ($node = $this->storage->getDocumentByToken($fileId, $token)) {
			return $this->prepareDocument($node);
		}
		return null;
	}

	/**
	 * Generates and returns an access token for a given fileId.
	 */
	private function wopiGetTokenPublic($fileId, $path, $editorUid){
		list($fileId, , $version) = Helper::parseFileId($fileId);
		$this->logger->info('wopiGetToken(): Generating WOPI Token for file {fileId}, version {version}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version ]);

		$updatable = true;
		// If token is for some versioned file
		if ($version !== '0') {
			$updatable = false;
		}

		$this->logger->debug('wopiGetToken(): File {fileid} is updatable? {updatable}', [
			'app' => $this->appName,
			'fileid' => $fileId,
			'updatable' => $updatable ]);

		$row = new Db\Wopi();
		$serverHost = $this->request->getServerProtocol() . '://' . $this->request->getServerHost();
		$token = $row->generatePublicFileToken($fileId, $path, $version, (int)$updatable, $serverHost, $editorUid);

		// Return the token.
		$result = array(
			'status' => 'success',
			'token' => $token
		);
		$this->logger->debug('wopiGetToken(): Issued token: {result}', ['app' => $this->appName, 'result' => $result]);
		return $result;
	}

	/**
	 * Generates and returns an access token for a given fileId.
	 */
	private function wopiGetToken($fileId){
		list($fileId, , $version) = Helper::parseFileId($fileId);
		$this->logger->info('wopiGetToken(): Generating WOPI Token for file {fileId}, version {version}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version ]);

		$view = \OC\Files\Filesystem::getView();
		$path = $view->getPath($fileId);
		$updatable = (bool)$view->isUpdatable($path);

		$encryptionManager = \OC::$server->getEncryptionManager();
		if ($encryptionManager->isEnabled()) {
			// Update the current file to be accessible with system public
			// shared key
			$this->logger->debug('wopiGetToken(): Encryption enabled.', ['app' => $this->appName]);
			$owner = $view->getOwner($path);
			$absPath = '/' . $owner . '/files' .  $path;
			$accessList = \OC::$server->getEncryptionFilesHelper()->getAccessList($absPath);
			$accessList['public'] = true;
			$encryptionManager->getEncryptionModule()->update($absPath, $owner, $accessList);
		}

		// Check if the editor (user who is accessing) is in editable group
		// UserCanWrite only if
		// 1. No edit groups are set or
		// 2. if they are set, it is in one of the edit groups
		$editorUid = \OC::$server->getUserSession()->getUser()->getUID();
		$editGroups = array_filter(explode('|', $this->appConfig->getAppValue('edit_groups')));
		if ($updatable && count($editGroups) > 0) {
			$updatable = false;
			foreach($editGroups as $editGroup) {
				$editorGroup = \OC::$server->getGroupManager()->get($editGroup);
				if ($editorGroup !== null && sizeof($editorGroup->searchUsers($editorUid)) > 0) {
					$this->logger->debug("wopiGetToken(): Editor {editor} is in edit group {group}", [
						'app' => $this->appName,
						'editor' => $editorUid,
						'group' => $editGroup
					]);
					$updatable = true;
					break;
				}
			}
		}

		// If token is for some versioned file
		if ($version !== '0') {
			$updatable = false;
		}

		$this->logger->debug('wopiGetToken(): File {fileid} is updatable? {updatable}', [
			'app' => $this->appName,
			'fileid' => $fileId,
			'updatable' => $updatable ]);
		$row = new Db\Wopi();
		$serverHost = $this->request->getServerProtocol() . '://' . $this->request->getServerHost();
		$token = $row->generateFileToken($fileId, $version, (int)$updatable, $serverHost, $editorUid);

		// Return the token.
		$result = array(
			'status' => 'success',
			'token' => $token
		);
		$this->logger->debug('wopiGetToken(): Issued token: {result}', ['app' => $this->appName, 'result' => $result]);
		return $result;
	}

	/**
	 * @NoCSRFRequired
	 * @PublicPage
	 * Generates and returns an access token and urlsrc for a given fileId
	 * for requests that provide secret token set in app settings
	 */
	public function extAppWopiGetData($fileId) {
		$secretToken = $this->request->getParam('secret_token');
		$apps = array_filter(explode(',', $this->appConfig->getAppValue('external_apps')));
		foreach($apps as $app) {
			if ($app !== '') {
				if ($secretToken === $app) {
					$appName = explode(':', $app);
					$this->logger->info('extAppWopiGetData(): External app "{extApp}" authenticated; issuing access token for fileId {fileId}', [
						'app' => $this->appName,
						'extApp' => $appName[0],
						'fileId' => $fileId
					]);
					$retArray = $this->wopiGetToken($fileId);
					if ($doc = $this->getDocumentByUserAuth($fileId, $this->uid)) {
						$retArray['urlsrc'] = $doc['urlsrc'];
					}
					return $retArray;
				}
			}
		}

		return new JSONResponse([], Http::STATUS_UNAUTHORIZED);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * Returns general info about a file.
	 */
	public function wopiCheckFileInfo($fileId){
		$token = $this->request->getParam('access_token');

		list($fileId, , $version) = Helper::parseFileId($fileId);
		$this->logger->warning('wopiCheckFileInfo(): Getting info about file {fileId}, version {version} by token {token}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token ]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		$res = $row->getPathForToken($token);
		if ($res == false) {
			$this->logger->warning('wopiCheckFileInfo(): getPathForToken() failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}

		$userFolder = \OC::$server->getRootFolder()->getUserFolder($res['owner']);
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes)) {
			$this->logger->warning('wopiCheckFileInfo(): No valid file info', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$file = $nodes[0];
		$this->logger->debug('wopiCheckFileInfo(): $file='.$file->getPath(), ['app' => $this->appName]);

		$editorName = \OC::$server->getUserManager()->get($res['editor'])->getDisplayName();
		$result = array(
			'BaseFileName' => $file->getName(),
			'Size' => $file->getSize(),
			'Version' => $version,
			'OwnerId' => $res['owner'],
			'UserId' => $res['editor'],
			'UserFriendlyName' => $editorName,
			'UserCanWrite' => $res['canwrite'] ? true : false,
			'UserCanNotWriteRelative' => \OC::$server->getEncryptionManager()->isEnabled() ? true : false,
			'PostMessageOrigin' => $res['server_host'],
			'LastModifiedTime' => Helper::toISO8601($file->getMTime())
		);
		$this->logger->debug("wopiCheckFileInfo(): Result: {result}", ['app' => $this->appName, 'result' => $result]);
		return $result;
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * Given an access token and a fileId, returns the contents of the file.
	 * Expects a valid token in access_token parameter.
	 */
	public function wopiGetFile($fileId){
		$token = $this->request->getParam('access_token');

		list($fileId, , $version) = Helper::parseFileId($fileId);
		$this->logger->info('wopiGetFile(): File {fileId}, version {version}, token {token}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token ]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		//TODO: Support X-WOPIMaxExpectedSize header.
		$res = $row->getPathForToken($token);
		$ownerid = $res['owner'];

		$userFolder = \OC::$server->getRootFolder()->getUserFolder($res['owner']);
		$nodes = $userFolder->getById($fileId);
		if (empty($nodes)) {
			$this->logger->warning('wopiCheckFileInfo(): No valid file info', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_NOT_FOUND);
		}
		$file = $nodes[0];
		// If some previous version is requested, fetch it from Files_Version app
		if ($version !== '0') {
			\OCP\JSON::checkAppEnabled('files_versions');

			$filename = '/files_versions/' . $res['path'] . '.v' . $version;
		} else {
			$filename = $file->getInternalPath();
		}
		$this->logger->warning('wopiCheckFileInfo(): $filename='.$filename, ['app' => $this->appName]);

		// This is required to be able to read encrypted documents
		\OC_User::setIncognitoMode(true);
		// This is required for reading encrypted files
		\OC_Util::tearDownFS();
		\OC_Util::setupFS($ownerid);
		$this->logger->warning('wopiCheckFileInfo(): setupFSed '.$ownerid, ['app' => $this->appName]);

		return new DownloadResponse($this->request, $ownerid, $filename);
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * Given an access token and a fileId, replaces the files with the request body.
	 * Expects a valid token in access_token parameter.
	 */
	public function wopiPutFile($fileId) {
		$token = $this->request->getParam('access_token');

		$isPutRelative = ($this->request->getHeader('X-WOPI-Override') === 'PUT_RELATIVE');

		list($fileId, , $version) = Helper::parseFileId($fileId);
		$this->logger->debug('wopiputFile(): File {fileId}, version {version}, token {token}, WopiOverride {wopiOverride}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'version' => $version,
			'token' => $token,
			'wopiOverride' => $this->request->getHeader('X-WOPI-Override')]);

		$row = new Db\Wopi();
		$row->loadBy('token', $token);

		$res = $row->getPathForToken($token);
		if ($res == false) {
			$this->logger->debug('wopiPutFile(): getPathForToken() failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		if (!$res['canwrite']) {
			$this->logger->debug('wopiPutFile(): getPathForToken() failed.', ['app' => $this->appName]);
			return new JSONResponse([], Http::STATUS_FORBIDDEN);
		}

		// This call is made from loolwsd, so we need to initialize the
		// session before we can make the user who opened the document
		// login. This is necessary to make activity app register the
		// change made to this file under this user's (editorid) name.
		$this->loginUser($res['editor']);

		// Set up the filesystem view for the owner (where the file actually is).
		$userFolder = \OC::$server->getRootFolder()->getUserFolder($res['owner']);
		$file = $userFolder->getById($fileId)[0];

		$this->logger->warning('wopiPutFile(): $filename='.$file->getPath(), ['app' => $this->appName]);
		if ($isPutRelative) {
			// the new file needs to be installed in the current user dir
			$userFolder = \OC::$server->getRootFolder()->getUserFolder($res['editor']);
			$file = $userFolder->getById($fileId)[0];

			$suggested = $this->request->getHeader('X-WOPI-SuggestedTarget');
			$suggested = iconv('utf-7', 'utf-8', $suggested);

			$path = '';
			if ($suggested[0] === '.') {
				$path = dirname($file->getPath()) . '/New File' . $suggested;
			}
			else if ($suggested[0] !== '/') {
				$path = dirname($file->getPath()) . '/' . $suggested;
			}
			else {
				$path = $userFolder->getPath() . $suggested;
			}

			if ($path === '') {
				return array(
					'status' => 'error',
					'message' => 'Cannot create the file'
				);
			}

			$root = \OC::$server->getRootFolder();

			// create the folder first
			if (!$root->nodeExists(dirname($path))) {
				$root->newFolder(dirname($path));
			}

			// create a unique new file
			$path = $root->getNonExistingName($path);
			$root->newFile($path);
			$file = $root->get($path);
		}
		else {
			$wopiHeaderTime = $this->request->getHeader('X-LOOL-WOPI-Timestamp');
			$this->logger->debug('wopiPutFile(): WOPI header timestamp: {wopiHeaderTime}', [
				'app' => $this->appName,
				'wopiHeaderTime' => $wopiHeaderTime]);
			if (!$wopiHeaderTime) {
				$this->logger->debug('wopiPutFile(): X-LOOL-WOPI-Timestamp absent. Saving file.', ['app' => $this->appName]);
			} else if ($wopiHeaderTime != Helper::toISO8601($file->getMTime())) {
				$this->logger->debug('wopiPutFile(): Document timestamp mismatch ! WOPI client says mtime {headerTime} but storage says {storageTime}', [
					'app' => $this->appName,
					'headerTime' => $wopiHeaderTime,
					'storageTime' => Helper::toISO8601($file->getMtime())]);
				// Tell WOPI client about this conflict.
				return new JSONResponse(['LOOLStatusCode' => self::LOOL_STATUS_DOC_CHANGED], Http::STATUS_CONFLICT);
			}
		}

		// Read the contents of the file from the POST body and store.
		$content = fopen('php://input', 'r');
		$this->logger->debug('wopiPutFile(): Storing file {fileId}, editor: {editor}, owner: {owner}.', [
			'app' => $this->appName,
			'fileId' => $fileId,
			'editor' => $res['editor'],
			'owner' => $res['owner']]);

		// To be able to make it work when server-side encryption is enabled
		\OC_User::setIncognitoMode(true);
		// Setup the FS which is needed to emit hooks (versioning).
		\OC_Util::tearDownFS();
		if ($isPutRelative) {
			\OC_Util::setupFS($res['editor']);
		} else {
			\OC_Util::setupFS($res['owner']);
		}
		$file->putContent($content);
		$mtime = $file->getMtime();

		if ($isPutRelative) {
			// generate a token for the new file (the user still has to be
			// logged in)
			$row = new Db\Wopi();
			$serverHost = $this->request->getServerProtocol() . '://' . $this->request->getServerHost();
			$wopiToken = $row->generateFileToken($file->getId(), 0, (int)true, $serverHost, $res['editor']);

			$wopi = 'index.php/apps/richdocuments/wopi/files/' . $file->getId() . '_' . $this->settings->getSystemValue('instanceid') . '?access_token=' . $wopiToken;
			$url = \OC::$server->getURLGenerator()->getAbsoluteURL($wopi);

			$this->logoutUser();
			return new JSONResponse([ 'Name' => $file->getName(), 'Url' => $url ], Http::STATUS_OK);
		}
		else {
			$this->logoutUser();
			return array(
				'status' => 'success',
				'LastModifiedTime' => Helper::toISO8601($mtime)
			);
		}
	}

	/**
	 * @NoAdminRequired
	 * @NoCSRFRequired
	 * @PublicPage
	 * Given an access token and a fileId, replaces the files with the request body.
	 * Expects a valid token in access_token parameter.
	 * Just actually routes to the PutFile, the implementation of PutFile
	 * handles both saving and saving as.
	 */
	public function wopiPutRelativeFile($fileId) {
		return $this->wopiPutFile($fileId);
	}

	/**
	 * @NoAdminRequired
	 * lists the documents the user has access to (including shared files, once the code in core has been fixed)
	 * also adds session and member info for these files
	 */
	public function listAll(){
		return $this->prepareDocuments($this->storage->getDocuments());
	}
}
