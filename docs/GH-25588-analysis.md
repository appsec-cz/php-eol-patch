# GH-25588 — pdo_sqlite bound parameter lifetime

## Failure mechanism

pdo_sqlite bound string and LOB parameters with `SQLITE_STATIC`. This tells SQLite
that the buffer stays valid until the statement is finalized. PDO does not keep
that promise. It releases or replaces a bound value on the next `execute()` with
a parameter array, on a later `bindValue()`, or when a `bindParam()` variable is
reassigned. SQLite keeps bindings across `sqlite3_reset()` and reads them again
while it steps the statement. It then reads freed memory and can store or compare
whatever was allocated there next.

Ordinary application code reaches this path. One example is a prepared `INSERT`
executed again with fewer parameters. Another is a statement whose bound value
changes while rows are still being fetched.

## Backport

The port binds text and blob values with `SQLITE_TRANSIENT`, so SQLite keeps its
own copy. It also reports a failed blob bind through PDO, as the text path already
did. The code is identical to upstream in both branches.

- **PHP 7.3.33** [0226](../patches/php-7.3/0226-GH-25588-pdo-sqlite-bound-param-lifetime.patch)
- **PHP 7.4.33** [0226](../patches/php-7.4/0226-GH-25588-pdo-sqlite-bound-param-lifetime.patch)

Key source files

- `ext/pdo_sqlite/sqlite_statement.c`

## Verification

On unpatched 7.3.33 and 7.4.33, including the official windows.php.net build, the
"bindValue() during fetch" case returned only the first row. SQLite compared each
row with a freed string that a new allocation had overwritten. The
`ext/pdo_sqlite/tests/gh25588.phpt` test is adapted for 7.x in both test trees.
PDO uses exception mode, and row IDs are compared as strings because 7.x returns
SQLite integers as strings. The test omits the upstream "PARAM_LOB bind failure"
case because the 7.x PDO core reports an unknown parameter name differently.

[Upstream issue](https://github.com/php/php-src/issues/25588) ·
[Upstream fix](https://github.com/php/php-src/commit/066f18f4f56ab58501b805dfd93f2251079d4fb3)
