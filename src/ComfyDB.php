<?php

namespace xrstf;

use PDO;
use PDOException;

class ComfyDB {
	protected $conn;
	protected $logfile;

	public function __construct(PDO $connection, $logfile = null) {
		$this->conn    = $connection;
		$this->logfile = $logfile;

		$this->conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
		$this->exec('SET NAMES utf8');
	}

	public static function connect($host, $username, $password, $database, array $options = []) {
		$dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8', $host, $database);

		return self::connectToDSN($dsn, $username, $password, $options);
	}

	public static function connectToDSN($dsn, $username, $password, array $options = []) {
		$logfile = isset($options['logfile']) ? $options['logfile'] : null;

		return new static(new PDO($dsn, $username, $password, $options), $logfile);
	}

	public function setLogfile($logfile) {
		$this->logfile = $logfile;
	}

	public function getConnection() {
		return $this->conn;
	}

	public function exec($query, array $data = null) {
		return $this->send('exec', $query, $data);
	}

	public function query($query, array $data = null) {
		return $this->send('query', $query, $data)->fetchAll(PDO::FETCH_ASSOC);
	}

	protected function send($style, $query, array $data = null) {
		if ($data !== null && count($data) > 0) {
			$query = $this->formatQuery($query, $data);
		}

		if ($this->logfile) {
			$before = microtime(true);
		}

		$result = null;
		$error  = null;

		try {
			if ($style === 'exec') {
				$result = $this->conn->exec($query);
			}
			else {
				$result = $this->conn->query($query);
			}
		}
		catch (PDOException $e) {
			$error = $e;
		}

		if ($this->logfile) {
			$duration = microtime(true) - $before;
			$logline  = sprintf('/* %.4Fs */ %s;', $duration, $query);

			if ($error) {
				$logline .= sprintf(' -- ERROR: %s', $e->getMessage());
			}

			file_put_contents($this->logfile, "$logline\n", FILE_APPEND);
		}

		if ($error) {
			throw $error;
		}

		return $result;
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

		return $this->exec($query, $params);
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
		$this->exec($query, $params);

		return $this->getInsertedID();
	}

	public function delete($table, $where) {
		list($whereClause, $params) = $this->buildWhereClause($where);

		$query = sprintf('DELETE FROM `%s` WHERE %s', $table, $whereClause);

		return $this->exec($query, $params);
	}

	public function getInsertedID() {
		return $this->conn->lastInsertId();
	}

	public function quote($s, $type = PDO::PARAM_STR) {
		return $this->conn->quote($s, $type);
	}

	/**
	 * Formats a query like it was an sprintf() format string
	 *
	 * @src http://schlueters.de/blog/archives/155-Escaping-from-the-statement-mess.html
	 */
	public function formatQuery($query, array $args) {
		$c            = $this->conn;
		$modify_funcs = [
			'n' => function($v) use ($c) { return $v === null ? 'NULL' : $c->quote($v); },
			's' => function($v) use ($c) { return $c->quote($v);                        },
			'd' => function($v) use ($c) { return (int) $v;                             },
			'i' => function($v) use ($c) { return (int) $v;                             }, // alias for %d
			'f' => function($v) use ($c) { return (float) $v;                           }
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
