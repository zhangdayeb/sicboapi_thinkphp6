<?php

namespace app\model;

use think\Model;

/**
 * 台桌模型
 * Class Table
 * @package app\model
 */
class Table extends Model
{
    // 数据表名
    protected $name = 'dianji_table';
    
    // 主键
    protected $pk = 'id';
    
    // 自动时间戳
    protected $autoWriteTimestamp = true;
    protected $createTime = 'create_time';
    protected $updateTime = 'update_time';

    // 数据类型转换
    protected $type = [
        'id'              => 'integer',
        'game_type'       => 'integer',
        'status'          => 'integer',
        'run_status'      => 'integer',
        'start_time'      => 'integer',
        'countdown_time'  => 'integer',
        'game_config'     => 'json',
        'create_time'     => 'datetime',
        'update_time'     => 'datetime',
    ];

    // 只读字段
    protected $readonly = [
        'id',
        'create_time',
    ];

    // 游戏类型常量
    const GAME_TYPE_BACCARAT = 1;     // 百家乐
    const GAME_TYPE_DRAGON_TIGER = 2; // 龙虎
    const GAME_TYPE_ROULETTE = 3;     // 轮盘
    const GAME_TYPE_SICBO = 9;        // 骰宝

    // 台桌状态常量
    const STATUS_CLOSED = 0;          // 关闭
    const STATUS_OPEN = 1;            // 开放
    const STATUS_MAINTENANCE = 2;     // 维护

    // 运行状态常量
    const RUN_STATUS_WAITING = 0;     // 等待中
    const RUN_STATUS_BETTING = 1;     // 投注中
    const RUN_STATUS_DEALING = 2;     // 开奖中

    /**
     * 获取骰宝台桌列表
     * @param bool $activeOnly 是否只获取开放的台桌
     * @return array
     */
    public static function getSicboTables(bool $activeOnly = true): array
    {
        $query = self::where('game_type', self::GAME_TYPE_SICBO);
        
        if ($activeOnly) {
            $query->where('status', self::STATUS_OPEN);
        }
        
        return $query->order('id asc')
            ->select()
            ->toArray();
    }

    /**
     * 根据游戏类型获取台桌
     * @param int $gameType 游戏类型
     * @param bool $activeOnly 是否只获取开放的台桌
     * @return array
     */
    public static function getTablesByGameType(int $gameType, bool $activeOnly = true): array
    {
        $query = self::where('game_type', $gameType);
        
        if ($activeOnly) {
            $query->where('status', self::STATUS_OPEN);
        }
        
        return $query->order('id asc')
            ->select()
            ->toArray();
    }

    /**
     * 获取台桌详细信息
     * @param int $tableId 台桌ID
     * @return array|null
     */
    public static function getTableInfo(int $tableId): ?array
    {
        $result = self::find($tableId);
        return $result ? $result->toArray() : null;
    }

    /**
     * 更新台桌状态
     * @param int $tableId 台桌ID
     * @param int $status 状态
     * @return bool
     */
    public static function updateTableStatus(int $tableId, int $status): bool
    {
        return self::where('id', $tableId)
            ->update(['status' => $status, 'update_time' => time()]) !== false;
    }

    /**
     * 更新台桌运行状态
     * @param int $tableId 台桌ID
     * @param int $runStatus 运行状态
     * @param array $extraData 额外数据
     * @return bool
     */
    public static function updateRunStatus(int $tableId, int $runStatus, array $extraData = []): bool
    {
        $updateData = array_merge([
            'run_status' => $runStatus,
            'update_time' => time()
        ], $extraData);
        
        return self::where('id', $tableId)
            ->update($updateData) !== false;
    }

    /**
     * 开始游戏 - 设置投注状态
     * @param int $tableId 台桌ID
     * @param int $bettingTime 投注时长(秒)
     * @return bool
     */
    public static function startGame(int $tableId, int $bettingTime = 30): bool
    {
        return self::updateRunStatus($tableId, self::RUN_STATUS_BETTING, [
            'start_time' => time(),
            'countdown_time' => $bettingTime
        ]);
    }

    /**
     * 停止投注 - 设置开奖状态
     * @param int $tableId 台桌ID
     * @return bool
     */
    public static function stopBetting(int $tableId): bool
    {
        return self::updateRunStatus($tableId, self::RUN_STATUS_DEALING);
    }

    /**
     * 游戏结束 - 设置等待状态
     * @param int $tableId 台桌ID
     * @return bool
     */
    public static function endGame(int $tableId): bool
    {
        return self::updateRunStatus($tableId, self::RUN_STATUS_WAITING, [
            'start_time' => 0,
            'countdown_time' => 0
        ]);
    }

    /**
     * 检查台桌是否可用
     * @param int $tableId 台桌ID
     * @param int $gameType 游戏类型
     * @return bool
     */
    public static function isTableAvailable(int $tableId, int $gameType): bool
    {
        $table = self::where('id', $tableId)
            ->where('game_type', $gameType)
            ->where('status', self::STATUS_OPEN)
            ->find();
        
        return $table !== null;
    }

    /**
     * 获取台桌配置
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function getTableConfig(int $tableId): array
    {
        $table = self::find($tableId);
        
        if (!$table || !$table->game_config) {
            return [];
        }
        
        return is_string($table->game_config) 
            ? json_decode($table->game_config, true) 
            : $table->game_config;
    }

    /**
     * 更新台桌配置
     * @param int $tableId 台桌ID
     * @param array $config 配置数据
     * @return bool
     */
    public static function updateTableConfig(int $tableId, array $config): bool
    {
        return self::where('id', $tableId)
            ->update([
                'game_config' => json_encode($config),
                'update_time' => time()
            ]) !== false;
    }

    /**
     * 获取台桌统计信息
     * @param int $tableId 台桌ID
     * @return array
     */
    public static function getTableStats(int $tableId): array
    {
        $table = self::find($tableId);
        
        if (!$table) {
            return [];
        }
        
        $stats = [
            'table_id' => $tableId,
            'table_name' => $table->table_title,
            'game_type' => $table->game_type,
            'status' => $table->status,
            'run_status' => $table->run_status,
            'create_time' => $table->create_time,
            'update_time' => $table->update_time,
        ];
        
        // 如果是骰宝台桌，获取额外统计
        if ($table->game_type == self::GAME_TYPE_SICBO) {
            $stats['today_games'] = \app\model\sicbo\SicboGameResults::where('table_id', $tableId)
                ->whereTime('created_at', 'today')
                ->count();
                
            $stats['today_bets'] = \app\model\sicbo\SicboBetRecords::where('table_id', $tableId)
                ->whereTime('bet_time', 'today')
                ->count();
        }
        
        return $stats;
    }

    /**
     * 获取所有活跃台桌的状态
     * @return array
     */
    public static function getAllActiveTablesStatus(): array
    {
        $tables = self::where('status', self::STATUS_OPEN)
            ->field('id,table_title,game_type,status,run_status,start_time,countdown_time')
            ->select()
            ->toArray();
        
        $result = [];
        foreach ($tables as $table) {
            $countdown = 0;
            if ($table['run_status'] == self::RUN_STATUS_BETTING && $table['start_time'] > 0) {
                $endTime = $table['start_time'] + $table['countdown_time'];
                $countdown = max(0, $endTime - time());
            }
            
            $result[] = [
                'table_id' => $table['id'],
                'table_name' => $table['table_title'],
                'game_type' => $table['game_type'],
                'status' => $table['status'],
                'run_status' => $table['run_status'],
                'countdown' => $countdown,
            ];
        }
        
        return $result;
    }

    /**
     * 创建新台桌
     * @param array $data 台桌数据
     * @return bool|int
     */
    public static function createTable(array $data)
    {
        $defaultData = [
            'status' => self::STATUS_CLOSED,
            'run_status' => self::RUN_STATUS_WAITING,
            'start_time' => 0,
            'countdown_time' => 30,
            'game_config' => json_encode([]),
            'create_time' => time(),
            'update_time' => time()
        ];
        
        $tableData = array_merge($defaultData, $data);
        
        return self::create($tableData);
    }

    /**
     * 获取游戏类型名称
     * @param int $gameType 游戏类型
     * @return string
     */
    public static function getGameTypeName(int $gameType): string
    {
        $gameTypes = [
            self::GAME_TYPE_BACCARAT => '百家乐',
            self::GAME_TYPE_DRAGON_TIGER => '龙虎',
            self::GAME_TYPE_ROULETTE => '轮盘',
            self::GAME_TYPE_SICBO => '骰宝',
        ];
        
        return $gameTypes[$gameType] ?? '未知游戏';
    }

    /**
     * 获取状态名称
     * @param int $status 状态
     * @return string
     */
    public static function getStatusName(int $status): string
    {
        $statuses = [
            self::STATUS_CLOSED => '关闭',
            self::STATUS_OPEN => '开放',
            self::STATUS_MAINTENANCE => '维护',
        ];
        
        return $statuses[$status] ?? '未知状态';
    }

    /**
     * 获取运行状态名称
     * @param int $runStatus 运行状态
     * @return string
     */
    public static function getRunStatusName(int $runStatus): string
    {
        $runStatuses = [
            self::RUN_STATUS_WAITING => '等待中',
            self::RUN_STATUS_BETTING => '投注中',
            self::RUN_STATUS_DEALING => '开奖中',
        ];
        
        return $runStatuses[$runStatus] ?? '未知状态';
    }
}