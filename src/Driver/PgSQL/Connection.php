<?php

declare(strict_types=1);

namespace Doctrine\DBAL\Driver\PgSQL;

use Doctrine\DBAL\Driver\Connection as ConnectionInterface;
use Doctrine\DBAL\Driver\Exception\NoIdentityValue;
use Doctrine\DBAL\SQL\Parser;
use PgSql\Connection as PgSqlConnection;
use TypeError;

use function assert;
use function gettype;
use function is_object;
use function is_resource;
use function pg_close;
use function pg_escape_literal;
use function pg_get_result;
use function pg_last_error;
use function pg_result_error;
use function pg_send_prepare;
use function pg_send_query;
use function pg_version;
use function sprintf;
use function uniqid;

final class Connection implements ConnectionInterface
{
    private Parser $parser;

    /** @param PgSqlConnection|resource $connection */
    public function __construct(private mixed $connection)
    {
        if (! is_resource($connection) && ! $connection instanceof PgSqlConnection) {
            throw new TypeError(sprintf(
                'Expected connection to be a resource or an instance of %s, got %s.',
                PgSqlConnection::class,
                is_object($connection) ? $connection::class : gettype($connection),
            ));
        }

        $this->parser = new Parser(false);
    }

    public function __destruct()
    {
        if (! isset($this->connection)) {
            return;
        }

        @pg_close($this->connection);
    }

    public function prepare(string $sql): Statement
    {
        $visitor = new ConvertParameters();
        $this->parser->parse($sql, $visitor);

        $statementName = uniqid('dbal', true);
        if (@pg_send_prepare($this->connection, $statementName, $visitor->getSQL()) !== true) {
            throw new Exception(pg_last_error($this->connection));
        }

        $result = @pg_get_result($this->connection);
        assert($result !== false);

        if ((bool) pg_result_error($result)) {
            throw Exception::fromResult($result);
        }

        return new Statement($this->connection, $statementName, $visitor->getParameterMap());
    }

    public function query(string $sql): Result
    {
        if (@pg_send_query($this->connection, $sql) !== true) {
            throw new Exception(pg_last_error($this->connection));
        }

        $result = @pg_get_result($this->connection);
        assert($result !== false);

        if ((bool) pg_result_error($result)) {
            throw Exception::fromResult($result);
        }

        return new Result($result);
    }

    /** {@inheritdoc} */
    public function quote(string $value): string
    {
        $quotedValue = pg_escape_literal($this->connection, $value);
        assert($quotedValue !== false);

        return $quotedValue;
    }

    public function exec(string $sql): int
    {
        return $this->query($sql)->rowCount();
    }

    /** {@inheritdoc} */
    public function lastInsertId(): int|string
    {
        try {
            return $this->query('SELECT LASTVAL()')->fetchOne();
        } catch (Exception $exception) {
            if ($exception->getSQLState() === '55000') {
                throw NoIdentityValue::new($exception);
            }

            throw $exception;
        }
    }

    public function beginTransaction(): void
    {
        $this->exec('BEGIN');
    }

    public function commit(): void
    {
        $this->exec('COMMIT');
    }

    public function rollBack(): void
    {
        $this->exec('ROLLBACK');
    }

    public function getServerVersion(): string
    {
        return (string) pg_version($this->connection)['server'];
    }

    /** @return PgSqlConnection|resource */
    public function getNativeConnection()
    {
        return $this->connection;
    }
}
