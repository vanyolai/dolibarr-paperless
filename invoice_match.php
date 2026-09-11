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
require_once __DIR__.'/class/paperlessinvoicematcher.class.php';
require_once __DIR__.'/class/paperlessinvoicesearch.class.php';

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('bills', 'suppliers', 'companies', 'paperless@paperless'));

$canCustomerRead = $user->hasRight('facture', 'lire');
$canSupplierRead = $user->hasRight('fournisseur', 'facture', 'lire');
if (!$canCustomerRead && !$canSupplierRead) {
	accessforbidden();
}
if (!getDolGlobalInt('PAPERLESS_INVOICE_MATCHING_ENABLED', 1)) {
	accessforbidden($langs->trans('PaperlessInvoiceMatchingDisabled'));
}

$apiUrl = trim(getDolGlobalString('PAPERLESS_API_URL'));
$webUrl = trim(getDolGlobalString('PAPERLESS_WEB_URL'));
$apiToken = trim(getDolGlobalString('PAPERLESS_API_TOKEN'));
$httpTimeout = max(1, getDolGlobalInt('PAPERLESS_HTTP_TIMEOUT', 30));
if ($apiUrl === '' || $apiToken === '') {
	accessforbidden($langs->trans('PaperlessNotConfigured'));
}

$kind = GETPOST('kind', 'alpha');
if ($kind !== 'supplier' && $kind !== 'customer') {
	$kind = $canSupplierRead ? 'supplier' : 'customer';
}
if ($kind === 'supplier' && !$canSupplierRead) {
	$kind = 'customer';
}
if ($kind === 'customer' && !$canCustomerRead) {
	$kind = 'supplier';
}

$days = GETPOSTINT('days');
if ($days <= 0) {
	$days = 365;
}
$days = max(1, min(3650, $days));

$limit = GETPOSTINT('limit');
if ($limit <= 0) {
	$limit = 50;
}
$limit = max(1, min(200, $limit));

$action = GETPOST('action', 'aZ09');

/**
 * @param mixed $rawTagIds
 * @return int[]
 */
function paperlessInvoiceMatcherNormalizeTagIds($rawTagIds)
{
	if (!is_array($rawTagIds)) {
		$rawTagIds = ($rawTagIds === '' || $rawTagIds === null) ? array() : array($rawTagIds);
	}
	$tagIds = array();
	foreach ($rawTagIds as $tagId) {
		$tagId = (int) $tagId;
		if ($tagId > 0) {
			$tagIds[$tagId] = $tagId;
		}
	}
	ksort($tagIds);
	return array_values($tagIds);
}

$rawTagIds = isset($_POST['tag_ids']) ? $_POST['tag_ids'] : (isset($_GET['tag_ids']) ? $_GET['tag_ids'] : array());
$tagIds = paperlessInvoiceMatcherNormalizeTagIds($rawTagIds);

$client = new PaperlessClient($apiUrl, $apiToken, $webUrl, $httpTimeout);
$matcher = new PaperlessInvoiceMatcher($db, $client, (int) $conf->entity);
$searchClient = new PaperlessInvoiceSearch($apiUrl, $apiToken, $httpTimeout);

/**
 * @param string $invoiceKind customer|supplier
 * @return bool
 */
function paperlessInvoiceCanWrite($invoiceKind)
{
	global $user;
	return $invoiceKind === 'supplier'
		? $user->hasRight('fournisseur', 'facture', 'creer')
		: $user->hasRight('facture', 'creer');
}

/**
 * @param int[] $tagIds
 * @return string
 */
function paperlessInvoiceMatcherHiddenTags($tagIds)
{
	$html = '';
	foreach ($tagIds as $tagId) {
		$html .= '<input type="hidden" name="tag_ids[]" value="'.((int) $tagId).'">';
	}
	return $html;
}

/**
 * @param int $entity
 * @param int $userId
 * @param string $apiUrl
 * @param string $kind
 * @param int $days
 * @param int $limit
 * @param int[] $tagIds
 * @return string
 */
function paperlessInvoiceMatcherCacheKey($entity, $userId, $apiUrl, $kind, $days, $limit, $tagIds)
{
	return hash('sha256', implode('|', array(
		(int) $entity,
		(int) $userId,
		sha1((string) $apiUrl),
		(string) $kind,
		(int) $days,
		(int) $limit,
		implode(',', $tagIds),
	)));
}

/**
 * @param string $key
 * @return array<int,array<string,mixed>>
 */
function paperlessInvoiceMatcherCacheGet($key)
{
	if (empty($_SESSION['paperless_invoice_matcher_cache']) || !is_array($_SESSION['paperless_invoice_matcher_cache'])) {
		return array();
	}
	$now = time();
	foreach ($_SESSION['paperless_invoice_matcher_cache'] as $cacheKey => $entry) {
		if (!is_array($entry) || empty($entry['updated']) || ((int) $entry['updated'] + 3600) < $now) {
			unset($_SESSION['paperless_invoice_matcher_cache'][$cacheKey]);
		}
	}
	$entry = $_SESSION['paperless_invoice_matcher_cache'][$key] ?? null;
	if (!is_array($entry) || empty($entry['results']) || !is_array($entry['results'])) {
		return array();
	}
	return $entry['results'];
}

/**
 * @param string $key
 * @param array<int,array<string,mixed>> $results
 * @return void
 */
function paperlessInvoiceMatcherCacheSet($key, $results)
{
	if (!isset($_SESSION['paperless_invoice_matcher_cache']) || !is_array($_SESSION['paperless_invoice_matcher_cache'])) {
		$_SESSION['paperless_invoice_matcher_cache'] = array();
	}
	$_SESSION['paperless_invoice_matcher_cache'][$key] = array(
		'updated' => time(),
		'results' => $results,
	);
	if (count($_SESSION['paperless_invoice_matcher_cache']) > 10) {
		uasort($_SESSION['paperless_invoice_matcher_cache'], function ($a, $b) {
			return ((int) ($a['updated'] ?? 0)) <=> ((int) ($b['updated'] ?? 0));
		});
		while (count($_SESSION['paperless_invoice_matcher_cache']) > 10) {
			array_shift($_SESSION['paperless_invoice_matcher_cache']);
		}
	}
}

/**
 * @param string $kind
 * @param int $days
 * @param int $limit
 * @param int[] $tagIds
 * @param int $focusInvoiceId
 * @return never
 */
function paperlessInvoiceMatcherRedirect($kind, $days, $limit, $tagIds, $focusInvoiceId = 0)
{
	$params = array(
		'mainmenu' => 'billing',
		'leftmenu' => 'paperless_invoice_match',
		'kind' => $kind,
		'days' => (int) $days,
		'limit' => (int) $limit,
	);
	if (!empty($tagIds)) {
		$params['tag_ids'] = array_values($tagIds);
	}
	$url = dol_buildpath('/paperless/invoice_match.php', 1).'?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986);
	if ($focusInvoiceId > 0) {
		$url .= '#invoice-'.((int) $focusInvoiceId);
	}
	header('Location: '.$url);
	exit;
}

$cacheKey = paperlessInvoiceMatcherCacheKey((int) $conf->entity, (int) $user->id, $apiUrl, $kind, $days, $limit, $tagIds);
$scanResults = paperlessInvoiceMatcherCacheGet($cacheKey);

if ($action === 'refresh') {
	$scanResults = array();
	paperlessInvoiceMatcherCacheSet($cacheKey, $scanResults);
}

// Manual association from a reviewed Paperless candidate.
if ($action === 'link') {
	if (!paperlessInvoiceCanWrite($kind)) {
		accessforbidden();
	}
	$invoiceId = GETPOSTINT('invoice_id');
	$documentId = GETPOSTINT('document_id');
	$invoice = $matcher->getInvoice($kind, $invoiceId);
	if ($invoice === false) {
		setEventMessages($matcher->error, null, 'errors');
		paperlessInvoiceMatcherRedirect($kind, $days, $limit, $tagIds, $invoiceId);
	}

	$linkId = $matcher->createLink($kind, $invoiceId, (string) $invoice['match_ref'], $documentId, $user);
	if ($linkId === false) {
		setEventMessages($langs->trans('PaperlessInvoiceLinkFailed', $invoice['match_ref'], $matcher->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('PaperlessInvoiceLinked', $invoice['match_ref']), null, 'mesgs');
		$existing = $matcher->getExistingPaperlessLink((string) $invoice['objecttype'], $invoiceId);
		$scanResults[$invoiceId] = array(
			'status' => 'linked',
			'link' => is_array($existing) ? $existing : array(),
			'candidates' => array(),
		);
		paperlessInvoiceMatcherCacheSet($cacheKey, $scanResults);
	}
	paperlessInvoiceMatcherRedirect($kind, $days, $limit, $tagIds, $invoiceId);
}

$invoices = $matcher->listInvoices($kind, $days, $limit);
if ($invoices === false) {
	accessforbidden($matcher->error);
}

$scanIds = array();
if ($action === 'scanall') {
	if (!paperlessInvoiceCanWrite($kind)) {
		accessforbidden();
	}
	$scanResults = array();
	foreach ($invoices as $invoice) {
		$scanIds[(int) $invoice['id']] = true;
	}
} elseif ($action === 'scanone') {
	$scanId = GETPOSTINT('invoice_id');
	if ($scanId > 0) {
		$scanIds[$scanId] = true;
	}
}

$usedDocumentIds = array();
$apiFailed = false;
$autoLinkUnique = ($action === 'scanall');

foreach ($invoices as $invoice) {
	$invoiceId = (int) $invoice['id'];
	$existing = $matcher->getExistingPaperlessLink((string) $invoice['objecttype'], $invoiceId);
	if ($existing === false) {
		$scanResults[$invoiceId] = array('status' => 'error', 'message' => $matcher->error, 'candidates' => array());
		continue;
	}
	if (is_array($existing)) {
		$scanResults[$invoiceId] = array('status' => 'linked', 'link' => $existing, 'candidates' => array());
		continue;
	}

	if (empty($scanIds[$invoiceId])) {
		if (!isset($scanResults[$invoiceId])) {
			$scanResults[$invoiceId] = array('status' => 'idle', 'candidates' => array());
		}
		continue;
	}
	if ($apiFailed) {
		$scanResults[$invoiceId] = array('status' => 'skipped', 'candidates' => array());
		continue;
	}
	if (trim((string) $invoice['match_ref']) === '') {
		$scanResults[$invoiceId] = array('status' => 'noref', 'candidates' => array());
		continue;
	}

	$search = $searchClient->searchReference((string) $invoice['match_ref'], $tagIds, 20);
	if ($search === false) {
		$scanResults[$invoiceId] = array('status' => 'error', 'message' => $searchClient->error, 'candidates' => array());
		$apiFailed = true;
		continue;
	}

	$exact = $search['exact'];
	$candidates = array_slice($search['candidates'], 0, 5);

	if (count($exact) === 1 && $autoLinkUnique) {
		$documentId = !empty($exact[0]['id']) ? (int) $exact[0]['id'] : 0;
		if ($documentId > 0 && empty($usedDocumentIds[$documentId])) {
			$linkId = $matcher->createLink($kind, $invoiceId, (string) $invoice['match_ref'], $documentId, $user);
			if ($linkId !== false) {
				$usedDocumentIds[$documentId] = true;
				$scanResults[$invoiceId] = array('status' => 'autolinked', 'document' => $exact[0], 'candidates' => array());
				continue;
			}
			$scanResults[$invoiceId] = array('status' => 'error', 'message' => $matcher->error, 'candidates' => $candidates);
			continue;
		}
	}

	if (count($exact) > 1) {
		$scanResults[$invoiceId] = array('status' => 'ambiguous', 'exact_count' => count($exact), 'candidates' => $candidates);
	} elseif (count($exact) === 1) {
		$scanResults[$invoiceId] = array('status' => 'unique', 'candidates' => $candidates);
	} elseif (!empty($candidates)) {
		$scanResults[$invoiceId] = array('status' => 'candidates', 'candidates' => $candidates);
	} else {
		$scanResults[$invoiceId] = array('status' => 'nomatch', 'candidates' => array());
	}
}

if ($action === 'scanall' || $action === 'scanone') {
	paperlessInvoiceMatcherCacheSet($cacheKey, $scanResults);
}

$availableTags = $searchClient->listTags(500);
if ($availableTags === false) {
	setEventMessages($langs->trans('PaperlessTagFilterUnavailable', $searchClient->error), null, 'warnings');
	$availableTags = array();
}

$title = $langs->trans('PaperlessInvoiceMatcher');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-paperless page-paperless-invoice-match');

print load_fiche_titre($title, '', 'file-pdf');
print '<div class="opacitymedium">'.$langs->trans('PaperlessInvoiceMatcherIntro').'</div><br>';

// Filters and bulk action.
print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
print '<input type="hidden" name="token" value="'.newToken().'">';
print '<input type="hidden" name="mainmenu" value="billing">';
print '<input type="hidden" name="leftmenu" value="paperless_invoice_match">';
print '<div class="fichecenter"><table class="noborder centpercent">';
print '<tr class="liste_titre"><td colspan="4">'.$langs->trans('Filter').'</td></tr>';
print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans('PaperlessInvoiceKind').'</td><td>';
print '<select name="kind" class="flat">';
if ($canCustomerRead) {
	print '<option value="customer"'.($kind === 'customer' ? ' selected' : '').'>'.$langs->trans('PaperlessCustomerInvoices').'</option>';
}
if ($canSupplierRead) {
	print '<option value="supplier"'.($kind === 'supplier' ? ' selected' : '').'>'.$langs->trans('PaperlessSupplierInvoices').'</option>';
}
print '</select></td>';
print '<td>'.$langs->trans('PaperlessLookbackDays').' <input class="width75" type="number" min="1" max="3650" name="days" value="'.((int) $days).'"></td>';
print '<td>'.$langs->trans('PaperlessInvoiceLimit').' <input class="width75" type="number" min="1" max="200" name="limit" value="'.((int) $limit).'"></td>';
print '</tr>';

print '<tr class="oddeven">';
print '<td class="titlefield">'.$langs->trans('PaperlessSearchTags').'</td>';
print '<td colspan="3">';
if (!empty($availableTags)) {
	print '<select name="tag_ids[]" class="flat minwidth300" multiple="multiple" size="'.min(6, max(3, count($availableTags))).'">';
	foreach ($availableTags as $tag) {
		$tagId = (int) $tag['id'];
		$selected = in_array($tagId, $tagIds, true) ? ' selected' : '';
		print '<option value="'.$tagId.'"'.$selected.'>'.dol_escape_htmltag((string) $tag['name']).'</option>';
	}
	print '</select>';
	print ' <span class="opacitymedium">'.$langs->trans('PaperlessSearchTagsHelp').'</span>';
} else {
	print '<span class="opacitymedium">'.$langs->trans('PaperlessNoTagsAvailable').'</span>';
}
print '</td>';
print '</tr>';

print '</table></div>';
print '<div class="center">';
print '<button class="button" type="submit" name="action" value="refresh">'.$langs->trans('Refresh').'</button>';
if (paperlessInvoiceCanWrite($kind)) {
	print ' <button class="button button-save" type="submit" name="action" value="scanall">'.$langs->trans('PaperlessScanAndLink').'</button>';
}
print '</div>';
print '</form><br>';

print '<div class="info">'.$langs->trans('PaperlessInvoiceMatcherSafety').'</div><br>';

print '<div class="div-table-responsive">';
print '<table class="tagtable liste centpercent">';
print '<tr class="liste_titre">';
print '<th>'.$langs->trans('Ref').'</th>';
print '<th>'.$langs->trans('ThirdParty').'</th>';
print '<th>'.$langs->trans('Date').'</th>';
print '<th class="right">'.$langs->trans('AmountTTC').'</th>';
print '<th>'.$langs->trans('PaperlessSearchReference').'</th>';
print '<th>'.$langs->trans('Status').'</th>';
print '<th class="center">'.$langs->trans('Action').'</th>';
print '</tr>';

foreach ($invoices as $invoice) {
	$invoiceId = (int) $invoice['id'];
	$result = isset($scanResults[$invoiceId]) ? $scanResults[$invoiceId] : array('status' => 'idle', 'candidates' => array());
	$invoiceUrl = $matcher->getInvoiceUrl($kind, $invoiceId);

	print '<tr id="invoice-'.$invoiceId.'" class="oddeven">';
	print '<td><a href="'.dol_escape_htmltag($invoiceUrl).'">'.dol_escape_htmltag((string) $invoice['ref']).'</a></td>';
	print '<td>'.dol_escape_htmltag((string) $invoice['thirdparty']).'</td>';
	print '<td>'.($invoice['date'] ? dol_print_date((int) $invoice['date'], 'day') : '').'</td>';
	print '<td class="right">'.price((float) $invoice['total_ttc']).'</td>';
	print '<td><strong>'.dol_escape_htmltag((string) $invoice['match_ref']).'</strong></td>';
	print '<td>';

	$status = (string) $result['status'];
	if ($status === 'linked') {
		print '<span class="badge badge-status4">'.$langs->trans('PaperlessAlreadyLinked').'</span>';
		if (!empty($result['link']['url'])) {
			print ' <a href="'.dol_escape_htmltag((string) $result['link']['url']).'" target="_blank" rel="noopener">'.img_picto($langs->trans('Open'), 'globe').'</a>';
		}
	} elseif ($status === 'autolinked') {
		print '<span class="badge badge-status4">'.$langs->trans('PaperlessAutoLinked').'</span>';
	} elseif ($status === 'ambiguous') {
		print '<span class="badge badge-status1">'.$langs->trans('PaperlessAmbiguousMatches', (int) $result['exact_count']).'</span>';
	} elseif ($status === 'unique') {
		print '<span class="badge badge-status4">'.$langs->trans('PaperlessUniqueMatch').'</span>';
	} elseif ($status === 'candidates') {
		print '<span class="badge badge-status1">'.$langs->trans('PaperlessNoExactMatch').'</span>';
	} elseif ($status === 'nomatch') {
		print '<span class="opacitymedium">'.$langs->trans('PaperlessNoMatch').'</span>';
	} elseif ($status === 'noref') {
		print '<span class="warning">'.$langs->trans('PaperlessNoInvoiceReference').'</span>';
	} elseif ($status === 'error') {
		print '<span class="error">'.dol_escape_htmltag((string) ($result['message'] ?? $langs->trans('Error'))).'</span>';
	} elseif ($status === 'skipped') {
		print '<span class="opacitymedium">'.$langs->trans('PaperlessSkippedAfterApiError').'</span>';
	} else {
		print '<span class="opacitymedium">'.$langs->trans('PaperlessNotScanned').'</span>';
	}

	if (!empty($result['candidates'])) {
		print '<div class="small margintoponly">';
		foreach ($result['candidates'] as $candidate) {
			$documentId = !empty($candidate['id']) ? (int) $candidate['id'] : 0;
			if ($documentId <= 0) {
				continue;
			}
			$candidateTitle = trim((string) ($candidate['title'] ?? ''));
			if ($candidateTitle === '') {
				$candidateTitle = trim((string) ($candidate['original_file_name'] ?? ''));
			}
			if ($candidateTitle === '') {
				$candidateTitle = '#'.$documentId;
			}

			print '<div class="nowraponall">';
			print '<a href="'.dol_escape_htmltag($client->getDocumentUrl($documentId)).'" target="_blank" rel="noopener">'.dol_escape_htmltag($candidateTitle).'</a>';
			if (paperlessInvoiceCanWrite($kind) && $status !== 'linked' && $status !== 'autolinked') {
				print ' <form class="inline-block" method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'#invoice-'.$invoiceId.'">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="mainmenu" value="billing">';
				print '<input type="hidden" name="leftmenu" value="paperless_invoice_match">';
				print '<input type="hidden" name="kind" value="'.dol_escape_htmltag($kind).'">';
				print '<input type="hidden" name="days" value="'.((int) $days).'">';
				print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
				print paperlessInvoiceMatcherHiddenTags($tagIds);
				print '<input type="hidden" name="invoice_id" value="'.$invoiceId.'">';
				print '<input type="hidden" name="document_id" value="'.$documentId.'">';
				print '<button class="button smallpaddingimp" type="submit" name="action" value="link">'.$langs->trans('PaperlessLinkThis').'</button>';
				print '</form>';
			}
			print '</div>';
		}
		print '</div>';
	}
	print '</td>';

	print '<td class="center">';
	if ($status !== 'linked' && $status !== 'autolinked') {
		print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'#invoice-'.$invoiceId.'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="mainmenu" value="billing">';
		print '<input type="hidden" name="leftmenu" value="paperless_invoice_match">';
		print '<input type="hidden" name="kind" value="'.dol_escape_htmltag($kind).'">';
		print '<input type="hidden" name="days" value="'.((int) $days).'">';
		print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
		print paperlessInvoiceMatcherHiddenTags($tagIds);
		print '<input type="hidden" name="invoice_id" value="'.$invoiceId.'">';
		print '<button class="button smallpaddingimp" type="submit" name="action" value="scanone">'.$langs->trans('Search').'</button>';
		print '</form>';
	}
	print '</td>';
	print '</tr>';
}

if (empty($invoices)) {
	print '<tr><td colspan="7" class="opacitymedium center">'.$langs->trans('NoRecordFound').'</td></tr>';
}
print '</table></div>';

llxFooter();
$db->close();
