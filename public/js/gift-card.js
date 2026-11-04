/*
 | T-251 — «يدمج الاسم والعبارة والشعار والرمز داخل التصميم بمقاس
 | 1200 × 675 … تصبح البطاقة جاهزة للتنزيل والمشاركة عبر واتساب»,
 | 4 November.
 |
 | Composed in the browser rather than on the server, for one reason:
 | Arabic. GD is the only image library on the box, and it draws glyphs
 | one after another with no shaping and no bidirectional ordering, so
 | «سنابل الوفاء» would come out as disconnected letters in the wrong
 | order. A canvas shapes Arabic correctly because a browser is a text
 | engine, and it costs no package, no headless browser on the server and
 | no CPU per card. Everything drawn here comes from the server: the
 | artwork, the two names, the reference and the QR matrix.
 |
 | The page itself still shows the card without any of this, so a browser
 | with no JavaScript loses the download and nothing else.
 */
(function () {
    const node = document.getElementById('gift-data');
    const button = document.getElementById('gift-download');

    if (!node || !button) {
        return;
    }

    const data = JSON.parse(node.textContent);

    const WIDTH = 1200;
    const HEIGHT = 675;
    const STRIP = 150;

    function drawQr(ctx, matrix, x, y, side) {
        const modules = matrix.length;
        const quiet = 2;
        const unit = side / (modules + quiet * 2);

        ctx.fillStyle = '#ffffff';
        ctx.fillRect(x, y, side, side);
        ctx.fillStyle = '#000000';

        for (let row = 0; row < modules; row++) {
            for (let column = 0; column < modules; column++) {
                if (matrix[row][column]) {
                    ctx.fillRect(
                        x + (column + quiet) * unit,
                        y + (row + quiet) * unit,
                        Math.ceil(unit),
                        Math.ceil(unit)
                    );
                }
            }
        }
    }

    function line(ctx, text, x, y, font, colour, align) {
        if (!text) {
            return;
        }

        ctx.font = font;
        ctx.fillStyle = colour;
        ctx.textAlign = align || 'center';
        ctx.direction = 'rtl';
        ctx.fillText(text, x, y);
    }

    function load(src) {
        return new Promise((resolve, reject) => {
            const image = new Image();
            image.crossOrigin = 'anonymous';
            image.onload = () => resolve(image);
            image.onerror = reject;
            image.src = src;
        });
    }

    async function compose() {
        const canvas = document.createElement('canvas');
        canvas.width = WIDTH;
        canvas.height = HEIGHT;

        const ctx = canvas.getContext('2d');
        ctx.textBaseline = 'middle';

        // The artwork fills the card; the strip sits over its foot.
        const art = await load(data.art);

        ctx.drawImage(art, 0, 0, WIDTH, HEIGHT);

        // The two names, and the amount where the giver chose to show it.
        ctx.save();
        ctx.fillStyle = 'rgba(255,255,255,.82)';
        ctx.fillRect(60, HEIGHT - STRIP - 150, WIDTH - 120, 120);
        ctx.restore();

        line(ctx, data.line, WIDTH / 2, HEIGHT - STRIP - 110, 'bold 34px Tajawal, sans-serif', '#1b1b1b');
        line(ctx, data.amount, WIDTH / 2, HEIGHT - STRIP - 60, 'bold 28px Tajawal, sans-serif', data.accent);

        // The association's strip: its marks in the middle, the formal
        // details on one side and the verification code on the other.
        ctx.fillStyle = data.accent;
        ctx.fillRect(0, HEIGHT - STRIP, WIDTH, STRIP);

        const middle = HEIGHT - STRIP / 2;

        // The association's own mark above its name, as their own drawing
        // of 4 November puts it. A mark that will not load is simply not
        // drawn: the card is still the association's card without it.
        try {
            const logo = await load(data.logo);
            const logoWidth = (logo.width / logo.height) * 44;

            ctx.drawImage(logo, WIDTH / 2 - logoWidth / 2, HEIGHT - STRIP + 8, logoWidth, 44);
        } catch (ignored) {
            // nothing
        }

        line(ctx, data.association, WIDTH / 2, middle + 16, 'bold 30px Tajawal, sans-serif', '#ffffff');
        line(ctx, data.tagline, WIDTH / 2, middle + 50, '20px Tajawal, sans-serif', 'rgba(255,255,255,.9)');

        line(ctx, data.website, WIDTH - 60, middle - 28, '20px Tajawal, sans-serif', 'rgba(255,255,255,.9)', 'right');
        line(ctx, data.licence, WIDTH - 60, middle + 4, '20px Tajawal, sans-serif', 'rgba(255,255,255,.9)', 'right');
        line(ctx, data.reference, WIDTH - 60, middle + 36, '20px Tajawal, sans-serif', 'rgba(255,255,255,.9)', 'right');

        drawQr(ctx, data.qr, 60, HEIGHT - STRIP + 16, 90);
        line(ctx, data.scan, 165, middle + 10, '18px Tajawal, sans-serif', 'rgba(255,255,255,.9)', 'left');

        return canvas;
    }

    button.addEventListener('click', async function () {
        button.disabled = true;

        try {
            // The web font has to be in before a single glyph is drawn, or
            // the canvas falls back to a face that may not carry Arabic.
            if (document.fonts && document.fonts.ready) {
                await document.fonts.ready;
            }

            const canvas = await compose();

            canvas.toBlob(function (blob) {
                const url = URL.createObjectURL(blob);
                const link = document.createElement('a');

                link.href = url;
                link.download = data.file;
                link.click();

                URL.revokeObjectURL(url);
            }, 'image/png');
        } finally {
            button.disabled = false;
        }
    });
})();
