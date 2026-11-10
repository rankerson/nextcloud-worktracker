<?php
declare(strict_types=1);

namespace OCA\WorkTracker\Migration;

use Closure;
use OCP\DB\ISchemaWrapper;
use OCP\Migration\IOutput;
use OCP\Migration\SimpleMigrationStep;

class Version1000Date20261110130000 extends SimpleMigrationStep {
    public function changeSchema(IOutput $output, Closure $schemaClosure, array $options): ?ISchemaWrapper {
        /** @var ISchemaWrapper $schema */
        $schema = $schemaClosure();

        $this->createIntervalTable($schema, 'worktracker_work', true);
        $this->createIntervalTable($schema, 'worktracker_pauses', true);
        $this->createIntervalTable($schema, 'worktracker_project_times', true);
        if (!$schema->hasTable('worktracker_projects')) {
            $table = $schema->createTable('worktracker_projects');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('owner_uid', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('name', 'string', ['notnull' => true, 'length' => 255]);
            $table->addColumn('code', 'string', ['notnull' => false, 'length' => 64]);
            $table->addColumn('wbs', 'string', ['notnull' => false, 'length' => 128]);
            $table->addColumn('description', 'text', ['notnull' => false]);
            $table->addColumn('valid_from', 'string', ['notnull' => false, 'length' => 10]);
            $table->addColumn('valid_until', 'string', ['notnull' => false, 'length' => 10]);
            $table->addColumn('color', 'string', ['notnull' => false, 'length' => 16]);
            $table->addColumn('sort_order', 'integer', ['notnull' => true, 'default' => 0]);
            $table->addColumn('is_shared', 'smallint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addIndex(['owner_uid', 'name'], 'wt_projects_owner');
        }

        if (!$schema->hasTable('worktracker_project_shares')) {
            $table = $schema->createTable('worktracker_project_shares');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('project_id', 'bigint', ['notnull' => true]);
            $table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('created_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['project_id', 'user_id'], 'wt_share_project_user');
            $table->addIndex(['user_id'], 'wt_share_user');
        }

        if (!$schema->hasTable('worktracker_day_summaries')) {
            $table = $schema->createTable('worktracker_day_summaries');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('work_date', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('work_exact_seconds', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('work_rounded_seconds', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('pause_exact_seconds', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('pause_rounded_seconds', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'work_date'], 'wt_day_user_date');
        }

        if (!$schema->hasTable('worktracker_project_days')) {
            $table = $schema->createTable('worktracker_project_days');
            $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
            $table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
            $table->addColumn('project_id', 'bigint', ['notnull' => true]);
            $table->addColumn('work_date', 'string', ['notnull' => true, 'length' => 10]);
            $table->addColumn('exact_seconds', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('rounded_seconds', 'bigint', ['notnull' => true, 'default' => 0]);
            $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
            $table->setPrimaryKey(['id']);
            $table->addUniqueIndex(['user_id', 'project_id', 'work_date'], 'wt_project_day_unique');
            $table->addIndex(['user_id', 'work_date'], 'wt_project_day_user');
        }

        return $schema;
    }

    private function createIntervalTable(ISchemaWrapper $schema, string $name, bool $withProjectId): void {
        if ($schema->hasTable($name)) {
            return;
        }
        $table = $schema->createTable($name);
        $table->addColumn('id', 'bigint', ['autoincrement' => true, 'notnull' => true]);
        $table->addColumn('user_id', 'string', ['notnull' => true, 'length' => 64]);
        if ($withProjectId && $name === 'worktracker_project_times') {
            $table->addColumn('project_id', 'bigint', ['notnull' => true]);
        }
        $table->addColumn('start_ts', 'bigint', ['notnull' => true]);
        $table->addColumn('end_ts', 'bigint', ['notnull' => false]);
        $table->addColumn('created_at', 'bigint', ['notnull' => true]);
        $table->addColumn('updated_at', 'bigint', ['notnull' => true]);
        $table->setPrimaryKey(['id']);
        $table->addIndex(['user_id', 'start_ts'], substr(str_replace('worktracker_', 'wt_', $name), 0, 18) . '_user_start');
        $table->addIndex(['user_id', 'end_ts'], substr(str_replace('worktracker_', 'wt_', $name), 0, 18) . '_user_end');
    }
}
