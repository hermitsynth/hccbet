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
if (isset($_POST['captcha']) && ($_POST['captcha'] != "")) {
    if (strcasecmp($_SESSION['captcha'], $_POST['captcha']) != 0) {
        $status = "<span style='background-color:#FF0000;'>Entered captcha code does not match! 
                    Kindly try again.</span>";
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

<?php

$captchaSolved = isset($_SESSION['captchasolved']) && $_SESSION['captchasolved'] === 'true';
$gameHidden    = $captchaSolved ? '' : 'hidden';
$captchaHidden = $captchaSolved ? 'hidden' : '';
$btnDisabled   = $captchaSolved ? '' : 'disabled';
?>

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
        <small><i>If you win, you will earn twice your bet. If you lose, you will lose your bet!</i></small><br><br>
        <input id="bet" type="number" min="1" max="5" value="1">
    </div>
</div>

<div class="captcha-area <?php echo $captchaHidden; ?>" id="captcha-area">
    <form name="form" method="post" id="captcha-form">
        <label><strong>Enter Captcha To Play:</strong></label><br />
        <input type="text" name="captcha" id="captcha-input" />
        <p><br />
        <img src="captcha.php?rand=<?php echo rand(); ?>" id='captcha_image'>
        </p>
        <p>Can't read the image?
        <a href='javascript: refreshCaptcha();'>click here</a>
        to refresh</p>
        <input type="submit" name="submit" value="Submit" id="captcha-submit">
    </form>
    <h2 id="capstatus"></h2>
</div>
<h2 id="status"></h2>
<h3 id="status2"></h3>

<script>
    function refreshCaptcha() {
        var img = document.images['captcha_image'];
        img.src = img.src.substring(0, img.src.lastIndexOf("?")) + "?rand=" + Math.random() * 1000;
    }

    document.getElementById('captcha-form').onsubmit = function(event) {
        event.preventDefault();
        const formData = new FormData(this);

        fetch('', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                document.getElementById('capstatus').innerHTML = data.status;

                if (!data.captchaEnabled) {
       
                    window.location.reload();
                }
            })
            .catch(error => console.error('Error:', error));
    };

    function setButtons(gameActive) {
        document.getElementById('hit').disabled   = !gameActive;
        document.getElementById('stand').disabled = !gameActive;
        document.getElementById('deal').disabled  =  gameActive;
    }


    function post(payload) {
        return fetch("/var/www/hccbet/backend.php", {
            method: "POST",
            headers: { "Content-type": "application/json" },
            body: JSON.stringify(payload)
        }).then(r => {
            if (!r.ok) throw new Error("Network error");
            return r.json();
        });
    }

    function renderHand(containerId, cards) {
        document.getElementById(containerId).innerHTML =
            cards.map(c => `<div class="card">${c.value === '?' ? '🂠' : c.value}</div>`).join('');
    }

    function applyState(data) {
        if (data.error) {
            document.getElementById('status').innerText = data.error;
            return;
        }

        renderHand('player-cards', data.player);
        renderHand('dealer-cards', data.dealer);
        document.getElementById('player-score').innerText = `Points: ${data.player_score}`;
        document.getElementById('dealer-score').innerText = `Points: ${data.dealer_score}`;
        document.getElementById('status').innerText  = data.message    || '';
        document.getElementById('status2').innerText = data.balance_msg || '';

        setButtons(data.active);
    }


    document.getElementById('hit').addEventListener('click', function () {
        post({ blackjack: "active", action: "hit" })
            .then(applyState)
            .catch(e => console.error(e));
    });


    document.getElementById('stand').addEventListener('click', function () {
        post({ blackjack: "active", action: "stand" })
            .then(applyState)
            .catch(e => console.error(e));
    });

    function dealNow() {
        const bet = document.getElementById('bet').value;
        post({ blackjack: "active", action: "deal", bet: bet })
            .then(applyState)
            .catch(e => console.error(e));
    }


    document.getElementById('deal').addEventListener('click', function() {
        fetch('', { method: 'POST', body: new URLSearchParams({ action: 'bet_count' }) })
            .then(r => r.json())
            .then(data => {
                if (data.needs_captcha) {
                    window.location.reload();
                } else {
                    dealNow();
                }
            })
            .catch(e => console.error(e));
    });

    fetch('/var/www/hccbet/backend.php')
        .then(r => r.text())
        .then(data => {
            document.getElementById("userx").innerHTML = data;
            if (data.trim() === "Not Logged In") {
                document.getElementById("disclaimer").innerHTML =
                    "<a href='connect.html'>Connect</a> your account to play BlackJack.";
            }
            dealNow();
        })
        .catch(e => console.error(e));
</script>
</body>
</html>