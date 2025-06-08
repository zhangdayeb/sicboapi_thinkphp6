# 🎲 骰宝游戏数据库需求重新梳理

## 📋 一、设计原则

### 1.1 核心思路
- **独立设计**：不兼容骰宝露珠表，创建全新骰宝表
- **专用优化**：针对骰宝特点优化表结构
- **简洁高效**：去除不必要的复杂设计
- **易于维护**：表结构清晰，字段明确

## 🗄️ 二、数据表设计

### 2.1 骰宝游戏结果表（核心表）

```sql
-- ==========================================
-- 骰宝游戏结果表 - 存储每局开奖结果
-- ==========================================
CREATE TABLE `ntp_sicbo_game_results` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `table_id` INT(11) NOT NULL COMMENT '台桌ID',
  `game_number` VARCHAR(50) NOT NULL COMMENT '游戏局号',
  `round_number` INT(11) NOT NULL COMMENT '当日轮次号',
  
  -- 骰子结果
  `dice1` TINYINT(1) NOT NULL COMMENT '骰子1点数(1-6)',
  `dice2` TINYINT(1) NOT NULL COMMENT '骰子2点数(1-6)', 
  `dice3` TINYINT(1) NOT NULL COMMENT '骰子3点数(1-6)',
  `total_points` TINYINT(2) NOT NULL COMMENT '总点数(3-18)',
  
  -- 基础结果
  `is_big` TINYINT(1) NOT NULL COMMENT '是否大 1=大(11-17) 0=小(4-10)',
  `is_odd` TINYINT(1) NOT NULL COMMENT '是否单 1=单 0=双',
  
  -- 特殊结果
  `has_triple` TINYINT(1) DEFAULT 0 COMMENT '是否三同号',
  `triple_number` TINYINT(1) NULL COMMENT '三同号数字(1-6)',
  `has_pair` TINYINT(1) DEFAULT 0 COMMENT '是否有对子',
  `pair_numbers` VARCHAR(10) NULL COMMENT '对子数字,逗号分隔',
  
  -- 中奖信息
  `winning_bets` JSON NULL COMMENT '所有中奖投注类型',
  
  -- 状态字段
  `status` TINYINT(1) DEFAULT 1 COMMENT '状态 1=正常 0=作废',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_game_number` (`game_number`),
  KEY `idx_table_id` (`table_id`),
  KEY `idx_created_at` (`created_at`),
  KEY `idx_table_date` (`table_id`, `created_at`),
  KEY `idx_total_points` (`total_points`),
  KEY `idx_big_small` (`is_big`),
  KEY `idx_odd_even` (`is_odd`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骰宝游戏结果表';
```

### 2.2 骰宝赔率配置表

```sql
-- ==========================================
-- 骰宝赔率配置表 - 管理所有投注类型和赔率
-- ==========================================
CREATE TABLE `ntp_sicbo_odds` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `bet_type` VARCHAR(50) NOT NULL COMMENT '投注类型标识',
  `bet_name_cn` VARCHAR(100) NOT NULL COMMENT '中文名称',
  `bet_name_en` VARCHAR(100) NULL COMMENT '英文名称',
  `bet_category` VARCHAR(30) NOT NULL COMMENT '投注分类',
  `odds` DECIMAL(8,2) NOT NULL COMMENT '赔率',
  `min_bet` DECIMAL(10,2) DEFAULT 10.00 COMMENT '最小投注',
  `max_bet` DECIMAL(10,2) DEFAULT 50000.00 COMMENT '最大投注',
  `probability` DECIMAL(8,6) NULL COMMENT '理论概率',
  `house_edge` DECIMAL(6,4) NULL COMMENT '庄家优势',
  `description` TEXT NULL COMMENT '说明',
  `sort_order` INT(11) DEFAULT 0 COMMENT '排序',
  `status` TINYINT(1) DEFAULT 1 COMMENT '状态 1=启用 0=禁用',
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_bet_type` (`bet_type`),
  KEY `idx_category` (`bet_category`),
  KEY `idx_status_sort` (`status`, `sort_order`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骰宝赔率配置表';
```

### 2.3 骰宝投注记录表

```sql
-- ==========================================
-- 骰宝投注记录表 - 记录用户投注详情
-- ==========================================
CREATE TABLE `ntp_sicbo_bet_records` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `user_id` INT(11) NOT NULL COMMENT '用户ID',
  `table_id` INT(11) NOT NULL COMMENT '台桌ID',
  `game_number` VARCHAR(50) NOT NULL COMMENT '游戏局号',
  `round_number` INT(11) NOT NULL COMMENT '轮次号',
  
  -- 投注信息
  `bet_type` VARCHAR(50) NOT NULL COMMENT '投注类型',
  `bet_amount` DECIMAL(10,2) NOT NULL COMMENT '投注金额',
  `odds` DECIMAL(8,2) NOT NULL COMMENT '投注时赔率',
  
  -- 结算信息
  `is_win` TINYINT(1) NULL COMMENT '是否中奖 1=中奖 0=未中奖',
  `win_amount` DECIMAL(10,2) DEFAULT 0.00 COMMENT '中奖金额',
  `settle_status` TINYINT(1) DEFAULT 0 COMMENT '结算状态 0=未结算 1=已结算',
  
  -- 账户变动
  `balance_before` DECIMAL(12,2) NOT NULL COMMENT '投注前余额',
  `balance_after` DECIMAL(12,2) NOT NULL COMMENT '投注后余额',
  
  -- 时间戳
  `bet_time` TIMESTAMP DEFAULT CURRENT_TIMESTAMP COMMENT '投注时间',
  `settle_time` TIMESTAMP NULL COMMENT '结算时间',
  
  PRIMARY KEY (`id`),
  KEY `idx_user_id` (`user_id`),
  KEY `idx_game_number` (`game_number`),
  KEY `idx_table_user` (`table_id`, `user_id`),
  KEY `idx_bet_time` (`bet_time`),
  KEY `idx_settle_status` (`settle_status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骰宝投注记录表';
```

### 2.4 骰宝游戏统计表

```sql
-- ==========================================
-- 骰宝游戏统计表 - 用于趋势分析和数据展示
-- ==========================================
CREATE TABLE `ntp_sicbo_statistics` (
  `id` INT(11) NOT NULL AUTO_INCREMENT,
  `table_id` INT(11) NOT NULL COMMENT '台桌ID',
  `stat_date` DATE NOT NULL COMMENT '统计日期',
  `stat_type` ENUM('hourly','daily','weekly','monthly') NOT NULL COMMENT '统计类型',
  
  -- 基础统计
  `total_rounds` INT(11) DEFAULT 0 COMMENT '总局数',
  `big_count` INT(11) DEFAULT 0 COMMENT '大的次数',
  `small_count` INT(11) DEFAULT 0 COMMENT '小的次数',
  `odd_count` INT(11) DEFAULT 0 COMMENT '单的次数',
  `even_count` INT(11) DEFAULT 0 COMMENT '双的次数',
  
  -- 特殊统计
  `triple_count` INT(11) DEFAULT 0 COMMENT '三同号次数',
  `pair_count` INT(11) DEFAULT 0 COMMENT '对子次数',
  
  -- 点数分布(JSON存储)
  `total_distribution` JSON NULL COMMENT '总点数分布统计',
  `dice_distribution` JSON NULL COMMENT '单骰分布统计',
  
  -- 投注统计
  `total_bet_amount` DECIMAL(15,2) DEFAULT 0.00 COMMENT '总投注额',
  `total_win_amount` DECIMAL(15,2) DEFAULT 0.00 COMMENT '总派彩额',
  `player_count` INT(11) DEFAULT 0 COMMENT '参与玩家数',
  
  `created_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
  `updated_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  
  PRIMARY KEY (`id`),
  UNIQUE KEY `uk_table_date_type` (`table_id`, `stat_date`, `stat_type`),
  KEY `idx_stat_date` (`stat_date`),
  KEY `idx_stat_type` (`stat_type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COMMENT='骰宝游戏统计表';
```

## 🎯 三、赔率数据初始化

### 3.1 插入骰宝赔率配置

```sql
-- ==========================================
-- 插入骰宝赔率数据
-- ==========================================
INSERT INTO `ntp_sicbo_odds` (`bet_type`, `bet_name_cn`, `bet_name_en`, `bet_category`, `odds`, `min_bet`, `max_bet`, `probability`, `house_edge`, `sort_order`) VALUES

-- 基础投注 (1-4)
('small', '小(4-10)', 'Small', 'basic', 1.00, 10, 50000, 0.486111, 0.0278, 1),
('big', '大(11-17)', 'Big', 'basic', 1.00, 10, 50000, 0.486111, 0.0278, 2),
('odd', '单', 'Odd', 'basic', 1.00, 10, 50000, 0.500000, 0.0000, 3),
('even', '双', 'Even', 'basic', 1.00, 10, 50000, 0.500000, 0.0000, 4),

-- 总和投注 (11-24)
('total-4', '总和4', 'Total 4', 'total', 60.00, 10, 1000, 0.013889, 0.1528, 11),
('total-5', '总和5', 'Total 5', 'total', 30.00, 10, 1000, 0.027778, 0.1389, 12),
('total-6', '总和6', 'Total 6', 'total', 17.00, 10, 1000, 0.046296, 0.1667, 13),
('total-7', '总和7', 'Total 7', 'total', 12.00, 10, 1000, 0.069444, 0.0972, 14),
('total-8', '总和8', 'Total 8', 'total', 8.00, 10, 1000, 0.097222, 0.1250, 15),
('total-9', '总和9', 'Total 9', 'total', 6.00, 10, 1000, 0.115741, 0.1898, 16),
('total-10', '总和10', 'Total 10', 'total', 6.00, 10, 1000, 0.125000, 0.1250, 17),
('total-11', '总和11', 'Total 11', 'total', 6.00, 10, 1000, 0.125000, 0.1250, 18),
('total-12', '总和12', 'Total 12', 'total', 6.00, 10, 1000, 0.115741, 0.1898, 19),
('total-13', '总和13', 'Total 13', 'total', 8.00, 10, 1000, 0.097222, 0.1250, 20),
('total-14', '总和14', 'Total 14', 'total', 12.00, 10, 1000, 0.069444, 0.0972, 21),
('total-15', '总和15', 'Total 15', 'total', 17.00, 10, 1000, 0.046296, 0.1667, 22),
('total-16', '总和16', 'Total 16', 'total', 30.00, 10, 1000, 0.027778, 0.1389, 23),
('total-17', '总和17', 'Total 17', 'total', 60.00, 10, 1000, 0.013889, 0.1528, 24),

-- 单骰投注 (31-36)
('single-1', '单骰1', 'Single 1', 'single', 1.00, 10, 10000, 0.421296, 0.0741, 31),
('single-2', '单骰2', 'Single 2', 'single', 1.00, 10, 10000, 0.421296, 0.0741, 32),
('single-3', '单骰3', 'Single 3', 'single', 1.00, 10, 10000, 0.421296, 0.0741, 33),
('single-4', '单骰4', 'Single 4', 'single', 1.00, 10, 10000, 0.421296, 0.0741, 34),
('single-5', '单骰5', 'Single 5', 'single', 1.00, 10, 10000, 0.421296, 0.0741, 35),
('single-6', '单骰6', 'Single 6', 'single', 1.00, 10, 10000, 0.421296, 0.0741, 36),

-- 对子投注 (41-46) 
('pair-1', '对子1', 'Pair 1', 'pair', 10.00, 10, 5000, 0.069444, 0.2361, 41),
('pair-2', '对子2', 'Pair 2', 'pair', 10.00, 10, 5000, 0.069444, 0.2361, 42),
('pair-3', '对子3', 'Pair 3', 'pair', 10.00, 10, 5000, 0.069444, 0.2361, 43),
('pair-4', '对子4', 'Pair 4', 'pair', 10.00, 10, 5000, 0.069444, 0.2361, 44),
('pair-5', '对子5', 'Pair 5', 'pair', 10.00, 10, 5000, 0.069444, 0.2361, 45),
('pair-6', '对子6', 'Pair 6', 'pair', 10.00, 10, 5000, 0.069444, 0.2361, 46),

-- 三同号投注 (51-57)
('triple-1', '三同号1', 'Triple 1', 'triple', 180.00, 10, 1000, 0.004630, 0.1620, 51),
('triple-2', '三同号2', 'Triple 2', 'triple', 180.00, 10, 1000, 0.004630, 0.1620, 52),
('triple-3', '三同号3', 'Triple 3', 'triple', 180.00, 10, 1000, 0.004630, 0.1620, 53),
('triple-4', '三同号4', 'Triple 4', 'triple', 180.00, 10, 1000, 0.004630, 0.1620, 54),
('triple-5', '三同号5', 'Triple 5', 'triple', 180.00, 10, 1000, 0.004630, 0.1620, 55),
('triple-6', '三同号6', 'Triple 6', 'triple', 180.00, 10, 1000, 0.004630, 0.1620, 56),
('any-triple', '任意三同号', 'Any Triple', 'triple', 30.00, 10, 5000, 0.027778, 0.1389, 57),

-- 组合投注 (61-75)
('combo-1-2', '组合1-2', 'Combo 1-2', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 61),
('combo-1-3', '组合1-3', 'Combo 1-3', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 62),
('combo-1-4', '组合1-4', 'Combo 1-4', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 63),
('combo-1-5', '组合1-5', 'Combo 1-5', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 64),
('combo-1-6', '组合1-6', 'Combo 1-6', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 65),
('combo-2-3', '组合2-3', 'Combo 2-3', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 66),
('combo-2-4', '组合2-4', 'Combo 2-4', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 67),
('combo-2-5', '组合2-5', 'Combo 2-5', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 68),
('combo-2-6', '组合2-6', 'Combo 2-6', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 69),
('combo-3-4', '组合3-4', 'Combo 3-4', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 70),
('combo-3-5', '组合3-5', 'Combo 3-5', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 71),
('combo-3-6', '组合3-6', 'Combo 3-6', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 72),
('combo-4-5', '组合4-5', 'Combo 4-5', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 73),
('combo-4-6', '组合4-6', 'Combo 4-6', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 74),
('combo-5-6', '组合5-6', 'Combo 5-6', 'combo', 6.00, 10, 5000, 0.138889, 0.0278, 75);
```

## 🔧 四、现有台桌表调整

### 4.1 修改台桌表（如果已按需求调整好则跳过）

```sql
-- 如果台桌表还未调整，执行以下语句
-- ALTER TABLE `ntp_dianji_table` 
-- ADD COLUMN `game_type` TINYINT(1) NOT NULL DEFAULT 3 
-- COMMENT '游戏类型: 1=现场台, 2=龙虎, 3=骰宝, 6=牛牛, 8=三公, 9=骰宝',
-- ADD COLUMN `game_config` JSON NULL COMMENT '游戏特定配置';

-- 插入骰宝测试台桌
INSERT INTO `ntp_dianji_table` (
    `game_type`, `table_title`, `lu_zhu_name`, `status`, `run_status`,
    `countdown_time`, `video_near`, `video_far`, `game_config`
) VALUES (
    9, '骰宝001', '骰宝001号台', 1, 0, 30, 
    'rtmp://stream.example.com/live/sicbo001_near',
    'rtmp://stream.example.com/live/sicbo001_far',
    JSON_OBJECT(
        'game_name', '骰宝',
        'betting_time', 30,
        'dice_rolling_time', 5,
        'result_display_time', 10,
        'auto_generate_game_number', true,
        'limits', JSON_OBJECT(
            'min_bet_basic', 10,
            'max_bet_basic', 50000,
            'min_bet_total', 10,
            'max_bet_total', 1000,
            'min_bet_triple', 10,
            'max_bet_triple', 500
        )
    )
);
```

## 📊 五、表结构总结

| 表名 | 用途 | 主要字段 |
|------|------|----------|
| `ntp_sicbo_game_results` | 游戏结果存储 | dice1-3, total_points, is_big, is_odd |
| `ntp_sicbo_odds` | 赔率配置管理 | bet_type, odds, min_bet, max_bet |
| `ntp_sicbo_bet_records` | 投注记录 | user_id, bet_type, bet_amount, is_win |
| `ntp_sicbo_statistics` | 统计分析 | big_count, small_count, triple_count |

## ✅ 六、核心优势

### 6.1 专用设计
- **纯骰宝表结构**：没有骰宝的历史包袱
- **字段精简**：只包含必要字段，性能更好
- **逻辑清晰**：表间关系明确，便于维护

### 6.2 功能完整
- **结果存储**：完整记录每局开奖信息
- **投注管理**：详细的投注和结算记录
- **统计分析**：支持多维度数据统计
- **赔率管理**：灵活的赔率配置系统

### 6.3 扩展性强
- **JSON字段**：支持复杂数据存储
- **索引优化**：针对常用查询优化
- **分类设计**：投注类型分类管理

这样设计的数据库结构专门为骰宝游戏优化，简洁高效，便于开发和维护！