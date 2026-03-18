const canvas = document.getElementById('captchaCanvas');
const ctx = canvas.getContext('2d');
const captchaInput = document.getElementById('captchaInput');
const errorText = document.getElementById('error');

let captchaText = '';

function generateCaptcha() {
    captchaText = '';
    ctx.clearRect(0, 0, canvas.width, canvas.height);
    
    // Generate random letters
    const letters = 'ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz0123456789';
    for (let i = 0; i < 6; i++) {
        captchaText += letters.charAt(Math.floor(Math.random() * letters.length));
    }
    
    // Set the font properties
    ctx.font = '40px Arial';
    ctx.fillStyle = '#000';
    ctx.textBaseline = 'middle';
    ctx.fillText(captchaText, 20, canvas.height / 2);

    // Add splotches/lines
    ctx.strokeStyle = 'rgba(0, 0, 0, 0.5)';
    for (let i = 0; i < 5; i++) {
        const x1 = Math.random() * canvas.width;
        const y1 = Math.random() * canvas.height;
        const x2 = Math.random() * canvas.width;
        const y2 = Math.random() * canvas.height;
        ctx.beginPath();
        ctx.moveTo(x1, y1);
        ctx.lineTo(x2, y2);
        ctx.stroke();
    }
}

// Initial call to generate CAPTCHA
generateCaptcha();

// Form submission
document.getElementById('captchaForm').addEventListener('submit', function(event) {
    event.preventDefault();
    if (captchaInput.value === captchaText) {
        alert('CAPTCHA verified successfully!');
        errorText.textContent = '';
        // Add your form submission logic here (e.g., send data via AJAX)
    } else {
        errorText.textContent = 'Incorrect CAPTCHA. Please try again.';
        captchaInput.value = '';
        generateCaptcha(); // Regenerate CAPTCHA
    }
});
