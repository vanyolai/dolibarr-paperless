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

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('bills', 'suppliers', 'companies', 'paperless@paperless'));

$canCustomerRead = $user->hasRight('facture', 'lire');
$canCustomerWrite = $user->hasRight('facture', 'creer');
$canSupplierRead = $user->hasRight('fournisseur', 'facture', 'lire');
$canSupplierWrite = $user->hasRight('fournisseur', 'facture', 'creer');
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

$client = new PaperlessClient($apiUrl, $apiToken, $webUrl, $httpTimeout);
$matcher = new PaperlessInvoiceMatcher($db, $client, (int) $conf->entity);

/**
 * Whether current user may create links on selected invoice kind.
 *
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
 * Redirect back to matcher page after a state-changing action.
 *
 * @param string $kind customer|supplier
 * @param int $days Look-back days
 * @param int $limit Row limit
 * @return never
 */
function paperlessInvoiceMatcherRedirect($kind, $days, $limit)
{
	$baseUrl = dol_buildpath('/paperless/invoice_match.php', 1);
	header('Location: '.$baseUrl.'?mainmenu=billing&leftmenu=paperless_invoice_match&kind='.rawurlencode($kind).'&days='.((int) $days).'&limit='.((int) $limit));
	exit;
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
		paperlessInvoiceMatcherRedirect($kind, $days, $limit);
	}
	$linkId = $matcher->createLink($kind, $invoiceId, (string) $invoice['match_ref'], $documentId, $user);
	if ($linkId === false) {
		setEventMessages($langs->trans('PaperlessInvoiceLinkFailed', $invoice['match_ref'], $matcher->error), null, 'errors');
	} else {
		setEventMessages($langs->trans('PaperlessInvoiceLinked', $invoice['match_ref']), null, 'mesgs');
	}
	paperlessInvoiceMatcherRedirect($kind, $days, $limit);
}

$invoices = $matcher->listInvoices($kind, $days, $limit);
if ($invoices === false) {
	accessforbidden($matcher->error);
}

$scanResults = array();
$scanIds = array();
if ($action === 'scanall') {
	if (!paperlessInvoiceCanWrite($kind)) {
		accessforbidden();
	}
	foreach ($invoices as $invoice) {
		$scanIds[(int) $invoice['id']] = true;
	}
} elseif ($action === 'scanone') {
	if (!paperlessInvoiceCanWrite($kind)) {
		accessforbidden();
	}
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
		$scanResults[$invoiceId] = array('status' => 'idle', 'candidates' => array());
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

	$search = $matcher->searchReference((string) $invoice['match_ref'], 10);
	if ($search === false) {
		$scanResults[$invoiceId] = array('status' => 'error', 'message' => $matcher->error, 'candidates' => array());
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
				$scanResults[$invoiceId] = array('status' => 'autolinked', 'document' => $exact[0], 'candidates' => $candidates);
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
	print '<tr class="oddeven">';
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
				$candidateTitle = '#'.$documentId;
			}
			print '<div class="nowraponall">';
			print '<a href="'.dol_escape_htmltag($client->getDocumentUrl($documentId)).'" target="_blank" rel="noopener">'.dol_escape_htmltag($candidateTitle).'</a>';
			if (paperlessInvoiceCanWrite($kind) && $status !== 'linked' && $status !== 'autolinked') {
				print ' <form class="inline-block" method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
				print '<input type="hidden" name="token" value="'.newToken().'">';
				print '<input type="hidden" name="mainmenu" value="billing">';
				print '<input type="hidden" name="leftmenu" value="paperless_invoice_match">';
				print '<input type="hidden" name="kind" value="'.dol_escape_htmltag($kind).'">';
				print '<input type="hidden" name="days" value="'.((int) $days).'">';
				print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
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
	if (paperlessInvoiceCanWrite($kind) && $status !== 'linked' && $status !== 'autolinked') {
		print '<form method="post" action="'.dol_escape_htmltag($_SERVER['PHP_SELF']).'">';
		print '<input type="hidden" name="token" value="'.newToken().'">';
		print '<input type="hidden" name="mainmenu" value="billing">';
		print '<input type="hidden" name="leftmenu" value="paperless_invoice_match">';
		print '<input type="hidden" name="kind" value="'.dol_escape_htmltag($kind).'">';
		print '<input type="hidden" name="days" value="'.((int) $days).'">';
		print '<input type="hidden" name="limit" value="'.((int) $limit).'">';
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
