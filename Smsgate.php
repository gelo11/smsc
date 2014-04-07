<?php

namespace App\Controller;

class Smsgate extends \App\SMS
{

    public $gw_user = '';
    public $gw_password = '';
    public $count_phones;
    public $gw_success = '';
    public $gw_error = '';
    public $validate_errors = array();
    protected $sms_qty = 1;
    protected $phones_arr = array();
    protected $phones_qty = 1;
    protected $db_ids = array();

    public function before()
    {
        $this->view = $this->pixie->view('gateway/main');
    }

    public function action_balance()
    {
        if ($this->request->method == 'GET') {

            $validate = $this->pixie->validate->get($this->request->get());

            $validate->field('username')->rules('filled', 'email');

            $validate->field('password')->rule('filled')->rule('matches', '/^[\pL\pN\.\,\[\]\*\@\_\-\(\)\+]++$/uD');

            $validate->valid();

            $validate_errors = $validate->errors();

            if (isset($validate_errors['username']) || isset($validate_errors['password'])) {
                $this->gw_error = 'ERROR101';
            } else {
                $username = $this->request->get('username');
                $password = $this->request->get('password');
// user auth
                $logged = $this->pixie->auth
                        ->provider('password')
                        ->login($username, $password);

                if (!$logged) {
                    $this->gw_error = 'ERROR101';
                } else {
                    $this->gw_success = number_format($this->pixie->auth->user()->sum, 2);
                }
            }
        } else {
            $this->gw_error = 'ERROR101';
        }

        $this->view->gw_error = $this->gw_error;

        $this->view->gw_success = $this->gw_success;
        $this->view->subview = 'send';
    }

    public function action_cost()
    {
        if ($this->request->method == 'POST') {

            $validate = $this->pixie->validate->get($this->request->post());

            $validate->field('username')->rules('filled', 'email');
            $validate->field('password')->rule('filled')->rule('matches', '/^[\pL\pN\.\,\[\]\*\@\_\-\(\)\+]++$/uD');
            $validate->field('phone')->rules('filled', 'numeric_phone');
            $validate->field('charset')->rule('numeric')->rule('max_length', 1);

            $validate->valid();

            $validate_errors = $validate->errors();

            if (isset($validate_errors['username']) || isset($validate_errors['password'])) {
                $this->gw_error = 'ERROR101';
            }
            if (isset($validate_errors['phone'])) {
                $this->gw_error = 'ERROR106';
            }
            if (empty($this->gw_error)) {
                $username = $this->request->post('username');
                $password = $this->request->post('password');

                $phone = $this->request->post('phone');
                $msgtext = urldecode($this->request->post('msgtext', ''));
                $charset = $this->request->post('charset', 0);
                $charset = $charset == 0 ? 0 : 6;
                $translit = $this->request->post('translit', false);
// user auth
                $logged = $this->pixie->auth
                        ->provider('password')
                        ->login($username, $password);

                if (!$logged) {
                    $this->gw_error = 'ERROR101';
                } else {
                    if (strpos($phone, ',')) {
                        $this->phones_arr = explode(',', $phone);
                        $this->phones_qty = count($this->phones_arr);
                    } else {
                        $this->phones_arr[0] = $phone;
                    }

                    if ($this->phones_qty < 5001) {

                        if ($translit != false || $charset == 0) {
                            $msgtext = $this->rus2translit($msgtext);
                            $msgtext = preg_replace('~[^-A-Za-z0-9\s_\,\.\/\?\!\$\@\'\"\+\:\;\(\)\*\&\%\#]+~u', '', $msgtext);
                            $charset = 0;
                        }

                        $this->sms_qty = $this->msg_lenght($msgtext, $charset);

                        $result = $this->cost_by_phone($phone, $this->phones_qty);

                        if (strpos($result->str, ';') === false) {
                            $arr[0] = explode('|', $result->str);
                        } else {
                            $arr = explode(';', $result->str);
                            foreach ($arr as $key => $var) {
                                $arr[$key] = explode('|', $var);
                            }
                        }

                        $this->set_cost_view($arr);

//                    $this->view->errors = mb_strlen($msgtext);
//                    $this->gw_success = number_format($this->pixie->auth->user()->sum, 2);
                    } else {
                        $this->gw_error = 'ERROR999';
                    }
                }
            }
        } else {
            $this->gw_error = 'ERROR101';
        }

        $this->view->gw_error = $this->gw_error;

        $this->view->gw_success = $this->gw_success;

        $this->view->subview = 'send';
        if ($this->request->server('REMOTE_ADDR') == '212.112.103.198') {
            $this->view->subview = 'send_test';
        } else {
            $this->view->subview = 'send';
        }
    }

    public function action_send()
    {
        if ($this->request->method == 'POST') {
            $validate = $this->pixie->validate->get($this->request->post());

            $validate->field('username')->rules('filled', 'email');

            $validate->field('password')->rule('filled')->rule('matches', '/^[\pL\pN\.\,\[\]\*\@\_\-\(\)\+]++$/uD');

            $validate->field('originator')->rule('filled')->rule('alpha_numeric')->rule('max_length', 11);

            $validate->field('phone')->rules('filled', 'numeric_phone');

            $validate->field('msgtype')->rule('alpha');

            $validate->field('charset')->rule('numeric')->rule('max_length', 1);

            $validate->field('showCOST')->rule('numeric');

            $validate->field('showOPERATORID')->rule('numeric');

            $validate->valid();

            $validate_errors = $validate->errors();

            $this->validate_errors($validate_errors);

            if ($this->gw_error == '') {
                $username = $this->request->post('username');
                $password = $this->request->post('password');
                $originator = $this->request->post('originator');
                $phone = $this->request->post('phone');
                $msgtext = urldecode($this->request->post('msgtext', ''));
                $msgtype = $this->request->post('msgtype', '');
                $msgtype = empty($msgtype) ? '' : 'F';
                $charset = $this->request->post('charset', 0);
                $charset = $charset == 0 ? 0 : 6;
                $showCOST = $this->request->post('showCOST', 0);
                $showDLR = $this->request->post('showDLR', 0);
                $showOPERATORID = $this->request->post('showOPERATORID', 0);
                $translit = $this->request->post('translit', false);
                $futuredate = $this->request->post('futuredate', '');
                $futuredate = empty($futuredate) ? '' : date("Y-m-d h:i:s", (strtotime(urldecode($futuredate)) + 6 * 60 * 60));

                if ($translit != false || $charset == 0) {
                    $msgtext = $this->rus2translit($msgtext);
                    $msgtext = preg_replace('~[^-A-Za-z0-9\s_\,\.\/\?\!\$\@\'\"\+\:\;\(\)\*\&\%\#]+~u', '', $msgtext);
                    $charset = 0;
                }

// count phones qty
                if (strpos($phone, ',')) {
                    $this->phones_arr = explode(',', $phone);
                    $this->phones_qty = count($this->phones_arr);
                } else {
                    $this->phones_arr[0] = $phone;
                }

                if ($this->phones_qty < 5001) {

                    $this->sms_qty = $this->msg_lenght($msgtext, $charset);

// user auth
                    $logged = $this->pixie->auth
                            ->provider('password')
                            ->login($username, $password);

                    if (!$logged) {
                        $this->gw_error = 'ERROR101';
                    } else {
                        $user = $this->pixie->auth->user();

                        $sql = "CALL `sum_cost_by_phone`('$phone', '$this->phones_qty');";

                        $result_sum_cost = $this->pixie->db->get()->execute($sql)->current();

                        if ($user->sum > 5 && $user->sum > ((float) $result_sum_cost->total_cost * $this->sms_qty)) {
                            if ($user->status == 1) {

                                for ($i = 0; $i < $this->phones_qty; $i++) {
                                    $this->pixie->db->query('insert')->table('sms_sms')
                                            ->data(array(
                                                'user_id' => $user->id,
                                                'originator' => $originator,
                                                'phone' => $this->phones_arr[$i],
                                                'message' => $msgtext,
                                                'sms_charset' => $charset,
                                                'sms_datetime' => $futuredate,
                                                'created' => date("Y-m-d h:i:s"),
                                                'status' => 0,
                                                'show_dlr' => $showDLR,
                                                'show_cost' => $showCOST,
                                                'show_operator' => $showOPERATORID,
                                                'msgtype' => $msgtype,
                                                'translit' => $translit
                                            ))
                                            ->execute();
                                    $this->db_ids[$i] = $this->pixie->db->insert_id();
                                }

                                if (count($this->db_ids) > 0) {
                                    $this->originator = $originator;
                                    $this->phone = $phone;
                                    $this->msgtext = $charset == 6 ? bin2hex(iconv("utf-8", "UCS-2", $msgtext)) : urlencode($msgtext);
                                    $this->msgtype = $msgtype;
                                    $this->charset = $charset;
                                    $this->futuredate = urlencode($futuredate);

                                    $this->showCOST = 1;
                                    $this->showOPERATORID = 1;
                                    $this->showDLR = 1;

                                    $sms_result = $this->send();

                                    $result = $this->sms_result($sms_result);

//                                $this->view->errors = array_merge($result, array('db_ids' => $this->db_ids, 'sms_result' => $sms_result));

                                    $this->set_send_view($result);
                                }
                            } else {
                                $this->gw_error = 'ERROR101';
                            }
                        } else {
                            $this->gw_error = 'ERROR102';
                        }
                    }
                } else {
                    $this->gw_error = 'ERROR999';
                }
            }
        } else {
            $this->gw_error = 'ERROR101';
        }

        $this->view->gw_error = $this->gw_error;

        $this->view->gw_success = $this->gw_success;

        $this->view->subview = 'send';
    }

    protected function validate_errors($arr)
    {
        if (count($arr) > 0) {
            foreach ($arr as $kvr => $vr) {
                if ($kvr == "username") {
                    $this->gw_error = 'ERROR101';
                    break;
                }
                if ($kvr == "password") {
                    $this->gw_error = 'ERROR101';
                    break;
                }
                if ($kvr == "originator") {
                    $this->gw_error = 'ERROR109';
                    break;
                }
                if ($kvr == "phone") {
                    $this->gw_error = 'ERROR103';
                    break;
                }
            }
        }
    }

    protected function sms_result($sms_result)
    {
        $gw_dlr_id = 0;
        $cost = 0;
        $operator_id = 0;
        $error = '';
        $gw_success = '';
        $result_arr = array();
        $answer = array();

        $sms_result = trim($sms_result);

        $result_arr = explode('ok', strtolower($sms_result));
        array_shift($result_arr);

        $result_qty = count($result_arr);

        for ($i = 0; $i < $result_qty; $i++) {

            if (stripos($result_arr[$i], 'error') === false) {
                $result_arr[$i] = trim($result_arr[$i]);
                $gw_success = $result_arr[$i];
                if (stripos($result_arr[$i], '|') !== false) {
                    $bufer = explode('|', $result_arr[$i]);
                    $gw_dlr_id = intval($bufer[0]);
                    $cost = floatval($bufer[1]);
                    $operator_id = intval($bufer[2]);
                    $our_cost_som = $this->get_our_cost_som($operator_id, $cost);
                } else {
                    $gw_dlr_id = str_replace('ok', '', strtolower($result_arr[$i]));
                }

// change user balance
                $this->pixie->db->query('update')->table('sms_users')
                        ->data(array('sum' => $this->pixie->db->expr('sum - ' . $our_cost_som)))
                        ->where('id', $this->pixie->auth->user()->id)->execute();
            } else {
                $error = $result_arr[$i];
            }
            $this->pixie->db->query('update')->table('sms_sms')
                    ->data(array(
                        'gw_success' => $result_arr[$i],
                        'gw_dlr_id' => $gw_dlr_id,
                        'cost_euro' => $cost,
                        'our_cost_som' => $our_cost_som,
                        'operator_id' => $operator_id,
                        'gw_error' => $error,
                        'updated' => date("Y-m-d h:i:s")
                    ))
                    ->where('id', $this->db_ids[$i])
                    ->execute();
            $answer[$i] = array('success' => array('dlr_id' => $gw_dlr_id, 'cost_euro' => $cost, 'our_cost_som' => $our_cost_som, 'operator_id' => $operator_id), 'error' => $error);
        }

        return $answer;
    }

    protected function get_our_cost_som($operator_id, $cost_euro, $sms_qty = 1)
    {
        $sms_qty = $this->sms_qty;
        $cost_arr = $this->pixie->db->query('select')->table('sms_cost')->where('operator_id', $operator_id)->limit(1)->execute()->current();
        if (!$cost_arr)
            return $this->calculate_our_cost_som($cost_euro, $sms_qty);
        if ($cost_arr->cost_euro == $cost_euro && $sms_qty == 1)
            return $cost_arr->our_cost_som;
        if ($cost_arr->cost_euro == ($cost_euro / $sms_qty))
            return $cost_arr->our_cost_som * $sms_qty;
        return $this->calculate_our_cost_som($cost_euro, $sms_qty);
    }

    protected function calculate_our_cost_som($cost_euro, $sms_qty)
    {
        $euro_rate = $this->exchange_rate();
        $cost_som = $this->round_up(($cost_euro * $euro_rate), 1) + 0.5;
        $cost_som = $cost_som < 1 ? 1 : $cost_som;
        return $cost_som * $sms_qty;
    }

    protected function exchange_rate($isocode = 'EUR')
    {
        $result = $this->pixie->db->query('select')->fields('value')->table('sms_exchange')->where('isocode', $isocode)->limit(1)->execute()->current();
        $this->exchange_rate = (isset($result->value) ? (float) $result->value : false);
        return $this->exchange_rate;
    }

    protected function set_send_view($arr)
    {
        $str = '';
        if (count($arr) > 0) {
            foreach ($arr as $v) {
                $str .= empty($v['error']) ? 'OK' . $v['success']['dlr_id'] . '|' . $v['success']['our_cost_som'] . '|' . $v['success']['operator_id'] : $v['error'];
                $str .= ' ';
            }
        }
        return $this->gw_success = trim($str);
    }

    protected function set_cost_view($arr)
    {
        $str = '';
        $total = 0;
        if (count($arr) == 0)
            return;
        foreach ($arr as $k => $v) {
            if (isset($this->phones_arr[($v[0] - 1)])) {
                $str .= $this->phones_arr[($v[0] - 1)];
                if (isset($v[7])) {
                    $cost = (float) $v[7] * $this->sms_qty;
                    $str .= '|' . $v[1] . '|' . $v[2] . '|' . $v[3] . '|' . $v[4] . '|' . $v[5] . '|' . $v[6] . '|' . $cost;
                    $total += $cost;
                }
                $str .= ' ';
            }
        }
        $str .= 'total|' . $total;
        return $this->gw_success = trim($str);
    }

    protected function round_up($value, $places = 0)
    {
        if ($places < 0)
            $places = 0;
        $mult = pow(10, $places);
        return ceil($value * $mult) / $mult;
    }

}
