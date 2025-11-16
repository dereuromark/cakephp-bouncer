<?php

declare(strict_types=1);

namespace Bouncer\Model\Table;

use Cake\ORM\RulesChecker;
use Cake\ORM\Table;
use Cake\Validation\Validator;

/**
 * BouncerRecords Model
 *
 * @method \Bouncer\Model\Entity\BouncerRecord newEmptyEntity()
 * @method \Bouncer\Model\Entity\BouncerRecord newEntity(array $data, array $options = [])
 * @method array<\Bouncer\Model\Entity\BouncerRecord> newEntities(array $data, array $options = [])
 * @method \Bouncer\Model\Entity\BouncerRecord get(mixed $primaryKey, array|string $finder = 'all', \Psr\SimpleCache\CacheInterface|string|null $cache = null, \Closure|string|null $cacheKey = null, mixed ...$args)
 * @method \Bouncer\Model\Entity\BouncerRecord findOrCreate($search, ?callable $callback = null, array $options = [])
 * @method \Bouncer\Model\Entity\BouncerRecord patchEntity(\Cake\Datasource\EntityInterface $entity, array $data, array $options = [])
 * @method array<\Bouncer\Model\Entity\BouncerRecord> patchEntities(iterable $entities, array $data, array $options = [])
 * @method \Bouncer\Model\Entity\BouncerRecord|false save(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method \Bouncer\Model\Entity\BouncerRecord saveOrFail(\Cake\Datasource\EntityInterface $entity, array $options = [])
 * @method iterable<\Bouncer\Model\Entity\BouncerRecord>|\Cake\Datasource\ResultSetInterface<\Bouncer\Model\Entity\BouncerRecord>|false saveMany(iterable $entities, array $options = [])
 * @method iterable<\Bouncer\Model\Entity\BouncerRecord>|\Cake\Datasource\ResultSetInterface<\Bouncer\Model\Entity\BouncerRecord> saveManyOrFail(iterable $entities, array $options = [])
 * @method iterable<\Bouncer\Model\Entity\BouncerRecord>|\Cake\Datasource\ResultSetInterface<\Bouncer\Model\Entity\BouncerRecord>|false deleteMany(iterable $entities, array $options = [])
 * @method iterable<\Bouncer\Model\Entity\BouncerRecord>|\Cake\Datasource\ResultSetInterface<\Bouncer\Model\Entity\BouncerRecord> deleteManyOrFail(iterable $entities, array $options = [])
 * @mixin \Cake\ORM\Behavior\TimestampBehavior
 */
class BouncerRecordsTable extends Table
{
    /**
     * Initialize method
     *
     * @param array<string, mixed> $config The configuration for the Table.
     * @return void
     */
    public function initialize(array $config): void
    {
        parent::initialize($config);

        $this->setTable('bouncer_records');
        $this->setDisplayField('id');
        $this->setPrimaryKey('id');

        $this->addBehavior('Timestamp');
    }

    /**
     * Default validation rules.
     *
     * @param \Cake\Validation\Validator $validator Validator instance.
     * @return \Cake\Validation\Validator
     */
    public function validationDefault(Validator $validator): Validator
    {
        $validator
            ->nonNegativeInteger('id')
            ->allowEmptyString('id', null, 'create');

        $validator
            ->scalar('source')
            ->maxLength('source', 255)
            ->requirePresence('source', 'create')
            ->notEmptyString('source');

        $validator
            ->nonNegativeInteger('primary_key')
            ->allowEmptyString('primary_key');

        $validator
            ->nonNegativeInteger('user_id')
            ->requirePresence('user_id', 'create')
            ->notEmptyString('user_id');

        $validator
            ->nonNegativeInteger('reviewer_id')
            ->allowEmptyString('reviewer_id');

        $validator
            ->scalar('status')
            ->maxLength('status', 20)
            ->requirePresence('status', 'create')
            ->notEmptyString('status')
            ->inList('status', ['pending', 'approved', 'rejected', 'superseded']);

        $validator
            ->scalar('data')
            ->requirePresence('data', 'create')
            ->notEmptyString('data');

        $validator
            ->scalar('original_data')
            ->allowEmptyString('original_data');

        $validator
            ->scalar('reason')
            ->allowEmptyString('reason');

        $validator
            ->dateTime('reviewed')
            ->allowEmptyDateTime('reviewed');

        return $validator;
    }

    /**
     * Returns a rules checker object that will be used for validating
     * application integrity.
     *
     * @param \Cake\ORM\RulesChecker $rules The rules object to be modified.
     * @return \Cake\ORM\RulesChecker
     */
    public function buildRules(RulesChecker $rules): RulesChecker
    {
        return $rules;
    }

    /**
     * Find pending bouncer records for a specific source and primary key.
     *
     * @param string $source Table name
     * @param int|null $primaryKey Primary key or null for new records
     * @param int|null $userId Optional user ID filter
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findPendingForRecord(string $source, ?int $primaryKey, ?int $userId = null)
    {
        $query = $this->find()
            ->where([
                'source' => $source,
                'primary_key IS' => $primaryKey,
                'status' => 'pending',
            ])
            ->orderBy(['created' => 'DESC']);

        if ($userId !== null) {
            $query->where(['user_id' => $userId]);
        }

        return $query;
    }

    /**
     * Find all pending bouncer records.
     *
     * @return \Cake\ORM\Query\SelectQuery
     */
    public function findPending()
    {
        return $this->find()
            ->where(['status' => 'pending'])
            ->orderBy(['created' => 'DESC']);
    }

    /**
     * Supersede other pending records for the same source/primary_key.
     *
     * @param string $source Table name
     * @param int|null $primaryKey Primary key
     * @param int $excludeId Bouncer record ID to exclude
     * @return int Number of records superseded
     */
    public function supersedeOthers(string $source, ?int $primaryKey, int $excludeId): int
    {
        return $this->updateAll(
            ['status' => 'superseded'],
            [
                'source' => $source,
                'primary_key IS' => $primaryKey,
                'status' => 'pending',
                'id !=' => $excludeId,
            ],
        );
    }
}
