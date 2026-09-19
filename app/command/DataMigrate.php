<?php

declare(strict_types=1);

namespace app\command;

use think\console\Command;
use think\console\Input;
use think\console\input\Option;
use think\console\Output;

/**
 * 旧系统数据迁移命令（P9）
 *
 * 用法：
 *   php think data:migrate                              迁移全部核心表
 *   php think data:migrate --tables=member,shop_product 仅迁移指定表
 *   php think data:migrate --aid=1                      仅迁移指定租户
 *   php think data:migrate --batch=1000                 批大小
 *   php think data:migrate --legacy-db=dxcx_legacy      覆盖旧库名（默认 env LEGACY.NAME）
 *
 * 设计：
 * - 旧库连接取 .env [LEGACY] 段（默认同机 dxcx_legacy）
 * - 逐表按「源∩目标」列交集拷贝，自动适配新旧结构差异（新库新增列走默认值）
 * - 按 id 升序分批 + INSERT IGNORE（保留原 ID，重复执行幂等，可断点重跑）
 * - 进度追加写 runtime/data_migrate.log
 */
class DataMigrate extends Command
{
    /** 核心业务表（新旧同名；其余表按需追加） */
    protected const CORE_TABLES = [
        'member',
        'member_address',
        'shop_category',
        'shop_product',
        'coupon',
        'coupon_record',
        'merchant',
        'mendian',
        'shop_order',
        'shop_order_goods',
    ];

    protected function configure()
    {
        $this->setName('data:migrate')
            ->setDescription('从旧系统库分批迁移核心业务数据（列交集拷贝，保留原ID，幂等可重跑）')
            ->addOption('tables', null, Option::VALUE_OPTIONAL, '逗号分隔表名（默认全部核心表）', '')
            ->addOption('aid', null, Option::VALUE_OPTIONAL, '仅迁移指定租户（0=全部）', '0')
            ->addOption('batch', null, Option::VALUE_OPTIONAL, '每批行数', '500')
            ->addOption('legacy-db', null, Option::VALUE_OPTIONAL, '旧库名（覆盖 env LEGACY.NAME）', '');
    }

    protected function execute(Input $input, Output $output)
    {
        $tables   = array_values(array_filter(array_map('trim', explode(',', (string) $input->getOption('tables')))));
        $tables   = empty($tables) ? self::CORE_TABLES : $tables;
        $aid      = (int) $input->getOption('aid');
        $batch    = max(1, (int) $input->getOption('batch'));
        $legacyDb = (string) ($input->getOption('legacy-db') ?: env('LEGACY.NAME', 'dxcx_legacy'));

        $src = $this->connect(
            (string) env('LEGACY.HOST', '127.0.0.1'),
            (int) env('LEGACY.PORT', 3306),
            $legacyDb,
            (string) env('LEGACY.USER', 'dxcx'),
            (string) env('LEGACY.PASS', 'dxcx123456')
        );
        $dst = $this->connect(
            (string) env('DATABASE.HOSTNAME', '127.0.0.1'),
            (int) env('DATABASE.HOSTPORT', 3306),
            (string) env('DATABASE.DATABASE', 'dxcx'),
            (string) env('DATABASE.USERNAME', 'dxcx'),
            (string) env('DATABASE.PASSWORD', '')
        );

        $output->writeln(sprintf('迁移开始：%s -> %s（%d 张表，batch=%d，aid=%s）', $legacyDb, (string) env('DATABASE.DATABASE', 'dxcx'), count($tables), $batch, $aid > 0 ? (string) $aid : '全部'));

        $logLines = [];
        $totalRows = 0;

        foreach ($tables as $table) {
            if (!$this->tableExists($src, $legacyDb, $table)) {
                $line = sprintf('[SKIP] %s：旧库无此表', $table);
                $output->writeln($line);
                $logLines[] = $line;

                continue;
            }
            if (!$this->tableExists($dst, (string) env('DATABASE.DATABASE', 'dxcx'), $table)) {
                $line = sprintf('[SKIP] %s：新库无此表', $table);
                $output->writeln($line);
                $logLines[] = $line;

                continue;
            }

            $cols = $this->commonColumns($src, $legacyDb, $dst, (string) env('DATABASE.DATABASE', 'dxcx'), $table);

            if (!in_array('id', $cols, true)) {
                $line = sprintf('[SKIP] %s：缺少 id 列无法按批迁移', $table);
                $output->writeln($line);
                $logLines[] = $line;

                continue;
            }

            $hasAid = in_array('aid', $cols, true);
            $rows   = $this->migrateTable($src, $dst, $table, $cols, $aid > 0 && $hasAid ? $aid : 0, $batch);
            $totalRows += $rows;

            $line = sprintf('[OK] %s：迁移 %d 行（列交集 %d 列）', $table, $rows, count($cols));
            $output->writeln($line);
            $logLines[] = $line;
        }

        $summary = sprintf('迁移完成：共 %d 行', $totalRows);
        $output->writeln($summary);

        file_put_contents(
            runtime_path() . 'data_migrate.log',
            date('Y-m-d H:i:s') . ' data:migrate ' . implode(',', $tables) . PHP_EOL . implode(PHP_EOL, $logLines) . PHP_EOL . $summary . PHP_EOL,
            FILE_APPEND | LOCK_EX
        );
    }

    /**
     * 单表分批迁移（id 升序，INSERT IGNORE 幂等）
     *
     * @param string[] $cols
     */
    protected function migrateTable(\PDO $src, \PDO $dst, string $table, array $cols, int $aid, int $batch): int
    {
        $colSql   = implode(',', array_map(static fn (string $c) => '`' . $c . '`', $cols));
        $lastId   = 0;
        $migrated = 0;

        while (true) {
            $sql = 'SELECT ' . $colSql . ' FROM `' . $table . '` WHERE `id` > ' . $lastId;

            if ($aid > 0) {
                $sql .= ' AND `aid` = ' . $aid;
            }

            $sql .= ' ORDER BY `id` ASC LIMIT ' . $batch;

            $rows = $src->query($sql)->fetchAll(\PDO::FETCH_ASSOC);

            if (empty($rows)) {
                break;
            }

            $placeholders = '(' . implode(',', array_fill(0, count($cols), '?')) . ')';
            $insert       = 'INSERT IGNORE INTO `' . $table . '` (' . $colSql . ') VALUES '
                . implode(',', array_fill(0, count($rows), $placeholders));

            $params = [];

            foreach ($rows as $row) {
                foreach ($cols as $col) {
                    $params[] = $row[$col];
                }
            }

            $dst->prepare($insert)->execute($params);

            $migrated += count($rows);
            $lastId    = (int) end($rows)['id'];

            if (count($rows) < $batch) {
                break;
            }
        }

        return $migrated;
    }

    /** 源∩目标 列交集（保持源表列序） */
    protected function commonColumns(\PDO $src, string $srcDb, \PDO $dst, string $dstDb, string $table): array
    {
        $srcCols = $this->columns($src, $srcDb, $table);
        $dstCols = array_flip($this->columns($dst, $dstDb, $table));

        return array_values(array_filter($srcCols, static fn (string $c) => isset($dstCols[$c])));
    }

    /** @return string[] */
    protected function columns(\PDO $pdo, string $db, string $table): array
    {
        $stmt = $pdo->prepare('SELECT COLUMN_NAME FROM information_schema.columns WHERE table_schema = ? AND table_name = ? ORDER BY ORDINAL_POSITION');
        $stmt->execute([$db, $table]);

        return $stmt->fetchAll(\PDO::FETCH_COLUMN);
    }

    protected function tableExists(\PDO $pdo, string $db, string $table): bool
    {
        $stmt = $pdo->prepare('SELECT 1 FROM information_schema.tables WHERE table_schema = ? AND table_name = ? LIMIT 1');
        $stmt->execute([$db, $table]);

        return false !== $stmt->fetchColumn();
    }

    protected function connect(string $host, int $port, string $db, string $user, string $pass): \PDO
    {
        return new \PDO(
            'mysql:host=' . $host . ';port=' . $port . ';dbname=' . $db . ';charset=utf8mb4',
            $user,
            $pass,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]
        );
    }
}
