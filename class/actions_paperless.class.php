<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/commonhookactions.class.php';

class ActionsPaperless extends CommonHookActions
{
	/** @var DoliDB */
	public $db;

	/** @var string */
	public $error = '';

	/** @var string[] */
	public $errors = array();

	/** @var mixed[] */
	public $results = array();

	/** @var ?string */
	public $resprints;

	public function __construct($db)
	{
		$this->db = $db;
	}

	/**
	 * Route PDF-only submissions of Dolibarr's standard attachment form to the
	 * module endpoint. Other file types keep the native Dolibarr upload path.
	 *
	 * A short-lived, one-time target token is stored in the user's session. The
	 * endpoint therefore never trusts an object id/type supplied only by the
	 * browser; the target is created here only after Dolibarr says the user has
	 * permission to attach a file to the current object.
	 *
	 * @param array<string,mixed> $parameters Hook metadata
	 * @param CommonObject $object Current Dolibarr object
	 * @param ?string $action Current action
	 * @param HookManager $hookmanager Hook manager
	 * @return int
	 */
	public function formattachOptionsUpload($parameters, &$object, &$action, $hookmanager)
	{
		$this->resprints = '';

		if (!getDolGlobalInt('PAPERLESS_REDIRECT_PDF_UPLOADS', 1)) {
			return 0;
		}
		if (empty($parameters['perm'])) {
			return 0;
		}
		if (!is_object($object) || empty($object->id) || empty($object->element)) {
			return 0;
		}

		$targetToken = bin2hex(random_bytes(16));
		if (!isset($_SESSION['paperless_upload_targets']) || !is_array($_SESSION['paperless_upload_targets'])) {
			$_SESSION['paperless_upload_targets'] = array();
		}

		// Keep the session small when a user opens many document tabs without uploading.
		$now = time();
		foreach ($_SESSION['paperless_upload_targets'] as $key => $target) {
			if (!is_array($target) || empty($target['created_at']) || ((int) $target['created_at'] < ($now - 3600))) {
				unset($_SESSION['paperless_upload_targets'][$key]);
			}
		}
		if (count($_SESSION['paperless_upload_targets']) >= 20) {
			$_SESSION['paperless_upload_targets'] = array_slice($_SESSION['paperless_upload_targets'], -19, null, true);
		}

		$returnUri = isset($_SERVER['REQUEST_URI']) ? (string) $_SERVER['REQUEST_URI'] : '';
		if ($returnUri === '' || substr($returnUri, 0, 1) !== '/' || substr($returnUri, 0, 2) === '//') {
			$returnUri = DOL_URL_ROOT.'/index.php';
		}

		$_SESSION['paperless_upload_targets'][$targetToken] = array(
			'objecttype' => (string) $object->element,
			'objectid' => (int) $object->id,
			'entity' => isset($object->entity) && $object->entity ? (int) $object->entity : 0,
			'return_uri' => $returnUri,
			'created_at' => $now,
		);

		$uploadUrl = dol_buildpath('/paperless/upload.php', 1);
		$uploadUrlJson = json_encode($uploadUrl, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
		$targetTokenJson = json_encode($targetToken);

		$this->resprints = '<script nonce="'.getNonce().'">'
			.'jQuery(function(){'
			.'var f=jQuery("#formuserfile");'
			.'if(!f.length){return;}'
			.'if(!f.find("input[name=paperless_target]").length){'
			.'f.append(jQuery("<input>",{type:"hidden",name:"paperless_target",value:'.$targetTokenJson.'}));'
			.'}'
			.'f.off("submit.paperless").on("submit.paperless",function(){'
			.'var files=[];'
			.'f.find("input[type=file]").each(function(){'
			.'if(this.files){for(var i=0;i<this.files.length;i++){files.push(this.files[i]);}}'
			.'});'
			.'if(!files.length){return true;}'
			.'var allPdf=files.every(function(file){return /\\.pdf$/i.test(file.name);});'
			.'if(allPdf){this.action='.$uploadUrlJson.';}'
			.'return true;'
			.'});'
			.'});'
			.'</script>'
			.'<div class="opacitymedium small"><span class="fa fa-file-pdf"></span> Paperless-ngx: PDF &rarr; Paperless, other files &rarr; Dolibarr</div>';

		dol_syslog('ActionsPaperless::formattachOptionsUpload target registered objecttype='.(string) $object->element.' objectid='.(int) $object->id, LOG_DEBUG);
		return 0;
	}
}
