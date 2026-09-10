<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

$res = @include __DIR__.'/../../main.inc.php';
if (!$res) {
	die('Include of main.inc.php failed');
}

require_once __DIR__.'/class/paperlessclient.class.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->load('paperless@paperless');

if (empty($user->id)) {
	accessforbidden();
}

$apiUrl = trim(getDolGlobalString('PAPERLESS_API_URL'));
$webUrl = trim(getDolGlobalString('PAPERLESS_WEB_URL'));
$apiToken = trim(getDolGlobalString('PAPERLESS_API_TOKEN'));
$httpTimeout = max(1, getDolGlobalInt('PAPERLESS_HTTP_TIMEOUT', 30));
$resolveWait = max(0, min(30, getDolGlobalInt('PAPERLESS_RESOLVE_WAIT', 10)));

if ($apiUrl === '' || $apiToken === '') {
	accessforbidden($langs->trans('PaperlessNotConfigured'));
}

$client = new PaperlessClient($apiUrl, $apiToken, $webUrl, $httpTimeout);
$documentId = GETPOSTINT('document');
$taskId = trim(GETPOST('task', 'alphanohtml'));
$linkId = GETPOSTINT('linkid');

if ($documentId > 0) {
	header('Location: '.$client->getDocumentUrl($documentId));
	exit;
}

if (!preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $taskId)) {
	accessforbidden($langs->trans('PaperlessInvalidTaskId'));
}

$resolvedId = 0;
$startedAt = time();
do {
	$result = $client->resolveDocumentId($taskId);
	if ($result === false) {
		llxHeader('', $langs->trans('Paperless'));
		print load_fiche_titre($langs->trans('PaperlessDocumentUnavailable'), '', 'file-pdf');
		print '<div class="error">'.dol_escape_htmltag($client->error).'</div>';
		llxFooter();
		exit;
	}
	if ($result > 0) {
		$resolvedId = (int) $result;
		break;
	}
	if ((time() - $startedAt) >= $resolveWait) {
		break;
	}
	sleep(1);
} while (true);

if ($resolvedId > 0) {
	// Convert the temporary task resolver URL into a stable document-id resolver URL.
	// We only touch the supplied link if it is in the current entity and still points
	// to the same task UUID.
	if ($linkId > 0) {
		$link = new Link($db);
		if ($link->fetch($linkId) > 0 && (int) $link->entity === (int) $conf->entity && strpos((string) $link->url, $taskId) !== false) {
			$link->url = dol_buildpath('/paperless/open.php', 2).'?document='.$resolvedId;
			$link->update($user, 0);
		}
	}

	header('Location: '.$client->getDocumentUrl($resolvedId));
	exit;
}

llxHeader('', $langs->trans('Paperless'));
print load_fiche_titre($langs->trans('PaperlessDocumentProcessing'), '', 'file-pdf');
print '<div class="info">'.$langs->trans('PaperlessDocumentProcessingHelp').'</div>';
print '<script nonce="'.getNonce().'">setTimeout(function(){ window.location.reload(); }, 2000);</script>';
llxFooter();
