<?php

/*
 * Copyright (c) 2026 The Recognize contributors.
 * This file is licensed under the Affero General Public License version 3 or later. See the COPYING file.
 */
declare(strict_types=1);
namespace OCA\Recognize\Db;

use OCP\AppFramework\Db\DoesNotExistException;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

/**
 * @template-extends QBMapper<ClipEmbedding>
 */
final class ClipEmbeddingMapper extends QBMapper {
	public function __construct(IDBConnection $db) {
		parent::__construct($db, 'recognize_clip_embeddings', ClipEmbedding::class);
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function findByFileId(int $fileId): ?ClipEmbedding {
		$qb = $this->db->getQueryBuilder();
		$qb->select(ClipEmbedding::$columns)
			->from($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createPositionalParameter($fileId, IQueryBuilder::PARAM_INT)));
		try {
			return $this->findEntity($qb);
		} catch (DoesNotExistException $e) {
			return null;
		}
	}

	/**
	 * Insert or replace the embedding of a file.
	 *
	 * @param list<float> $vector
	 * @throws \OCP\DB\Exception
	 */
	public function store(int $fileId, string $model, array $vector, ?string $phash): ClipEmbedding {
		$entity = $this->findByFileId($fileId) ?? new ClipEmbedding();
		$entity->setFileId($fileId);
		$entity->setModel($model);
		$entity->setVector(ClipEmbedding::packFloats($vector));
		$entity->setPhash($phash);
		$entity->setUpdatedAt(time());
		return $entity->getId() ? $this->update($entity) : $this->insert($entity);
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function deleteByFileId(int $fileId): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())
			->where($qb->expr()->eq('file_id', $qb->createPositionalParameter($fileId, IQueryBuilder::PARAM_INT)))
			->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function deleteAll(): void {
		$qb = $this->db->getQueryBuilder();
		$qb->delete($this->getTableName())->executeStatement();
	}

	/**
	 * @throws \OCP\DB\Exception
	 */
	public function countAll(): int {
		$qb = $this->db->getQueryBuilder();
		$qb->select($qb->func()->count('id'))->from($this->getTableName());
		$result = $qb->executeQuery();
		$count = (int)$result->fetch(\PDO::FETCH_COLUMN);
		$result->closeCursor();
		return $count;
	}

	/**
	 * Stream (file id, packed vector) pairs of one model; keeps memory flat for large libraries.
	 *
	 * @return \Generator<int, string>
	 * @throws \OCP\DB\Exception
	 */
	public function iterateVectors(string $model): \Generator {
		$qb = $this->db->getQueryBuilder();
		$qb->select('file_id', 'vector')
			->from($this->getTableName())
			->where($qb->expr()->eq('model', $qb->createPositionalParameter($model)));
		$result = $qb->executeQuery();
		while ($row = $result->fetch()) {
			$vector = $row['vector'];
			yield (int)$row['file_id'] => is_resource($vector) ? (string)stream_get_contents($vector) : (string)$vector;
		}
		$result->closeCursor();
	}

	/**
	 * @return array<int, string> file id => phash
	 * @throws \OCP\DB\Exception
	 */
	public function findPhashes(): array {
		return array_map(static fn (array $row) => $row['phash'], $this->findPhashesWithMtime());
	}

	/**
	 * @return array<int, array{phash:string, mtime:int}> file id => hash and file modification time
	 * @throws \OCP\DB\Exception
	 */
	public function findPhashesWithMtime(): array {
		$qb = $this->db->getQueryBuilder();
		$qb->select('e.file_id', 'e.phash', 'f.mtime')
			->from($this->getTableName(), 'e')
			->innerJoin('e', 'filecache', 'f', $qb->expr()->eq('f.fileid', 'e.file_id'))
			->where($qb->expr()->isNotNull('e.phash'));
		$result = $qb->executeQuery();
		$rows = [];
		while ($row = $result->fetch()) {
			$rows[(int)$row['file_id']] = ['phash' => (string)$row['phash'], 'mtime' => (int)$row['mtime']];
		}
		$result->closeCursor();
		return $rows;
	}
}
