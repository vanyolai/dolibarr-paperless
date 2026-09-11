<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

$res = @include __DIR__.'/../../../main.inc.php';
if (!$res) {
	die('Include of main.inc.php failed');
}

require_once DOL_DOCUMENT_ROOT.'/core/lib/admin.lib.php';
require_once DOL_DOCUMENT_ROOT.'/core/class/html.formsetup.class.php';

/** @var Conf $conf */
/** @var DoliDB $db */
/** @var Translate $langs */
/** @var User $user */

$langs->loadLangs(array('admin', 'paperless@paperless'));

if (!$user->admin) {
	accessforbidden();
}

$action = GETPOST('action', 'aZ09');
$backtopage = GETPOST('backtopage', 'alpha');
$modulepart = 'paperless';

$formSetup = new FormSetup($db);

$item = $formSetup->newItem('PAPERLESS_API_URL');
$item->fieldParams['isMandatory'] = 1;
$item->fieldAttr['placeholder'] = 'https://paperless.example.com';
$item->cssClass = 'minwidth500';
$item->helpText = $langs->trans('PAPERLESS_API_URL_HELP');

$item = $formSetup->newItem('PAPERLESS_WEB_URL');
$item->fieldAttr['placeholder'] = $langs->trans('PaperlessSameAsApiUrl');
$item->cssClass = 'minwidth500';
$item->helpText = $langs->trans('PAPERLESS_WEB_URL_HELP');

$item = $formSetup->newItem('PAPERLESS_API_TOKEN');
$item->fieldParams['isMandatory'] = 1;
$item->setAsSecureKey();
$item->cssClass = 'minwidth500';
$item->helpText = $langs->trans('PAPERLESS_API_TOKEN_HELP');

$item = $formSetup->newItem('PAPERLESS_TAG_NAME');
$item->fieldAttr['placeholder'] = 'dolibarr';
$item->cssClass = 'minwidth300';
$item->helpText = $langs->trans('PAPERLESS_TAG_NAME_HELP');

$item = $formSetup->newItem('PAPERLESS_REDIRECT_PDF_UPLOADS');
$item->setAsYesNo();
$item->helpText = $langs->trans('PAPERLESS_REDIRECT_PDF_UPLOADS_HELP');

$item = $formSetup->newItem('PAPERLESS_INVOICE_MATCHING_ENABLED');
$item->setAsYesNo();
$item->helpText = $langs->trans('PAPERLESS_INVOICE_MATCHING_ENABLED_HELP');

$item = $formSetup->newItem('PAPERLESS_HTTP_TIMEOUT');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '1';
$item->fieldAttr['max'] = '300';
$item->helpText = $langs->trans('PAPERLESS_HTTP_TIMEOUT_HELP');

$item = $formSetup->newItem('PAPERLESS_RESOLVE_WAIT');
$item->fieldAttr['type'] = 'number';
$item->fieldAttr['min'] = '0';
$item->fieldAttr['max'] = '30';
$item->helpText = $langs->trans('PAPERLESS_RESOLVE_WAIT_HELP');

include DOL_DOCUMENT_ROOT.'/core/actions_setmoduleoptions.inc.php';

$form = new Form($db);
$title = $langs->trans('PaperlessSetup');
llxHeader('', $title, '', '', 0, 0, '', '', '', 'mod-paperless page-admin');

$linkback = '<a href="'.($backtopage ? $backtopage : DOL_URL_ROOT.'/admin/modules.php?restore_lastsearch_values=1').'">'.img_picto($langs->trans('BackToModuleList'), 'back', 'class="pictofixedwidth"').'<span class="hideonsmartphone">'.$langs->trans('BackToModuleList').'</span></a>';
print load_fiche_titre($title, $linkback, 'title_setup');

print '<div class="opacitymedium">'.$langs->trans('PaperlessSetupIntro').'</div><br>';
print $formSetup->generateOutput(true);
print '<br>';
print '<div class="info">'.$langs->trans('PaperlessApiVersionInfo').'</div>';

llxFooter();
$db->close();
