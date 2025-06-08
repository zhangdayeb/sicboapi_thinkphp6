# 骰宝游戏系统前后端通信协议

## 目录
- [1. 协议概述](#1-协议概述)
- [2. HTTP API 协议](#2-http-api-协议)
- [3. WebSocket 协议](#3-websocket-协议)
- [4. 数据结构定义](#4-数据结构定义)
- [5. 错误码说明](#5-错误码说明)

---

## 1. 协议概述

### 1.1 系统架构
- **HTTP API**: 用于用户操作、荷官管理、数据查询等同步操作
- **WebSocket**: 用于实时推送开牌结果、台桌状态、倒计时等异步通信

### 1.2 数据格式
- 所有接口统一使用 JSON 格式
- 字符编码：UTF-8
- 时间格式：`Y-m-d H:i:s` (如: 2025-01-15 14:30:25)

### 1.3 通用响应格式
```json
{
    "code": 200,
    "message": "ok！",
    "data": {}
}
```

---

## 2. HTTP API 协议

### 2.1 用户投注相关

#### 2.1.1 用户下注
**接口**: `POST /sicbo/bet/order`

**请求头**:
```
Content-Type: application/json
x-csrf-token: {用户token}
```

**请求参数**:
```json
{
    "table_id": 1,
    "game_type": 9,
    "is_exempt": 0,
    "bet": [
        {
            "money": 100,
            "rate_id": 15
        },
        {
            "money": 200,
            "rate_id": 23
        }
    ]
}
```

**参数说明**:
- `table_id`: 台桌ID
- `game_type`: 游戏类型 (9=骰宝)
- `is_exempt`: 免佣状态 (0=收佣金, 1=免佣)
- `bet`: 投注数组
  - `money`: 投注金额
  - `rate_id`: 赔率ID(对应投注类型)

**成功响应**:
```json
{
    "code": 200,
    "message": "ok！",
    "data": {
        "money_balance": 8700,
        "money_spend": 300,
        "0": {
            "money": 100,
            "rate_id": 15
        },
        "1": {
            "money": 200,
            "rate_id": 23
        }
    }
}
```

#### 2.1.2 获取当前投注记录
**接口**: `POST /sicbo/current/record`

**请求参数**:
```json
{
    "id": 1
}
```

**成功响应**:
```json
{
    "code": 200,
    "message": "ok！",
    "data": {
        "is_exempt": 0,
        "record_list": [
            {
                "bet_amt": 100,
                "game_peilv_id": 15,
                "is_exempt": 0
            }
        ]
    }
}
```

### 2.2 游戏信息查询

#### 2.2.1 获取露珠数据
**接口**: `GET /sicbo/get_table/get_data`

**请求参数**:
```
tableId=1&xue=1&gameType=9
```

**成功响应**:
```json
{
    "code": 1,
    "message": "ok！",
    "data": {
        "k0": "{\"1\":\"3\",\"2\":\"4\",\"3\":\"2\"}",
        "k1": "{\"1\":\"1\",\"2\":\"6\",\"3\":\"5\"}",
        "k2": "{\"1\":\"2\",\"2\":\"2\",\"3\":\"3\"}"
    }
}
```

#### 2.2.2 获取台桌列表
**接口**: `GET /sicbo/get_table/list`

**成功响应**:
```json
{
    "code": 1,
    "message": "ok！",
    "data": [
        {
            "id": 1,
            "table_title": "骰宝台桌A",
            "status": 1,
            "run_status": 1,
            "game_type": 9,
            "viewType": "_sicbo",
            "number": 1523,
            "xue_number": 3,
            "video_near": "rtmp://stream.example.com/live/table1_near",
            "video_far": "rtmp://stream.example.com/live/table1_far"
        }
    ]
}
```

#### 2.2.3 获取台桌详细信息
**接口**: `GET /sicbo/get_table/table_info`

**请求参数**:
```
tableId=1&gameType=9
```

**成功响应**:
```json
{
    "code": 1,
    "message": "ok！",
    "data": {
        "id": 1,
        "lu_zhu_name": "A桌",
        "num_pu": 15,
        "num_xue": 3,
        "video_near": "rtmp://stream.example.com/live/table1_near",
        "video_far": "rtmp://stream.example.com/live/table1_far",
        "time_start": 45,
        "right_money_banker_player": 1000,
        "right_money_tie": 500
    }
}
```

### 2.3 荷官操作相关

#### 2.3.1 开局信号 (开始倒计时)
**接口**: `POST /sicbo/start/signal`

**请求参数**:
```json
{
    "tableId": 1,
    "time": 45
}
```

**成功响应**:
```json
{
    "code": 200,
    "message": "ok！",
    "data": {
        "start_time": 1705298400,
        "countdown_time": 45,
        "status": 1,
        "run_status": 1,
        "table_id": 1
    }
}
```

#### 2.3.2 结束信号 (停止投注)
**接口**: `POST /sicbo/end/signal`

**请求参数**:
```json
{
    "tableId": 1
}
```

#### 2.3.3 荷官开牌
**接口**: `POST /sicbo/get_table/post_data`

**请求参数**:
```json
{
    "gameType": 9,
    "tableId": 1,
    "xueNumber": 3,
    "puNumber": 15,
    "result": 1,
    "ext": 0,
    "pai_result": {
        "1": "3",
        "2": "4", 
        "3": "2"
    }
}
```

**参数说明**:
- `pai_result`: 骰子结果，3个骰子分别的点数
- `result`: 主要结果类型
- `ext`: 扩展结果

**成功响应**:
```json
{
    "code": 200,
    "message": "ok！",
    "data": {}
}
```

#### 2.3.4 设置靴号
**接口**: `POST /sicbo/get_table/add_xue`

**请求参数**:
```json
{
    "tableId": 1,
    "num_xue": 4,
    "gameType": 9
}
```

#### 2.3.5 删除露珠记录
**接口**: `POST /sicbo/get_table/clear_lu_zhu`

**请求参数**:
```json
{
    "tableId": 1,
    "num_xue": 3,
    "num_pu": 15
}
```

### 2.4 游戏结果查询

#### 2.4.1 获取牌型信息
**接口**: `POST /sicbo/game/poker`

**请求参数**:
```json
{
    "id": 123
}
```

**成功响应**:
```json
{
    "code": 200,
    "message": "ok！",
    "data": {
        "result": {
            "win_array": [15, 23, 31],
            "basic_small": 0,
            "basic_big": 1,
            "total_total_9": 1
        },
        "info": {
            "dice1": "3",
            "dice2": "4",
            "dice3": "2"
        },
        "pai_flash": [15, 23, 31]
    }
}
```

---

## 3. WebSocket 协议

### 3.1 连接建立

**连接地址**: `ws://域名:2009`

#### 3.1.1 客户端连接
```json
{
    "user_id": "12345",
    "table_id": 1,
    "game_type": 9
}
```

**参数说明**:
- `user_id`: 用户ID，游客可传 "null__"
- `table_id`: 要监听的台桌ID
- `game_type`: 游戏类型

#### 3.1.2 服务器响应
```json
{
    "code": 200,
    "msg": "成功",
    "data": {
        "table_run_info": {
            "id": 1,
            "table_title": "骰宝台桌A",
            "status": 1,
            "run_status": 1,
            "end_time": 35,
            "bureau_number": "2025011514130115",
            "is_exempt": 0,
            "video_near": "rtmp://stream.example.com/live/table1_near",
            "video_far": "rtmp://stream.example.com/live/table1_far"
        }
    }
}
```

### 3.2 消息类型

#### 3.2.1 台桌状态推送 (code: 200)
**服务器 → 客户端**
```json
{
    "code": 200,
    "msg": "成功",
    "data": {
        "table_run_info": {
            "id": 1,
            "end_time": 30,
            "run_status": 1,
            "status": 1
        }
    }
}
```

**说明**: 每秒推送一次台桌倒计时和状态信息

#### 3.2.2 开牌结果推送 (code: 200)
**服务器 → 客户端**
```json
{
    "code": 200,
    "msg": "成功", 
    "data": {
        "result_info": {
            "result": {
                "win_array": [15, 23, 31],
                "basic_small": 0,
                "basic_big": 1,
                "basic_odd": 1,
                "basic_even": 0
            },
            "info": {
                "dice1": "3",
                "dice2": "4", 
                "dice3": "2"
            },
            "pai_flash": [15, 23, 31],
            "money": 150,
            "table_info": {
                "user_id": "12345",
                "table_id": 1,
                "game_type": 9
            }
        },
        "bureau_number": "2025011514130115"
    }
}
```

**说明**: 
- `result.win_array`: 中奖的投注类型ID数组
- `info`: 骰子点数信息
- `pai_flash`: 需要闪烁高亮的投注区域
- `money`: 用户本局输赢金额 (正数=赢钱，负数=输钱)

#### 3.2.3 洗牌状态推送 (code: 207)
**服务器 → 客户端**
```json
{
    "code": 207,
    "msg": "洗牌中！",
    "data": {
        "id": 1,
        "lu_zhu_name": "A桌",
        "num_pu": 1,
        "num_xue": 4
    }
}
```

#### 3.2.4 用户下注推送 (code: 209)
**服务器 → 客户端**
```json
{
    "code": 209,
    "msg": "当前投注",
    "data": {
        "money_balance": 8700,
        "money_spend": 300
    }
}
```

#### 3.2.5 语音消息推送 (code: 205)
**客户端 → 服务器**
```json
{
    "code": 205,
    "user_id": "12345_",
    "msg": "语音消息内容",
    "data": {}
}
```

### 3.3 心跳机制

#### 3.3.1 客户端心跳
```
ping
```

#### 3.3.2 服务器响应
```
pong
```

**说明**: 建议每30秒发送一次心跳包维持连接

---

## 4. 数据结构定义

### 4.1 骰宝投注类型映射

| 投注ID | 投注类型 | 中文名称 | 赔率示例 |
|--------|----------|----------|----------|
| 1-6 | single_single_1~6 | 单骰(1-6) | 1:1 |
| 7-12 | pair_pair_1~6 | 对子(1-6) | 1:10 |
| 13-18 | triple_triple_1~6 | 三同(1-6) | 1:180 |
| 19 | triple_any_triple | 任意三同 | 1:30 |
| 20 | basic_small | 小(4-10) | 1:1 |
| 21 | basic_big | 大(11-17) | 1:1 |
| 22 | basic_odd | 单 | 1:1 |
| 23 | basic_even | 双 | 1:1 |
| 24-37 | total_total_4~17 | 总和(4-17) | 变动 |
| 38-52 | combo_combo_* | 组合投注 | 1:6 |

### 4.2 台桌状态枚举

#### 4.2.1 台桌状态 (status)
- `1`: 正常运营
- `2`: 暂停

#### 4.2.2 运行状态 (run_status)
- `0`: 暂停
- `1`: 投注中
- `2`: 开牌中
- `3`: 洗牌中

#### 4.2.3 洗牌状态 (wash_status)  
- `0`: 正常
- `1`: 洗牌中

### 4.3 游戏类型枚举
- `9`: 骰宝

### 4.4 用户免佣状态
- `0`: 收取佣金
- `1`: 免佣模式

### 4.5 投注记录状态
- `1`: 未结算
- `2`: 已结算

---

## 5. 错误码说明

### 5.1 HTTP 状态码
- `200`: 成功
- `500`: 系统错误

### 5.2 业务错误码

| 错误码 | 错误信息 | 说明 |
|--------|----------|------|
| 0 | 操作失败 | 通用失败 |
| 200 | ok！ | 操作成功 |

### 5.3 常见错误信息

#### 5.3.1 投注相关错误
- `amount not selected`: 金额未选
- `table not selected`: 台桌未选择  
- `game not selected`: 游戏未选择
- `table does not exist`: 台桌不存在
- `table stop`: 台桌停止
- `opening card`: 开牌中,暂停投注
- `your balance insufficient`: 余额不足
- `bet failed`: 下注失败
- `limit red minimum least`: 限红最少下注
- `limit red maximum most`: 限红最多下注

#### 5.3.2 参数错误
- `parameter error`: 参数错误
- `please fill in the correct odds id`: 请填写正确的赔率ID
- `token错误`: 用户token无效

#### 5.3.3 系统错误
- `network connection error`: 网络连接错误
- `upload failed`: 上传失败

---

## 6. 接口调用示例

### 6.1 完整下注流程示例

```javascript
// 1. 建立WebSocket连接
const ws = new WebSocket('ws://域名:2009');

ws.onopen = function() {
    // 连接建立后发送用户信息
    ws.send(JSON.stringify({
        "user_id": "12345",
        "table_id": 1,
        "game_type": 9
    }));
};

ws.onmessage = function(event) {
    const data = JSON.parse(event.data);
    
    switch(data.code) {
        case 200:
            if (data.data.table_run_info) {
                // 台桌状态更新
                updateTableStatus(data.data.table_run_info);
            } else if (data.data.result_info) {
                // 开牌结果
                showGameResult(data.data.result_info);
            }
            break;
        case 209:
            // 下注成功推送
            updateUserBalance(data.data);
            break;
    }
};

// 2. 下注操作
function placeBet() {
    fetch('/sicbo/bet/order', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'x-csrf-token': userToken
        },
        body: JSON.stringify({
            table_id: 1,
            game_type: 9,
            is_exempt: 0,
            bet: [
                { money: 100, rate_id: 20 }, // 压小
                { money: 50, rate_id: 23 }   // 压双
            ]
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.code === 200) {
            console.log('下注成功', data.data);
        } else {
            console.error('下注失败', data.message);
        }
    });
}

// 3. 心跳维持
setInterval(() => {
    if (ws.readyState === WebSocket.OPEN) {
        ws.send('ping');
    }
}, 30000);
```

### 6.2 荷官开牌示例

```javascript
// 荷官开牌操作
function dealCards() {
    fetch('/sicbo/get_table/post_data', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json'
        },
        body: JSON.stringify({
            gameType: 9,
            tableId: 1,
            xueNumber: 3,
            puNumber: 15,
            result: 1,
            ext: 0,
            pai_result: {
                "1": "3",  // 第一个骰子: 3点
                "2": "4",  // 第二个骰子: 4点  
                "3": "2"   // 第三个骰子: 2点
            }
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.code === 200) {
            console.log('开牌成功');
        }
    });
}
```

---

## 7. 注意事项

1. **安全性**:
   - 所有用户操作需要有效的 `x-csrf-token`
   - WebSocket连接需要验证用户身份
   - 敏感操作需要权限校验

2. **性能优化**:
   - WebSocket消息每秒推送，注意处理频率
   - 大量用户时注意服务器负载
   - 合理使用缓存机制

3. **容错处理**:
   - 网络断线重连机制
   - 数据校验和异常处理
   - 并发操作的防重复提交

4. **多语言支持**:
   - 错误信息支持多语言
   - 根据用户语言设置返回对应文本

5. **时区处理**:
   - 系统默认使用 Asia/Shanghai 时区
   - 前端显示时需要考虑用户本地时区