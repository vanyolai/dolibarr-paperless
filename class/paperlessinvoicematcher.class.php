<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

require_once DOL_DOCUMENT_ROOT.'/core/class/link.class.php';

/**
 * Match Dolibarr invoices to existing Paperless-ngx documents by invoice reference.
 */
class PaperlessInvoiceMatcher
{
	/** @var DoliDB */
	private $db;
	/** @var PaperlessClient */
	private $client;
	/** @var int */
	private $entity;
	/** @var string */
	private $resolverBase;
	/** @var string */
	public $error = '';

	/**
	 * @param DoliDB $db Database handler
	 * @param PaperlessClient $client Paperless API client
	 * @param int $entity Dolibarr entity
	 */
	public function __construct($db, $client, $entity)
	{
		$this->db = $db;
		$this->client = $client;
		$this->entity = (int) $entity;
		$this->resolverBase = dol_buildpath('/paperless/open.php', 2);
	}

	/**
	 * Return recent validated invoices for matching.
	 *
	 * @param string $kind customer|supplier
	 * @param int $days Look-back window
	 * @param int $limit Maximum rows
	 * @return array<int,array<string,mixed>>|false
	 */
	public function listInvoices($kind, $days = 365, $limit = 50)
	{
		$this->error = '';
		$kind = $kind === 'supplier' ? 'supplier' : 'customer';
		$days = max(1, min(3650, (int) $days));
		$limit = max(1, min(200, (int) $limit));
		$fromDate = dol_now() - ($days * 86400);

		if ($kind === 'supplier') {
			$sql = 'SELECT f.rowid, f.ref, f.ref_supplier, f.datef, f.total_ttc, s.nom AS thirdparty';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'facture_fourn AS f';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = f.fk_soc';
			$sql .= ' WHERE f.entity = '.((int) $this->entity);
			$sql .= " AND f.ref IS NOT NULL AND f.ref <> ''";
			$sql .= ' AND f.fk_statut > 0';
			$sql .= " AND f.datef >= '".$this->db->idate($fromDate)."'";
			$sql .= ' ORDER BY f.datef DESC, f.rowid DESC';
			$sql .= $this->db->plimit($limit);
		} else {
			$sql = 'SELECT f.rowid, f.ref, f.ref_client, f.datef, f.total_ttc, s.nom AS thirdparty';
			$sql .= ' FROM '.MAIN_DB_PREFIX.'facture AS f';
			$sql .= ' LEFT JOIN '.MAIN_DB_PREFIX.'societe AS s ON s.rowid = f.fk_soc';
			$sql .= ' WHERE f.entity = '.((int) $this->entity);
			$sql .= " AND f.ref IS NOT NULL AND f.ref <> ''";
			$sql .= " AND f.ref NOT LIKE '(PROV%'";
			$sql .= ' AND f.fk_statut > 0';
			$sql .= " AND f.datef >= '".$this->db->idate($fromDate)."'";
			$sql .= ' ORDER BY f.datef DESC, f.rowid DESC';
			$sql .= $this->db->plimit($limit);
		}

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}

		$rows = array();
		while ($obj = $this->db->fetch_object($resql)) {
			$reference = $kind === 'supplier' ? trim((string) $obj->ref_supplier) : trim((string) $obj->ref);
			if ($reference === '') {
				// Supplier invoices without supplier reference can still be searched by the Dolibarr ref.
				$reference = trim((string) $obj->ref);
			}
			$rows[] = array(
				'id' => (int) $obj->rowid,
				'kind' => $kind,
				'objecttype' => $this->objectTypeForKind($kind),
				'ref' => (string) $obj->ref,
				'match_ref' => $reference,
				'thirdparty' => (string) $obj->thirdparty,
				'date' => $this->db->jdate($obj->datef),
				'total_ttc' => (float) $obj->total_ttc,
			);
		}
		$this->db->free($resql);
		return $rows;
	}

	/**
	 * Fetch one invoice and validate its entity.
	 *
	 * @param string $kind customer|supplier
	 * @param int $invoiceId Dolibarr invoice ID
	 * @return array<string,mixed>|false
	 */
	public function getInvoice($kind, $invoiceId)
	{
		$kind = $kind === 'supplier' ? 'supplier' : 'customer';
		$invoiceId = (int) $invoiceId;
		if ($invoiceId <= 0) {
			$this->error = 'Invalid invoice ID.';
			return false;
		}

		if ($kind === 'supplier') {
			$sql = 'SELECT rowid, ref, ref_supplier FROM '.MAIN_DB_PREFIX.'facture_fourn';
		} else {
			$sql = 'SELECT rowid, ref, ref_client FROM '.MAIN_DB_PREFIX.'facture';
		}
		$sql .= ' WHERE rowid = '.$invoiceId.' AND entity = '.((int) $this->entity);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			$this->error = 'Invoice not found.';
			return false;
		}

		$reference = $kind === 'supplier' ? trim((string) $obj->ref_supplier) : trim((string) $obj->ref);
		if ($reference === '') {
			$reference = trim((string) $obj->ref);
		}

		return array(
			'id' => (int) $obj->rowid,
			'kind' => $kind,
			'objecttype' => $this->objectTypeForKind($kind),
			'ref' => (string) $obj->ref,
			'match_ref' => $reference,
		);
	}

	/**
	 * Search Paperless and separate exact-normalized reference matches from broader search candidates.
	 *
	 * @param string $reference Invoice number/reference
	 * @param int $limit Paperless result limit
	 * @return array{exact:array<int,array<string,mixed>>,candidates:array<int,array<string,mixed>>}|false
	 */
	public function searchReference($reference, $limit = 10)
	{
		$this->error = '';
		$reference = trim((string) $reference);
		if ($reference === '') {
			return array('exact' => array(), 'candidates' => array());
		}

		$documents = $this->client->searchDocuments($reference, $limit);
		if ($documents === false) {
			$this->error = $this->client->error;
			return false;
		}

		$exact = array();
		foreach ($documents as $document) {
			if ($this->documentContainsReference($document, $reference)) {
				$exact[] = $document;
			}
		}

		return array('exact' => $exact, 'candidates' => $documents);
	}

	/**
	 * Return existing Paperless link for the invoice, if any.
	 *
	 * @param string $objectType Dolibarr object type
	 * @param int $objectId Dolibarr object ID
	 * @return array<string,mixed>|null|false
	 */
	public function getExistingPaperlessLink($objectType, $objectId)
	{
		$this->error = '';
		$sql = 'SELECT rowid, url, label FROM '.MAIN_DB_PREFIX.'links';
		$sql .= " WHERE objecttype = '".$this->db->escape($objectType)."'";
		$sql .= ' AND objectid = '.((int) $objectId);
		$sql .= ' AND entity = '.((int) $this->entity);
		$sql .= " AND url LIKE '%/paperless/open.php?%'";
		$sql .= ' ORDER BY rowid ASC';
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		return array('id' => (int) $obj->rowid, 'url' => (string) $obj->url, 'label' => (string) $obj->label);
	}

	/**
	 * Return a Dolibarr link already pointing to a stable Paperless document ID.
	 *
	 * @param int $documentId Paperless document ID
	 * @return array<string,mixed>|null|false
	 */
	public function getExistingLinkForDocument($documentId)
	{
		$this->error = '';
		$documentId = (int) $documentId;
		if ($documentId <= 0) {
			return null;
		}
		$stableUrl = $this->resolverBase.'?document='.$documentId;
		$sql = 'SELECT rowid, objecttype, objectid, url, label FROM '.MAIN_DB_PREFIX.'links';
		$sql .= ' WHERE entity = '.((int) $this->entity);
		$sql .= " AND url = '".$this->db->escape($stableUrl)."'";
		$sql .= ' ORDER BY rowid ASC';
		$sql .= $this->db->plimit(1);

		$resql = $this->db->query($sql);
		if (!$resql) {
			$this->error = $this->db->lasterror();
			return false;
		}
		$obj = $this->db->fetch_object($resql);
		$this->db->free($resql);
		if (!$obj) {
			return null;
		}
		return array(
			'id' => (int) $obj->rowid,
			'objecttype' => (string) $obj->objecttype,
			'objectid' => (int) $obj->objectid,
			'url' => (string) $obj->url,
			'label' => (string) $obj->label,
		);
	}

	/**
	 * Create a stable Dolibarr link to an existing Paperless document.
	 *
	 * @param string $kind customer|supplier
	 * @param int $invoiceId Dolibarr invoice ID
	 * @param string $reference Invoice reference used for label
	 * @param int $documentId Paperless document ID
	 * @param User $user Current Dolibarr user
	 * @return int|false Link ID; false on error
	 */
	public function createLink($kind, $invoiceId, $reference, $documentId, $user)
	{
		$this->error = '';
		$invoiceId = (int) $invoiceId;
		$documentId = (int) $documentId;
		if ($invoiceId <= 0 || $documentId <= 0) {
			$this->error = 'Invalid invoice or Paperless document ID.';
			return false;
		}

		$invoice = $this->getInvoice($kind, $invoiceId);
		if ($invoice === false) {
			return false;
		}

		$existing = $this->getExistingPaperlessLink((string) $invoice['objecttype'], $invoiceId);
		if ($existing === false) {
			return false;
		}
		if (is_array($existing)) {
			return (int) $existing['id'];
		}

		$documentLink = $this->getExistingLinkForDocument($documentId);
		if ($documentLink === false) {
			return false;
		}
		if (is_array($documentLink)) {
			$this->error = 'This Paperless document is already linked to another Dolibarr object.';
			return false;
		}

		$document = $this->client->getDocument($documentId);
		if ($document === false) {
			$this->error = $this->client->error;
			return false;
		}

		$link = new Link($this->db);
		$link->entity = $this->entity;
		$link->url = $this->resolverBase.'?document='.$documentId;
		$link->label = trim((string) $reference).' (Paperless)';
		$link->objecttype = (string) $invoice['objecttype'];
		$link->objectid = $invoiceId;
		$linkId = $link->create($user);
		if ($linkId <= 0) {
			$this->error = !empty($link->error) ? $link->error : 'Could not create Dolibarr link.';
			return false;
		}
		return (int) $linkId;
	}

	/**
	 * Build the Dolibarr card URL for an invoice.
	 *
	 * @param string $kind customer|supplier
	 * @param int $invoiceId Invoice ID
	 * @return string
	 */
	public function getInvoiceUrl($kind, $invoiceId)
	{
		if ($kind === 'supplier') {
			return DOL_URL_ROOT.'/fourn/facture/card.php?facid='.((int) $invoiceId);
		}
		return DOL_URL_ROOT.'/compta/facture/card.php?facid='.((int) $invoiceId);
	}

	/**
	 * @param string $kind customer|supplier
	 * @return string
	 */
	private function objectTypeForKind($kind)
	{
		return $kind === 'supplier' ? 'invoice_supplier' : 'facture';
	}

	/**
	 * Decide whether a Paperless result contains the complete invoice reference.
	 * Separators and whitespace are ignored to tolerate OCR formatting differences.
	 *
	 * @param array<string,mixed> $document Paperless document payload
	 * @param string $reference Invoice reference
	 * @return bool
	 */
	private function documentContainsReference($document, $reference)
	{
		$needle = $this->normalizeReference($reference);
		// Very short references are unsafe for automatic matching.
		if (strlen($needle) < 4) {
			return false;
		}

		$parts = array();
		foreach (array('title', 'content', 'original_file_name') as $key) {
			if (!empty($document[$key]) && is_scalar($document[$key])) {
				$parts[] = (string) $document[$key];
			}
		}
		$haystack = strtoupper(implode("\n", $parts));
		if ($haystack === '') {
			return false;
		}

		// Match the complete reference while allowing OCR to insert/remove separators
		// such as spaces, dashes and slashes between its alphanumeric characters.
		$chars = str_split($needle);
		$pattern = '/(?<![A-Z0-9])'.implode('[^A-Z0-9]*', array_map('preg_quote', $chars)).'(?![A-Z0-9])/i';
		return (bool) preg_match($pattern, $haystack);
	}

	/**
	 * Normalize invoice references and OCR text for separator-insensitive comparison.
	 *
	 * @param string $value Input value
	 * @return string
	 */
	private function normalizeReference($value)
	{
		$value = strtoupper((string) $value);
		$value = preg_replace('/[^A-Z0-9]+/', '', $value);
		return is_string($value) ? $value : '';
	}
}
