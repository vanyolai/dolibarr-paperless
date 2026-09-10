<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * \file htdocs/custom/paperless/core/modules/modPaperless.class.php
 * \ingroup paperless
 * \brief Paperless-ngx integration module descriptor.
 */

include_once DOL_DOCUMENT_ROOT.'/core/modules/DolibarrModules.class.php';

class modPaperless extends DolibarrModules
{
	public function __construct($db)
	{
		global $conf;

		$this->db = $db;
		$this->numero = 510900;
		$this->rights_class = 'paperless';
		$this->family = 'interface';
		$this->module_position = '70';
		$this->name = preg_replace('/^mod/i', '', get_class($this));
		$this->description = 'ModulePaperlessDesc';
		$this->descriptionlong = 'ModulePaperlessDescLong';
		$this->editor_name = 'Krisztian Vanyolai';
		$this->editor_url = 'https://github.com/vanyolai/dolibarr-paperless';
		$this->version = '0.1.4';
		$this->const_name = 'MAIN_MODULE_'.strtoupper($this->name);
		$this->picto = 'file-pdf';

		$this->module_parts = array(
			'hooks' => array(
				// Register globally because attachment forms are rendered from many
				// object-specific document pages. The hook itself only changes the
				// standard attachment form when the current user has upload permission.
				'data' => array('all'),
				'entity' => '0',
			),
		);

		$this->dirs = array();
		$this->config_page_url = array('setup.php@paperless');
		$this->hidden = 0;
		$this->depends = array();
		$this->requiredby = array();
		$this->conflictwith = array();
		$this->langfiles = array('paperless@paperless');
		$this->phpmin = array(7, 4);
		$this->need_dolibarr_version = array(23, 0);
		$this->need_javascript_ajax = 0;

		$this->warnings_activation = array();
		$this->warnings_activation_ext = array();

		// Keep integration settings when the module is temporarily disabled/re-enabled.
		$this->const = array(
			1 => array('PAPERLESS_API_URL', 'chaine', '', 'Paperless-ngx base URL used for REST API calls', 0, 'current', 0),
			2 => array('PAPERLESS_WEB_URL', 'chaine', '', 'Paperless-ngx browser URL; defaults to API URL', 0, 'current', 0),
			3 => array('PAPERLESS_API_TOKEN', 'chaine', '', 'Paperless-ngx API token', 0, 'current', 0),
			4 => array('PAPERLESS_REDIRECT_PDF_UPLOADS', 'yesno', 1, 'Send PDF uploads from linked-document pages to Paperless-ngx', 0, 'current', 0),
			5 => array('PAPERLESS_HTTP_TIMEOUT', 'chaine', '30', 'HTTP timeout in seconds', 0, 'current', 0),
			6 => array('PAPERLESS_RESOLVE_WAIT', 'chaine', '10', 'Seconds to wait for Paperless consumption when opening a queued link', 0, 'current', 0),
			7 => array('PAPERLESS_TAG_NAME', 'chaine', 'dolibarr', 'Paperless tag applied to documents uploaded from Dolibarr; empty disables tagging', 0, 'current', 0),
		);

		$this->tabs = array();
		$this->dictionaries = array();
		$this->boxes = array();
		$this->cronjobs = array();
		$this->rights = array();
		$this->menu = array();

		if (!isModEnabled('paperless')) {
			$conf->paperless = new stdClass();
			$conf->paperless->enabled = 0;
		}
	}

	public function init($options = '')
	{
		$sql = array();
		return $this->_init($sql, $options);
	}

	public function remove($options = '')
	{
		$sql = array();
		return $this->_remove($sql, $options);
	}
}
