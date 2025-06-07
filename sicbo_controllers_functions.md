# 🎲 骰宝控制器功能规划文档

## 📁 控制器文件功能分工

### 🎮 一、SicboGameController.php - 游戏主控制器

**职责**：处理核心游戏逻辑，游戏状态管理，开奖流程控制

#### 1.1 游戏状态管理
```php
/**
 * 获取台桌游戏信息
 * 路由: GET /sicbo/game/table-info
 * 参数: table_id
 * 返回: 台桌状态、倒计时、当前局号等
 */
public function getTableInfo()

/**
 * 获取游戏历史记录
 * 路由: GET /sicbo/game/history  
 * 参数: table_id, limit=20
 * 返回: 最近N局的开奖结果
 */
public function getGameHistory()

/**
 * 获取游戏统计数据
 * 路由: GET /sicbo/game/statistics
 * 参数: table_id, type(daily/weekly)
 * 返回: 大小单双统计、热冷号码等
 */
public function getStatistics()
```

#### 1.2 游戏流程控制
```php
/**
 * 开始新游戏局
 * 路由: POST /sicbo/game/start
 * 参数: table_id, betting_time=30
 * 功能: 生成新游戏局号，启动投注倒计时
 */
public function startNewGame()

/**
 * 停止投注
 * 路由: POST /sicbo/game/stop-betting
 * 参数: table_id, game_number
 * 功能: 停止当前局投注，准备开奖
 */
public function stopBetting()

/**
 * 公布开奖结果
 * 路由: POST /sicbo/game/announce-result  
 * 参数: table_id, game_number, dice1, dice2, dice3
 * 功能: 计算开奖结果，触发结算流程
 */
public function announceResult()
```

#### 1.3 实时数据获取
```php
/**
 * 获取当前投注统计
 * 路由: GET /sicbo/game/bet-stats
 * 参数: table_id, game_number
 * 返回: 各投注类型的投注总额分布
 */
public function getCurrentBetStats()

/**
 * 获取赔率信息
 * 路由: GET /sicbo/game/odds
 * 参数: table_id (可选)
 * 返回: 所有投注类型的当前赔率
 */
public function getOddsInfo()
```

---

### 💰 二、SicboBetController.php - 投注控制器

**职责**：处理用户投注逻辑，投注验证，余额管理

#### 2.1 用户投注操作
```php
/**
 * 提交用户投注
 * 路由: POST /sicbo/bet/place
 * 参数: table_id, game_number, bets[], total_amount
 * 功能: 验证并处理用户投注，扣除余额
 */
public function placeBet()

/**
 * 修改当前投注
 * 路由: PUT /sicbo/bet/modify
 * 参数: table_id, game_number, bets[]
 * 功能: 允许用户在投注期内修改投注
 */
public function modifyBet()

/**
 * 取消当前投注
 * 路由: DELETE /sicbo/bet/cancel
 * 参数: table_id, game_number
 * 功能: 取消当前局所有投注，退回金额
 */
public function cancelBet()
```

#### 2.2 投注记录查询
```php
/**
 * 获取用户当前投注
 * 路由: GET /sicbo/bet/current
 * 参数: table_id, game_number
 * 返回: 用户在当前局的所有投注
 */
public function getCurrentBets()

/**
 * 获取用户投注历史
 * 路由: GET /sicbo/bet/history
 * 参数: user_id, page=1, limit=20, date_range
 * 返回: 用户历史投注记录
 */
public function getBetHistory()

/**
 * 获取投注详情
 * 路由: GET /sicbo/bet/detail/{bet_id}
 * 参数: bet_id
 * 返回: 单笔投注的详细信息
 */
public function getBetDetail()
```

#### 2.3 余额和限额管理
```php
/**
 * 获取用户余额信息
 * 路由: GET /sicbo/bet/balance
 * 返回: 当前余额、冻结金额、可用余额
 */
public function getUserBalance()

/**
 * 获取投注限额信息
 * 路由: GET /sicbo/bet/limits
 * 参数: table_id, bet_type (可选)
 * 返回: 当前用户的投注限额设置
 */
public function getBetLimits()

/**
 * 预检投注合法性
 * 路由: POST /sicbo/bet/validate
 * 参数: table_id, bets[]
 * 返回: 投注是否合法，错误原因
 */
public function validateBet()
```

---

### 🛠️ 三、SicboAdminController.php - 管理后台控制器

**职责**：后台管理功能，荷官操作，系统配置

#### 3.1 台桌管理
```php
/**
 * 获取台桌列表
 * 路由: GET /sicbo/admin/tables
 * 返回: 所有骰宝台桌信息和状态
 */
public function getTableList()

/**
 * 更新台桌设置
 * 路由: PUT /sicbo/admin/table/{table_id}
 * 参数: game_config, status, limits等
 * 功能: 更新台桌配置信息
 */
public function updateTableConfig()

/**
 * 台桌开关控制
 * 路由: POST /sicbo/admin/table/{table_id}/toggle
 * 参数: action(open/close/maintain)
 * 功能: 开启、关闭或维护台桌
 */
public function toggleTableStatus()
```

#### 3.2 荷官操作功能
```php
/**
 * 荷官开始游戏
 * 路由: POST /sicbo/admin/dealer/start-game
 * 参数: table_id, betting_duration
 * 功能: 荷官启动新游戏局
 */
public function dealerStartGame()

/**
 * 荷官录入开奖结果
 * 路由: POST /sicbo/admin/dealer/input-result
 * 参数: table_id, game_number, dice1, dice2, dice3
 * 功能: 荷官手动输入骰子结果
 */
public function dealerInputResult()

/**
 * 荷官强制结束游戏
 * 路由: POST /sicbo/admin/dealer/force-end
 * 参数: table_id, game_number, reason
 * 功能: 异常情况下强制结束游戏
 */
public function dealerForceEnd()
```

#### 3.3 数据监控和报表
```php
/**
 * 获取实时监控数据
 * 路由: GET /sicbo/admin/monitor/realtime
 * 参数: table_id (可选)
 * 返回: 在线人数、投注统计、系统状态
 */
public function getRealtimeMonitor()

/**
 * 获取财务报表
 * 路由: GET /sicbo/admin/report/financial
 * 参数: date_range, table_id, report_type
 * 返回: 收入、支出、盈亏统计
 */
public function getFinancialReport()

/**
 * 获取用户行为分析
 * 路由: GET /sicbo/admin/report/user-behavior
 * 参数: date_range, user_type
 * 返回: 用户投注行为分析数据
 */
public function getUserBehaviorReport()
```

#### 3.4 系统配置管理
```php
/**
 * 获取赔率配置
 * 路由: GET /sicbo/admin/config/odds
 * 返回: 所有投注类型的赔率配置
 */
public function getOddsConfig()

/**
 * 更新赔率配置
 * 路由: PUT /sicbo/admin/config/odds
 * 参数: bet_type, odds, limits等
 * 功能: 修改投注类型的赔率和限额
 */
public function updateOddsConfig()

/**
 * 获取系统参数配置
 * 路由: GET /sicbo/admin/config/system
 * 返回: 游戏相关的系统参数
 */
public function getSystemConfig()

/**
 * 更新系统参数
 * 路由: PUT /sicbo/admin/config/system
 * 参数: 各种系统参数
 * 功能: 修改系统级配置
 */
public function updateSystemConfig()
```

---

### 🔗 四、SicboApiController.php - API接口控制器

**职责**：对外API接口，第三方集成，移动端API

#### 4.1 基础API接口
```php
/**
 * API身份验证
 * 路由: POST /api/sicbo/auth
 * 参数: api_key, secret, timestamp
 * 返回: access_token
 */
public function authenticate()

/**
 * 获取台桌列表API
 * 路由: GET /api/sicbo/tables
 * 参数: game_type=sicbo
 * 返回: 台桌列表(简化版)
 */
public function apiGetTables()

/**
 * 获取游戏状态API
 * 路由: GET /api/sicbo/game-status/{table_id}
 * 返回: 当前游戏状态信息
 */
public function apiGetGameStatus()
```

#### 4.2 投注相关API
```php
/**
 * API投注接口
 * 路由: POST /api/sicbo/bet
 * 参数: table_id, user_token, bets[]
 * 功能: 第三方平台投注接口
 */
public function apiBet()

/**
 * 查询投注结果API
 * 路由: GET /api/sicbo/bet-result/{game_number}
 * 参数: user_token
 * 返回: 用户在指定游戏的投注结果
 */
public function apiGetBetResult()

/**
 * 获取用户余额API
 * 路由: GET /api/sicbo/balance
 * 参数: user_token
 * 返回: 用户当前余额信息
 */
public function apiGetBalance()
```

#### 4.3 数据查询API
```php
/**
 * 获取开奖历史API
 * 路由: GET /api/sicbo/results
 * 参数: table_id, limit, date_range
 * 返回: 开奖历史记录
 */
public function apiGetResults()

/**
 * 获取赔率信息API
 * 路由: GET /api/sicbo/odds/{table_id}
 * 返回: 当前有效赔率信息
 */
public function apiGetOdds()

/**
 * 获取统计数据API
 * 路由: GET /api/sicbo/statistics/{table_id}
 * 参数: period(1h/24h/7d)
 * 返回: 统计分析数据
 */
public function apiGetStatistics()
```

#### 4.4 移动端专用接口
```php
/**
 * 移动端快速投注
 * 路由: POST /api/sicbo/mobile/quick-bet
 * 参数: table_id, bet_type, amount
 * 功能: 移动端一键投注功能
 */
public function mobileQuickBet()

/**
 * 移动端游戏状态推送注册
 * 路由: POST /api/sicbo/mobile/subscribe
 * 参数: device_token, table_id
 * 功能: 注册推送通知
 */
public function mobileSubscribe()

/**
 * 移动端用户偏好设置
 * 路由: PUT /api/sicbo/mobile/preferences
 * 参数: 各种偏好设置
 * 功能: 保存用户个性化设置
 */
public function mobileSetPreferences()
```

---

## 🔄 控制器间协作关系

### 数据流向
```
前端用户操作 → SicboGameController (游戏状态)
              ↓
用户投注 → SicboBetController (投注处理)
              ↓  
荷官操作 → SicboAdminController (结果录入)
              ↓
第三方调用 → SicboApiController (API接口)
```

### 依赖关系
- **SicboBetController** 依赖 **SicboGameController** 的游戏状态
- **SicboAdminController** 控制 **SicboGameController** 的游戏流程
- **SicboApiController** 封装其他控制器的功能对外提供接口

### 权限控制
- **SicboGameController**: 普通用户权限
- **SicboBetController**: 认证用户权限  
- **SicboAdminController**: 管理员/荷官权限
- **SicboApiController**: API密钥权限

## 📝 开发优先级建议

### 第一阶段 (核心功能)
1. **SicboGameController**: `getTableInfo()`, `startNewGame()`, `announceResult()`
2. **SicboBetController**: `placeBet()`, `getCurrentBets()`, `getUserBalance()`

### 第二阶段 (管理功能)  
3. **SicboAdminController**: `dealerStartGame()`, `dealerInputResult()`, `getRealtimeMonitor()`

### 第三阶段 (扩展功能)
4. **SicboApiController**: 基础API接口
5. 其他高级功能和报表功能

这个规划文档可以作为后续开发的蓝图，每个函数的功能都很明确，便于分工开发和代码复用。