<?php
session_start();

/* ════════════════════════════════════════════════════════
   SECTION 1 – CAPTCHA VERIFICATION  (AJAX from form)
   ════════════════════════════════════════════════════════ */
if (isset($_POST['captcha'])) {
    $status = '';
    $captchaEnabled = true;

    if ($_POST['captcha'] === '') {
        $status = "<span style='background-color:#FF0000;'>Please enter the captcha.</span>";
    } elseif (strcasecmp($_SESSION['captcha'], $_POST['captcha']) != 0) {
        $status = "<span style='background-color:#FF0000;'>Entered captcha code does not match! Kindly try again.</span>";
        $_SESSION["captchasolved"] = "false";
    } else {
        $status = "<span style='background-color:#11FF00;color:black;'>Captcha verified! Good luck.</span>";
        $captchaEnabled = false;
        $_SESSION["captchasolved"] = "true";
        $_SESSION["hilo_turns"] = 0; // reset turn counter on each fresh captcha solve
    }

    header('Content-Type: application/json');
    echo json_encode(['status' => $status, 'captchaEnabled' => $captchaEnabled]);
    exit();
}

/* ════════════════════════════════════════════════════════
   SECTION 2 – GAME PLAY  (AJAX from JS, JSON body)
   ════════════════════════════════════════════════════════ */
$raw = file_get_contents('php://input');
if ($raw !== '') {
    $payload = json_decode($raw, true);

    if (isset($payload['action']) && $payload['action'] === 'play') {

        header('Content-Type: application/json');

        /* ── guard: captcha must be solved ── */
        if (($_SESSION["captchasolved"] ?? '') !== 'true') {
            echo json_encode(['error' => 'Captcha not verified.']);
            exit();
        }

        /* ── validate inputs ── */
        $side = $payload['side'] ?? '';
        $bet  = intval($payload['bet'] ?? 0);

        if (!in_array($side, ['high', 'low'], true)) {
            echo json_encode(['error' => 'Invalid side.']);
            exit();
        }
        if ($bet < 1 || $bet > 5) {
            echo json_encode(['error' => 'Bet must be between 1 and 5.']);
            exit();
        }

        /* ── server-side roll ── */
        $MAX    = 99999;
        $half   = $bet * 5000;            // bet1→5000, bet5→25000
        $loseLow  = 50000 - $half;
        $loseHigh = 50000 + $half;
        $rolled = random_int(0, $MAX);    // cryptographically secure

        /* ── determine outcome ── */
        $inLoseZone = ($rolled >= $loseLow && $rolled <= $loseHigh);
        $won = false;
        if (!$inLoseZone) {
            if ($side === 'high' && $rolled > $loseHigh) $won = true;
            if ($side === 'low'  && $rolled < $loseLow)  $won = true;
        }

        /* ── forward result to backend.php server-side (no client involvement) ── */
        $balanceMsg = '';

        // Release the session lock before making server-side HTTP calls to backend.php.
        // Without this, backend.php blocks forever waiting to acquire the same session lock
        // that hilo.php is already holding — a silent deadlock causing timeouts.
        session_write_close();

        // Build shared cookie header for all backend calls
        $cookieHeader = '';
        if (!empty($_COOKIE)) {
            $pairs = [];
            foreach ($_COOKIE as $k => $v) {
                $pairs[] = rawurlencode($k) . '=' . rawurlencode($v);
            }
            $cookieHeader = implode('; ', $pairs);
        }

        // Step 1: fetch a verify token from backend.php
        $verifyToken = '';
        $verifyCtx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                             "Cookie: " . $cookieHeader . "\r\n",
                'content' => json_encode(['verify' => 'active']),
                'timeout' => 5,
                'ignore_errors' => true,
            ]
        ]);
        $backendUrl      = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . $_SERVER['HTTP_HOST'] . dirname($_SERVER['PHP_SELF']) . '/backend.php';
        $verifyResponse  = @file_get_contents($backendUrl, false, $verifyCtx);
        if ($verifyResponse !== false) {
            $vd = json_decode($verifyResponse, true);
            if (isset($vd['status'])) {
                $verifyToken = $vd['status'];
            }
        }

        // Step 2: include the verify token in the win/lose payload
        $backendPayload = json_encode([
            'hilo'              => 'active',
            'action'            => $won ? 'win' : 'lose',
            'bet'               => $bet,
            'rolled'            => $rolled,
            'verifytransaction' => $verifyToken
        ]);

        // Send the win/lose payload (with verify token) to backend.php
        $ctx = stream_context_create([
            'http' => [
                'method'  => 'POST',
                'header'  => "Content-Type: application/json\r\n" .
                             "Cookie: " . $cookieHeader . "\r\n",
                'content' => $backendPayload,
                'timeout' => 5,
                'ignore_errors' => true,
            ]
        ]);
        $backendResponse = @file_get_contents($backendUrl, false, $ctx);
        if ($backendResponse !== false) {
            $bd = json_decode($backendResponse, true);
            // FIX 1: backend.php returns "status", not "balance_msg"
            if (!empty($bd['status'])) {
                $balanceMsg = $bd['status'];
            }
        }

        /* ── re-open session to write turn counter ── */
        session_start();

        /* ── increment server-side turn counter ── */
        $_SESSION["hilo_turns"] = ($_SESSION["hilo_turns"] ?? 0) + 1;
        $needsCaptcha = false;
        if ($_SESSION["hilo_turns"] >= 5) {
            // Lock the game; force captcha before next round
            $_SESSION["captchasolved"] = "false";
            $_SESSION["hilo_turns"]    = 0;
            $needsCaptcha = true;
        }

        /* ── reply to browser (only display data, no game logic the client can influence) ── */
        echo json_encode([
            'rolled'        => $rolled,
            'won'           => $won,
            'in_lose_zone'  => $inLoseZone,
            'lose_low'      => $loseLow,
            'lose_high'     => $loseHigh,
            'balance_msg'   => $balanceMsg,
            'needs_captcha' => $needsCaptcha,
        ]);
        exit();
    }
}

/* ════════════════════════════════════════════════════════
   SECTION 3 – PAGE HTML
   ════════════════════════════════════════════════════════ */
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Hi/Lo Game</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" href="hcc.png" type="image/x-icon">
    <style>
        .hidden { display: none; }

        .bar-outer {
            display: flex;
            width: 80%;
            max-width: 700px;
            height: 32px;
            margin: 0 auto;
            border: 2px solid #11FF00;
            border-radius: 8px;
            overflow: hidden;
        }
        .bar-low  { background: #11FF00; transition: width .4s ease; }
        .bar-lose { background: #FF0000; transition: width .4s ease; }
        .bar-high { background: #11FF00; flex: 1; }

        .bar-needle-wrap {
            position: relative;
            width: 80%;
            max-width: 700px;
            height: 0;
            margin: 0 auto;
        }
        .bar-needle {
            display: none;
            position: absolute;
            top: 0;
            width: 3px;
            height: 22px;
            background: white;
            transform: translateX(-50%);
            transition: left .6s cubic-bezier(.22,1,.36,1);
            border-radius: 2px;
        }
        .bar-needle::after {
            content: '';
            position: absolute;
            top: 100%;
            left: 50%;
            transform: translateX(-50%);
            border-left: 5px solid transparent;
            border-right: 5px solid transparent;
            border-top: 7px solid white;
        }

        .bar-range-labels {
            display: flex;
            width: 80%;
            max-width: 700px;
            margin: 8px auto 0;
            justify-content: space-between;
        }
        .bar-edge-labels {
            display: flex;
            width: 80%;
            max-width: 700px;
            margin: 0 auto 6px;
            justify-content: space-between;
            font-size: 12px;
        }

        .digit-row {
            display: flex;
            justify-content: center;
            gap: 10px;
            margin: 20px 0;
        }
        .digit {
            display: inline-block;
            width: 56px;
            height: 80px;
            line-height: 80px;
            text-align: center;
            background-color: #11FF00;
            color: black;
            font-family: 'Courier New', monospace;
            font-size: 32px;
            font-weight: bold;
            border-radius: 5px;
            transition: background-color .2s, color .2s;
        }
        .digit.lose    { background-color: #FF0000; color: white; }
        .digit.rolling { opacity: .7; }

        input[type="range"] {
            -webkit-appearance: none;
            width: 200px;
            height: 6px;
            background: #0E9C00;
            border: 2px solid #11FF00;
            border-radius: 4px;
            outline: none;
            vertical-align: middle;
        }
        input[type="range"]::-webkit-slider-thumb {
            -webkit-appearance: none;
            width: 20px; height: 20px;
            border-radius: 50%;
            background: #11FF00;
            cursor: pointer;
            border: 2px solid black;
        }
        input[type="range"]::-moz-range-thumb {
            width: 20px; height: 20px;
            border-radius: 50%;
            background: #11FF00;
            cursor: pointer;
            border: none;
        }

        #result-msg { font-size: 22px; font-weight: bold; min-height: 30px; }
        #result-msg.win  { color: #11FF00; }
        #result-msg.lose { color: #FF0000; }
    </style>
</head>
<body>

<!-- ── nav ── -->
<div class="link-container">
    <a href="https://discord.gg/53w4DqaMqe">Discord</a>
    <a href="connect.html">Connect Account</a>
    <a href="donate.html">Donate</a>
    <a href="https://hashcashfaucet.com">Signup</a>
    <a href="deposit.html">Deposit / Withdraw</a>
    <a href="explore.html">Explorer</a>
    <a href="index.html">Main Page</a><br>
    <b id="userx"></b>
</div>

<h1 id="disclaimer" class="heading1">Hi/Lo Game</h1>

<!-- ── captcha ── -->
<div class="captcha-area" id="captcha-area">
    <form name="form" method="post" id="captcha-form">
        <label><strong>Enter Captcha To Play:</strong></label><br><br>
        <input type="text" name="captcha" id="captcha-input" autocomplete="off"><br>
        <p><br>
            <img src="captcha.php?rand=<?php echo rand(); ?>" id="captcha_image" alt="captcha">
        </p>
        <p>Can't read the image?
            <a href="javascript:refreshCaptcha();">click here</a> to refresh
        </p>
        <input type="submit" name="submit" value="Submit" id="captcha-submit">
    </form>
    <h2 id="capstatus"></h2>
</div>

<!-- ── game ── -->
<div class="game-area hidden" id="game-area">

    <p>
        To win: <strong>BET HIGH</strong> and roll above
        <span style="background:#11FF00;color:black;padding:1px 8px;border-radius:4px;" id="hi-val">55,000</span>
        &nbsp;or&nbsp; <strong>BET LOW</strong> and roll below
        <span style="background:#11FF00;color:black;padding:1px 8px;border-radius:4px;" id="lo-val">45,000</span>
    </p>

    <!-- digit display -->
    <div class="digit-row">
        <div class="digit" id="d0">0</div>
        <div class="digit" id="d1">0</div>
        <div class="digit" id="d2">0</div>
        <div class="digit" id="d3">0</div>
        <div class="digit" id="d4">0</div>
    </div>

    <!-- bar -->
    <div class="bar-edge-labels">
        <span>0</span>
        <span>99,999</span>
    </div>
    <div class="bar-outer">
        <div class="bar-low"  id="bar-low"></div>
        <div class="bar-lose" id="bar-lose"></div>
        <div class="bar-high" id="bar-high"></div>
    </div>
    <div class="bar-needle-wrap">
        <div class="bar-needle" id="bar-needle"></div>
    </div>
    <div class="bar-range-labels">
        <span id="lbl-lose-low"  style="background:#FF0000;color:white;padding:2px 8px;border-radius:4px;">45,000</span>
        <span id="lbl-lose-high" style="background:#FF0000;color:white;padding:2px 8px;border-radius:4px;">55,000</span>
    </div>

    <br>
    <p><strong>Set your bet:</strong></p>
    <p><small><i>Higher bet = wider red losing range. Lower bet = smaller red range.</i></small></p>
    <br>
    <label>
        Bet: <strong id="bet-display">1</strong>&nbsp;
        <input type="range" id="bet-slider" min="1" max="5" value="1" step="1">
        &nbsp;(1–5)
    </label>
    <br><br>

    <button class="button2" id="btn-low"  disabled>BET LOW</button>
    <button class="button2" id="btn-high" disabled>BET HIGH</button>

    <br><br>
    <div id="result-msg"></div>
    <div id="balance-msg"></div>
    <div id="server-msg"></div>
    <br><br>
</div>

<h2 id="status"></h2>

<script>
    /* ── captcha ── */
    function refreshCaptcha() {
        const img = document.getElementById('captcha_image');
        img.src = img.src.split('?')[0] + '?rand=' + Math.random() * 1e6;
    }

    document.getElementById('captcha-form').onsubmit = function(e) {
        e.preventDefault();
        fetch('', { method: 'POST', body: new FormData(this) })
            .then(r => r.json())
            .then(data => {
                document.getElementById('capstatus').innerHTML = data.status;
                if (!data.captchaEnabled) {
                    document.getElementById('captcha-area').classList.add('hidden');
                    document.getElementById('game-area').classList.remove('hidden');
                    enableBtns(true);
                    updateBar(1); // render bar for default bet
                }
            })
            .catch(err => console.error(err));
    };

    /* ── bar display (visual only, computed from server-confirmed lose range) ── */
    const MAX = 99999;

    function getLoseRange(bet) {
        const half = bet * 5000;
        return { low: 50000 - half, high: 50000 + half };
    }

    function fmt(n) { return n.toLocaleString('en-US'); }

    function updateBar(bet) {
        const { low, high } = getLoseRange(bet);
        const lowPct  = (low / MAX) * 100;
        const losePct = ((high - low) / MAX) * 100;

        document.getElementById('bar-low').style.width  = lowPct + '%';
        document.getElementById('bar-lose').style.width = losePct + '%';
        document.getElementById('lbl-lose-low').textContent  = fmt(low);
        document.getElementById('lbl-lose-high').textContent = fmt(high);
        document.getElementById('hi-val').textContent = fmt(high);
        document.getElementById('lo-val').textContent = fmt(low);
        document.getElementById('bet-display').textContent = bet;
    }

    document.getElementById('bet-slider').addEventListener('input', function() {
        updateBar(+this.value);
        // reset needle and result when bet changes
        document.getElementById('bar-needle').style.display = 'none';
        document.getElementById('result-msg').textContent = '';
        document.getElementById('result-msg').className = '';
        document.getElementById('balance-msg').textContent = '';
        document.getElementById('server-msg').textContent = '';
        setDigits(0, '');
    });

    /* ── digit rendering ── */
    function setDigits(num, cls) {
        const s = String(num).padStart(5, '0');
        for (let i = 0; i < 5; i++) {
            const el = document.getElementById('d' + i);
            el.textContent = s[i];
            el.className = 'digit' + (cls ? ' ' + cls : '');
        }
    }

    function rollAnimation() {
        return new Promise(resolve => {
            let ticks = 0;
            const id = setInterval(() => {
                for (let i = 0; i < 5; i++) {
                    const el = document.getElementById('d' + i);
                    el.textContent = Math.floor(Math.random() * 10);
                    el.className = 'digit rolling';
                }
                if (++ticks >= 28) { clearInterval(id); resolve(); }
            }, 55);
        });
    }

    function moveNeedle(num) {
        const needle = document.getElementById('bar-needle');
        needle.style.display = 'block';
        needle.style.left = ((num / MAX) * 100) + '%';
    }

    /* ── play: JS only sends side + bet, all logic is server-side ── */
    let rolling = false;

    function enableBtns(on) {
        document.getElementById('btn-low').disabled  = !on;
        document.getElementById('btn-high').disabled = !on;
    }

    function play(side) {
        if (rolling) return;
        rolling = true;
        enableBtns(false);

        const bet = +document.getElementById('bet-slider').value;

        const resultEl  = document.getElementById('result-msg');
        const balanceEl = document.getElementById('balance-msg');
        const serverEl  = document.getElementById('server-msg');
        resultEl.className = '';
        resultEl.textContent = '';
        balanceEl.textContent = '';
        serverEl.textContent = '';
        document.getElementById('bar-needle').style.display = 'none';

        // Start the visual roll animation while the server processes
        const animPromise = rollAnimation();

        // Send ONLY side and bet — server generates the number and decides the outcome
        const serverPromise = fetch('', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ action: 'play', side: side, bet: bet })
        }).then(r => {
            if (!r.ok) throw new Error('Server error');
            return r.json();
        });

        // Wait for both animation and server response before revealing result
        Promise.all([animPromise, serverPromise])
            .then(([_, data]) => {
                console.log('[hilo] server response:', data);
                if (data.error) {
                    resultEl.textContent = 'Error: ' + data.error;
                    resultEl.className = 'lose';
                    rolling = false;
                    enableBtns(true);
                    return;
                }

                // Update bar to reflect what the server used (confirms bet was accepted)
                updateBar(bet);

                setDigits(data.rolled, data.won ? '' : 'lose');
                moveNeedle(data.rolled);

                if (data.won) {
                    resultEl.textContent = 'YOU WIN! +' + bet;
                    resultEl.className   = 'win';
                    balanceEl.textContent = 'Rolled ' + fmt(data.rolled) + ' — ' +
                        (side === 'high' ? 'above' : 'below') + ' the losing zone.';
                } else {
                    resultEl.textContent = 'YOU LOSE! -' + bet;
                    resultEl.className   = 'lose';
                    balanceEl.textContent = data.in_lose_zone
                        ? 'Rolled ' + fmt(data.rolled) + ' — landed in the red zone!'
                        : 'Rolled ' + fmt(data.rolled) + ' — wrong side!';
                }

                // FIX 2: populate #server-msg with the status message from backend.php
                if (data.balance_msg) {
                    serverEl.textContent = data.balance_msg;
                }

                rolling = false;

                if (data.needs_captcha) {
                    // Show result briefly, then require captcha again after a short delay
                    enableBtns(false);
                    setTimeout(function() {
                        document.getElementById('game-area').classList.add('hidden');
                        document.getElementById('captcha-area').classList.remove('hidden');
                        refreshCaptcha();
                        document.getElementById('captcha-input').value = '';
                        document.getElementById('capstatus').innerHTML =
                            "<span style='background-color:#FF0000;'>Please complete the captcha to continue playing.</span>";
                    }, 2000); // 2 second delay so the user can see their result first
                } else {
                    enableBtns(true);
                }
            })
            .catch(err => {
                console.error(err);
                resultEl.textContent = 'Connection error. Please try again.';
                resultEl.className = 'lose';
                rolling = false;
                enableBtns(true);
            });
    }

    document.getElementById('btn-low').addEventListener('click',  () => play('low'));
    document.getElementById('btn-high').addEventListener('click', () => play('high'));

    /* ── load user info (unchanged, this is fine client-side) ── */
    fetch('backend.php')
        .then(r => r.text())
        .then(data => {
            document.getElementById('userx').innerHTML = data;
            if (data.trim() === 'Not Logged In') {
                document.getElementById('disclaimer').innerHTML =
                    "<a href='connect.html'>Connect</a> your account to play Hi/Lo.";
            }
        })
        .catch(e => console.error(e));
</script>
</body>
</html>