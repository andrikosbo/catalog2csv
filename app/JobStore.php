<?php

declare(strict_types=1);

namespace Catalog2Csv;

use PDO;

final class JobStore
{
    private const MAX_PAYLOAD = 7 * 1024 * 1024;
    private PDO $pdo;

    public function __construct(array $config)
    {
        $this->pdo = new PDO($config['dsn'], $config['user'], $config['password'], [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_EMULATE_PREPARES => false,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }

    public function find(string $id): ?array
    {
        $stmt = $this->pdo->prepare('SELECT revision, payload FROM catalog2csv_jobs WHERE id = UNHEX(?) AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        return $row ? ['revision' => (int) $row['revision']] + json_decode($row['payload'], true, 512, JSON_THROW_ON_ERROR) : null;
    }

    public function replace(?string $previousId, array $payload): string
    {
        $data = $this->encode($payload);
        $this->purge();
        $id = bin2hex(random_bytes(32));
        $this->pdo->beginTransaction();
        try {
            $stmt = $this->pdo->prepare('INSERT INTO catalog2csv_jobs (id, payload, created_at, expires_at) VALUES (UNHEX(?), ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL 24 HOUR)');
            $stmt->execute([$id, $data]);
            if ($previousId !== null) {
                $this->remove($previousId);
            }
            $this->pdo->commit();
        } catch (\Throwable $exception) {
            $this->pdo->rollBack();
            throw $exception;
        }
        return $id;
    }

    public function update(string $id, array $job): void
    {
        $revision = $job['revision'];
        unset($job['revision']);
        $stmt = $this->pdo->prepare('UPDATE catalog2csv_jobs SET revision = revision + 1, payload = ? WHERE id = UNHEX(?) AND revision = ? AND expires_at > UTC_TIMESTAMP()');
        $stmt->execute([$this->encode($job), $id, $revision]);
        if ($stmt->rowCount() !== 1) {
            throw new CatalogException('The catalog changed in another tab or expired. Reload and try again.');
        }
    }

    public function remove(string $id): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM catalog2csv_jobs WHERE id = UNHEX(?)');
        $stmt->execute([$id]);
    }

    public function purge(int $limit = 100): int
    {
        $stmt = $this->pdo->prepare('DELETE FROM catalog2csv_jobs WHERE expires_at <= UTC_TIMESTAMP() ORDER BY expires_at LIMIT ?');
        $stmt->bindValue(1, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->rowCount();
    }

    private function encode(array $payload): string
    {
        $json = json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (strlen($json) > self::MAX_PAYLOAD) {
            throw new CatalogException('The converted catalog is too large. Split it into smaller files.');
        }
        return $json;
    }
}
