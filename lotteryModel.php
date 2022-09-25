 <?
    class lotteryModel
    {
        /**
         * 1. 随机得出本期乐透中奖号码
         * 2. 判定都有谁中奖中到什么程度
         * 3. 发放奖励
         * 3. 储存至数据库
         * @return 
         */
        public function lottery()
        {
            function compare($a, $b)
            {
                if ($a === $b) {
                    return 0;
                }
                return ($a > $b) ? 1 : -1;
            }
            function emoji_calc($array)
            {
                $num = [0, 1, 2, 3, 4, 5, 6, 7, 8];
                $slot = ['🍟' => 0, '🍕' => 0, '🥑' => 0, '🍭' => 0, '🍺' => 0, '🍉' => 0, '🥐' => 0, '🍙' => 0, '🍦' => 0];
                $count = array_count_values($array);
                return array_combine($num, array_merge($slot, $count));
            }

            /* start 定义 */
            $rows = 4;     //老虎机排数
            $pool = $this->calc_amount('lottery');  //奖池总数
            $p0 = 200;          //特等奖最低限度
            $p0_rate = 0.9;     //特等奖资金池比率
            $p1 = 100;          //一等奖
            $p1_rate = 0.5;     //一等奖资金池比率
            $p2 = 8;
            $p3 = 4;

            $lottery = array();
            $winner = array();
            $arr = array();
            $arr['none'] = 0;
            $arr['once'] = 0;
            $arr['twice'] = 0;
            $arr['thirdTimes'] = 0;
            $arr['fourTimes'] = 0;
            $arr['fullHouse'] = 0;
            $lotteryStr = "";
            $lotteryArray = array();
            $slot = [0, 0, 0, 0, 0, 0, 0, 0, 0];
            $emoji = ['🍟', '🍕', '🥑', '🍭', '🍺', '🍉', '🥐', '🍙', '🍦'];
            /* end 定义 */

            for ($i = 1; $i <= 4; $i++) {
                $p = random_int(0, 8);
                $slot[$p] += 1;
                $lotteryStr .= $emoji[$p];
                $lotteryArray[$i - 1] = $emoji[$p];
            }
            $lotteryCalc = emoji_calc($lotteryArray);
            for ($i = 1; $i <= 4; $i++) {
                $lottery["num" . $i] = $lotteryArray[$i - 1];                //制造lottery数组用于最后加入数据库
            }

            /* 获取全部彩票 */
            $sql = 'select * from lottery_tickets';
            $all = $this->db->get_all($sql);

            foreach ($all as $one) {
                $ticketArray = array();                                      //删除$one数组中的用户id等
                for ($c = 0; $c < $rows; $c++) {
                    $ticketArray['num' . ($c + 1)] = $one['num' . ($c + 1)];
                }
                $ticketStr = implode("", $ticketArray);                      //获取字符串如"🍟🍟🍟🍟"
                $ticketCalc = emoji_calc($ticketArray);                      //获取calc   

                if ($ticketStr === $lotteryStr) {                            //特等奖！
                    $block = array('u' => $one['uid'], 'c' => $ticketStr, 'p' => 0);
                    array_push($winner, $block);
                    $arr['fullHouse']++;
                } else {                                                     //其他奖
                    $time = 0;
                    for ($i = 0; $i < 9; $i++) {
                        if ($ticketCalc[$i] <= $lotteryCalc[$i]) {
                            $time += $ticketCalc[$i];
                        } else {
                            $time += $lotteryCalc[$i];
                        }
                    }
                }
                switch ($time) {
                    case 0:      //无奖       
                        $arr['none']++;
                        break;
                    case 1:     //无奖
                        $arr['once']++;
                        break;
                    case 2:     //三等奖 
                        $block = array('u' => $one['uid'], 'c' => $ticketStr, 'p' => 3);
                        array_push($winner, $block);
                        $arr['twice']++;
                        break;
                    case 3:     //二等奖
                        $block = array('u' => $one['uid'], 'c' => $ticketStr, 'p' => 2);
                        array_push($winner, $block);
                        $arr['thirdTimes']++;
                        break;
                    case 4:     //一等奖
                        $block = array('u' => $one['uid'], 'c' => $ticketStr, 'p' => 1);
                        array_push($winner, $block);
                        $arr['fourTimes']++;
                        break;
                }
            }


            /* 汇款开始 */

            $temp_p0 = array();     //特等奖临时数组，缓冲用
            $temp_p1 = array();     //一等奖临时数组，缓冲用
            foreach ($winner as $s) {   //汇款不会冲突，因为小奖由fandao补贴
                switch ($s['p']) {
                    case 0:             //特等奖 200
                        array_push($temp_p0, $s['u']);
                        break;
                    case 1:             //一等奖 100
                        array_push($temp_p1, $s['u']);
                        break;
                    case 2:             //二等奖 3                     
                        $this->lottery_issueTo($s['u'], $p2, "lottery@2");
                        break;
                    case 3:             //安慰奖 1               
                        $this->lottery_issueTo($s['u'], $p3, "lottery@3");
                        break;
                }
            }
            /* 汇款给特等奖 */
            $prize_full = ceil($p0_rate * $pool);
            $prize_full_limit = $arr['fullHouse'] * $p0;
            $prize_full_each = ceil(($p0_rate * $pool / $arr['fullHouse'])) >= $p0 ?: $p0;
            if ($prize_full < $prize_full_limit) {
                $need = $prize_full_limit - $prize_full;
                $this->issueTo(115, $need, "lottery");              //计算出该奖总共要给出的金额，少了的数额由fandao支付          
            }
            foreach ($temp_p0 as $t) {
                $this->lottery_issueTo(
                    $t['u'],
                    $prize_full_each,
                    "lottery@0"
                );
            }

            $pool = $this->calc_amount('lottery');                  //更新pool数额

            /* 汇款给一等奖 */
            $prize_full = ceil($p1_rate * $pool);
            $prize_full_limit = $arr['fourTimes'] * $p1;
            $prize_full_each = ceil(($p1_rate * $pool / $arr['fourTimes'])) >= $p1 ?: $p1;
            if ($prize_full < $prize_full_limit) {
                $need = $prize_full_limit - $prize_full;
                $this->issueTo(115, $need, "lottery");              //计算出该奖总共要给出的金额，少了的数额由fandao支付          
            }
            foreach ($temp_p0 as $t) {
                $this->lottery_issueTo(
                    $t['u'],
                    $prize_full_each,
                    "lottery@1"
                );
            }
            /* 汇款结束 */
            $lottery['winner'] = serialize($winner);
            /* 统计 */
            $rate[0] = 100 * $arr['none'] / array_sum($arr) . '%';
            $rate[1] = 100 * $arr['once'] / array_sum($arr) . '%';
            $rate[2] = 100 * $arr['twice'] / array_sum($arr) . '%';
            $rate[3] = 100 *  $arr['thirdTimes'] / array_sum($arr) . '%';
            $rate[4] = 100 *  $arr['fourTimes'] / array_sum($arr) . '%';
            $rate['fullHouse'] = 100 *   $arr['fullHouse'] / array_sum($arr) . '%';
            $lottery['summary'] = implode("|", $rate);
            $lottery['time'] = time();
            $lottery['count'] = $this->count_tickets();
            return $this->db->auto_execute($this->lot, $lottery); //将数组写入数据库
        }

        /* 下略 */
        protected $lot = "lottery";
        /**
         * 通过日志链计算钱包数额	
         * @param string $wid	
         * @return int
         **/
        public function calc_amount($wid, $gid = 1)
        {
        }
        /**
         * 通过基金会发钱给用户
         * @param int $uid
         * @param int $amount
         * @param string $note	
         * @return array $checkCount
         **/
        public function issueTo($uid, $amount, $note = "")
        {
        }
        /**
         * 通过彩票基金会发钱给用户
         * @param int $uid
         * @param int $amount
         * @param string $note	
         * @return array $checkCount
         **/
        public function lottery_issueTo($uid, $amount, $note)
        {
        }
        /**
         * 获取本期彩票数量    
         * @return int
         */
        public function count_tickets()
        {
        }
    }
