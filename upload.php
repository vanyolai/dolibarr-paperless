<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require __DIR__.'/../../main.inc.php';

/**
 * @var Conf $conf
 * @var DoliDB $db
 * @var Translate $langs
 * @var User $user
 */

$langs->load('paperless@paperless');

if (empty($user->id)) {
	accessforbidden();
}
if (empty($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
	header('Allow: POST');
	http_response_code(405);
	exit;
}

$targetToken = GETPOST('paperless_target', 'aZ09');
$targets = isset($_SESSION['paperless_upload_targets']) && is_array($_SESSION['paperless_upload_targets'])
	? $_SESSION['paperless_upload_targets']
	: array();

if ($targetToken === '' || empty($targets[$targetToken]) || !is_array($targets[$targetToken])) {
	accessforbidden('Invalid or expired Paperless upload target');
}

$target = $targets[$targetToken];
unset($_SESSION['paperless_upload_targets'][$targetToken]);

if (empty($target['created_at']) || (int) $target['created_at'] < (time() - 3600)) {
	accessforbidden('Expired Paperless upload target');
}

$targetEntity = !empty($target['entity']) ? (int) $target['entity'] : (int) $conf->entity;
if ($targetEntity !== (int) $conf->entity) {
	accessforbidden('Paperless upload target belongs to another entity');
}

$objectType = isset($target['objecttype']) ? (string) $target['objecttype'] : '';
$objectId = isset($target['objectid']) ? (int) $target['objectid'] : 0;
$returnUri = isset($target['return_uri']) ? (string) $target['return_uri'] : '';
if ($objectType === '' || $objectId <= 0) {
	accessforbidden('Invalid Paperless upload target object');
}
if ($returnUri === '' || substr($returnUri, 0, 1) !== '/' || substr($returnUri, 0, 2) === '//') {
	$returnUri = DOL_URL_ROOT.'/index.php';
}

/**
 * Redirect back to the originating Dolibarr document page.
 *
 * @param string $uri Relative URI stored server-side in the session target
 * @return never
 */
function paperlessRedirectBack($uri)
{
	header('Location: '.$uri);
	exit;
}

/**
 * Normalize PHP's single/multiple upload shapes.
 *
 * @param array<string,mixed> $upload Upload entry
 * @return array<int,array{name:string,tmp_name:string,error:int,size:int,type:string}>
 */
function paperlessNormalizeFiles($upload)
{
	$files = array();
	if (isset($upload['tmp_name']) && is_array($upload['tmp_name'])) {
		foreach ($upload['tmp_name'] as $key => $tmpName) {
			$files[] = array(
				'name' => isset($upload['name'][$key]) ? (string) $upload['name'][$key] : '',
				'tmp_name' => (string) $tmpName,
				'error' => isset($upload['error'][$key]) ? (int) $upload['error'][$key] : UPLOAD_ERR_NO_FILE,
				'size' => isset($upload['size'][$key]) ? (int) $upload['size'][$key] : 0,
				'type' => isset($upload['type'][$key]) ? (string) $upload['type'][$key] : '',
			);
		}
	} elseif (isset($upload['tmp_name'])) {
		$files[] = array(
			'name' => isset($upload['name']) ? (string) $upload['name'] : '',
			'tmp_name' => (string) $upload['tmp_name'],
			'error' => isset($upload['error']) ? (int) $upload['error'] : UPLOAD_ERR_NO_FILE,
			'size' => isset($upload['size']) ? (int) $upload['size'] : 0,
			'type' => isset($upload['type']) ? (string) $upload['type'] : '',
		);
	}
	return $files;
}

/**
 * Validate a PDF by extension and magic signature.
 *
 * @param string $tmpPath Temporary uploaded file
 * @param string $filename Original/sanitized filename
 * @return bool
 */
function paperlessIsPdf($tmpPath, $filename)
{
	if (strtolower(pathinfo($filename, PATHINFO_EXTENSION)) !== 'pdf' || !is_uploaded_file($tmpPath) || !is_readable($tmpPath)) {
		return false;
	}
	$handle = @fopen($tmpPath, 'rb');
	if ($handle === false) {
		return false;
	}
	$signature = fread($handle, 5);
	fclose($handle);
	return $signature === '%PDF-';
}

if (!getDolGlobalInt('PAPERLESS_REDIRECT_PDF_UPLOADS', 1)) {
	setEventMessages('Paperless PDF routing is disabled.', null, 'warnings');
	paperlessRedirectBack($returnUri);
}
if (!isset($_FILES['userfile']) || !is_array($_FILES['userfile'])) {
	setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('File')), null, 'errors');
	paperlessRedirectBack($returnUri);
}

$files = paperlessNormalizeFiles($_FILES['userfile']);
$filesToUpload = array();
foreach ($files as $file) {
	if ((int) $file['error'] === UPLOAD_ERR_NO_FILE) {
		continue;
	}
	if ((int) $file['error'] !== UPLOAD_ERR_OK) {
		setEventMessages($langs->trans('PaperlessUploadPhpError', (string) $file['name'], (int) $file['error']), null, 'errors');
		paperlessRedirectBack($returnUri);
	}
	$filename = dol_sanitizeFileName((string) $file['name'], '_', 0);
	if (!paperlessIsPdf((string) $file['tmp_name'], $filename)) {
		setEventMessages($langs->trans('PaperlessInvalidPdf', $filename), null, 'errors');
		paperlessRedirectBack($returnUri);
	}
	$file['sanitized_name'] = $filename;
	$filesToUpload[] = $file;
}

if (empty($filesToUpload)) {
	setEventMessages($langs->trans('ErrorFieldRequired', $langs->transnoentitiesnoconv('File')), null, 'errors');
	paperlessRedirectBack($returnUri);
}

$apiUrl = trim(getDolGlobalString('PAPERLESS_API_URL'));
$webUrl = trim(getDolGlobalString('PAPERLESS_WEB_URL'));
$apiToken = trim(getDolGlobalString('PAPERLESS_API_TOKEN'));
$tagName = trim(getDolGlobalString('PAPERLESS_TAG_NAME'));
$httpTimeout = max(1, getDolGlobalInt('PAPERLESS_HTTP_TIMEOUT', 30));
if ($apiUrl === '' || $apiToken === '') {
	setEventMessages($langs->trans('PaperlessNotConfigured'), null, 'errors');
	paperlessRedirectBack($returnUri);
}

require_once __DIR__.'/class/paperlessclient.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';

$client = new PaperlessClient($apiUrl, $apiToken, $webUrl, $httpTimeout);
$resolverBase = dol_buildpath('/paperless/open.php', 2);
$successCount = 0;
$tagId = 0;

if ($tagName !== '') {
	$resolvedTagId = $client->getOrCreateTagId($tagName);
	if ($resolvedTagId === false) {
		setEventMessages($langs->trans('PaperlessTagUnavailable', $tagName, $client->error), null, 'warnings');
	} else {
		$tagId = (int) $resolvedTagId;
	}
}

foreach ($filesToUpload as $file) {
	$filename = (string) $file['sanitized_name'];
	$title = pathinfo($filename, PATHINFO_FILENAME);
	$taskId = $client->uploadDocument((string) $file['tmp_name'], $filename, $title, $tagId);
	if ($taskId === false) {
		setEventMessages($langs->trans('PaperlessUploadFailed', $filename, $client->error), null, 'errors');
		continue;
	}

	$link = new Link($db);
	$link->entity = $targetEntity;
	$link->url = $resolverBase.'?task='.rawurlencode($taskId);
	$link->label = $filename.' (Paperless)';
	$link->objecttype = $objectType;
	$link->objectid = $objectId;

	$linkId = $link->create($user);
	if ($linkId <= 0) {
		setEventMessages($langs->trans('PaperlessLinkCreateFailed', $filename, $taskId), null, 'errors');
		continue;
	}

	$link->url = $resolverBase.'?task='.rawurlencode($taskId).'&linkid='.(int) $linkId;
	$link->update($user, 0);

	$successCount++;
	setEventMessages($langs->trans('PaperlessUploadQueued', $filename), null, 'mesgs');
}

dol_syslog('Paperless upload endpoint uploaded='.$successCount.' objecttype='.$objectType.' objectid='.$objectId.' tag='.(string) $tagName.' tagid='.(int) $tagId, LOG_INFO);
paperlessRedirectBack($returnUri);
