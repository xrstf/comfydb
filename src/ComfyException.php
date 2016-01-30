<?php

namespace xrstf;

class ComfyException extends \Exception {
	protected $query;
	protected $error;

	public function __construct($query, $errorCode, $errorMessage) {
		parent::__construct('A database query has failed: '.$errorMessage, $errorCode);

		$this->query = $query;
		$this->error = $errorMessage;
	}

	public function getQuery() {
		return $this->query;
	}

	public function getErrorMessage() {
		return $this->error;
	}
}
