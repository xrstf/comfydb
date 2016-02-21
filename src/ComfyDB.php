<?php

namespace xrstf;

use mysqli;

class ComfyDB {
	protected $conn;
	protected $logfile;
	protected $inTrx;

	public function __construct(mysqli $connection, $logfile = null) {
		$this->conn    = $connection;
		$this->logfile = $logfile;
		$this->inTrx   = $inTrx;
	}

	public static function connect($host, $username, $password, $database, $logfile = null, $charset = 'utf8') {
		$db = new mysqli($host, $username, $password, $database);

		if ($db->connect_errno) {
			throw new ComfyException('CONNECT', $db->connect_errno, $db->connect_error);
		}

		if ($charset !== null) {
			$db->set_charset($charset);
		}

		return new static($db, $logfile);
	}

	public function setLogfile($logfile) {
		$this->logfile = $logfile;
	}

	public function getConnection() {
		return $this->conn;
	}

	public function query($query, array $data = null) {
		if ($data !== null && count($data) > 0) {
			$query = $this->formatQuery($query, $data);
		}

		if ($this->logfile) {
			$before = microtime(true);
		}

		$result = $this->conn->query($query);

		if ($this->logfile) {
			$duration = microtime(true) - $before;
			$logline  = sprintf('/* %.4Fs */ %s;', $duration, $query);

			if ($result === false) {
				$logline .= sprintf(' -- ERROR (%d): %s', $this->conn->errno, $this->conn->error);
			}

			file_put_contents($this->logfile, "$logline\n", FILE_APPEND);
		}

		if ($result === false) {
			throw new ComfyException($query, $this->conn->errno, $this->conn->error);
		}

		if ($result === true) {
			return $result;
		}

		$rows = [];

		// do not use ->fetch_all(), as it's mysqlnd-only
		while ($row = $result->fetch_assoc()) {
			$rows[] = $row;
		}

		$result->free();

		return $rows;
	}

	public function fetch($query, array $data = null) {
		$rows = $this->query($query, $data);

		if (count($rows) === 0) {
			return null;
		}

		$row = reset($rows);

		return count($row) === 1 ? reset($row) : $row;
	}

	public function fetchColumn($query, array $data = null) {
		$rows   = $this->query($query, $data);
		$result = [];

		foreach ($rows as $row) {
			$result[] = reset($row);
		}

		return $result;
	}

	public function fetchMap($query, array $data = null) {
		$rows   = $this->query($query, $data);
		$result = [];

		foreach ($rows as $row) {
			$key   = array_shift($row);
			$value = count($row) === 1 ? reset($row) : $row;

			$result[$key] = $value;
		}

		return $result;
	}

	public function update($table, array $newData, $where) {
		$params  = [];
		$updates = [];
		$wheres  = [];

		foreach ($newData as $col => $value) {
			$updates[] = sprintf('`%s` = %s', $col, $this->getPlaceholder($value));
			$params[]  = $value;
		}

		list($whereClause, $whereParams) = $this->buildWhereClause($where);
		$params = array_merge($params, $whereParams);

		$query = sprintf('UPDATE `%s` SET %s WHERE %s', $table, implode(', ', $updates), $whereClause);
		$this->query($query, $params);
	}

	public function insert($table, array $data) {
		$params  = [];
		$values  = [];
		$columns = [];

		foreach ($data as $col => $value) {
			$values[]  = $this->getPlaceholder($value);
			$columns[] = '`'.$col.'`';
			$params[]  = $value;
		}

		$query = sprintf('INSERT INTO `%s` (%s) VALUES (%s)', $table, implode(', ', $columns), implode(', ', $values));
		$this->query($query, $params);
	}

	public function delete($table, $where) {
		list($whereClause, $params) = $this->buildWhereClause($where);

		$query = sprintf('DELETE FROM `%s` WHERE %s', $table, $whereClause);
		$this->query($query, $params);
	}

	public function getInsertedID() {
		return $this->conn->insert_id;
	}

	public function getAffectedRows() {
		return $this->conn->affected_rows;
	}

	public function quote($s) {
		return "'".$this->conn->real_escape_string($s)."'";
	}

	public function begin($flags = null) {
		if ($this->conn->begin_transaction($flags) === false) {
			throw new ComfyException('BEGIN', $db->connect_errno, $db->connect_error);
		}

		$this->inTrx = true;
	}

	public function commit($flags = null) {
		if ($this->conn->commit($flags) === false) {
			throw new ComfyException('COMMIT', $db->connect_errno, $db->connect_error);
		}

		$this->inTrx = false;
	}

	public function rollback($flags = null) {
		if ($this->conn->rollback($flags) === false) {
			throw new ComfyException('ROLLBACK', $db->connect_errno, $db->connect_error);
		}

		$this->inTrx = false;
	}

	public function transactional($callback) {
		$myTrx = !$this->inTrx;

		if ($myTrx) {
			$this->begin();
		}

		try {
			$return = $callback($this);

			if ($myTrx) {
				$this->commit();
			}

			return $return;
		}
		catch (\Exception $e) {
			if ($myTrx) {
				$this->rollback();
			}

			throw $e;
		}
	}

	/**
	 * Formats a query like it was an sprintf() format string
	 *
	 * @src http://schlueters.de/blog/archives/155-Escaping-from-the-statement-mess.html
	 */
	public function formatQuery($query, array $args) {
		$modify_funcs = [
			'n' => function($v) { return $v === null ? 'NULL' : $this->quote($v); },
			's' => function($v) { return $this->quote($v);                        },
			'd' => function($v) { return (int) $v;                                },
			'i' => function($v) { return (int) $v;                                }, // alias for %d
			'f' => function($v) { return (float) $v;                              }
		];

		return preg_replace_callback(
			'/%(['.preg_quote(implode(array_keys($modify_funcs))).'%])(\+?)/',
			function ($match) use (&$args, $modify_funcs) {
				if ($match[1] == '%') {
					return '%';
				}

				if (!count($args)) {
					throw new \Exception('Missing values!');
				}

				$arg       = array_shift($args);
				$arrayMode = $match[2] === '+';

				if (!$arrayMode && (!is_scalar($arg) && !is_null($arg))) {
					throw new \Exception('List values are not allowed for this placeholder.');
				}
				elseif ($arrayMode && (is_scalar($arg) || is_null($arg))) {
					throw new \Exception('Expected a list value but got '.gettype($arg).' instead.');
				}

				if ($arg instanceof \Traversable) {
					$arg = iterator_to_array($arg);
					$arg = array_map($modify_funcs[$match[1]], $arg);

					return implode(', ', $arg);
				}
				elseif (is_array($arg)) {
					$arg = array_map($modify_funcs[$match[1]], $arg);

					return implode(', ', $arg);
				}
				else {
					$func = $modify_funcs[$match[1]];

					return $func($arg);
				}
			},
			$query
		);
	}

	public function buildWhereClause($where) {
		$wheres = [];
		$params = [];

		if (is_string($where)) {
			return [$where, $params];
		}

		if (count($where) === 0) {
			return ['1', $params];
		}

		foreach ($where as $col => $value) {
			if ($value === null) {
				$wheres[] = sprintf('`%s` IS NULL', $col);
			}
			elseif (is_array($value)) {
				if (empty($value)) {
					$wheres[] = '0'; // empty IN() is forbidden
				}
				else {
					// assuming we deal with strings is probably the safest
					$wheres[] = sprintf('`%s` IN (%%s+)', $col);
					$params[] = $value;
				}
			}
			else {
				$wheres[] = sprintf('`%s` = %s', $col, $this->getPlaceholder($value));
				$params[] = $value;
			}
		}

		return [implode(' AND ', $wheres), $params];
	}

	protected function getPlaceholder($var) {
		if (is_null($var))                 return '%n';
		if (is_int($var) || is_bool($var)) return '%d';
		if (is_float($var))                return '%f';

		return '%s';
	}
}
