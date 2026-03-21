<?php
session_start();

// Initialise captcha state only if it has never been set in this session
if (!isset($_SESSION["captchasolved"])) {
    $_SESSION["captchasolved"] = "false";
}

$status = '';
$captchaEnabled = true;

// ── Bet counter handler ──
if (isset($_POST['action']) && $_POST['action'] === 'bet_count') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['captchasolved']) || $_SESSION['captchasolved'] !== 'true') {
        echo json_encode(['needs_captcha' => true]);
        exit();
    }

    if (!isset($_SESSION['bet_count'])) {
        $_SESSION['bet_count'] = 0;
    }

    $_SESSION['bet_count']++;

    if ($_SESSION['bet_count'] >= 5) {
        $_SESSION['captchasolved'] = "x";
        $_SESSION['bet_count'] = 0;
        echo json_encode(['needs_captcha' => true]);
    } else {
        echo json_encode(['needs_captcha' => false]);
    }
    exit();
}

// ── Captcha handler ──
if (isset($_POST['captcha']) && $_POST['captcha'] !== '') {
    header('Content-Type: application/json');

    if (!isset($_SESSION['captcha']) || strcasecmp($_SESSION['captcha'], $_POST['captcha']) !== 0) {
        $status = "<span style='background-color:#FF0000;'>Entered captcha code does not match! Kindly try again.</span>";
        $_SESSION["captchasolved"] = "false";
    } else {
        $status = "<span style='background-color:#46ab4a;'>Your captcha code is correct.</span>";
        $captchaEnabled = false;
        $_SESSION["captchasolved"] = "true";
        unset($_SESSION['captcha']); // Prevent replay attacks
    }

    echo json_encode(['status' => $status, 'captchaEnabled' => $captchaEnabled]);
    exit();
}

$captchaSolved = isset($_SESSION['captchasolved']) && $_SESSION['captchasolved'] === 'true';
$gameHidden    = $captchaSolved ? '' : 'hidden';
$captchaHidden = $captchaSolved ? 'hidden' : '';
$btnDisabled   = $captchaSolved ? '' : 'disabled';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Blackjack Game</title>
    <link rel="stylesheet" href="styles.css">
    <link rel="icon" href="hcc.png" type="image/x-icon">
    <style>
        .hidden { display: none; }
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

<h1 id="disclaimer" class="heading1">Blackjack Game</h1>

<div class="game-area <?php echo $gameHidden; ?>" id="game-area">
    <h2>Dealer's Hand</h2>
    <div id="dealer-cards" class="cards"></div>
    <div class="score" id="dealer-score"></div>

    <h2>Your Hand</h2>
    <div id="player-cards" class="cards"></div>
    <div class="score" id="player-score"></div>

    <div>
        <button class="button" id="hit" <?php echo $btnDisabled; ?>>Hit</button>
        <button class="button" id="stand" <?php echo $btnDisabled; ?>>Stand</button>
        <button class="button" id="deal" <?php echo $btnDisabled; ?>>New Game</button>
    </div>

    <br><br><br>

    <div>
        <b>Set your bet:</b><br><br>
        <small><i>If you win, you will earn twice your bet. If you lose, you will lose your bet.</i></small><br><br>
        <input id="bet" type="number" min="1" max="5" value="1">
    </div>
</div>

<div class="captcha-area <?php echo $captchaHidden; ?>" id="captcha-area">
    <form name="form" method="post" id="captcha-form">
        <label><strong>Enter Captcha To Play:</strong></label><br />
        <input type="text" name="captcha" id="captcha-input" />
        <p><br />
            <img src="captcha.php?rand=<?php echo rand(); ?>" id="captcha_image">
        </p>
        <p>
            Can't read the image?
            <a href="javascript:refreshCaptcha();">click here</a>
            to refresh
        </p>
        <input type="submit" name="submit" value="Submit" id="captcha-submit">
    </form>
    <h2 id="capstatus"></h2>
</div>

<h2 id="status"></h2>
<h3 id="status2"></h3>

<script>
    function refreshCaptcha() {
        const img = document.getElementById('captcha_image');
        img.src = 'captcha.php?rand=' + Math.random() * 1000;
    }

    document.getElementById('captcha-form').onsubmit = function(event) {
        event.preventDefault();
        const formData = new FormData(this);

        fetch('', {
            method: 'POST',
            body: formData,
            credentials: 'same-origin'
        })
            .then(response => response.json())
            .then(data => {
                document.getElementById('capstatus').innerHTML = data.status || '';

                if (!data.captchaEnabled) {
                    window.location.reload();
                }
            })
            .catch(error => console.error('Captcha error:', error));
    };

    function setButtons(gameActive, canDeal = true) {
        const hit = document.getElementById('hit');
        const stand = document.getElementById('stand');
        const deal = document.getElementById('deal');

        hit.disabled = !gameActive;
        stand.disabled = !gameActive;
        deal.disabled = gameActive || !canDeal;
    }

    function normalizeBet(value) {
        let bet = parseInt(value, 10);
        if (Number.isNaN(bet)) bet = 1;
        if (bet < 1) bet = 1;
        if (bet > 5) bet = 5;
        return bet;
    }

    function post(payload) {
        return fetch('/backend.php', {
            method: 'POST',
            headers: { 'Content-type': 'application/json' },
            body: JSON.stringify(payload),
            credentials: 'same-origin'
        }).then(async r => {
            let text = await r.text();
            text = text.replace(/^\uFEFF/, '').trim();

            if (!r.ok) {
                return {
                    error: `Network error (${r.status}): ${text}`,
                    active: false
                };
            }

            if (!text) {
                console.error('Empty response from /backend.php', { payload });
                return {
                    error: 'Empty response from /backend.php',
                    active: false
                };
            }

            try {
                return JSON.parse(text);
            } catch (e) {
                console.error('Invalid JSON from /backend.php', {
                    payload,
                    rawResponse: text
                });
                return {
                    error: `Invalid JSON from /backend.php: ${text}`,
                    active: false
                };
            }
        }).catch(err => {
            console.error('POST /backend.php failed', {
                payload,
                error: String(err)
            });
            return {
                error: String(err),
                active: false
            };
        });
    }

    function renderHand(containerId, cards) {
        if (!Array.isArray(cards)) {
            document.getElementById(containerId).innerHTML = '';
            return;
        }

        document.getElementById(containerId).innerHTML =
            cards.map(c => `<div class="card">${c.value === '?' ? '🂠' : c.value}</div>`).join('');
    }

    function applyState(data) {
        if (!data || typeof data !== 'object') {
            document.getElementById('status').innerText = 'Unexpected empty game state.';
            setButtons(false, true);
            return;
        }

        if (data.error) {
            document.getElementById('status').innerText = data.error;
            document.getElementById('status2').innerText = '';
            setButtons(false, true);
            return;
        }

        if (data.status && !data.player && !data.dealer) {
            document.getElementById('status').innerText = data.status;
            document.getElementById('status2').innerText = '';
            setButtons(false, true);
            return;
        }

        renderHand('player-cards', data.player || []);
        renderHand('dealer-cards', data.dealer || []);
        document.getElementById('player-score').innerText =
            data.player_score !== undefined ? `Points: ${data.player_score}` : '';
        document.getElementById('dealer-score').innerText =
            data.dealer_score !== undefined ? `Points: ${data.dealer_score}` : '';
        document.getElementById('status').innerText = data.message || '';
        document.getElementById('status2').innerText = data.balance_msg || '';

        setButtons(!!data.active, true);
    }

    document.getElementById('hit').addEventListener('click', function() {
        post({ blackjack: 'active', action: 'hit' }).then(applyState);
    });

    document.getElementById('stand').addEventListener('click', function() {
        post({ blackjack: 'active', action: 'stand' }).then(applyState);
    });

    function dealNow() {
        const bet = normalizeBet(document.getElementById('bet').value);
        document.getElementById('bet').value = bet;
        post({ blackjack: 'active', action: 'deal', bet: bet }).then(applyState);
    }

    document.getElementById('deal').addEventListener('click', function() {
        fetch('', {
            method: 'POST',
            body: new URLSearchParams({ action: 'bet_count' }),
            credentials: 'same-origin'
        })
            .then(r => r.json())
            .then(data => {
                if (data.needs_captcha) {
                    window.location.reload();
                } else {
                    dealNow();
                }
            })
            .catch(e => console.error('Bet counter error:', e));
    });

    fetch('/backend.php', { credentials: 'same-origin' })
        .then(r => r.text())
        .then(data => {
            const trimmed = data.trim();
            document.getElementById('userx').innerHTML = data;

            if (trimmed === 'Not Logged In' || trimmed === 'Failed Security Check') {
                document.getElementById('disclaimer').innerHTML =
                    "<a href='connect.html'>Connect</a> your account to play BlackJack.";
                setButtons(false, false);
                return;
            }

            setButtons(false, true);
        })
        .catch(e => {
            console.error('Initial session check failed:', e);
            setButtons(false, false);
        });
</script>
</body>
</html>
