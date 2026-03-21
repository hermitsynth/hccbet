<?php
session_start();
error_reporting(0);
ini_set('display_errors', 1);

header('Content-Type: application/json');

ob_start();

register_shutdown_function(function () {
    $error = error_get_last();

    if (!$error) {
        return;
    }

    $fatalTypes = [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR];
    if (!in_array($error['type'], $fatalTypes, true)) {
        return;
    }

    $message = sprintf(
        '[FATAL] %s in %s on line %d',
        $error['message'],
        $error['file'],
        $error['line']
    );

    if (defined('HCCBET_LOG_FILE')) {
        file_put_contents(HCCBET_LOG_FILE, $message . PHP_EOL, FILE_APPEND);
    }

    if (ob_get_length()) {
        ob_clean();
    }

    if (!headers_sent()) {
        header('Content-Type: application/json');
        http_response_code(500);
    }

    if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
        echo json_encode([
            'error' => 'Backend fatal error',
            'details' => $message,
            'active' => false
        ]);
    } else {
        echo 'Backend fatal error';
    }
});

define('HCCBET_PRIVATE_DIR', '/var/www/hccbet/private');
define('HCCBET_USERS_FILE', HCCBET_PRIVATE_DIR . '/users.json');
define('HCCBET_TRANSACTIONS_FILE', HCCBET_PRIVATE_DIR . '/transactions.json');
define('HCCBET_BACKUP_FILE', HCCBET_PRIVATE_DIR . '/backup.json');
define('HCCBET_LOG_FILE', HCCBET_PRIVATE_DIR . '/debug.log');
define('HCCBET_CURL_LOG_FILE', HCCBET_PRIVATE_DIR . '/curl_debug.log');
define('HCCBET_ENV_FILE', HCCBET_PRIVATE_DIR . '/.env');

function readJsonFileSafe($filename, $default = []) {
    if (!file_exists($filename)) {
        return $default;
    }
    $content = file_get_contents($filename);
    if ($content === false || $content === '') {
        return $default;
    }
    $decoded = json_decode($content, true);
    return is_array($decoded) ? $decoded : $default;
}

// ─── Load .env ────────────────────────────────────────────────────────────────
$env = parse_ini_file(HCCBET_ENV_FILE);
if ($env === false) {
    http_response_code(500);
    echo json_encode(["error" => "Failed to load environment configuration."]);
    exit;
}

$keys       = explode(',', $env['ENCRYPT_KEYS']);
$ivs        = explode(',', $env['ENCRYPT_IVS']);
$walletIn   = $env['WALLET_IN'];
$walletOut  = $env['WALLET_OUT'];
$bearerIn   = $env['BEARER_IN'];
$bearerOut  = $env['BEARER_OUT'];

// ─── Security helpers ─────────────────────────────────────────────────────────
function userdecrypt($sec, $usr) {
    global $keys, $ivs;
    foreach ($keys as $key) {
        foreach ($ivs as $iv) {
            $decrypt = openssl_decrypt($sec, "AES-128-CTR", $key, 0, $iv);
            if ($decrypt === $usr) return true;
        }
    }
    return false;
}

function vdecrypt($sec, $usr) {
    global $keys, $ivs;
    foreach ($keys as $key) {
        foreach ($ivs as $iv) {
            $decrypt = openssl_decrypt($sec, "AES-128-CTR", $key, 0, $iv);
            if ($decrypt !== false) {
                $dc2 = explode(":", $decrypt);
                if (count($dc2) === 2 && $dc2[0] === $usr && $dc2[1] === date("d.H.i")) return true;
            }
        }
    }
    return false;
}

// ─── User / balance helpers ───────────────────────────────────────────────────
function id($inputString) {
    $existingIds = readJsonFileSafe(HCCBET_USERS_FILE, []);
    $hash = abs(crc32($inputString));
    $uniqueNumber = str_pad($hash % 10000000, 7, '0', STR_PAD_LEFT);
    if (array_key_exists($uniqueNumber, $existingIds)) return $uniqueNumber;
    $uniqueString = '';
    $characters = '0123456789ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz';
    for ($i = 0; $i < 7; $i++) {
        $uniqueString .= $characters[$hash % strlen($characters)];
        $hash = intval($hash / strlen($characters));
    }
    return $uniqueString;
}

function getBalance($accountId) {
    $data = readJsonFileSafe(HCCBET_USERS_FILE, []);
    return $data[$accountId] ?? 0;
}

function updateBalance($accountId, $delta) {
    $filename = HCCBET_USERS_FILE;
    $balances = readJsonFileSafe($filename, []);
    if (isset($balances[$accountId])) {
        $balances[$accountId] += $delta;
    } else {
        $balances[$accountId] = $delta;
    }
    file_put_contents($filename, json_encode($balances, JSON_PRETTY_PRINT));
}

function addTransaction($t_id, $usr, $amount) {
    $filename = HCCBET_TRANSACTIONS_FILE;
    $transactions = readJsonFileSafe($filename, []);
    if (isset($transactions[$t_id])) return;
    $transactions[$t_id] = [
        'hash'   => hash("sha256", strval($usr . date("H:i:s"))),
        'amount' => $amount
    ];
    file_put_contents($filename, json_encode($transactions, JSON_PRETTY_PRINT));
}

function transfertransaction($amount, $type) {
    global $walletIn, $walletOut, $bearerIn, $bearerOut;

    $url = 'https://hashcashfaucet.com/api/transfer';

    if ($type === "in") {
        $requestBody = json_encode(["to_address" => $walletIn, "amount" => $amount]);
        $bearer = $bearerIn;
    } else {
        $requestBody = json_encode(["to_address" => $walletOut, "amount" => $amount]);
        $bearer = $bearerOut;
    }

    $curl = curl_init($url);
    curl_setopt($curl, CURLOPT_HTTPHEADER, [
        'Content-Type: application/json',
        "Authorization: Bearer $bearer"
    ]);
    curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($curl, CURLOPT_POST, true);
    curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBody);
    curl_setopt($curl, CURLOPT_TIMEOUT, 20);

    $response = curl_exec($curl);
    $errno = curl_errno($curl);
    $error = curl_error($curl);
    $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);

    $logLine = json_encode([
        'type' => $type,
        'amount' => $amount,
        'status' => $statusCode,
        'curl_errno' => $errno,
        'curl_error' => $error,
        'response' => $response
    ]) . PHP_EOL;

    file_put_contents(HCCBET_CURL_LOG_FILE, $logLine, FILE_APPEND);

    curl_close($curl);
}

// ─── Blackjack card helpers ───────────────────────────────────────────────────
function bj_createDeck() {
    $suits  = ['Hearts', 'Diamonds', 'Clubs', 'Spades'];
    $values = ['A','2','3','4','5','6','7','8','9','10','J','Q','K'];
    $deck   = [];
    foreach ($suits  as $s)
    foreach ($values as $v)
        $deck[] = ['suit' => $s, 'value' => $v];
    shuffle($deck);
    return $deck;
}

function bj_cardValue($v) {
    if (in_array($v, ['J','Q','K'])) return 10;
    if ($v === 'A')                  return 1;
    return intval($v);
}

function bj_score($hand) {
    return array_sum(array_map(fn($c) => bj_cardValue($c['value']), $hand));
}

function bj_dealerVisible($hand) {
    return bj_cardValue($hand[0]['value']);
}

// ─── GET – surface-level session check ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    if (isset($_SESSION["id"], $_SESSION["security"], $_SESSION["addr"])) {
        if (userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            $balances = readJsonFileSafe(HCCBET_USERS_FILE, []);
            echo "User: " . $_SESSION["id"] . " Balance: " . ($balances[$_SESSION["id"]] ?? 0);
        } else {
            echo "Failed Security Check";
        }
    } else {
        echo "Not Logged In";
    }
    exit;
}

// ─── POST – all game & account actions ───────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $data = json_decode(file_get_contents("php://input"), true);

    if (!is_array($data)) {
        $data = [];
    }

    if (isset($data["get_transactions"]) && $data["get_transactions"] === "active") {
        echo json_encode(readJsonFileSafe(HCCBET_TRANSACTIONS_FILE, []));
        exit;
    }

    if (isset($data["get_users"]) && $data["get_users"] === "active") {
        echo json_encode(readJsonFileSafe(HCCBET_USERS_FILE, []));
        exit;
    }

    // ── SIGNUP ───────────────────────────────────────────────────────────────
    if (isset($data["address"]) && (($data["connection"] ?? null) === "active")) {
        $usercheck       = file_get_contents("https://hashcashfaucet.com/api/account?account_id=" . $data["address"]);
        $usercheckresult = json_decode($usercheck);
        if ($data["address"] == $walletOut || $data["address"] == $walletIn) {
            echo json_encode(["status" => "Ohnononono you cant login to that account silly"]);
            exit;
        }
        if (isset($usercheckresult->account_id)) {
            $_SESSION["id"] = id($data["address"]);
            if (isset($_SESSION["addr"])) {
                $result = userdecrypt($_SESSION["security"], $data["address"]);
                if (!$result) {
                    $security = openssl_encrypt($data["address"], "AES-128-CTR",
                        $keys[rand(0, count($keys)-1)], 0, $ivs[rand(0, count($ivs)-1)]);
                    $_SESSION["security"] = $security;
                    $_SESSION["addr"]     = $data["address"];
                    echo json_encode(["status" => "User account changed to [" . $_SESSION["id"] . "] successfully!"]);
                } else {
                    echo json_encode(["status" => "Security Check Passed, but this address is already in use."]);
                }
            } else {
                $security = openssl_encrypt($data["address"], "AES-128-CTR",
                    $keys[rand(0, count($keys)-1)], 0, $ivs[rand(0, count($ivs)-1)]);
                $_SESSION["security"] = $security;
                $_SESSION["addr"]     = $data["address"];
                echo json_encode(["status" => "User [" . $_SESSION["id"] . "] Connected!"]);
            }
        } else {
            echo json_encode(["status" => "Not a valid HCC address."]);
        }
        exit;
    }

    // ── DEPOSIT ──────────────────────────────────────────────────────────────
    if ((($data["validate"] ?? null) === "active") && isset($_SESSION["security"], $_SESSION["addr"], $_SESSION["id"])) {
        if (userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            $cont = file_get_contents("https://hashcashfaucet.com/api/events_page?account_id=" . $_SESSION["addr"]);
            if ($cont === false) { echo json_encode(["status" => "Failed to retrieve transactions."]); exit; }
            $response = json_decode($cont, true);
            if (!isset($response['events']) || !is_array($response['events']) || empty($response['events'])) {
                echo json_encode(["status" => "No transactions found."]); exit;
            }
            $filename             = HCCBET_TRANSACTIONS_FILE;
            $existingTransactions = readJsonFileSafe($filename, []);
            $allocate = 0;
            foreach ($response['events'] as $item) {
                if ($item['type'] == 'transfer_out') {
                    $transactionId = $item["id"];
                    if (isset($existingTransactions[$transactionId])) continue;
                    if (trim($item['other']) == $walletIn) {
                        $allocate = abs($item['amount']);
                        if (!is_numeric($allocate) || $allocate <= 0) {
                            echo json_encode(["status" => "No transaction to validate."]); exit;
                        }
                        addTransaction($transactionId, $_SESSION["id"], $allocate);
                        break;
                    }
                }
            }
            if ($allocate > 0) {
                updateBalance($_SESSION["id"], $allocate);
                echo json_encode(["status" => "Success! $allocate HCC deposited to your wallet: " . $_SESSION["id"]]);
            } else {
                echo json_encode(["status" => "No valid transaction found."]);
            }
        }
        exit;
    }

    // ── FAUCET BALANCE ────────────────────────────────────────────────────────
    if (($data["faucetbalance"] ?? null) === "active") {
        $response = json_decode(file_get_contents("https://hashcashfaucet.com/api/account?account_id=" . $walletOut), true);
        echo json_encode(["status" => $response["credits"]]);
        exit;
    }

    // ── WITHDRAW ──────────────────────────────────────────────────────────────
    if ((($data["withdraw"] ?? null) === "active") && isset($_SESSION["security"], $_SESSION["addr"], $_SESSION["id"])) {
        if (userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            $balance = getBalance($_SESSION["id"]);
            if ($balance < 1) { echo json_encode(["status" => "No balance to withdraw."]); exit; }
            $requestBody = json_encode(["to_address" => $_SESSION["addr"], "amount" => $balance]);
            $curl = curl_init('https://hashcashfaucet.com/api/transfer');
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                "Authorization: Bearer $bearerOut"
            ]);
            curl_setopt($curl, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $requestBody);
            $response   = curl_exec($curl);
            $statusCode = curl_getinfo($curl, CURLINFO_HTTP_CODE);
            if (curl_errno($curl)) {
                echo json_encode(["status" => 'Error: ' . curl_error($curl)]);
            } else {
                file_put_contents(HCCBET_CURL_LOG_FILE, $response, FILE_APPEND);
                if ($statusCode === 200) {
                    $antibalance = $balance * -1;
                    updateBalance($_SESSION["id"], $antibalance);
                    addTransaction(
                        str_pad(abs(crc32($_SESSION["addr"] . date("d:H:m:i:s"))) % 10000000, 7, '0', STR_PAD_LEFT),
                        $_SESSION["id"], $antibalance
                    );
                    echo json_encode(["status" => "Successful withdrawal of $balance HCC to your wallet!"]);
                } else {
                    echo json_encode(["status" => "Withdrawal failed with status: $statusCode", "response" => $response]);
                }
            }
            curl_close($curl);
        } else {
            echo json_encode(["status" => "Security Check Failed."]);
        }
        exit;
    }

    // ── VERIFY token ──────────────────────────────────────────────────────────
    if ((($data["verify"] ?? null) === "active") && isset($_SESSION["security"], $_SESSION["addr"], $_SESSION["id"])) {
        if (userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            $verify = openssl_encrypt(
                $_SESSION["addr"] . ":" . date("d.H.i"),
                "AES-128-CTR",
                $keys[rand(0, count($keys)-1)], 0,
                $ivs[rand(0, count($ivs)-1)]
            );
            echo json_encode(["status" => $verify]);
        } else {
            echo json_encode(["status" => "Security check failed"]);
        }
        exit;
    }

    // ─────────────────────────────────────────────────────────────────────────
    // ── BLACKJACK ─────────────────────────────────────────────────────────────
    // ─────────────────────────────────────────────────────────────────────────
    if (($data["blackjack"] ?? null) === "active") {

        if (!isset($_SESSION["security"], $_SESSION["addr"], $_SESSION["id"])) {
            echo json_encode(["error" => "Not logged in.", "active" => false]); exit;
        }
        if (!userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            echo json_encode(["error" => "Security check failed.", "active" => false]); exit;
        }
        if (!isset($_SESSION["captchasolved"]) || $_SESSION["captchasolved"] !== "true") {
            echo json_encode(["error" => "Captcha failed. I see you firefly", "active" => false]); exit;
        }

        $action = $data["action"] ?? "";

        // ── DEAL ──────────────────────────────────────────────────────────────
        if ($action === "deal") {

            if (!empty($_SESSION['bj_active'])) {
                $dealer = $_SESSION['bj_dealer'];
                $player = $_SESSION['bj_player'];
                echo json_encode([
                    "player"       => $player,
                    "dealer"       => [$dealer[0], ["suit" => "Hidden", "value" => "?"]],
                    "player_score" => bj_score($player),
                    "dealer_score" => bj_dealerVisible($dealer),
                    "active"       => true,
                    "message"      => "",
                    "balance_msg"  => ""
                ]);
                exit;
            }

            $bet = intval($data["bet"] ?? 1);
            if ($bet < 1)  $bet = 1;
            if ($bet > 15) $bet = 15;

            $balance = getBalance($_SESSION["id"]);
            if ($balance < $bet) {
                echo json_encode(["error" => "Not enough balance to cover that bet.", "active" => false]); exit;
            }
            if ($balance < 1) {
                echo json_encode(["error" => "Not enough balance to play.", "active" => false]); exit;
            }

            $deck   = bj_createDeck();
            $player = [array_pop($deck), array_pop($deck)];
            $dealer = [array_pop($deck), array_pop($deck)];

            $_SESSION['bj_deck']   = $deck;
            $_SESSION['bj_player'] = $player;
            $_SESSION['bj_dealer'] = $dealer;
            $_SESSION['bj_bet']    = $bet;
            $_SESSION['bj_active'] = true;

            $playerScore = bj_score($player);
            $dealerScore = bj_score($dealer);

            if ($playerScore === 21) {
                $_SESSION['bj_active'] = false;
                $txId = rand(0, 100000000000);
                updateBalance($_SESSION["id"], $bet * 2);
                addTransaction($txId, $_SESSION["id"], $bet * 2);
                $newBal = getBalance($_SESSION["id"]);
                echo json_encode([
                    "player"       => $player,
                    "dealer"       => $dealer,
                    "player_score" => $playerScore,
                    "dealer_score" => $dealerScore,
                    "active"       => false,
                    "message"      => "Blackjack! You win!",
                    "balance_msg"  => "You won " . ($bet * 2) . " HCC! New balance: $newBal"
                ]);
                exit;
            }

            echo json_encode([
                "player"       => $player,
                "dealer"       => [$dealer[0], ["suit" => "Hidden", "value" => "?"]],
                "player_score" => $playerScore,
                "dealer_score" => bj_dealerVisible($dealer),
                "active"       => true,
                "message"      => "",
                "balance_msg"  => ""
            ]);
            exit;
        }

        // ── HIT ───────────────────────────────────────────────────────────────
        if ($action === "hit") {

            if (empty($_SESSION['bj_active'])) {
                echo json_encode(["error" => "No active game. Click Deal to start.", "active" => false]); exit;
            }

            $deck   = $_SESSION['bj_deck'];
            $player = $_SESSION['bj_player'];
            $dealer = $_SESSION['bj_dealer'];
            $bet    = $_SESSION['bj_bet'];

            $player[] = array_pop($deck);
            $_SESSION['bj_player'] = $player;
            $_SESSION['bj_deck']   = $deck;

            $playerScore = bj_score($player);

            if ($playerScore > 21) {
                $_SESSION['bj_active'] = false;
                $txId = rand(0, 100000000000);
                updateBalance($_SESSION["id"], $bet * -1);
                addTransaction($txId, $_SESSION["id"], $bet * -1);
                $newBal = getBalance($_SESSION["id"]);
                echo json_encode([
                    "player"       => $player,
                    "dealer"       => $dealer,
                    "player_score" => $playerScore,
                    "dealer_score" => bj_score($dealer),
                    "active"       => false,
                    "message"      => "Bust! You lose.",
                    "balance_msg"  => "You lost $bet HCC... New balance: $newBal"
                ]);
                exit;
            }

            echo json_encode([
                "player"       => $player,
                "dealer"       => [$dealer[0], ["suit" => "Hidden", "value" => "?"]],
                "player_score" => $playerScore,
                "dealer_score" => bj_dealerVisible($dealer),
                "active"       => true,
                "message"      => "",
                "balance_msg"  => ""
            ]);
            exit;
        }

        // ── STAND ─────────────────────────────────────────────────────────────
        if ($action === "stand") {

            if (empty($_SESSION['bj_active'])) {
                echo json_encode(["error" => "No active game. Click Deal to start.", "active" => false]); exit;
            }

            $deck   = $_SESSION['bj_deck'];
            $dealer = $_SESSION['bj_dealer'];
            $player = $_SESSION['bj_player'];
            $bet    = $_SESSION['bj_bet'];

            while (bj_score($dealer) < 17) {
                $dealer[] = array_pop($deck);
            }

            $_SESSION['bj_active'] = false;

            $playerScore = bj_score($player);
            $dealerScore = bj_score($dealer);
            $txId        = rand(0, 100000000000);

            if ($dealerScore > 21 || $playerScore > $dealerScore) {
                updateBalance($_SESSION["id"], $bet * 2);
                addTransaction($txId, $_SESSION["id"], $bet * 2);
                transfertransaction($bet * 2, "in");
                $newBal     = getBalance($_SESSION["id"]);
                $message    = "You win!";
                $balanceMsg = "You won " . ($bet * 2) . " HCC! New balance: $newBal";

            } elseif ($playerScore === $dealerScore) {
                $message    = "It's a tie! No one wins.";
                $balanceMsg = "Your bet of $bet HCC has been returned.";

            } else {
                updateBalance($_SESSION["id"], $bet * -1);
                addTransaction($txId, $_SESSION["id"], $bet * -1);
                transfertransaction($bet, "out");
                $newBal     = getBalance($_SESSION["id"]);
                $message    = "Dealer wins.";
                $balanceMsg = "You lost $bet HCC... New balance: $newBal";
            }

            echo json_encode([
                "player"       => $player,
                "dealer"       => $dealer,
                "player_score" => $playerScore,
                "dealer_score" => $dealerScore,
                "active"       => false,
                "message"      => $message,
                "balance_msg"  => $balanceMsg
            ]);
            exit;
        }

        echo json_encode(["error" => "Unknown action.", "active" => false]);
        exit;
    }
    // ─── end BLACKJACK ────────────────────────────────────────────────────────


    // ── HILO GAME ─────────────────────────────────────────────────────────────
    if (($data["hilo"] ?? null) === "active") {
        if (!isset($_SESSION["security"], $_SESSION["addr"], $_SESSION["id"])) {
            echo json_encode(["status" => "Not logged in."]);
            exit();
        }
        if (!isset($data["action"], $data["bet"], $data["rolled"], $data["verifytransaction"])) {
            echo json_encode(["status" => "Missing Hilo parameters."]);
            exit();
        }
        if (!isset($_SESSION['captchasolved']) || $_SESSION['captchasolved'] !== 'true') {
            echo json_encode(['status' => 'Captcha not solved.']);
            exit();
        }
        if (userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            if (vdecrypt($data["verifytransaction"], $_SESSION["addr"])) {
                $bet = intval($data["bet"]);
                if ($bet > 5) { $bet = 5; }
                if ($bet < 1) { $bet = 1; }
                $bal1 = getBalance($_SESSION["id"]);
                if ($bal1 < 1 || $bal1 < $bet) {
                    echo json_encode(["status" => "Balance too low! No hcc subtracted from your balance."]);
                    exit;
                }

                $txId = rand(0, 100000000000);

                if ($data["action"] == "win") {
                    updateBalance($_SESSION["id"], $bet);
                    addTransaction($txId, $_SESSION["id"], $bet);
                    transfertransaction($bet, "in");
                    $newbal = getBalance($_SESSION["id"]);
                    echo json_encode(["status" => "You won " . $bet . " HCC! New balance: " . $newbal]);
                    exit;
                } elseif ($data["action"] == "lose") {
                    updateBalance($_SESSION["id"], $bet * -1);
                    addTransaction($txId, $_SESSION["id"], $bet * -1);
                    transfertransaction($bet, "out");
                    $newbal = getBalance($_SESSION["id"]);
                    echo json_encode(["status" => "You lost " . $bet . " HCC... New balance: " . $newbal]);
                    exit;
                } else {
                    echo json_encode(["status" => "Invalid action."]);
                    exit;
                }
            } else {
                echo json_encode(["status" => "Secondary verification failed."]);
                exit;
            }
        } else {
            echo json_encode(["status" => "Security check failed."]);
            exit;
        }
    }

    // ── HORSE RACING GAME ─────────────────────────────────────────────────────
    if (($data["horseracing"] ?? null) === "active") {
        if (!isset($_SESSION["security"], $_SESSION["addr"], $_SESSION["id"])) {
            echo json_encode(["status" => "Not logged in."]);
            exit();
        }
        if (!isset($data["verifytransaction"], $data["won"], $data["horse"], $data["bet"], $data["winner"])) {
            echo json_encode(["status" => "Missing horse racing parameters."]);
            exit();
        }
        if (!isset($_SESSION['captchasolved']) || $_SESSION['captchasolved'] !== 'true') {
            echo json_encode(['status' => 'Captcha not solved.']);
            exit();
        }
        $bal  = getBalance($_SESSION["id"]);
        $txId = rand(0, 1000000000000000);
        if (userdecrypt($_SESSION["security"], $_SESSION["addr"])) {
            if (vdecrypt($data["verifytransaction"], $_SESSION["addr"])) {
                $bet = $data["bet"];
                if ($bet < 1) { $bet = 1; }
                if ($bet > 5) { $bet = 5; }
                if ($bal < 1 || $bal < $bet) {
                    echo json_encode(["status" => "Balance too low! No hcc subtracted from your balance."]);
                    exit;
                }

                if ($data["won"] == "true") {
                    if ($data["horse"] == $data["winner"]) {
                        updateBalance($_SESSION["id"], $bet * 5);
                        addTransaction($txId, $_SESSION["id"], $bet * 5);
                        transfertransaction($bet * 5, "in");
                        $newbal = getBalance($_SESSION["id"]);
                        echo json_encode(["status" => "You won " . $bet * 5 . " HCC! New balance: " . $newbal]);
                        exit;
                    } else {
                        echo json_encode(["status" => "Invalid response."]);
                        exit;
                    }
                } elseif ($data["won"] == "false") {
                    updateBalance($_SESSION["id"], $bet * -1);
                    addTransaction($txId, $_SESSION["id"], $bet * -1);
                    transfertransaction($bet, "out");
                    $newbal = getBalance($_SESSION["id"]);
                    echo json_encode(["status" => "You lost " . $bet . " HCC... New balance: " . $newbal]);
                    exit;
                } else {
                    echo json_encode(["status" => "Invalid response."]);
                    exit;
                }
            } else {
                echo json_encode(["status" => "Secondary verification failed."]);
            }
        } else {
            echo json_encode(["status" => "Security check failed."]);
        }
    }
    // Fallback for unknown POST payloads
    echo json_encode([
        "error" => "Unhandled POST request.",
        "received_keys" => array_keys($data)
    ]);
    exit;
}
