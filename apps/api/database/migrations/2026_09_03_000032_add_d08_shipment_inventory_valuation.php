<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;

return new class extends Migration
{
    private const TABLE = 'shipment_inventory_valuations';

    public function up(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            // Reconcile — never recreate. The first run of this migration failed
            // midway: the auto-generated FK name for production_receipt_movement_id
            // ('shipment_inventory_valuations_production_receipt_movement_id_foreign',
            // 68 chars) exceeds MySQL's 64-character identifier limit. MySQL DDL is
            // non-transactional, so the CREATE TABLE and the constraints before that
            // FK were committed while the failure prevented the migrations row from
            // being recorded — leaving an orphan table that broke every later migrate.
            //
            // This is deliberately NOT a bare Schema::hasTable() skip. This branch
            // proves schema equivalence (every column, type, nullable, default) and
            // then applies only the missing additive constraints that this migration
            // already defines (unique / index / FK). Any incompatible difference
            // throws a detailed error instead of being hidden. When up() returns,
            // the migrator records this migration, so database state and migration
            // history become consistent. No existing row is created, modified, or
            // deleted by this branch.
            $this->reconcileExistingTable();

            return;
        }

        Schema::create('shipment_inventory_valuations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('company_id')->constrained('companies')->restrictOnDelete();
            $table->foreignId('shipment_id')->constrained('shipments')->restrictOnDelete();
            $table->foreignId('shipment_line_id')->constrained('shipment_lines')->restrictOnDelete();
            $table->foreignId('packing_list_id')->constrained('packing_lists')->restrictOnDelete();
            $table->foreignId('production_order_id')->constrained('production_orders')->restrictOnDelete();
            $table->foreignId('production_receipt_movement_id');
            $table->foreign('production_receipt_movement_id', 'd08_siv_prod_receipt_fk')->references('id')->on('stock_movements')->restrictOnDelete();
            $table->foreignId('shipment_movement_id')->constrained('stock_movements')->restrictOnDelete();
            $table->foreignId('shipment_ledger_id')->constrained('stock_ledger')->restrictOnDelete();
            $table->foreignId('stock_balance_id')->constrained('stock_balances')->restrictOnDelete();
            $table->string('item_type', 8)->default('FG');
            $table->foreignId('style_id')->constrained('styles')->restrictOnDelete();
            $table->foreignId('colorway_id')->nullable()->constrained('colorways')->restrictOnDelete();
            $table->foreignId('size_id')->nullable()->constrained('sizes')->restrictOnDelete();
            $table->foreignId('warehouse_id')->constrained('warehouses')->restrictOnDelete();
            $table->string('ownership', 8)->default('COMPANY');
            $table->decimal('shipment_quantity', 18, 4);
            $table->decimal('moving_average_unit_cost', 19, 6);
            $table->decimal('shipment_inventory_cost', 19, 4);
            $table->string('currency', 3);
            $table->string('cost_method', 24)->default('MOVING_AVERAGE');
            $table->string('valuation_event', 32)->default('ITS_SHIPMENT_OUT');
            $table->unsignedInteger('valuation_version')->default(1);
            $table->decimal('on_hand_before', 18, 4);
            $table->char('source_hash', 64);
            $table->foreignId('created_by')->constrained('users')->restrictOnDelete();
            $table->timestamp('valued_at', 6);
            $table->timestamp('created_at', 6)->useCurrent();
            $table->unique(['company_id','shipment_id','shipment_line_id','valuation_event'], 'uq_shipment_inventory_valuation_event');
            $table->unique(['company_id','shipment_line_id','source_hash'], 'uq_shipment_inventory_valuation_source');
            $table->index(['company_id','shipment_movement_id'], 'idx_shipment_valuation_its');
            $table->index(['company_id','production_order_id','valued_at'], 'idx_shipment_valuation_mo');
        });
    }

    public function down(): void
    {
        // Reverses up() on databases where this migration created the table
        // (fresh / disposable environments). On a reconciled database the table
        // predates this migration record — do not roll this batch back there.
        Schema::dropIfExists(self::TABLE);
    }

    /**
     * Strictly verify an existing table against the committed D-08 schema and
     * apply only missing additive constraints. Rows are never touched.
     */
    private function reconcileExistingTable(): void
    {
        $database = (string) Schema::getConnection()->getDatabaseName();

        $engine = DB::selectOne(
            'SELECT ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?',
            [$database, self::TABLE]
        );

        if (! $engine || strtoupper((string) $engine->ENGINE) !== 'INNODB') {
            throw new RuntimeException('D-08 reconcile: '.self::TABLE.' must be InnoDB; stopping for manual inspection.');
        }

        $this->verifyColumns($database);
        $this->ensureUniqueConstraintsAndIndexes($database);
        $this->ensureForeignKeys($database);
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: ?string}>
     */
    private function expectedColumns(): array
    {
        // [type, nullable, default] — mirrors the committed Schema::create above.
        return [
            'id' => ['bigint unsigned', false, null],
            'company_id' => ['bigint unsigned', false, null],
            'shipment_id' => ['bigint unsigned', false, null],
            'shipment_line_id' => ['bigint unsigned', false, null],
            'packing_list_id' => ['bigint unsigned', false, null],
            'production_order_id' => ['bigint unsigned', false, null],
            'production_receipt_movement_id' => ['bigint unsigned', false, null],
            'shipment_movement_id' => ['bigint unsigned', false, null],
            'shipment_ledger_id' => ['bigint unsigned', false, null],
            'stock_balance_id' => ['bigint unsigned', false, null],
            'item_type' => ['varchar(8)', false, 'FG'],
            'style_id' => ['bigint unsigned', false, null],
            'colorway_id' => ['bigint unsigned', true, null],
            'size_id' => ['bigint unsigned', true, null],
            'warehouse_id' => ['bigint unsigned', false, null],
            'ownership' => ['varchar(8)', false, 'COMPANY'],
            'shipment_quantity' => ['decimal(18,4)', false, null],
            'moving_average_unit_cost' => ['decimal(19,6)', false, null],
            'shipment_inventory_cost' => ['decimal(19,4)', false, null],
            'currency' => ['varchar(3)', false, null],
            'cost_method' => ['varchar(24)', false, 'MOVING_AVERAGE'],
            'valuation_event' => ['varchar(32)', false, 'ITS_SHIPMENT_OUT'],
            'valuation_version' => ['int unsigned', false, '1'],
            'on_hand_before' => ['decimal(18,4)', false, null],
            'source_hash' => ['char(64)', false, null],
            'created_by' => ['bigint unsigned', false, null],
            'valued_at' => ['timestamp(6)', false, null],
            'created_at' => ['timestamp(6)', false, 'CURRENT_TIMESTAMP(6)'],
        ];
    }

    private function verifyColumns(string $database): void
    {
        $rows = DB::select(
            'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_DEFAULT, EXTRA
             FROM information_schema.COLUMNS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY ORDINAL POSITION',
            [$database, self::TABLE]
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[$row->COLUMN_NAME] = $row;
        }

        $expected = $this->expectedColumns();

        $missing = array_diff(array_keys($expected), array_keys($actual));
        $extra = array_diff(array_keys($actual), array_keys($expected));

        if ($missing !== [] || $extra !== []) {
            throw new RuntimeException(sprintf(
                'D-08 reconcile: incompatible column set on %s — missing: [%s], unexpected: [%s]. Stopping for manual inspection; no changes were made.',
                self::TABLE,
                implode(', ', $missing),
                implode(', ', $extra)
            ));
        }

        $diffs = [];

        foreach ($expected as $name => [$type, $nullable, $default]) {
            $row = $actual[$name];
            $actualType = $this->normalizeColumnType((string) $row->COLUMN_TYPE);
            $actualNullable = strtoupper((string) $row->IS_NULLABLE) === 'YES';
            $actualDefault = $row->COLUMN_DEFAULT === null ? null : trim((string) $row->COLUMN_DEFAULT, "'");

            if ($actualType !== $type) {
                $diffs[] = "{$name} type expected [{$type}] got [{$actualType}]";
            }

            if ($actualNullable !== $nullable) {
                $diffs[] = sprintf('%s nullable expected [%s] got [%s]', $name, $nullable ? 'YES' : 'NO', $actualNullable ? 'YES' : 'NO');
            }

            if ($default !== null && strtoupper((string) $actualDefault) !== strtoupper($default)) {
                $diffs[] = "{$name} default expected [{$default}] got [".var_export($actualDefault, true).']';
            }
        }

        if (! str_contains(strtolower((string) ($actual['id']->EXTRA ?? '')), 'auto_increment')) {
            $diffs[] = 'id is not auto_increment';
        }

        if ($diffs !== []) {
            throw new RuntimeException('D-08 reconcile: incompatible column definitions on '.self::TABLE.' — '.implode('; ', $diffs).'. Stopping for manual inspection; no changes were made.');
        }
    }

    private function normalizeColumnType(string $type): string
    {
        $type = strtolower(trim($type));

        // MySQL < 8.0.17 reports display widths (bigint(20)); 8.4 does not.
        return preg_replace('/^(bigint|int|mediumint|smallint|tinyint)\(\d+\)/', '$1', $type);
    }

    /**
     * @return array<string, array{unique: bool, columns: array<int, string>}>
     */
    private function expectedKeys(): array
    {
        return [
            'uq_shipment_inventory_valuation_event' => ['unique' => true, 'columns' => ['company_id', 'shipment_id', 'shipment_line_id', 'valuation_event']],
            'uq_shipment_inventory_valuation_source' => ['unique' => true, 'columns' => ['company_id', 'shipment_line_id', 'source_hash']],
            'idx_shipment_valuation_its' => ['unique' => false, 'columns' => ['company_id', 'shipment_movement_id']],
            'idx_shipment_valuation_mo' => ['unique' => false, 'columns' => ['company_id', 'production_order_id', 'valued_at']],
        ];
    }

    private function ensureUniqueConstraintsAndIndexes(string $database): void
    {
        $rows = DB::select(
            'SELECT INDEX_NAME, NON_UNIQUE, COLUMN_NAME
             FROM information_schema.STATISTICS
             WHERE TABLE_SCHEMA = ? AND TABLE_NAME = ?
             ORDER BY INDEX_NAME, SEQ_IN_INDEX',
            [$database, self::TABLE]
        );

        $actual = [];
        foreach ($rows as $row) {
            $actual[$row->INDEX_NAME]['columns'][] = $row->COLUMN_NAME;
            $actual[$row->INDEX_NAME]['unique'] = ((int) $row->NON_UNIQUE) === 0;
        }

        if (! isset($actual['PRIMARY'])) {
            throw new RuntimeException('D-08 reconcile: '.self::TABLE.' has no PRIMARY KEY; stopping for manual inspection.');
        }

        $missing = [];

        foreach ($this->expectedKeys() as $name => $definition) {
            if (! isset($actual[$name])) {
                $missing[] = $name;
                continue;
            }

            if ($actual[$name]['columns'] !== $definition['columns'] || $actual[$name]['unique'] !== $definition['unique']) {
                throw new RuntimeException(sprintf(
                    'D-08 reconcile: key [%s] on %s exists with a different definition (columns: %s). Stopping for manual inspection.',
                    $name,
                    self::TABLE,
                    implode(',', $actual[$name]['columns'])
                ));
            }
        }

        // Additive only: create the keys this migration defines but the partial
        // first run never reached. Existing rows are validated by MySQL here;
        // a duplicate in a unique key fails loudly by design.
        foreach ($missing as $name) {
            $definition = $this->expectedKeys()[$name];

            Schema::table(self::TABLE, function (Blueprint $table) use ($name, $definition): void {
                if ($definition['unique']) {
                    $table->unique($definition['columns'], $name);
                } else {
                    $table->index($definition['columns'], $name);
                }
            });
        }
    }

    /**
     * Every FK name stays within MySQL's 64-character identifier limit;
     * production_receipt_movement_id uses the explicit short name
     * 'd08_siv_prod_receipt_fk' (the auto-generated name would be 68 chars).
     *
     * @return array<string, array{0: string, 1: string}>
     */
    private function expectedForeignKeys(): array
    {
        return [
            'shipment_inventory_valuations_company_id_foreign' => ['company_id', 'companies'],
            'shipment_inventory_valuations_shipment_id_foreign' => ['shipment_id', 'shipments'],
            'shipment_inventory_valuations_shipment_line_id_foreign' => ['shipment_line_id', 'shipment_lines'],
            'shipment_inventory_valuations_packing_list_id_foreign' => ['packing_list_id', 'packing_lists'],
            'shipment_inventory_valuations_production_order_id_foreign' => ['production_order_id', 'production_orders'],
            'd08_siv_prod_receipt_fk' => ['production_receipt_movement_id', 'stock_movements'],
            'shipment_inventory_valuations_shipment_movement_id_foreign' => ['shipment_movement_id', 'stock_movements'],
            'shipment_inventory_valuations_shipment_ledger_id_foreign' => ['shipment_ledger_id', 'stock_ledger'],
            'shipment_inventory_valuations_stock_balance_id_foreign' => ['stock_balance_id', 'stock_balances'],
            'shipment_inventory_valuations_style_id_foreign' => ['style_id', 'styles'],
            'shipment_inventory_valuations_colorway_id_foreign' => ['colorway_id', 'colorways'],
            'shipment_inventory_valuations_size_id_foreign' => ['size_id', 'sizes'],
            'shipment_inventory_valuations_warehouse_id_foreign' => ['warehouse_id', 'warehouses'],
            'shipment_inventory_valuations_created_by_foreign' => ['created_by', 'users'],
        ];
    }

    private function ensureForeignKeys(string $database): void
    {
        $rows = DB::select(
            'SELECT k.CONSTRAINT_NAME AS name, k.COLUMN_NAME AS column_name,
                    k.REFERENCED_TABLE_NAME AS referenced_table,
                    r.UPDATE_RULE AS update_rule, r.DELETE_RULE AS delete_rule
             FROM information_schema.KEY_COLUMN_USAGE k
             JOIN information_schema.REFERENTIAL_CONSTRAINTS r
               ON r.CONSTRAINT_SCHEMA = k.CONSTRAINT_SCHEMA
              AND r.CONSTRAINT_NAME = k.CONSTRAINT_NAME
             WHERE k.TABLE_SCHEMA = ? AND k.TABLE_NAME = ? AND k.REFERENCED_TABLE_NAME IS NOT NULL',
            [$database, self::TABLE]
        );

        $byName = [];
        $byColumn = [];
        foreach ($rows as $row) {
            $byName[$row->name] = $row;
            $byColumn[$row->column_name] = $row;
        }

        foreach ($this->expectedForeignKeys() as $name => [$column, $referencedTable]) {
            if (isset($byName[$name])) {
                $this->assertForeignKeyMatches($name, $byName[$name], $column, $referencedTable);
                continue;
            }

            if (isset($byColumn[$column])) {
                // Harmless historical difference: the column already carries an FK
                // under a different (manually created) name. Accept it only when it
                // targets the same table with the same rules.
                $this->assertForeignKeyMatches((string) $byColumn[$column]->name, $byColumn[$column], $column, $referencedTable);
                continue;
            }

            // Missing additive FK defined by this migration — add it. MySQL
            // validates existing rows here; a violation fails loudly by design.
            Schema::table(self::TABLE, function (Blueprint $table) use ($column, $name, $referencedTable): void {
                $table->foreign($column, $name)->references('id')->on($referencedTable)->restrictOnDelete();
            });
        }
    }

    private function assertForeignKeyMatches(string $name, object $actual, string $column, string $referencedTable): void
    {
        $deleteRule = strtoupper((string) $actual->delete_rule);
        $updateRule = strtoupper((string) $actual->update_rule);

        if ($actual->column_name !== $column
            || $actual->referenced_table !== $referencedTable
            || $deleteRule !== 'RESTRICT'
            || ! in_array($updateRule, ['RESTRICT', 'NO ACTION'], true)) {
            throw new RuntimeException(sprintf(
                'D-08 reconcile: FK [%s] on %s mismatch (column %s → %s, ON DELETE %s, ON UPDATE %s; expected %s → %s ON DELETE RESTRICT). Stopping for manual inspection.',
                $name,
                self::TABLE,
                $actual->column_name,
                $actual->referenced_table,
                $deleteRule,
                $updateRule,
                $column,
                $referencedTable
            ));
        }
    }
};
