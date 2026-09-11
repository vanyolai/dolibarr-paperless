<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Paperless-side search helper used by the invoice matcher.
 *
 * Keeps invoice-search concerns separate from the upload/task API client:
 * tag discovery, server-side tag filtering and strict invoice-reference
 * verification.
 */
class PaperlessInvoiceSearch
{
	/** @var string */
	private $apiUrl;
	/** @var string */
	private $token;
	/** @var int */
	private $timeout;
	/** @var string */
	public $error = '';

	public function __construct($apiUrl, $token, $timeout = 30)
	{
		$this->apiUrl = rtrim(trim((string) $apiUrl), '/');
		$this->token = trim((string) $token);
		$this->timeout = max(1, (int) $timeout);
	}

	/**
	 * List available Paperless tags.
	 *
	 * @param int $limit Maximum number of tags
	 * @return array<int,array{id:int,name:string}>|false
	 */
	public function listTags($limit = 500)
	{
		$this->error = '';
		$limit = max(1, min(1000, (int) $limit));
		$response = $this->request('/api/tags/?'.http_build_query(array(
			'ordering' => 'name',
			'page_size' => $limit,
		), '', '&', PHP_QUERY_RFC3986));
		if ($response === false) {
			return false;
		}

		$items = $this->extractResults($response);
		$tags = array();
		foreach ($items as $tag) {
			if (!is_array($tag) || empty($tag['id'])) {
				continue;
			}
			$name = trim((string) ($tag['name'] ?? ''));
			if ($name === '') {
				continue;
			}
			$tags[] = array('id' => (int) $tag['id'], 'name' => $name);
		}

		usort($tags, function ($a, $b) {
			return strnatcasecmp((string) $a['name'], (string) $b['name']);
		});

		return $tags;
	}

	/**
	 * Search Paperless by invoice reference, optionally requiring all selected tags.
	 *
	 * @param string $reference Invoice reference
	 * @param int[] $tagIds Paperless tag IDs; all selected tags are required
	 * @param int $limit Maximum Paperless results
	 * @return array{exact:array<int,array<string,mixed>>,candidates:array<int,array<string,mixed>>}|false
	 */
	public function searchReference($reference, $tagIds = array(), $limit = 20)
	{
		$this->error = '';
		$reference = trim((string) $reference);
		if ($reference === '') {
			return array('exact' => array(), 'candidates' => array());
		}

		$tagIds = $this->normalizeTagIds($tagIds);
		$limit = max(1, min(100, (int) $limit));
		$params = array(
			'text' => $reference,
			'page_size' => $limit,
		);
		if (!empty($tagIds)) {
			// Paperless tags__id__all uses AND semantics: every selected tag must be present.
			$params['tags__id__all'] = implode(',', $tagIds);
		}

		$response = $this->request('/api/documents/?'.http_build_query($params, '', '&', PHP_QUERY_RFC3986));
		if ($response === false) {
			return false;
		}

		$documents = $this->extractResults($response);
		$exact = array();
		$candidates = array();
		foreach ($documents as $document) {
			if (!is_array($document) || empty($document['id'])) {
				continue;
			}
			$compact = $this->compactDocument($document);
			$candidates[] = $compact;
			if ($this->documentContainsReference($document, $reference)) {
				$exact[] = $compact;
			}
		}

		return array('exact' => $exact, 'candidates' => $candidates);
	}

	/**
	 * @param array<string,mixed> $response
	 * @return array<int,mixed>
	 */
	private function extractResults($response)
	{
		if (isset($response['results']) && is_array($response['results'])) {
			return $response['results'];
		}
		return is_array($response) ? $response : array();
	}

	/**
	 * Keep cached scan results small; OCR content can be very large.
	 *
	 * @param array<string,mixed> $document
	 * @return array<string,mixed>
	 */
	private function compactDocument($document)
	{
		return array(
			'id' => (int) $document['id'],
			'title' => (string) ($document['title'] ?? ''),
			'original_file_name' => (string) ($document['original_file_name'] ?? ''),
			'tags' => !empty($document['tags']) && is_array($document['tags']) ? array_values($document['tags']) : array(),
		);
	}

	/**
	 * @param array<string,mixed> $document
	 * @param string $reference
	 * @return bool
	 */
	private function documentContainsReference($document, $reference)
	{
		$needle = $this->normalizeReference($reference);
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

		$chars = str_split($needle);
		$escaped = array();
		foreach ($chars as $char) {
			$escaped[] = preg_quote($char, '~');
		}
		$pattern = '~(?<![A-Z0-9])'.implode('[^A-Z0-9]*', $escaped).'(?![A-Z0-9])~i';
		return (bool) preg_match($pattern, $haystack);
	}

	/**
	 * @param string $value
	 * @return string
	 */
	private function normalizeReference($value)
	{
		$value = strtoupper((string) $value);
		$value = preg_replace('/[^A-Z0-9]+/', '', $value);
		return is_string($value) ? $value : '';
	}

	/**
	 * @param mixed $tagIds
	 * @return int[]
	 */
	private function normalizeTagIds($tagIds)
	{
		if (!is_array($tagIds)) {
			return array();
		}
		$result = array();
		foreach ($tagIds as $tagId) {
			$tagId = (int) $tagId;
			if ($tagId > 0) {
				$result[$tagId] = $tagId;
			}
		}
		ksort($result);
		return array_values($result);
	}

	/**
	 * @param string $path API path
	 * @return array<string,mixed>|array<int,mixed>|false
	 */
	private function request($path)
	{
		if ($this->apiUrl === '' || $this->token === '') {
			return $this->fail('Paperless-ngx API URL or API token is not configured.');
		}
		if (!function_exists('curl_init')) {
			return $this->fail('PHP cURL extension is required for the Paperless-ngx integration.');
		}

		$ch = curl_init($this->apiUrl.$path);
		if ($ch === false) {
			return $this->fail('Could not initialize cURL.');
		}
		curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
		curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, min(10, $this->timeout));
		curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
		curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
		curl_setopt($ch, CURLOPT_HTTPHEADER, array(
			'Authorization: Token '.$this->token,
			'Accept: application/json; version=10',
		));

		$body = curl_exec($ch);
		$curlError = curl_error($ch);
		$httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
		curl_close($ch);

		if ($body === false) {
			return $this->fail('Paperless-ngx request failed: '.$curlError);
		}
		if ($httpCode < 200 || $httpCode >= 300) {
			$detail = trim((string) $body);
			if (strlen($detail) > 500) {
				$detail = substr($detail, 0, 500).'...';
			}
			return $this->fail('Paperless-ngx returned HTTP '.$httpCode.($detail !== '' ? ': '.$detail : ''));
		}

		$decoded = json_decode((string) $body, true);
		if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
			return $this->fail('Paperless-ngx returned an invalid JSON response.');
		}
		return $decoded;
	}

	private function fail($message)
	{
		$this->error = (string) $message;
		return false;
	}
}
