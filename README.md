ComfyDB
=======

ComfyDB is a wrapper around PHP's mysqli extension and aimed to provide convenience methods for
common tasks like fetching one row, fetching one column etc.

Note that performance is not the main focus of this library. All fetched result sets will be read
in full, so when you want to stream-process large result sets, this might not be the droid you're
looking for.

Also, this library is not using mysqli's prepared statement support to handle parameterized queries.
Instead, a sprintf-like approach is used, where placeholders like ``%s`` or ``%d`` are replaced
before the query is sent to to the server.

Examples: Connecting
--------------------

Connect to a database:

```php
use xrstf\ComfyDB;

$db = ComfyDB::connect('host', 'user', 'password', 'database');

// alternatively, wrap an existing mysqli connection
$db = new ComfyDB($mysqliConnection);
```

Examples: Query Data
--------------------

Select data. Rows are always associative arrays. No magic here.

```php
$rows = $db->query('SELECT id, firstname, lastname FROM persons');

/*
$rows = [
    ['id' => 1, 'firstname' => 'Tom', 'lastname', 'Schlock'],
    ['id' => 2, 'firstname' => 'Max', 'lastname', 'Power'],
    ['id' => 3, 'firstname' => 'Maria', 'lastname', 'Gomez'],
    ...
];
*/

// use %s for strings, %d for integers, %f for floats and %n for NULLable values
// (%n will result in 'NULL' if the given value is null, otherwise it will encode
// the value as a string, like %s)
$rows = $db->query('SELECT * FROM persons WHERE firstname = %s AND id = %d', ['Tom', 1]);
```

Fetch a single column from the result set. The return value is a flat array of the
values.

```php
$names = $db->fetchColumn('SELECT firstname FROM persons WHERE 1');
// $names = ['Max', 'Tom', 'Maria'];
```

Fetch a result set and use the first column as the key in the final result. If only
two columns are selected, the value in the final map is not an array, but the
second column.

```php
// select three columns, use ID as the key
$names = $db->fetchMap('SELECT id, firstname, lastname FROM persons WHERE 1');

/*
$names = [
    1 => ['firstname' => 'Tom', 'lastname', 'Schlock'],
    2 => ['firstname' => 'Max', 'lastname', 'Power'],
    3 => ['firstname' => 'Maria', 'lastname', 'Gomez'],
];
*/

// select two columns
$names = $db->fetchMap('SELECT id, firstname FROM persons WHERE 1');

/*
$names = [
    1 => 'Tom',
    2 => 'Max',
    3 => 'Maria',
];
*/
```

Fetch a single row. If only one column is selected, that value of the first row is
returned instead of an associative array. If no rows are found, ``null`` is returned.
If more than one row is found, only the first is taken into consideration.

```php
// fetch a single cell from the database
$firstname = $db->fetch('SELECT firstname FROM persons WHERE id = %d', [1]);
// $firstname = 'Tom'

// fetch more than one cell
$name = $db->fetch('SELECT firstname, lastname FROM persons WHERE id = %d', [1]);
// $name = ['firstname' => 'Tom', 'lastname', 'Schlock']

// find no rows (returns always null, disregarding of the number of columns selected)
$row = $db->fetch('SELECT * FROM table WHERE 1 = 2');
// $row = null
```

Examples: Update Data
---------------------

``update()`` takes the table name, the new data and the WHERE criteria.

```php
$newData = [
    'firstname' => 'Anja',
    'lastname' => 'Muster',
];

// for simple conditions, you can give the WHERE criteria as an array
$db->update('persons', $newData, ['id' => 3]);

// more complex criteria can be given as a string, which is copied verbatim
$db->update('persons', $newData, '(firstname NOT NULL OR lastname NOT LIKE "%foo")');
```

Examples: Insert Data
---------------------

``insert()`` takes the table name and the new data.

```php
$newData = [
    'firstname' => 'Anja',
    'lastname' => 'Muster',
];

$db->insert('persons', $newData);

$id = $db->getInsertedID();
```

Examples: Delete Data
---------------------

``delete()`` takes the table name and the WHERE criteria, like ``update()``.

```php
$db->delete('persons', ['id' => 2]);
$deleted = $db->getAffectedRows();
```

Examples: Error Handling
------------------------

In case of any error, a ``xrstf\ComfyException`` is thrown, which contains the error code,
error message and the failed query.

```php
try {
    $db->delete('nope', ['id' => 2]);
}
catch (ComfyException $e) {
    print "Query: ".$e->getQuery()."\n";
    print "Code : ".$e->getCode()."\n";
    print "Error: ".$e->getErrorMessage()."\n";
}
```
