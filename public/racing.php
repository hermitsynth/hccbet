<?php
session_start();

// Initialise captcha state
if (!isset($_SESSION['captchasolved'])) {
    $_SESSION['captchasolved'] = 'false';
}

// Horse definitions
$horses = [
    ['name' => 'Spirit', 'color' => '#01CC00'],
    ['name' => 'Comet',      'color' => '#01A100'],
    ['name' => 'Thunder',  'color' => '#018000'],
    ['name' => 'Notorious',   'color' => '#016900'],
    ['name' => 'Glory',         'color' => '#014200'],
];

// ── Bet counter handler (re-captcha every 5 races) ──
if (isset($_POST['action']) && $_POST['action'] === 'bet_count') {
    if (($_SESSION['captchasolved'] ?? '') !== 'true') {
        echo json_encode(['needs_captcha' => true]);
        exit();
    }
    if (!isset($_SESSION['race_count'])) $_SESSION['race_count'] = 0;
    $_SESSION['race_count']++;
    if ($_SESSION['race_count'] >= 5) {
        $_SESSION['captchasolved'] = 'x';
        $_SESSION['race_count'] = 0;
        echo json_encode(['needs_captcha' => true]);
    } else {
        echo json_encode(['needs_captcha' => false]);
    }
    exit();
}

// ── Captcha handler ──
if (isset($_POST['captcha']) && $_POST['captcha'] !== '') {
    if (strcasecmp($_SESSION['captcha'] ?? '', $_POST['captcha']) !== 0) {
        $msg = "<span style='background:#FF0000'>Captcha incorrect. Try again.</span>";
        $_SESSION['captchasolved'] = 'false';
    } else {
        $msg = "<span style='background:#46ab4a'>Captcha correct!</span>";
        $_SESSION['captchasolved'] = 'true';
        unset($_SESSION['captcha']);
    }
    echo json_encode(['status' => $msg, 'captchaEnabled' => ($_SESSION['captchasolved'] !== 'true')]);
    exit();
}

// ── Race handler ──
if (isset($_POST['action']) && $_POST['action'] === 'race') {
    if (($_SESSION['captchasolved'] ?? '') !== 'true') {
        echo json_encode(['error' => 'Please solve the captcha first.']);
        exit();
    }

    $selected = intval($_POST['horse'] ?? -1);
    if ($selected < 0 || $selected > 4) {
        echo json_encode(['error' => 'Invalid horse selection.']);
        exit();
    }

    $bet = intval($_POST['bet'] ?? 0);
    if ($bet < 1) {
        echo json_encode(['error' => 'Minimum bet is 1 HCC.']);
        exit();
    }
    if ($bet > 5) {
        echo json_encode(['error' => 'Maximum bet is 5 HCC.']);
        exit();
    }

    $payout = $bet * 5; // 5x the bet (1 HCC per horse x 5 horses)

    // -- Step 1: Build absolute URL for /var/www/hccbet/private/backend.php --
    // curl cannot resolve relative paths — it must have a full URL.
    $protocol   = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host       = $_SERVER['HTTP_HOST'];
    $dir        = rtrim(dirname($_SERVER['PHP_SELF']), '/');
    $backendUrl = $protocol . '://' . $host . $dir . '//var/www/hccbet/private/backend.php';

    $sessionCookie = session_name() . '=' . session_id();

    // Release the session lock before making curl requests to /var/www/hccbet/private/backend.php.
    // /var/www/hccbet/private/backend.php calls session_start() too — without this, it blocks waiting
    // for the lock and both curl calls time out, returning empty responses.
    session_write_close();

    $ch = curl_init($backendUrl);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode(['verify' => 'active']),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIE         => $sessionCookie,
    ]);
    $verifyRaw = curl_exec($ch);
    if (curl_errno($ch)) {
        file_put_contents('racing_debug.log', date('H:i:s') . ' verify curl error: ' . curl_error($ch) . "\n", FILE_APPEND);
    }
    curl_close($ch);

    $verifyToken = '';
    if ($verifyRaw !== false) {
        $verifyData  = json_decode($verifyRaw, true);
        $verifyToken = $verifyData['status'] ?? '';
    }

    if (empty($verifyToken)) {
        file_put_contents('racing_debug.log', date('H:i:s') . ' verify token empty. Raw: ' . $verifyRaw . "\n", FILE_APPEND);
    }

    // -- Generate random speeds for each horse (higher = faster) --
    $speeds = [];
    for ($i = 0; $i < 5; $i++) {
        $speeds[$i] = rand(40, 100);
    }

    // -- Determine winner (highest speed) --
    $winner = (int) array_search(max($speeds), $speeds);

    // Build placement rankings (index sorted by speed descending)
    $tmp = $speeds;
    arsort($tmp);
    $rankings = array_values(array_keys($tmp));

    $won = ($winner === $selected);

    // -- Step 2: Report transaction result to /var/www/hccbet/private/backend.php and capture response --
    $ch2 = curl_init($backendUrl);
    curl_setopt_array($ch2, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode([
            "horseracing"       => "active",
            'verifytransaction' => $verifyToken,
            'won'               => $won ? 'true' : 'false',
            'bet'               => $bet,
            'horse'             => $selected,
            'winner'            => $winner,
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_COOKIE         => $sessionCookie,
    ]);
    $backendRaw = curl_exec($ch2);
    if (curl_errno($ch2)) {
        file_put_contents('racing_debug.log', date('H:i:s') . ' race curl error: ' . curl_error($ch2) . "\n", FILE_APPEND);
    }
    curl_close($ch2);

    // Parse the backend response so we can forward its Status to the frontend
    $backendStatus = '';
    if ($backendRaw !== false) {
        $backendData  = json_decode($backendRaw, true);
        $backendStatus = $backendData['status'] ?? '';
    }

    file_put_contents('racing_debug.log', date('H:i:s') . ' race raw: ' . $backendRaw . "\n", FILE_APPEND);

    echo json_encode([
        'speeds'        => array_values($speeds),
        'winner'        => $winner,
        'rankings'      => $rankings,
        'won'           => $won,
        'selected'      => $selected,
        'bet'           => $bet,
        'payout'        => $payout,
        'backendStatus' => $backendStatus,
    ]);
    exit();
}

$captchaSolved = isset($_SESSION['captchasolved']) && $_SESSION['captchasolved'] === 'true';
$gameHidden    = $captchaSolved ? '' : 'hidden';
$captchaHidden = $captchaSolved ? 'hidden' : '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Horse Racing</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" href="hcc.png" type="image/x-icon">
    <style>
        .hidden { display: none; }

        /* ── Track ── */
        .track-container {
            width: 92%;
            max-width: 720px;
            margin: 18px auto;
            border: 3px solid #00ff00;
            border-radius: 8px;
            overflow: hidden;
            background: #001200;
        }

        .track-header {
            background: #003300;
            color: #00ff00;
            text-align: center;
            padding: 9px 0;
            font-family: 'Courier New', monospace;
            font-size: 18px;
            font-weight: bold;
            border-bottom: 2px solid #00ff00;
            letter-spacing: 2px;
        }

        .track-inner {
            padding: 12px 10px;
        }

        .lane {
            display: flex;
            align-items: center;
            margin: 5px 0;
            height: 46px;
            border-radius: 4px;
            overflow: hidden;
        }

        .lane-label {
            width: 136px;
            min-width: 136px;
            font-family: 'Courier New', monospace;
            font-size: 11.5px;
            font-weight: bold;
            color: #fff;
            text-align: center;
            padding: 2px 4px;
            height: 100%;
            display: flex;
            align-items: center;
            justify-content: center;
            border-right: 2px solid rgba(255,255,255,0.25);
            flex-shrink: 0;
        }

        .lane-track {
            flex: 1;
            height: 100%;
            position: relative;
            background: rgba(255, 255, 255, 0.10);
        }

        /* Chequered finish line */
        .finish-line {
            position: absolute;
            right: 0;
            top: 0;
            bottom: 0;
            width: 5px;
            background: repeating-linear-gradient(
                to bottom,
                #fff 0px, #fff 5px,
                #111 5px, #111 10px
            );
            z-index: 2;
        }

        .horse {
            position: absolute;
            left: 4px;
            top: 50%;
            transform: translateY(-50%);
            font-size: 26px;
            z-index: 2;
            line-height: 1;
            /* transition is set by JS at race start */
        }

        @keyframes gallop {
            0%   { margin-top: -2px; }
            50%  { margin-top:  3px; }
            100% { margin-top: -2px; }
        }
        .horse.racing {
            animation: gallop 0.22s infinite ease-in-out;
        }

        /* ── Horse selection buttons ── */
        .horse-select {
            display: flex;
            flex-wrap: wrap;
            justify-content: center;
            gap: 9px;
            margin: 18px auto 6px;
            max-width: 720px;
        }

        .horse-btn {
            padding: 9px 15px;
            border: 3px solid transparent;
            border-radius: 6px;
            cursor: pointer;
            font-family: 'Courier New', monospace;
            font-size: 13px;
            font-weight: bold;
            color: #fff;
            transition: border-color 0.15s, transform 0.1s, opacity 0.15s;
        }

        .horse-btn:hover:not(:disabled) {
            transform: scale(1.06);
            opacity: 0.88;
        }

        .horse-btn.selected {
            border-color: #00ff00;
            box-shadow: 0 0 10px #00ff00;
        }

        .horse-btn:disabled {
            cursor: not-allowed;
            opacity: 0.5;
        }

        /* ── Start button ── */
        .race-btn {
            display: block;
            margin: 12px auto;
            padding: 11px 38px;
            border: 2px solid #00ff00;
            border-radius: 6px;
            font-family: 'Courier New', monospace;
            font-size: 15px;
            font-weight: bold;
            cursor: pointer;
            letter-spacing: 2px;
            transition: background 0.15s, box-shadow 0.15s;
        }

        .race-btn:hover:not(:disabled) {
            background: #005500;
            box-shadow: 0 0 14px #00ff00;
        }

        .race-btn:disabled {
            opacity: 0.38;
            cursor: not-allowed;
        }

        /* ── Status text ── */
        .bet-info {
            text-align: center;
            font-family: 'Courier New', monospace;
            color: #00ff00;
            margin: 6px 0;
            font-size: 13.5px;
        }

        #race-status {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 21px;
            color: #00ff00;
            margin: 10px 4px 4px;
            min-height: 32px;
            font-weight: bold;
        }

        #balance-msg {
            text-align: center;
            font-family: 'Courier New', monospace;
            font-size: 14px;
            color: #00cc00;
            margin: 2px 4px 10px;
            min-height: 20px;
        }

        .win-txt  { color: #ffff00; }
        .lose-txt { color: #ff4444; }

        /* ── Results board ── */
        .results-area {
            max-width: 500px;
            margin: 6px auto 20px;
        }

        .result-row {
            display: flex;
            align-items: center;
            justify-content: space-between;
            padding: 7px 14px;
            margin: 4px 0;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
            font-weight: bold;
            color: #fff;
            font-size: 14.5px;
        }

        .result-place { min-width: 55px; text-align: right; }

        /* ── Captcha ── */
        .captcha-area {
            text-align: center;
            margin: 40px auto;
            max-width: 420px;
            font-family: 'Courier New', monospace;
            color: #00ff00;
        }

        .captcha-area input[type="text"] {
            background: #000;
            border: 2px solid #00ff00;
            color: #00ff00;
            font-family: 'Courier New', monospace;
            font-size: 16px;
            padding: 6px 10px;
            border-radius: 4px;
            margin: 8px 0;
        }

        .captcha-area a { color: #00aa00; }
    </style>
</head>
<body>

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

<h1 class="heading1" id="disclaimer">Horse Racing</h1>

<!-- ─── CAPTCHA ─── -->
<div class="captcha-area <?php echo $captchaHidden; ?>" id="captcha-area">
    <form id="captcha-form" method="post">
        <label><strong>Enter Captcha To Play:</strong></label><br><br>
        <input type="text" name="captcha" id="captcha-input" autocomplete="off"><br>
        <p><img src="captcha.php?rand=<?php echo rand(); ?>" id="captcha_image"></p>
        <p>Can't read it? <a href="javascript:refreshCaptcha();">Click here</a> to refresh.</p>
        <input type="submit" value="Submit">
    </form>
    <h2 id="capstatus"></h2>
</div>

<!-- ─── GAME ─── -->
<div id="game-area" class="<?php echo $gameHidden; ?>">

    <!-- Track -->
    <div class="track-container">
        <div class="track-header">🏁 Racetrack 🏁</div>
        <div class="track-inner" id="track-inner">
            <?php foreach ($horses as $i => $h): ?>
            <div class="lane" style="background:<?php echo $h['color']; ?>44;" id="lane-<?php echo $i; ?>">
                <div class="lane-label" style="background:<?php echo $h['color']; ?>bb;">
                    🐴 <?php echo htmlspecialchars($h['name']); ?>
                </div>
                <div class="lane-track" id="ltrack-<?php echo $i; ?>">
                    <div class="finish-line"></div>
                    <div class="horse" id="horse-<?php echo $i; ?>">🐴</div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- Horse selection -->
    <p class="bet-info">Pick a horse and set your bet (1–5 HCC):</p>
    <div class="horse-select" id="horse-select">
        <?php foreach ($horses as $i => $h): ?>
        <button class="horse-btn"
                style="background:<?php echo $h['color']; ?>;"
                onclick="selectHorse(<?php echo $i; ?>)"
                id="hbtn-<?php echo $i; ?>">
            🐴 <?php echo htmlspecialchars($h['name']); ?>
        </button>
        <?php endforeach; ?>
    </div>

    <div class="bet-info" id="selected-msg">No horse selected.</div>

    <div style="text-align:center;margin:12px 0 4px;">
        <label for="bet-input" style="font-family:'Courier New',monospace;color:#00ff00;font-size:14px;">
            <strong>Bet Amount (HCC):</strong>
        </label><br><br>
        <input id="bet-input" type="number" min="1" max="5" value="1"
               oninput="updateBetLabel()"><br>
        <br><small style="font-family:'Courier New',monospace;color:#009900;font-size:12px;">
            Win pays <span id="payout-preview">5</span> HCC (5× your bet)
        </small>
    </div>

    <button class="race-btn" id="start-btn" disabled onclick="initiateRace()">
        🏁 PLACE BET &amp; RACE (<span id="btn-bet-label">1</span> HCC)
    </button>

    <div id="race-status"></div>
    <div id="balance-msg"></div>

    <!-- Results board (hidden until race ends) -->
    <div class="results-area hidden" id="results-area">
        <?php foreach ($horses as $i => $h): ?>
        <div class="result-row" style="background:<?php echo $h['color']; ?>;" id="result-<?php echo $i; ?>">
            <span>🐴 <?php echo htmlspecialchars($h['name']); ?></span>
            <span class="result-place" id="place-<?php echo $i; ?>"></span>
        </div>
        <?php endforeach; ?>
        <b id="backend-status-msg" style="display:block;text-align:center;font-family:'Courier New',monospace;color:#00ff00;font-size:15px;margin-top:12px;"></b>
    </div>

</div><!-- end game-area -->

<script>
    const HORSE_NAMES  = <?php echo json_encode(array_column($horses, 'name')); ?>;
    const HORSE_COLORS = <?php echo json_encode(array_column($horses, 'color')); ?>;
    const PLACES = ['🥇 1st', '🥈 2nd', '🥉 3rd', '4th', '5th'];

    let selectedHorse = -1;
    let raceRunning   = false;

    /* ── Captcha ── */
    function refreshCaptcha() {
        const img = document.getElementById('captcha_image');
        img.src = img.src.replace(/\?.*/, '') + '?rand=' + Math.random();
    }

    const captchaForm = document.getElementById('captcha-form');
    if (captchaForm) {
        captchaForm.onsubmit = function (e) {
            e.preventDefault();
            fetch('', { method: 'POST', body: new FormData(this) })
                .then(r => r.json())
                .then(d => {
                    document.getElementById('capstatus').innerHTML = d.status;
                    if (!d.captchaEnabled) window.location.reload();
                })
                .catch(console.error);
        };
    }

    /* ── Bet label updater ── */
    function updateBetLabel() {
        const val = parseInt(document.getElementById('bet-input').value) || 1;
        document.getElementById('btn-bet-label').innerText = val;
        document.getElementById('payout-preview').innerText = val * 5;
    }

    /* ── Horse selection ── */
    function selectHorse(idx) {
        if (raceRunning) return;
        selectedHorse = idx;
        document.querySelectorAll('.horse-btn').forEach((b, i) => {
            b.classList.toggle('selected', i === idx);
        });
        document.getElementById('selected-msg').innerText =
            'Selected: 🐴 ' + HORSE_NAMES[idx];
        document.getElementById('start-btn').disabled = false;
    }

    /* ── Reset track to starting positions ── */
    function resetTrack() {
        for (let i = 0; i < 5; i++) {
            const h = document.getElementById('horse-' + i);
            h.classList.remove('racing');
            h.style.transition = 'none';
            h.style.left = '4px';
        }
        document.getElementById('results-area').classList.add('hidden');
        document.getElementById('race-status').innerHTML = '';
        document.getElementById('balance-msg').innerHTML = '';
        document.getElementById('backend-status-msg').innerText = '';
        for (let i = 0; i < 5; i++) {
            document.getElementById('place-' + i).innerText = '';
        }
    }

    /* ── Start flow: check bet counter first ── */
    function initiateRace() {
        if (raceRunning || selectedHorse < 0) return;
        fetch('', { method: 'POST', body: new URLSearchParams({ action: 'bet_count' }) })
            .then(r => r.json())
            .then(d => {
                if (d.needs_captcha) { window.location.reload(); }
                else { doRace(); }
            })
            .catch(console.error);
    }

    /* ── POST race to server, then animate ── */
    function doRace() {
        raceRunning = true;
        document.getElementById('start-btn').disabled = true;
        document.querySelectorAll('.horse-btn').forEach(b => b.disabled = true);

        resetTrack();
        document.getElementById('race-status').innerText = '🏁 Race starting…';

        fetch('', {
            method: 'POST',
            body: new URLSearchParams({ action: 'race', horse: selectedHorse, bet: document.getElementById('bet-input').value })
        })
        .then(r => r.json())
        .then(data => {
            if (data.needs_captcha) { window.location.reload(); return; }
            if (data.error) {
                document.getElementById('race-status').innerText = '⚠ ' + data.error;
                raceRunning = false;
                document.getElementById('start-btn').disabled = false;
                return;
            }
            runAnimation(data);
        })
        .catch(err => {
            console.error(err);
            raceRunning = false;
            document.getElementById('start-btn').disabled = false;
        });
    }

    /* ── Animate the race ── */
    function runAnimation(data) {
        const speeds    = data.speeds;                    // [speed0, speed1, …]
        const maxSpeed  = Math.max(...speeds);
        const BASE_MS   = 3800;                           // fastest horse travel time

        // Compute finish positions per lane (subtract horse width & finish line)
        setTimeout(() => {
            document.getElementById('race-status').innerText = '🏇 They\'re off!';

            speeds.forEach((spd, i) => {
                const duration  = BASE_MS * (maxSpeed / spd);   // slower horses take longer
                const laneTrack = document.getElementById('ltrack-' + i);
                const targetPx  = (laneTrack.clientWidth - 38) + 'px'; // stop before finish line

                const horse = document.getElementById('horse-' + i);
                // Force reflow so the snap-back registers before the transition
                horse.getBoundingClientRect();
                horse.classList.add('racing');
                horse.style.transition = `left ${duration}ms linear`;
                horse.style.left = targetPx;
            });

            // Resolve after slowest horse finishes
            const minSpeed   = Math.min(...speeds);
            const maxDuration = BASE_MS * (maxSpeed / minSpeed);
            setTimeout(() => showResults(data), maxDuration + 700);

        }, 400);
    }

    /* ── Display results ── */
    function showResults(data) {
        const { winner, rankings, won, selected, bet, payout, backendStatus } = data;

        // Stop gallop animation, show placements
        for (let i = 0; i < 5; i++) {
            document.getElementById('horse-' + i).classList.remove('racing');
        }

        rankings.forEach((horseIdx, place) => {
            document.getElementById('place-' + horseIdx).innerText = PLACES[place];
        });
        document.getElementById('results-area').classList.remove('hidden');

        const winnerName = HORSE_NAMES[winner];
        if (won) {
            document.getElementById('race-status').innerHTML =
                `<span class="win-txt">🎉 ${winnerName} wins! You won!</span>`;
        } else {
            document.getElementById('race-status').innerHTML =
                `<span class="lose-txt">🐴 ${winnerName} wins! You lost your bet.</span>`;
        }

        // Display and log the backend status message
        console.log('backendStatus:', backendStatus);
        document.getElementById('backend-status-msg').innerText = backendStatus || '';

        // Re-enable UI
        raceRunning = false;
        document.querySelectorAll('.horse-btn').forEach(b => b.disabled = false);
        document.getElementById('start-btn').disabled = (selectedHorse < 0);

        // Refresh balance display
        fetch('/var/www/hccbet/private/backend.php')
            .then(r => r.text())
            .then(txt => { document.getElementById('userx').innerHTML = txt; })
            .catch(console.error);
    }

    /* ── Load user info on page open ── */
    fetch('/var/www/hccbet/private/backend.php')
        .then(r => r.text())
        .then(txt => {
            document.getElementById('userx').innerHTML = txt;
            if (txt.trim() === 'Not Logged In') {
                document.getElementById('disclaimer').innerHTML =
                    "<a href='connect.html'>Connect</a> your account to play Horse Racing.";
            }
        })
        .catch(console.error);
</script>
</body>
</html>