<?php
/* Copyright (C) 2026 Krisztian Vanyolai
 *
 * This program is free software: you can redistribute it and/or modify
 * it under the terms of the GNU General Public License as published by
 * the Free Software Foundation, either version 3 of the License, or
 * (at your option) any later version.
 */

/**
 * Small server-side client for the Paperless-ngx REST API.
 */
class PaperlessClient
{
	/** @var string */
	private $apiUrl;
	/** @var string */
	private $webUrl;
	/** @var string */
	private $token;
	/** @var int */
	private $timeout;
	/** @var string */
	public $error = '';
	/** @var string[] */
	public $errors = array();

	public function __construct($apiUrl, $token, $webUrl = '', $timeout = 30)
	{
		$this->apiUrl = rtrim(trim($apiUrl), '/');
		$this->webUrl = rtrim(trim($webUrl !== '' ? $webUrl : $apiUrl), '/');
		$this->token = trim($token);
		$this->timeout = max(1, (int) $timeout);
	}

	/**
	 * Upload a PDF to Paperless-ngx.
	 *
	 * @param string $tmpPath PHP upload temporary path
	 * @param string $filename Original file name
	 * @param string $title Optional document title
	 * @param int $tagId Optional Paperless tag ID
	 * @return string|false Task UUID on success, false on error
	 */
	public function uploadDocument($tmpPath, $filename, $title = '', $tagId = 0)
	{
		$this->clearError();
		if (!is_file($tmpPath) || !is_readable($tmpPath)) {
			return $this->fail('Uploaded temporary file is not readable.');
		}
		if (!class_exists('CURLFile')) {
			return $this->fail('PHP cURL file upload support is not available.');
		}

		$fields = array('document' => new CURLFile($tmpPath, 'application/pdf', $filename));
		if ($title !== '') {
			$fields['title'] = $title;
		}
		if ((int) $tagId > 0) {
			$fields['tags'] = (string) ((int) $tagId);
		}

		$response = $this->request('POST', '/api/documents/post_document/', $fields);
		if ($response === false) {
			return false;
		}
		if (is_string($response) && $this->isTaskId($response)) {
			return $response;
		}
		if (is_array($response)) {
			foreach (array('task_id', 'id') as $key) {
				if (!empty($response[$key]) && is_string($response[$key]) && $this->isTaskId($response[$key])) {
					return $response[$key];
				}
			}
		}
		return $this->fail('Paperless-ngx accepted the request but did not return a valid consumption task UUID.');
	}

	/**
	 * Resolve a tag name to an ID, creating it when necessary.
	 *
	 * @param string $name Tag name
	 * @return int|false Positive tag ID, 0 for empty name, false on API error
	 */
	public function getOrCreateTagId($name)
	{
		$name = trim((string) $name);
		if ($name === '') {
			return 0;
		}

		$tagId = $this->findTagIdByName($name);
		if ($tagId === false || $tagId > 0) {
			return $tagId;
		}

		$response = $this->request('POST', '/api/tags/', array('name' => $name));
		if (is_array($response) && !empty($response['id'])) {
			return (int) $response['id'];
		}
		if ($response === false) {
			// A concurrent request may have created the tag between lookup and create.
			$retry = $this->findTagIdByName($name);
			if ($retry > 0) {
				return $retry;
			}
			return false;
		}
		return $this->fail('Paperless-ngx created no usable tag ID for tag "'.$name.'".');
	}

	/**
	 * @param string $name Exact tag name, case-insensitive
	 * @return int|false Positive ID, 0 if not found, false on API error
	 */
	private function findTagIdByName($name)
	{
		$response = $this->request('GET', '/api/tags/?name__iexact='.rawurlencode($name).'&page_size=100');
		if ($response === false) {
			return false;
		}
		$tags = (is_array($response) && isset($response['results']) && is_array($response['results'])) ? $response['results'] : (is_array($response) ? $response : array());
		foreach ($tags as $tag) {
			if (is_array($tag) && !empty($tag['id']) && isset($tag['name']) && strcasecmp((string) $tag['name'], $name) === 0) {
				return (int) $tag['id'];
			}
		}
		return 0;
	}

	/**
	 * Get one consumption task by UUID.
	 * Supports both the paginated API v10 response and older array response.
	 *
	 * @param string $taskId Task UUID
	 * @return array<string,mixed>|null|false
	 */
	public function getTask($taskId)
	{
		$this->clearError();
		if (!$this->isTaskId($taskId)) {
			return $this->fail('Invalid Paperless-ngx task UUID.');
		}
		$response = $this->request('GET', '/api/tasks/?task_id='.rawurlencode($taskId));
		if ($response === false) {
			return false;
		}
		$tasks = array();
		if (is_array($response) && isset($response['results']) && is_array($response['results'])) {
			$tasks = $response['results'];
		} elseif (is_array($response)) {
			$tasks = $response;
		}
		foreach ($tasks as $task) {
			if (is_array($task) && isset($task['task_id']) && (string) $task['task_id'] === $taskId) {
				return $task;
			}
		}
		return null;
	}

	/**
	 * Extract final Paperless document ID from a completed task.
	 * Supports Paperless API v10 and older task representations.
	 *
	 * @param string $taskId Task UUID
	 * @return int|false 0 if pending, positive ID on success, false on failure
	 */
	public function resolveDocumentId($taskId)
	{
		$task = $this->getTask($taskId);
		if ($task === false) {
			return false;
		}
		if ($task === null) {
			return 0;
		}

		$status = strtoupper((string) ($task['status'] ?? ''));
		if ($status === 'SUCCESS') {
			$documentId = 0;

			// Paperless API v10 exposes document IDs as a list.
			if (!empty($task['related_document_ids']) && is_array($task['related_document_ids'])) {
				foreach ($task['related_document_ids'] as $candidate) {
					if ((int) $candidate > 0) {
						$documentId = (int) $candidate;
						break;
					}
				}
			}

			// v10 result_data is the source for related_document_ids; keep this fallback
			// for installations where the serializer omits the derived list.
			if ($documentId <= 0 && !empty($task['result_data']) && is_array($task['result_data']) && !empty($task['result_data']['document_id'])) {
				$documentId = (int) $task['result_data']['document_id'];
			}

			// Backward compatibility with older Paperless task responses.
			if ($documentId <= 0 && !empty($task['related_document'])) {
				$documentId = (int) $task['related_document'];
			}

			if ($documentId > 0) {
				return $documentId;
			}
			return $this->fail('Paperless-ngx reports success, but no related document ID was returned.');
		}

		if ($status === 'FAILURE' || $status === 'FAILED') {
			$result = trim((string) ($task['result'] ?? ''));
			if ($result === '' && !empty($task['result_data'])) {
				$result = json_encode($task['result_data'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
			}
			return $this->fail('Paperless-ngx document consumption failed'.($result !== '' ? ': '.$result : '.'));
		}
		return 0;
	}

	/**
	 * Search documents using Paperless' simple title+content full-text search.
	 *
	 * @param string $text Search text
	 * @param int $limit Maximum number of results to return
	 * @return array<int,array<string,mixed>>|false
	 */
	public function searchDocuments($text, $limit = 10)
	{
		$this->clearError();
		$text = trim((string) $text);
		if ($text === '') {
			return array();
		}
		$limit = max(1, min(100, (int) $limit));
		$response = $this->request('GET', '/api/documents/?text='.rawurlencode($text).'&page_size='.$limit);
		if ($response === false) {
			return false;
		}
		if (is_array($response) && isset($response['results']) && is_array($response['results'])) {
			return $response['results'];
		}
		if (is_array($response)) {
			return $response;
		}
		return array();
	}

	/**
	 * Fetch one Paperless document.
	 *
	 * @param int $documentId Paperless document ID
	 * @return array<string,mixed>|false
	 */
	public function getDocument($documentId)
	{
		$this->clearError();
		$documentId = (int) $documentId;
		if ($documentId <= 0) {
			return $this->fail('Invalid Paperless document ID.');
		}
		$response = $this->request('GET', '/api/documents/'.$documentId.'/');
		if ($response === false) {
			return false;
		}
		if (!is_array($response) || empty($response['id'])) {
			return $this->fail('Paperless-ngx returned no usable document data.');
		}
		return $response;
	}

	/**
	 * Return browser-facing Paperless detail URL.
	 *
	 * @param int $documentId Paperless document ID
	 * @return string
	 */
	public function getDocumentUrl($documentId)
	{
		return $this->webUrl.'/documents/'.((int) $documentId).'/details';
	}

	/**
	 * Execute one Paperless REST request and decode JSON.
	 *
	 * @param string $method HTTP method
	 * @param string $path API path beginning with /
	 * @param mixed $postFields cURL POST fields
	 * @return mixed|false
	 */
	private function request($method, $path, $postFields = null)
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
		if (strtoupper($method) === 'POST') {
			curl_setopt($ch, CURLOPT_POST, true);
			curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
		}
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
		if (json_last_error() === JSON_ERROR_NONE) {
			return $decoded;
		}
		return $this->fail('Paperless-ngx returned an invalid JSON response.');
	}

	private function clearError()
	{
		$this->error = '';
		$this->errors = array();
	}

	private function fail($message)
	{
		$this->error = $message;
		$this->errors[] = $message;
		return false;
	}

	private function isTaskId($value)
	{
		return is_string($value) && (bool) preg_match('/^[0-9a-f]{8}-[0-9a-f]{4}-[1-5][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/i', $value);
	}
}
